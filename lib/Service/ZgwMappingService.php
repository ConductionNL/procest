<?php

/**
 * Procest ZGW Mapping Service
 *
 * Service for managing ZGW API mapping configuration stored in IAppConfig.
 * Each mapping defines how English OpenRegister properties translate to/from
 * Dutch ZGW API properties using Twig templates.
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
use Psr\Log\LoggerInterface;

/**
 * Service for managing ZGW API mapping configuration.
 *
 * Stores mapping configuration as JSON in IAppConfig under keys like
 * `zgw_mapping_zaak`, `zgw_mapping_zaaktype`, etc.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class ZgwMappingService
{

    /**
     * Prefix for ZGW mapping config keys in IAppConfig.
     */
    private const CONFIG_PREFIX = 'zgw_mapping_';

    /**
     * All known ZGW resource keys.
     *
     * @var string[]
     */
    private const RESOURCE_KEYS = [
        'zaak',
        'zaaktype',
        'status',
        'statustype',
        'resultaat',
        'resultaattype',
        'rol',
        'roltype',
        'eigenschap',
        'besluit',
        'besluittype',
        'informatieobjecttype',
    ];

    /**
     * Constructor for the ZgwMappingService.
     *
     * @param IAppConfig      $appConfig The app configuration service
     * @param LoggerInterface $logger    The logger interface
     *
     * @return void
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the mapping configuration for a specific ZGW resource.
     *
     * @param string $resourceKey The ZGW resource key (e.g., 'zaak', 'zaaktype')
     *
     * @return array|null The mapping configuration or null if not found
     */
    public function getMapping(string $resourceKey): ?array
    {
        $json = $this->appConfig->getValueString(
            Application::APP_ID,
            self::CONFIG_PREFIX.$resourceKey,
            ''
        );

        if ($json === '') {
            return null;
        }

        $config = json_decode($json, true);
        if ($config === null || is_array($config) === false) {
            return null;
        }

        return $config;
    }//end getMapping()

    /**
     * Save the mapping configuration for a specific ZGW resource.
     *
     * @param string $resourceKey The ZGW resource key (e.g., 'zaak', 'zaaktype')
     * @param array  $config      The mapping configuration
     *
     * @return void
     */
    public function saveMapping(string $resourceKey, array $config): void
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->appConfig->setValueString(
            Application::APP_ID,
            self::CONFIG_PREFIX.$resourceKey,
            $json
        );

        $this->logger->info(
            'ZGW mapping saved',
            ['resourceKey' => $resourceKey]
        );
    }//end saveMapping()

    /**
     * List all ZGW mapping configurations.
     *
     * Returns an associative array keyed by resource key. Resources without
     * a saved configuration will have null values.
     *
     * @return array<string, array|null>
     */
    public function listMappings(): array
    {
        $mappings = [];

        foreach (self::RESOURCE_KEYS as $key) {
            $mappings[$key] = $this->getMapping($key);
        }

        return $mappings;
    }//end listMappings()

    /**
     * Delete the mapping configuration for a specific ZGW resource.
     *
     * @param string $resourceKey The ZGW resource key (e.g., 'zaak', 'zaaktype')
     *
     * @return void
     */
    public function deleteMapping(string $resourceKey): void
    {
        $configKey = self::CONFIG_PREFIX.$resourceKey;
        $this->appConfig->deleteKey(app: Application::APP_ID, key: $configKey);

        $this->logger->info(
            'ZGW mapping deleted',
            ['resourceKey' => $resourceKey]
        );
    }//end deleteMapping()

    /**
     * Get all known ZGW resource keys.
     *
     * @return string[]
     */
    public function getResourceKeys(): array
    {
        return self::RESOURCE_KEYS;
    }//end getResourceKeys()

    /**
     * Check whether a mapping exists for a given resource.
     *
     * @param string $resourceKey The ZGW resource key
     *
     * @return bool
     */
    public function hasMapping(string $resourceKey): bool
    {
        return $this->getMapping($resourceKey) !== null;
    }//end hasMapping()

    /**
     * Reset a mapping to its default configuration.
     *
     * Loads the default from the defaults array and saves it.
     *
     * @param string $resourceKey The ZGW resource key
     * @param array  $defaults    The default mapping configurations
     *
     * @return void
     */
    public function resetToDefault(string $resourceKey, array $defaults): void
    {
        if (isset($defaults[$resourceKey]) === true) {
            $this->saveMapping(resourceKey: $resourceKey, config: $defaults[$resourceKey]);
            $this->logger->info(
                'ZGW mapping reset to default',
                ['resourceKey' => $resourceKey]
            );
        }
    }//end resetToDefault()
}//end class
