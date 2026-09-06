<?php

/**
 * SettingsService Unit Tests
 *
 * Tests for the Dossiq SettingsService configuration management.
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
 * Unit tests for the SettingsService class.
 *
 * @covers \OCA\Dossiq\Service\SettingsService
 *
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 * @uses \OCA\Dossiq\Service\Settings\SchemaAnnotationReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaKeyReconciler
 * @uses \OCA\Dossiq\Service\Settings\SchemaSlugResolver
 */
class SettingsServiceTest extends TestCase {

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
	 * Test that isOpenRegisterAvailable returns true when the app is enabled.
	 *
	 * @return void
	 */
	public function testIsOpenRegisterAvailableReturnsTrue(): void {
		$this->appManager
			->expects($this->once())
			->method('isEnabledForUser')
			->with('openregister')
			->willReturn(true);

		$this->assertTrue($this->service->isOpenRegisterAvailable());

	}//end testIsOpenRegisterAvailableReturnsTrue()

	/**
	 * Test that isOpenRegisterAvailable returns false when the app is disabled.
	 *
	 * @return void
	 */
	public function testIsOpenRegisterAvailableReturnsFalse(): void {
		$this->appManager
			->expects($this->once())
			->method('isEnabledForUser')
			->with('openregister')
			->willReturn(false);

		$this->assertFalse($this->service->isOpenRegisterAvailable());

	}//end testIsOpenRegisterAvailableReturnsFalse()

	/**
	 * getFileService() resolves OpenRegister's FileService by class name.
	 *
	 * ADR-084 publishes no file operation, so this container lookup is the
	 * only in-process route an app has for attaching bytes to an OpenRegister
	 * object — see
	 * openspec/changes/woo-publication-in-process-object-writes/proposal.md.
	 * The class name is asserted because it IS the contract here: there is no
	 * interface to type-hint against, so a typo would surface only at runtime.
	 *
	 * @return void
	 */
	public function testGetFileServiceResolvesOpenRegistersFileService(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$fileService = new \stdClass();
		$this->container
			->expects($this->once())
			->method('get')
			->with('OCA\OpenRegister\Service\FileService')
			->willReturn($fileService);

		$this->assertSame($fileService, $this->service->getFileService());

	}//end testGetFileServiceResolvesOpenRegistersFileService()

	/**
	 * getFileService() returns null — never throws — when OpenRegister is
	 * absent, so callers can degrade rather than break the case flow.
	 *
	 * @return void
	 */
	public function testGetFileServiceReturnsNullWithoutOpenRegister(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->appManager->method('isInstalled')->willReturn(false);

		$this->container->expects($this->never())->method('get');

		$this->assertNull($this->service->getFileService());

	}//end testGetFileServiceReturnsNullWithoutOpenRegister()

	/**
	 * A container that cannot resolve the class is logged and reported as
	 * null, not propagated. `\Throwable` is caught deliberately: a container
	 * miss on a class the app does not own can surface as an `Error`, which a
	 * `\Exception` catch would let through.
	 *
	 * @return void
	 */
	public function testGetFileServiceReturnsNullWhenTheContainerCannotResolveIt(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->container
			->method('get')
			->willThrowException(new \RuntimeException('not registered'));

		$this->logger->expects($this->once())->method('error');

		$this->assertNull($this->service->getFileService());

	}//end testGetFileServiceReturnsNullWhenTheContainerCannotResolveIt()

	/**
	 * Test that getSettings returns all config keys with their values.
	 *
	 * @return void
	 */
	public function testGetSettingsReturnsAllConfigKeys(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnCallback(
				function (string $app, string $key, string $default): string {
					if ($key === 'register') {
						return '42';
					}

					if ($key === 'case_schema') {
						return '101';
					}

					return '';
				}
			);

		$settings = $this->service->getSettings();

		$this->assertArrayHasKey('register', $settings);
		$this->assertArrayHasKey('case_schema', $settings);
		$this->assertArrayHasKey('task_schema', $settings);
		$this->assertArrayHasKey('status_schema', $settings);
		$this->assertArrayHasKey('role_schema', $settings);
		$this->assertArrayHasKey('result_schema', $settings);
		$this->assertArrayHasKey('decision_schema', $settings);
		$this->assertArrayHasKey('case_type_schema', $settings);
		$this->assertArrayHasKey('default_case_type', $settings);

		$this->assertSame('42', $settings['register']);
		$this->assertSame('101', $settings['case_schema']);
		$this->assertSame('', $settings['task_schema']);

	}//end testGetSettingsReturnsAllConfigKeys()

