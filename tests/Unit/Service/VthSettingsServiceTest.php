<?php

/**
 * VTH SettingsService Unit Tests
 *
 * Tests for the VTH-specific extensions to SettingsService:
 * VTH config keys (inspectie_checklist_schema, inspectie_rapport_schema,
 * handhavingsactie_schema, advies_aanvraag_schema, lhsMatrix).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for VTH-specific SettingsService configuration.
 *
 * @covers \OCA\Dossiq\Service\SettingsService
 *
 * @uses \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaKeyReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaSlugResolver
 */
class VthSettingsServiceTest extends TestCase {

	/**
	 * The mocked app configuration service.
	 *
	 * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The mocked app manager service.
	 *
	 * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IAppManager $appManager;

	/**
	 * The mocked DI container.
	 *
	 * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The mocked logger interface.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The service under test.
	 *
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new SettingsService(
			$this->appConfig,
			$this->appManager,
			$this->container,
			$this->logger,
		);

	}//end setUp()

	/**
	 * Test that getSettings includes VTH-specific keys.
	 *
	 * @return void
	 */
	public function testGetSettingsIncludesVthSchemaKeys(): void {
		$this->appConfig
			->method('getValueString')
			->willReturn('');

		$settings = $this->service->getSettings();

		$vthKeys = [
			'inspectie_checklist_schema',
			'inspectie_rapport_schema',
			'handhavingsactie_schema',
			'advies_aanvraag_schema',
			'lhsMatrix',
		];

		foreach ($vthKeys as $key) {
			$this->assertArrayHasKey(
				$key,
				$settings,
				"getSettings() must include VTH key '{$key}'"
			);
		}

	}//end testGetSettingsIncludesVthSchemaKeys()

	/**
	 * Test that updateSettings persists VTH schema keys.
	 *
	 * @return void
	 */
	public function testUpdateSettingsPersistsVthKeys(): void {
		$vthData = [
			'inspectie_checklist_schema' => 'schema-uuid-100',
			'inspectie_rapport_schema' => 'schema-uuid-101',
			'handhavingsactie_schema' => 'schema-uuid-102',
			'advies_aanvraag_schema' => 'schema-uuid-103',
			'lhsMatrix' => '[[3,2],[1,4]]',
		];

		$setCallArgs = [];
		$this->appConfig
			->method('setValueString')
			->willReturnCallback(
				function (string $app, string $key, string $value) use (&$setCallArgs): bool {
					$setCallArgs[] = $key;
					return true;
				}
			);

		$this->appConfig
			->method('getValueString')
			->willReturn('');

		$this->service->updateSettings($vthData);

		foreach (array_keys($vthData) as $key) {
			$this->assertContains(
				$key,
				$setCallArgs,
				"updateSettings() should persist VTH key '{$key}'"
			);
		}

	}//end testUpdateSettingsPersistsVthKeys()

	/**
	 * Test that the lhsMatrix key is readable and writable independently.
	 *
	 * @return void
	 */
	public function testLhsMatrixKeyIsReadableViaGetConfigValue(): void {
		$matrixJson = '[[3,2,1,0],[2,2,1,0],[1,1,1,0],[0,0,0,0]]';

		$this->appConfig
			->expects($this->once())
			->method('getValueString')
			->with('dossiq', 'lhsMatrix', '')
			->willReturn($matrixJson);

		$result = $this->service->getConfigValue('lhsMatrix', '');

		$this->assertSame($matrixJson, $result);

	}//end testLhsMatrixKeyIsReadableViaGetConfigValue()

	/**
	 * Test that VTH schema keys do not override core keys.
	 *
	 * The addition of VTH keys must not remove any previously existing
	 * core case management configuration keys.
	 *
	 * @return void
	 */
	public function testVthKeysDoNotOverrideCoreKeys(): void {
		$this->appConfig
			->method('getValueString')
			->willReturn('');

		$settings = $this->service->getSettings();

		$coreKeys = ['register', 'case_schema', 'task_schema', 'status_schema', 'role_schema'];
		foreach ($coreKeys as $key) {
			$this->assertArrayHasKey(
				$key,
				$settings,
				"Core key '{$key}' must still be present after VTH extension"
			);
		}

	}//end testVthKeysDoNotOverrideCoreKeys()

}//end class
