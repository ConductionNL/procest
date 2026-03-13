<?php

/**
 * SettingsService Unit Tests
 *
 * Tests for the Procest SettingsService configuration management.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SettingsService;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the SettingsService class.
 *
 * @covers \OCA\Procest\Service\SettingsService
 */
class SettingsServiceTest extends TestCase
{

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
    protected function setUp(): void
    {
        $this->appConfig  = $this->createMock(IAppConfig::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->container  = $this->createMock(ContainerInterface::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

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
    public function testIsOpenRegisterAvailableReturnsTrue(): void
    {
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
    public function testIsOpenRegisterAvailableReturnsFalse(): void
    {
        $this->appManager
            ->expects($this->once())
            ->method('isEnabledForUser')
            ->with('openregister')
            ->willReturn(false);

        $this->assertFalse($this->service->isOpenRegisterAvailable());

    }//end testIsOpenRegisterAvailableReturnsFalse()


    /**
     * Test that getSettings returns all config keys with their values.
     *
     * @return void
     */
    public function testGetSettingsReturnsAllConfigKeys(): void
    {
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
    public function testUpdateSettingsOnlyUpdatesRecognizedKeys(): void
    {
        $data = [
            'register'    => '99',
            'case_schema' => '200',
            'bogus_key'   => 'should-be-ignored',
        ];

        // Expect setValueString to be called exactly twice (register + case_schema).
        $this->appConfig
            ->expects($this->exactly(2))
            ->method('setValueString')
            ->willReturnCallback(
                function (string $app, string $key, string $value): bool {
                    $this->assertSame('procest', $app);
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
    public function testGetConfigValueDelegatesToAppConfig(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('getValueString')
            ->with('procest', 'register', 'fallback')
            ->willReturn('123');

        $result = $this->service->getConfigValue('register', 'fallback');

        $this->assertSame('123', $result);

    }//end testGetConfigValueDelegatesToAppConfig()


    /**
     * Test that setConfigValue delegates to appConfig correctly.
     *
     * @return void
     */
    public function testSetConfigValueDelegatesToAppConfig(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('setValueString')
            ->with('procest', 'task_schema', '555');

        $this->service->setConfigValue('task_schema', '555');

    }//end testSetConfigValueDelegatesToAppConfig()


    /**
     * Test that loadConfiguration fails when OpenRegister is not available.
     *
     * @return void
     */
    public function testLoadConfigurationFailsWithoutOpenRegister(): void
    {
        $this->appManager
            ->method('isEnabledForUser')
            ->willReturn(false);

        $result = $this->service->loadConfiguration();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not installed', $result['message']);

    }//end testLoadConfigurationFailsWithoutOpenRegister()


}//end class