	/**
	 * Test that updateSettings persists only recognized config keys.
	 *
	 * @return void
	 */
	public function testUpdateSettingsOnlyUpdatesRecognizedKeys(): void {
		$data = [
			'register' => '99',
			'case_schema' => '200',
			'bogus_key' => 'should-be-ignored',
		];

		// Expect setValueString to be called exactly twice (register + case_schema).
		$this->appConfig
			->expects($this->exactly(2))
			->method('setValueString')
			->willReturnCallback(
				function (string $app, string $key, string $value): bool {
					$this->assertSame('dossiq', $app);
					$this->assertContains(
						$key,
						['register', 'case_schema']
					);
					return true;
				}
			);

		// getValueString is called by getSettings() at the end.
		$this->appConfig
			->method('getValueString')
			->willReturn('');

		$this->service->updateSettings($data);

	}//end testUpdateSettingsOnlyUpdatesRecognizedKeys()

	/**
	 * Test that getConfigValue delegates to appConfig correctly.
	 *
	 * @return void
	 */
	public function testGetConfigValueDelegatesToAppConfig(): void {
		$this->appConfig
			->expects($this->once())
			->method('getValueString')
			->with('dossiq', 'register', 'fallback')
			->willReturn('123');

		$result = $this->service->getConfigValue('register', 'fallback');

		$this->assertSame('123', $result);

	}//end testGetConfigValueDelegatesToAppConfig()

	/**
	 * Test that setConfigValue delegates to appConfig correctly.
	 *
	 * @return void
	 */
	public function testSetConfigValueDelegatesToAppConfig(): void {
		$this->appConfig
			->expects($this->once())
			->method('setValueString')
			->with('dossiq', 'task_schema', '555');

		$this->service->setConfigValue('task_schema', '555');

	}//end testSetConfigValueDelegatesToAppConfig()

	/**
	 * Test that loadConfiguration fails when OpenRegister is not available.
	 *
	 * @return void
	 */
	public function testLoadConfigurationFailsWithoutOpenRegister(): void {
		$this->appManager
			->method('isEnabledForUser')
			->willReturn(false);

		$result = $this->service->loadConfiguration();

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not installed', $result['message']);

	}//end testLoadConfigurationFailsWithoutOpenRegister()

	/**
	 * loadConfiguration() reads dossiq_register.json, deep-merges the ADR-037
	 * register.d fragments on top of it, and hands the RESULT to
	 * ConfigurationService::importFromApp() under the file's own version.
	 *
	 * Guards the read/parse/merge path: without this, that whole path could be
	 * broken (wrong file, unparsed JSON, fragments dropped) and the only
	 * existing loadConfiguration test — the OpenRegister-unavailable early
	 * return — would still pass, because it returns before any of it runs.
	 *
	 * @return void
	 */
	public function testLoadConfigurationImportsMergedConfigUnderItsOwnVersion(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->appManager->method('isInstalled')->willReturn(true);

		$configurationService = $this->createMock(DossiqConfigurationServiceStub::class);

		$captured = [];
		$configurationService->expects($this->once())
			->method('importFromApp')
			->willReturnCallback(
				function (string $appId, array $data, string $version, bool $force) use (&$captured): array {
					$captured = [
						'appId' => $appId,
						'data' => $data,
						'version' => $version,
						'force' => $force,
					];
					return ['registers' => [], 'schemas' => []];
				}
			);

		$this->container->method('get')->willReturnCallback(
			static function (string $class) use ($configurationService) {
				if ($class === 'OCA\OpenRegister\Service\ConfigurationService') {
					return $configurationService;
				}

				throw new \RuntimeException('not resolvable in this test: ' . $class);
			}
		);

		$result = $this->service->loadConfiguration();

		// The on-disk configuration is what must have been read and merged.
		$onDisk = json_decode(
			file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		$this->assertTrue($result['success']);
		$this->assertSame('dossiq', $captured['appId']);
		$this->assertFalse($captured['force']);
		$this->assertSame($onDisk['info']['version'], $captured['version']);
		$this->assertSame($onDisk['info']['version'], $result['version']);

		// Parsed, not handed over as a raw string, and carrying the file's own
		// content rather than an empty array.
		$this->assertIsArray($captured['data']);
		$this->assertArrayHasKey('info', $captured['data']);
		$this->assertNotEmpty($captured['data']);

		// The version must stay a bare version — never a `+frag.<hash>` build
		// suffix, which OpenRegister's version_compare gate compares
		// lexically (see #721).
		$this->assertStringNotContainsString('+', $captured['version']);
	}//end testLoadConfigurationImportsMergedConfigUnderItsOwnVersion()

}//end class

/**
 * Stub matching the named-arg signature of OpenRegister's ConfigurationService
 * as loadConfiguration() calls it.
 */
interface DossiqConfigurationServiceStub {

	/**
	 * Import a register configuration on behalf of an app.
	 *
	 * @param string $appId The importing app id.
	 * @param array $data The effective (merged) configuration.
	 * @param string $version The configuration version.
	 * @param bool $force Whether to re-import regardless of version.
	 *
	 * @return array
	 */
	public function importFromApp(string $appId, array $data, string $version, bool $force): array;
}//end interface
