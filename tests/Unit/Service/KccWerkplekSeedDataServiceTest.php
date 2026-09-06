<?php

/**
 * Unit tests for KccWerkplekSeedDataService.
 *
 * Exercises the seed pipeline against an in-memory ObjectService fake,
 * asserts idempotency, and verifies the documented seed shape (five default
 * quick-actions and two example belplannen).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\KccWerkplekSeedDataService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\KccWerkplekSeedDataService
 */
class KccWerkplekSeedDataServiceTest extends TestCase {
	private FakeKccSeedObjectService $objects;

	private KccWerkplekSeedDataService $service;

	protected function setUp(): void {
		$this->objects = new FakeKccSeedObjectService();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'kcc_quick_action_schema' => 'kccQuickAction',
					'belplan_schema' => 'belplan',
					default => '',
				};
			},
		);

		$this->service = new KccWerkplekSeedDataService(
			$settings,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * @return void
	 */
	public function testSeedCreatesQuickActionsAndBelplannen(): void {
		$result = $this->service->seed();

		self::assertSame(true, $result['success']);
		self::assertSame(5, $result['quickActions']);
		self::assertSame(2, $result['belplannen']);
		self::assertSame(0, $result['skipped']);
		self::assertCount(5, $this->objects->store['kccQuickAction']);
		self::assertCount(2, $this->objects->store['belplan']);
	}

	/**
	 * @return void
	 */
	public function testSeedIsIdempotent(): void {
		$this->service->seed();
		$second = $this->service->seed();

		self::assertSame(true, $second['success']);
		self::assertSame(0, $second['quickActions']);
		self::assertSame(0, $second['belplannen']);
		self::assertSame(7, $second['skipped']);
		self::assertCount(5, $this->objects->store['kccQuickAction']);
		self::assertCount(2, $this->objects->store['belplan']);
	}

	/**
	 * A refused row is counted, and refusals make the seed unsuccessful.
	 *
	 * THE COUNTS ARE THE WHOLE POINT. `seed()` used to log each row failure and
	 * then return `success: true` with the untouched counters, so "seven rows
	 * refused" and "seven rows already present" both came back as
	 * `success: true, quickActions: 0, belplannen: 0` — and only one of those
	 * means the install is healthy. The repair step printed the second reading
	 * either way.
	 *
	 * @return void
	 */
	public function testRefusedRowsAreCountedAndBreakSuccess(): void {
		$objects = new RefusingKccSeedObjectService();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'kcc_quick_action_schema' => 'kccQuickAction',
					'belplan_schema' => 'belplan',
					default => '',
				};
			},
		);

		$result = (new KccWerkplekSeedDataService($settings, $this->createMock(LoggerInterface::class)))->seed();

		self::assertSame(false, $result['success'], 'a seed that seeded nothing must not report success');
		self::assertSame(7, $result['failed'], 'every refused row must be counted');
		self::assertSame(0, $result['quickActions']);
		self::assertSame(0, $result['belplannen']);
		self::assertStringContainsString('refused', (string)$result['message']);
	}//end testRefusedRowsAreCountedAndBreakSuccess()

	/**
	 * A healthy seed still reports no failures, so the counter cannot be noise.
	 *
	 * @return void
	 */
	public function testASuccessfulSeedReportsNoFailures(): void {
		$result = $this->service->seed();

		self::assertSame(true, $result['success']);
		self::assertSame(0, $result['failed']);
	}//end testASuccessfulSeedReportsNoFailures()

	/**
	 * @return void
	 */
	public function testQuickActionTypesAreValidEnumValues(): void {
		$this->service->seed();

		$allowed = [
			'status_geven',
			'new_case',
			'complaint_registreren',
			'doorverbinden',
			'bel_terug_inplannen',
			'email_sturen',
			'kopie_document_sturen',
		];
		foreach ($this->objects->store['kccQuickAction'] as $row) {
			self::assertContains($row['actionType'], $allowed);
			self::assertNotSame('', (string)$row['name']);
		}
	}

	/**
	 * @return void
	 */
	public function testAlgemeenBelplanHasKeuzemenuAndOverflow(): void {
		$this->service->seed();

		$belplan = $this->objects->store['belplan']['kcc-belplan-algemeen'];
		$types = array_map(static fn (array $step): string => (string)$step['type'], $belplan['routingSteps']);
		self::assertContains('keuzemenu', $types);
		self::assertContains('vaardigheid_match', $types);
		self::assertContains('wachtrij_overflow', $types);
		self::assertSame('voicemail', $belplan['fallbackAction']);
	}

	/**
	 * @return void
	 */
	public function testReturnsErrorWhenSchemasUnconfigured(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturn('');

		$service = new KccWerkplekSeedDataService($settings, $this->createMock(LoggerInterface::class));
		$result = $service->seed();

		self::assertSame(false, $result['success']);
	}
}

/**
 * In-memory ObjectService fake supporting only the calls the seed pipeline needs.
 */
class FakeKccSeedObjectService {
	/** @var array<string, array<string, array<string, mixed>>> */
	public array $store = [];

	/**
	 * @param string $register Register id.
	 * @param string $schema Schema id.
	 * @param array<string, mixed> $object Object.
	 * @return array<string, mixed>
	 */
	public function saveObject(string $register, string $schema, array $object): array {
		$id = (string)($object['id'] ?? ('row-' . count($this->store[$schema] ?? [])));
		$object['id'] = $id;
		$this->store[$schema][$id] = $object;
		return $object;
	}

	/**
	 * @param string $register Register id.
	 * @param string $schema Schema id.
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function findObjects(string $register, string $schema, array $filters = []): array {
		return array_values($this->store[$schema] ?? []);
	}

	/**
	 * Slug-aware search bridge mirroring ObjectService::searchObjectsBySlug().
	 *
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
		return $this->findObjects($registerSlug, $schemaSlug, $filters);
	}

	/**
	 * Numeric-ID search bridge mirroring ObjectService::searchObjects().
	 *
	 * @param array<string, mixed> $query Query carrying `@self` register/schema.
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjects(array $query = []): array {
		$schema = (string)(($query['@self'] ?? [])['schema'] ?? '');
		return $this->findObjects('', $schema);
	}
}

/**
 * An ObjectService that refuses every write, the way OpenRegister refuses Anonymous.
 *
 * The read side answers empty rather than throwing, which is the harder case:
 * the seed then believes nothing exists yet and attempts every row.
 */
class RefusingKccSeedObjectService {
	/**
	 * Refuse the write.
	 *
	 * @param array<string, mixed> $object Object.
	 * @param string $register Register id.
	 * @param string $schema Schema id.
	 *
	 * @return array<string, mixed> Never returns.
	 *
	 * @throws \RuntimeException Always.
	 */
	public function saveObject(array $object, string $register, string $schema): array {
		throw new \RuntimeException(
			sprintf("User 'Anonymous' does not have permission to 'create' objects in schema '%s'", $schema)
		);
	}//end saveObject()

	/**
	 * Slug-aware search bridge, answering empty.
	 *
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 * @param array<string, mixed> $filters Filters.
	 *
	 * @return array<int, array<string, mixed>> Always empty.
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
		return [];
	}//end searchObjectsBySlug()

	/**
	 * Numeric-ID search bridge, answering empty.
	 *
	 * @param array<string, mixed> $query Query.
	 *
	 * @return array<int, array<string, mixed>> Always empty.
	 */
	public function searchObjects(array $query = []): array {
		return [];
	}//end searchObjects()
}
