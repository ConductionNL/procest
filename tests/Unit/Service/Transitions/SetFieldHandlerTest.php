<?php

/**
 * SetFieldHandler Unit Tests
 *
 * Verifies the setField action handler updates a named case field via OR,
 * resolves the `__now__` macro to an ISO-8601 timestamp, and surfaces
 * missing-field/storage/exception envelopes.
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

use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\SetFieldHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\SetFieldHandler
 *
 * @uses \OCA\Dossiq\Service\CaseFieldWriter
 *
 *
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class SetFieldHandlerTest extends TestCase {
	/**
	 * @return void
	 */
	public function testFailsWhenFieldMissing(): void {
		$handler = new SetFieldHandler(
			settingsService: $this->createMock(SettingsService::class),
			caseWriter: new CaseFieldWriter(),
			logger: new NullLogger(),
		);

		$result = $handler->handle(
			actionConfig: ['type' => 'setField', 'value' => 'x'],
			case: ['id' => 'c'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('set_field_missing_field', $result->error);
	}//end testFailsWhenFieldMissing()

	/**
	 * @return void
	 */
	public function testFailsWhenObjectServiceUnavailable(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$handler = new SetFieldHandler($settings, new CaseFieldWriter(), new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'setField', 'field' => 'endDate'],
			case: ['id' => 'c'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('storage_unavailable', $result->error);
	}//end testFailsWhenObjectServiceUnavailable()

	/**
	 * @return void
	 */
	public function testWritesFieldOnCase(): void {
		$recorded = null;
		$objectService = new class($recorded) {
			/** @var mixed */
			public $recorded;

			public function __construct(&$recorded) {
				$this->recorded = &$recorded;
			}

			public function saveObject(array $object, string $register, string $schema): array {
				$this->recorded = $object;
				return $object;
			}

			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): array {
				$this->recorded = array_merge((array) $this->recorded, $data);
				return $this->recorded;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				return [
					'register' => 'reg-1',
					'case_schema' => 'case-schema',
				][$key] ?? '';
			}
		);

		$handler = new SetFieldHandler($settings, new CaseFieldWriter(), new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'setField', 'field' => 'result', 'value' => 'toegewezen'],
			case: ['id' => 'case-1', 'result' => null],
			transitionContext: [],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('result', $result->data['field']);
		self::assertSame('toegewezen', $recorded['result']);
	}//end testWritesFieldOnCase()

	/**
	 * @return void
	 */
	public function testResolvesNowMacro(): void {
		$recorded = null;
		$objectService = new class($recorded) {
			/** @var mixed */
			public $recorded;

			public function __construct(&$recorded) {
				$this->recorded = &$recorded;
			}

			public function saveObject(array $object, string $register, string $schema): array {
				$this->recorded = $object;
				return $object;
			}

			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): array {
				$this->recorded = array_merge((array) $this->recorded, $data);
				return $this->recorded;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				return [
					'register' => 'reg-1',
					'case_schema' => 'case-schema',
				][$key] ?? '';
			}
		);

		$handler = new SetFieldHandler($settings, new CaseFieldWriter(), new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'setField', 'field' => 'endDate', 'value' => '__now__'],
			case: ['id' => 'case-1'],
			transitionContext: [],
		);

		self::assertTrue($result->succeeded);
		// ISO-8601 ATOM format: YYYY-MM-DDTHH:MM:SS+ZZ:ZZ.
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
			(string)$recorded['endDate'],
		);
	}//end testResolvesNowMacro()

	/**
	 * @return void
	 */
	public function testCatchesExceptionFromObjectService(): void {
		$objectService = new class {
			public function saveObject(array $object, string $register, string $schema): array {
				throw new RuntimeException('boom');
			}

			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null): array {
				throw new RuntimeException('boom');
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			function (string $key): string {
				return [
					'register' => 'reg-1',
					'case_schema' => 'case-schema',
				][$key] ?? '';
			}
		);

		$handler = new SetFieldHandler($settings, new CaseFieldWriter(), new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'setField', 'field' => 'x', 'value' => 'y'],
			case: ['id' => 'c'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('set_field_failed', $result->error);
	}//end testCatchesExceptionFromObjectService()
}//end class
