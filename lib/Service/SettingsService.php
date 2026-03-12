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
        'catalogus_schema',
        'case_schema',
        'task_schema',
        'status_schema',
        'status_record_schema',
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
        'zaaktype_informatieobjecttype_schema',
        'case_property_schema',
        'case_document_schema',
        'case_object_schema',
        'customer_contact_schema',
        'decision_document_schema',
        'dispatch_schema',
        'document_schema',
        'document_link_schema',
        'usage_rights_schema',
        'kanaal_schema',
        'abonnement_schema',
        'default_case_type',
    ];

    /**
     * Mapping of schema slugs (from procest_register.json) to app config keys.
     */
    private const SLUG_TO_CONFIG_KEY = [
        'catalogus'                    => 'catalogus_schema',
        'case'                         => 'case_schema',
        'task'                         => 'task_schema',
        'status'                       => 'status_schema',
        'statusRecord'                 => 'status_record_schema',
        'role'                         => 'role_schema',
        'result'                       => 'result_schema',
        'decision'                     => 'decision_schema',
        'caseType'                     => 'case_type_schema',
        'statusType'                   => 'status_type_schema',
        'resultType'                   => 'result_type_schema',
        'roleType'                     => 'role_type_schema',
        'propertyDefinition'           => 'property_definition_schema',
        'documentType'                 => 'document_type_schema',
        'decisionType'                 => 'decision_type_schema',
        'zaaktypeInformatieobjecttype' => 'zaaktype_informatieobjecttype_schema',
        'caseProperty'                 => 'case_property_schema',
        'caseDocument'                 => 'case_document_schema',
        'caseObject'                   => 'case_object_schema',
        'customerContact'              => 'customer_contact_schema',
        'decisionDocument'             => 'decision_document_schema',
        'dispatch'                     => 'dispatch_schema',
        'document'                     => 'document_schema',
        'documentLink'                 => 'document_link_schema',
        'usageRights'                  => 'usage_rights_schema',
        'kanaal'                       => 'kanaal_schema',
        'abonnement'                   => 'abonnement_schema',
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
    }

    /**
     * Check if OpenRegister is installed and enabled.
     *
     * @return bool
     */
    public function isOpenRegisterAvailable(): bool
    {
        return $this->appManager->isEnabledForUser(self::OPENREGISTER_APP_ID);
    }

    /**
     * Load the register configuration from procest_register.json via ConfigurationService.
     *
     * @param bool $force Whether to force re-import regardless of version
     *
     * @return array Import result
     */
    public function loadConfiguration(bool $force = false): array
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
                'message' => 'Could not access ConfigurationService: ' . $e->getMessage(),
            ];
        }

        $configPath = __DIR__ . '/../Settings/procest_register.json';
        if (file_exists($configPath) === false) {
            $this->logger->error(
                'Procest: Configuration file not found at ' . $configPath
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
                ['version' => $configVersion]
            );

            // Auto-configure schema IDs from import result.
            $configuredCount = $this->autoConfigureAfterImport(importResult: $importResult);

            return [
                'success'    => true,
                'message'    => 'Configuration imported and auto-configured (' . $configuredCount . ' schemas mapped)',
                'version'    => $configVersion,
                'configured' => $configuredCount,
                'result'     => $importResult,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'Procest: Configuration import failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
            ];
        }
    }

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
    }

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
    }

    /**
     * Get a single configuration value by key.
     *
     * @param string $key     The configuration key
     * @param string $default The default value if key not found
     *
     * @return string
     */
    public function getConfigValue(string $key, string $default = ''): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
    }

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
    }

    /**
     * Auto-configure schema and register IDs from the import result.
     *
     * Extracts schema entities from the ConfigurationService import result,
     * maps their slugs to app config keys, and persists the IDs.
     *
     * @param array $importResult The result from ConfigurationService::importFromApp()
     *
     * @return int The number of schemas successfully configured
     */
    private function autoConfigureAfterImport(array $importResult): int
    {
        $configuredCount = 0;

        // Configure register ID from imported registers.
        $registers = ($importResult['registers'] ?? []);
        foreach ($registers as $register) {
            if (is_object($register) === false) {
                continue;
            }

            $registerId = (string) $register->getId();
            $this->appConfig->setValueString(
                Application::APP_ID,
                'register',
                $registerId
            );
            $this->logger->info(
                'Procest: Auto-configured register ID',
                ['registerId' => $registerId]
            );
            break;
        }

        // Configure schema IDs from imported schemas.
        $schemas = ($importResult['schemas'] ?? []);
        foreach ($schemas as $schema) {
            if (is_object($schema) === false) {
                continue;
            }

            $slug = $schema->getSlug();
            if (isset(self::SLUG_TO_CONFIG_KEY[$slug]) === false) {
                continue;
            }

            $configKey = self::SLUG_TO_CONFIG_KEY[$slug];
            $schemaId  = (string) $schema->getId();

            $this->appConfig->setValueString(
                Application::APP_ID,
                $configKey,
                $schemaId
            );

            $this->logger->debug(
                'Procest: Auto-configured schema',
                [
                    'slug'      => $slug,
                    'configKey' => $configKey,
                    'schemaId'  => $schemaId,
                ]
            );

            $configuredCount++;
        }

        $this->logger->info(
            'Procest: Auto-configuration complete',
            ['configuredSchemas' => $configuredCount]
        );

        return $configuredCount;
    }
}
