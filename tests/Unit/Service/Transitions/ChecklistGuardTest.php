<?php

/**
 * ChecklistGuard Unit Tests
 *
 * Verifies the guard rejects when the referenced task has unchecked items,
 * honours the requiredItems whitelist, and degrades gracefully when the
 * task store is unreachable.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-19
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\ChecklistGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\ChecklistGuard
 *
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 */
class ChecklistGuardTest extends TestCase {
	/**
	 * A guard naming no task reads every task on the case.
	 *
	 * A workflow TEMPLATE cannot know a runtime task uuid, so every shipped
	 * checklist guard names none. Refusing those outright made them permanent
	 * blockers that looked like unfinished work on the case.
	 *
	 * @return void
	 */
	public function testWithoutTaskIdReadsEveryTaskOnTheCase(): void {
		$objectService = new class {
			/**
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return [
					['checklist' => [['label' => 'Stuk 1', 'checked' => true]]],
					['checklist' => [['label' => 'Stuk 2', 'checked' => false]]],
				];
			}
		};

		$guard = new ChecklistGuard($this->buildSettings($objectService), new NullLogger());
		$result = $guard->evaluate(guardConfig: [], case: ['id' => 'c'], userId: 'u');

		self::assertFalse($result->passed);
		self::assertSame(['Stuk 2'], $result->details['missing']);
	}//end testWithoutTaskIdReadsEveryTaskOnTheCase()

	/**
	 * A checklist stored the way the schema stores it is still read.
	 *
	 * The task schema holds `checklist` as a JSON-encoded string. Reading it as
	 * an array yielded no items at all, so the guard passed on the one shape
	 * the store actually holds.
	 *
	 * @return void
	 */
	public function testDecodesAJsonEncodedChecklist(): void {
		$objectService = new class {
			/**
			 * @param string $id The task id.
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, string $register, string $schema): array {
				return ['checklist' => json_encode([['label' => 'Stuk 1', 'checked' => false]])];
			}
		};

		$guard = new ChecklistGuard($this->buildSettings($objectService), new NullLogger());
		$result = $guard->evaluate(guardConfig: ['taskId' => 't-1'], case: ['id' => 'c'], userId: 'u');

		self::assertFalse($result->passed);
		self::assertSame(['Stuk 1'], $result->details['missing']);
	}//end testDecodesAJsonEncodedChecklist()

	/**
	 * A required item the checklist does not carry counts as missing.
	 *
	 * The allow-list only reported items that were present AND unticked, so an
	 * item nobody had put on the checklist satisfied the guard.
	 *
	 * @return void
	 */
	public function testARequiredItemThatIsAbsentCountsAsMissing(): void {
		$objectService = new class {
			/**
			 * @param string $id The task id.
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, string $register, string $schema): array {
				return ['checklist' => [['label' => 'Iets anders', 'checked' => true]]];
			}
		};

		$guard = new ChecklistGuard($this->buildSettings($objectService), new NullLogger());
		$result = $guard->evaluate(
			guardConfig: ['taskId' => 't-1', 'requiredItems' => ['Rechtsmiddelenclausule opgenomen']],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
		self::assertSame(['Rechtsmiddelenclausule opgenomen'], $result->details['missing']);
	}//end testARequiredItemThatIsAbsentCountsAsMissing()

	/**
	 * A case-wide check with no case to read fails closed.
	 *
	 * @return void
	 */
	public function testFailsWhenTheCaseCannotBeIdentified(): void {
		$objectService = new class {
			/**
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return [];
			}
		};

		$guard = new ChecklistGuard($this->buildSettings($objectService), new NullLogger());
		$result = $guard->evaluate(guardConfig: [], case: [], userId: 'u');

		self::assertFalse($result->passed);
		self::assertSame('Zaak niet herkend voor checklistcontrole', $result->failureMessage);
	}//end testFailsWhenTheCaseCannotBeIdentified()

	/**
	 * @return void
	 */
	public function testFailsWhenStorageUnavailable(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$guard = new ChecklistGuard($settings, new NullLogger());
		$result = $guard->evaluate(
			guardConfig: ['taskId' => 't-1'],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
		self::assertSame('Opslag niet beschikbaar', $result->failureMessage);
	}//end testFailsWhenStorageUnavailable()

	/**
	 * @return void
	 */
	public function testFailsWhenRegisterOrSchemaMissing(): void {
		$objectService = new class {
			public function find(string $id, string $register, string $schema): array {
				return [];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturn('');

		$guard = new ChecklistGuard($settings, new NullLogger());
		$result = $guard->evaluate(
			guardConfig: ['taskId' => 't-1'],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
		self::assertSame('Taak-register niet geconfigureerd', $result->failureMessage);
	}//end testFailsWhenRegisterOrSchemaMissing()

	/**
	 * @return void
	 */
	public function testFailsWhenTaskLoadThrows(): void {
		$objectService = new class {
			public function find(string $id, string $register, string $schema): array {
				throw new RuntimeException('not found');
			}
		};

		$settings = $this->buildSettings($objectService);

		$guard = new ChecklistGuard($settings, new NullLogger());
		$result = $guard->evaluate(
			guardConfig: ['taskId' => 't-1'],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
		self::assertSame('Gekoppelde taak niet gevonden', $result->failureMessage);
	}//end testFailsWhenTaskLoadThrows()

	/**
	 * @return void
	 */
	public function testPassesWhenAllItemsChecked(): void {
		$objectService = new class {
			public function find(string $id, string $register, string $schema): array {
				return [
					'checklist' => [
						['label' => 'Stuk 1', 'checked' => true],
						['label' => 'Stuk 2', 'checked' => true],
					],
				];
			}
		};

		$settings = $this->buildSettings($objectService);

		$guard = new ChecklistGuard($settings, new NullLogger());
		$result = $guard->evaluate(
			guardConfig: ['taskId' => 't-1'],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertTrue($result->passed);
	}//end testPassesWhenAllItemsChecked()

	/**
	 * @return void
	 */
	public function testFailsAndListsMissingItems(): void {
		$objectService = new class {
			public function find(string $id, string $register, string $schema): array {
				return [
					'checklist' => [
						['label' => 'Stuk 1', 'checked' => true],
						['label' => 'Stuk 2', 'checked' => false],
						['label' => 'Stuk 3', 'checked' => false],
					],
				];
			}
		};

		$settings = $this->buildSettings($objectService);

		$guard = new ChecklistGuard($settings, new NullLogger());
		$result = $guard->evaluate(
			guardConfig: ['taskId' => 't-1'],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertFalse($result->passed);
		self::assertSame(['Stuk 2', 'Stuk 3'], $result->details['missing']);
	}//end testFailsAndListsMissingItems()

	/**
	 * @return void
	 */
	public function testHonoursRequiredItemsWhitelist(): void {
		// Only require Stuk 2; Stuk 1 unchecked must NOT trip the guard.
		$objectService = new class {
			public function find(string $id, string $register, string $schema): array {
				return [
					'checklist' => [
						['label' => 'Stuk 1', 'checked' => false],
						['label' => 'Stuk 2', 'checked' => true],
					],
				];
			}
		};

		$settings = $this->buildSettings($objectService);

		$guard = new ChecklistGuard($settings, new NullLogger());
		$result = $guard->evaluate(
			guardConfig: ['taskId' => 't-1', 'requiredItems' => ['Stuk 2']],
			case: ['id' => 'c'],
			userId: 'u',
		);

		self::assertTrue($result->passed);
	}//end testHonoursRequiredItemsWhitelist()

	/**
	 * Build a SettingsService mock returning a configured register+task_schema.
	 *
	 * @param object $objectService Object-service double
	 *
	 * @return SettingsService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function buildSettings(object $objectService): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				return [
					'register' => 'reg-1',
					'task_schema' => 'task-schema',
				][$key] ?? '';
			}
		);
		return $settings;
	}//end buildSettings()
}//end class
