<?php

/**
 * Procest Settings Service
 *
 * Service for managing Procest application configuration and settings.
 *
 * @category Service
 * @package  OCA\Procest\Service
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

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IAppConfig;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Procest application configuration and settings.
 */
class SettingsService
{
    private const CONFIG_KEYS = [
        'register',
        'case_schema',
        'task_schema',
        'status_schema',
        'role_schema',
        'result_schema',
        'decision_schema',
        'case_type_schema',
        'status_type_schema',
        'result_type_schema',
        'role_type_schema',
        'property_definition_schema',
        'document_type_schema',
        'decision_type_schema',
        'default_case_type',
    ];

    private const OPENREGISTER_APP_ID = 'openregister';

    /**
     * Constructor for the SettingsService.
     *
     * @param IAppConfig         $appConfig  The app configuration service
     * @param IAppManager        $appManager The app manager service
     * @param ContainerInterface $container  The DI container
     * @param LoggerInterface    $logger     The logger interface
     *
     * @return void
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check if OpenRegister is installed and enabled.
     *
     * @return bool
     */
    public function isOpenRegisterAvailable(): bool
    {
        return $this->appManager->isEnabledForUser(self::OPENREGISTER_APP_ID);
    }//end isOpenRegisterAvailable()

    /**
     * Load the register configuration from procest_register.json via ConfigurationService.
     *
     * @param bool $force Whether to force re-import regardless of version
     *
     * @return array Import result
     */
    public function loadConfiguration(bool $force=false): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled',
            ];
        }

        try {
            $configurationService = $this->container->get(
                'OCA\OpenRegister\Service\ConfigurationService'
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Procest: Could not access ConfigurationService',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'Could not access ConfigurationService: '.$e->getMessage(),
            ];
        }

        $configPath = __DIR__.'/../Settings/procest_register.json';
        if (file_exists($configPath) === false) {
            $this->logger->error(
                'Procest: Configuration file not found at '.$configPath
            );
            return [
                'success' => false,
                'message' => 'Configuration file not found',
            ];
        }

        $configContent = file_get_contents($configPath);
        $configData    = json_decode($configContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Procest: Invalid JSON in configuration file');
            return [
                'success' => false,
                'message' => 'Invalid JSON in configuration file',
            ];
        }

        $configVersion = ($configData['info']['version'] ?? '0.0.0');

        try {
            $importResult = $configurationService->importFromApp(
                appId: Application::APP_ID,
                data: $configData,
                version: $configVersion,
                force: $force,
            );

            $this->logger->info(
                    'Procest: Configuration imported successfully',
                    [
                        'version' => $configVersion,
                    ]
                    );

            return [
                'success' => true,
                'message' => 'Configuration imported successfully',
                'version' => $configVersion,
                'result'  => $importResult,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'Procest: Configuration import failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'Import failed: '.$e->getMessage(),
            ];
        }//end try
    }//end loadConfiguration()

    /**
     * Get all current settings as an associative array.
     *
     * @return array
     */
    public function getSettings(): array
    {
        $config = [];
        foreach (self::CONFIG_KEYS as $key) {
            $config[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
        }

        return $config;
    }//end getSettings()

    /**
     * Update settings with the provided data.
     *
     * @param array $data The settings data to update
     *
     * @return array
     */
    public function updateSettings(array $data): array
    {
        foreach (self::CONFIG_KEYS as $key) {
            if (isset($data[$key]) === true) {
                $this->appConfig->setValueString(Application::APP_ID, $key, (string) $data[$key]);
            }
        }

        $this->logger->info('Procest settings updated', ['keys' => array_keys($data)]);

        return $this->getSettings();
    }//end updateSettings()

    /**
     * Get a single configuration value by key.
     *
     * @param string $key     The configuration key
     * @param string $default The default value if key not found
     *
     * @return string
     */
    public function getConfigValue(string $key, string $default=''): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
    }//end getConfigValue()

    /**
     * Set a single configuration value.
     *
     * @param string $key   The configuration key
     * @param string $value The value to set
     *
     * @return void
     */
    public function setConfigValue(string $key, string $value): void
    {
        $this->appConfig->setValueString(Application::APP_ID, $key, $value);
    }//end setConfigValue()
}//end class
