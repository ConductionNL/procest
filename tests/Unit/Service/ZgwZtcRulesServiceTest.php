<?php

/**
 * ZgwZtcRulesService Unit Tests — publish + deletion validation paths.
 *
 * Focused on the case-types-02-backend-validation tasks: the test fixture
 * builds the rules service against mocked SettingsService + FieldValidator
 * dependencies and stubs the OpenRegister `ObjectService::searchObjectsBySlug`
 * surface so each scenario can deterministically wire status / case rows.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-types-02-backend-validation/tasks.md#task-ct-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\FieldValidator;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwZtcRulesService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\ZgwZtcRulesService
 *
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\DeadlineMonitoringSeedDataService
 * @uses \OCA\Dossiq\Service\ZgwRulesBase
 */
class ZgwZtcRulesServiceTest extends TestCase {
	private ZgwZtcRulesService $svc;
	private SettingsService $settings;

	protected function setUp(): void {
		parent::setUp();
		$logger = $this->createMock(LoggerInterface::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			function (string $key, string $default = ''): string {
				return match ($key) {
					'status_type_schema' => 'statusType',
					'case_type_schema' => 'caseType',
					'case_schema' => 'case',
					default => $default,
				};
			}
		);
		$this->svc = new ZgwZtcRulesService(
			$logger,
			$this->settings,
			new FieldValidator(),
		);
	}

	/**
	 * Build an OR-stub that resolves a deterministic searchObjectsBySlug map.
	 *
	 * @param array<string, array<int, array<string,mixed>>> $byKey Map keyed by "{register}|{schema}|{filter_json}".
	 *
	 * @return object
	 */
	private function stubObjectService(array $byKey): object {
		return new class($byKey) {
			public function __construct(
				private array $map,
			) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				// Real OpenRegister contract: a top-level `id`/`uuid` filter
				// addresses a schema property no schema declares and matches
				// ZERO rows. Only declared-property filters resolve.
				if (isset($filters['id']) === true || isset($filters['uuid']) === true) {
					return [];
				}

				// Key on (register|schema|caseType filter) — the production filter shape.
				$caseType = (string)($filters['caseType'] ?? '');
				$isFinal = isset($filters['isFinal']) === true ? '1' : '';
				$key = $register . '|' . $schema . '|' . $caseType . '|' . $isFinal . '|';
				return $this->map[$key] ?? [];
			}

			public function searchObjects(array $query): array {
				return [];
			}

			/**
			 * The real get-by-id path: resolves the id directly, throws on a
			 * miss, returns an entity-shaped object like the live service.
			 */
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
			): object {
				foreach ($this->map as $rows) {
					foreach ($rows as $row) {
						if (($row['id'] ?? null) === (string)$id) {
							return new class ($row) implements \JsonSerializable {
								public function __construct(private readonly array $row) {
								}

								public function jsonSerialize(): array {
									return $this->row;
								}
							};
						}
					}
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('Object ' . $id . ' does not exist');
			}
		};
	}

	public function testValidatePublishFailsWithNoStatusTypes(): void {
		$os = $this->stubObjectService([]);
		$this->svc->setContext($os, []);
		$errors = $this->svc->validatePublish('reg', 'ct-1');
		$this->assertContains('At least one status type must be defined before publishing', $errors);
	}

	public function testValidatePublishFailsWithNoFinalStatus(): void {
		$os = $this->stubObjectService([
			'reg|statusType|ct-1||' => [['id' => 's1', 'isFinal' => false]],
		]);
		$this->svc->setContext($os, []);
		$errors = $this->svc->validatePublish('reg', 'ct-1');
		$this->assertContains('At least one status type must be marked as final', $errors);
		$this->assertNotContains('At least one status type must be defined before publishing', $errors);
	}

	public function testValidatePublishFailsWithMissingValidFrom(): void {
		$os = $this->stubObjectService([
			'reg|statusType|ct-1||' => [['id' => 's1', 'isFinal' => true]],
			'reg|caseType|||ct-1' => [['id' => 'ct-1', 'validFrom' => '']],
		]);
		$this->svc->setContext($os, []);
		$errors = $this->svc->validatePublish('reg', 'ct-1');
		$this->assertContains("'Valid from' date must be set before publishing", $errors);
	}

	public function testValidatePublishSucceedsWithAllPrerequisites(): void {
		$os = $this->stubObjectService([
			'reg|statusType|ct-1||' => [
				['id' => 's1', 'isFinal' => false],
				['id' => 's2', 'isFinal' => true],
			],
			'reg|caseType|||ct-1' => [['id' => 'ct-1', 'validFrom' => '2026-01-01']],
		]);
		$this->svc->setContext($os, []);
		$errors = $this->svc->validatePublish('reg', 'ct-1');
		$this->assertSame([], $errors);
	}

	public function testValidatePublishFailsWithoutObjectService(): void {
		$this->svc->setContext(null, []);
		$errors = $this->svc->validatePublish('reg', 'ct-1');
		$this->assertContains('Cannot validate publish: OpenRegister object service unavailable', $errors);
	}

	public function testValidatePublishFailsWithEmptyCaseTypeId(): void {
		$os = $this->stubObjectService([]);
		$this->svc->setContext($os, []);
		$errors = $this->svc->validatePublish('reg', '');
		$this->assertContains('Cannot validate publish: case type id is empty', $errors);
	}

	public function testValidateDeletionAllowedWhenNoCasesExist(): void {
		$os = $this->stubObjectService([]);
		$this->svc->setContext($os, []);
		$r = $this->svc->validateDeletion('reg', 'ct-1');
		$this->assertFalse($r['blocked']);
		$this->assertFalse($r['requiresConfirmation']);
		$this->assertSame('', $r['message']);
	}

	public function testValidateDeletionBlockedWhenActiveCasesExist(): void {
		// Two cases exist; the schemaSettings's case_schema key resolves to 'case'.
		// No final status types, so every case is treated as active.
		$os = $this->stubObjectService([
			'reg|case|ct-1||' => [
				['id' => 'c1', 'status' => 'open'],
				['id' => 'c2', 'status' => 'in_progress'],
			],
		]);
		$this->svc->setContext($os, []);
		$r = $this->svc->validateDeletion('reg', 'ct-1');
		$this->assertTrue($r['blocked']);
		$this->assertFalse($r['requiresConfirmation']);
		$this->assertStringContainsString('2 active case(s)', $r['message']);
	}

	public function testValidateDeletionRequiresConfirmationWhenOnlyClosedCases(): void {
		// 1 closed case (status === 'final'), no active.
		// Final status types list contains 'final'.
		$os = $this->stubObjectService([
			'reg|case|ct-1||' => [['id' => 'c1', 'status' => 'final']],
			'reg|statusType|ct-1|1|' => [['id' => 'final', 'isFinal' => true]],
		]);
		$this->svc->setContext($os, []);
		$r = $this->svc->validateDeletion('reg', 'ct-1');
		$this->assertFalse($r['blocked']);
		$this->assertTrue($r['requiresConfirmation']);
		$this->assertStringContainsString('1 closed case(s)', $r['message']);
	}

	public function testValidateDeletionReturnsDefaultWithoutObjectService(): void {
		$this->svc->setContext(null, []);
		$r = $this->svc->validateDeletion('reg', 'ct-1');
		$this->assertFalse($r['blocked']);
		$this->assertFalse($r['requiresConfirmation']);
	}
}
