<?php

/**
 * CreateTaskHandler Unit Tests
 *
 * Verifies the createTask action handler envelope under storage-unavailable,
 * missing-config, success, and exception-swallow paths.
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
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-20
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\CreateTaskHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\CreateTaskHandler
 *
 *
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class CreateTaskHandlerTest extends TestCase {
	/**
	 * @return void
	 */
	public function testFailsWhenObjectServiceUnavailable(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$handler = new CreateTaskHandler($settings, new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'createTask', 'title' => 'Doe X'],
			case: ['id' => 'case-1'],
			transitionContext: ['transitionLabel' => 'Approve'],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('storage_unavailable', $result->error);
	}//end testFailsWhenObjectServiceUnavailable()

	/**
	 * @return void
	 */
	public function testFailsWhenTaskSchemaNotConfigured(): void {
		$objectService = new class {
			public function saveObject(array $object, string $register, string $schema): array {
				return ['id' => 'unreachable'];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				return $key === 'register' ? 'reg-1' : '';
			}
		);

		$handler = new CreateTaskHandler($settings, new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'createTask'],
			case: ['id' => 'c'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('task_schema_not_configured', $result->error);
	}//end testFailsWhenTaskSchemaNotConfigured()

	/**
	 * @return void
	 */
	public function testCreatesTaskWithCaseLinkAndAssigneeOnSuccess(): void {
		$recorded = null;

		$objectService = new class($recorded) {
			/** @var mixed */
			public $recorded;

			public function __construct(&$recorded) {
				$this->recorded = &$recorded;
			}

			public function saveObject(array $object, string $register, string $schema): array {
				$this->recorded = ['object' => $object, 'register' => $register, 'schema' => $schema];
				return ['id' => 'task-uuid'];
			}
		};

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

		$handler = new CreateTaskHandler($settings, new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'createTask', 'title' => 'Review docs', 'assignee' => 'alice'],
			case: ['id' => 'case-9'],
			transitionContext: ['transitionLabel' => 'In Review'],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('task-uuid', $result->data['taskId']);
		self::assertSame('Review docs', $recorded['object']['title']);
		self::assertSame('case-9', $recorded['object']['case']);
		self::assertSame('alice', $recorded['object']['assignee']);
		// The task schema declares enum available|active|completed|terminated|disabled
		// with initial state 'available'. A status outside that enum yields a task no
		// lifecycle transition can advance, so assert the declared initial state.
		self::assertSame('available', $recorded['object']['status']);
		self::assertContains(
			$recorded['object']['status'],
			['available', 'active', 'completed', 'terminated', 'disabled'],
			'CreateTaskHandler must write a status the task schema allows'
		);
		self::assertSame('reg-1', $recorded['register']);
		self::assertSame('task-schema', $recorded['schema']);
	}//end testCreatesTaskWithCaseLinkAndAssigneeOnSuccess()

	/**
	 * @return void
	 */
	public function testCatchesExceptionFromObjectService(): void {
		$objectService = new class {
			public function saveObject(array $object, string $register, string $schema): array {
				throw new RuntimeException('storage went away');
			}
		};

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

		$handler = new CreateTaskHandler($settings, new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'createTask'],
			case: ['id' => 'c'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('create_task_failed', $result->error);
	}//end testCatchesExceptionFromObjectService()
}//end class
