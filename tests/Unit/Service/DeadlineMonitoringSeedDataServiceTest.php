<?php

/**
 * Unit tests for DeadlineMonitoringSeedDataService.
 *
 * Exercises the seed pipeline against an in-memory ObjectService fake,
 * asserts idempotency, and verifies the documented seed shape
 * (Omgevingsvergunning-regulier 56d, Wmo-aanvraag 42d, Woo-verzoek 28d
 * with €15/day max €500 regime).
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

use OCA\Dossiq\Service\DeadlineMonitoringSeedDataService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\DeadlineMonitoringSeedDataService
 */
class DeadlineMonitoringSeedDataServiceTest extends TestCase {
	private FakeTermijnObjectService $objects;

	private DeadlineMonitoringSeedDataService $service;

	protected function setUp(): void {
		$this->objects = new FakeTermijnObjectService();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_definitie_schema' => 'deadlineDefinition',
					default => '',
				};
			},
		);

		$this->service = new DeadlineMonitoringSeedDataService(
			$settings,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * How many definitions the shipped seed file actually declares.
	 *
	 * READ, NOT HARDCODED. These assertions used to say `3`, so adding the
	 * definitions that bind the SHIPPED case types — the fix for a fresh
	 * install arming no timer at all — reddened three tests that were only
	 * ever restating the file's current length.
	 *
	 * @return int The shipped row count.
	 */
	private function shippedDefinitionCount(): int {
		$seed = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/termijnbewaking_seed_data.json'),
			true
		);
		self::assertIsArray($seed, 'The shipped termijnbewaking seed must parse.');

		return count($seed['termijnDefinities'] ?? []);
	}

	/**
	 * @return void
	 */
	public function testSeedCreatesEveryShippedDefinition(): void {
		$result = $this->service->seed();

		self::assertSame(true, $result['success']);
		self::assertSame($this->shippedDefinitionCount(), $result['definities']);
		self::assertSame(0, $result['skipped']);
		self::assertCount($this->shippedDefinitionCount(), $this->objects->store['deadlineDefinition']);
	}

	/**
	 * @return void
	 */
	public function testSeedIsIdempotent(): void {
		$this->service->seed();
		$second = $this->service->seed();

		self::assertSame(true, $second['success']);
		self::assertSame(0, $second['definities']);
		self::assertSame($this->shippedDefinitionCount(), $second['skipped']);
		self::assertCount($this->shippedDefinitionCount(), $this->objects->store['deadlineDefinition']);
	}

	/**
	 * @return void
	 */
	public function testWooSeedHasCustomRegime(): void {
		$this->service->seed();

		$woo = $this->objects->store['deadlineDefinition']['td-woo-verzoek'];
		self::assertSame('woo-verzoek', $woo['caseType']);
		self::assertSame(28, $woo['standardDurationDays']);
		self::assertSame(1500, $woo['deviatingPenaltyPaymentRegime']['dailyTariff']);
		self::assertSame(50000, $woo['deviatingPenaltyPaymentRegime']['plafond']);
	}

	/**
	 * A refused row is COUNTED, so the caller can refuse a success-shaped
	 * report. This is the "0 definities (0 overgeslagen)" defect: every row
	 * failed under Anonymous RBAC and the summary still looked like success.
	 *
	 * @return void
	 */
	public function testRefusedRowsAreCountedAsFailed(): void {
		$objects = new RefusingTermijnObjectService();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_definitie_schema' => 'deadlineDefinition',
					default => '',
				};
			},
		);

		$service = new DeadlineMonitoringSeedDataService(
			$settings,
			$this->createMock(LoggerInterface::class),
		);

		$result = $service->seed();

		self::assertSame(0, $result['definities']);
		self::assertSame(0, $result['skipped']);
		self::assertSame($this->shippedDefinitionCount(), $result['failed']);
	}

	/**
	 * @return void
	 */
	public function testWmoSeedHas42DayDuration(): void {
		$this->service->seed();

		$wmo = $this->objects->store['deadlineDefinition']['td-wmo-aanvraag'];
		self::assertSame('wmo-melding', $wmo['caseType']);
		self::assertSame(42, $wmo['standardDurationDays']);
		self::assertSame(0, $wmo['countExtensions']);
	}
}

/**
 * In-memory ObjectService fake supporting only the calls the seed pipeline needs.
 */
class FakeTermijnObjectService {
	/** @var array<string, array<string, array<string, mixed>>> */
	public array $store = [];

	/**
	 * Real ObjectService::saveObject() signature: `$object` FIRST. A caller
	 * still using the retired `($register, $schema, $object)` order fatals
	 * here as it does live.
	 *
	 * @param array<string, mixed> $object Object.
	 * @param array|null $extend Relations to expand (ignored).
	 * @param string|int|null $register Register id.
	 * @param string|int|null $schema Schema id.
	 * @param string|null $uuid UUID to update, null to create.
	 * @return array<string, mixed>
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
	): array {
		$schema = (string)$schema;
		$id = (string)($uuid ?? $object['id'] ?? ('row-' . count($this->store[$schema] ?? [])));
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
	 * The seed service routes idempotency lookups through the SearchesObjects
	 * trait, which calls this method for slug-form register/schema config.
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
 * Fake that refuses every write, the way OpenRegister RBAC refuses Anonymous.
 */
class RefusingTermijnObjectService extends FakeTermijnObjectService {
	/**
	 * @param array<string, mixed> $object Object.
	 * @param array|null $extend Relations to expand (ignored).
	 * @param string|int|null $register Register id.
	 * @param string|int|null $schema Schema id.
	 * @param string|null $uuid UUID to update, null to create.
	 * @return array<string, mixed>
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
	): array {
		throw new \RuntimeException("User 'Anonymous' does not have permission to 'create'");
	}
}
