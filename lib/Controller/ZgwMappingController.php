<?php

/**
 * Procest ZGW Mapping Controller
 *
 * Controller for managing ZGW API mapping configurations via the admin UI.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Repair\LoadDefaultZgwMappings;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\ZgwMappingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing ZGW API mapping configurations.
 */
class ZgwMappingController extends Controller
{
    /**
     * Constructor for the ZgwMappingController.
     *
     * @param IRequest          $request           The request object
     * @param ZgwMappingService $zgwMappingService The ZGW mapping service
     * @param SettingsService   $settingsService   The settings service
     * @param LoggerInterface   $logger            The logger interface
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly ZgwMappingService $zgwMappingService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }

    /**
     * List all ZGW mapping configurations.
     *
     * @return JSONResponse
     */
    public function index(): JSONResponse
    {
        return new JSONResponse(
            [
                    'success'  => true,
                    'mappings' => $this->zgwMappingService->listMappings(),
                ]
        );
    }

    /**
     * Get a single ZGW mapping configuration.
     *
     * @param string $resourceKey The ZGW resource key
     *
     * @return JSONResponse
     */
    public function show(string $resourceKey): JSONResponse
    {
        $mapping = $this->zgwMappingService->getMapping($resourceKey);

        if ($mapping === null) {
            return new JSONResponse(
                [
                        'success' => false,
                        'message' => "No mapping configured for {$resourceKey}",
                    ]
            );
        }

        return new JSONResponse(
            [
                    'success' => true,
                    'mapping' => $mapping,
                ]
        );
    }

    /**
     * Save a ZGW mapping configuration.
     *
     * @param string $resourceKey The ZGW resource key
     *
     * @return JSONResponse
     */
    public function update(string $resourceKey): JSONResponse
    {
        $params = $this->request->getParams();

        // Remove framework params.
        unset($params['_route'], $params['resourceKey']);

        $this->zgwMappingService->saveMapping(resourceKey: $resourceKey, config: $params);

        return new JSONResponse(
            [
                    'success' => true,
                    'mapping' => $this->zgwMappingService->getMapping($resourceKey),
                ]
        );
    }

    /**
     * Delete a ZGW mapping configuration.
     *
     * @param string $resourceKey The ZGW resource key
     *
     * @return JSONResponse
     */
    public function destroy(string $resourceKey): JSONResponse
    {
        $this->zgwMappingService->deleteMapping($resourceKey);

        return new JSONResponse(
            [
                    'success' => true,
                ]
        );
    }

    /**
     * Reset a single mapping to its default configuration.
     *
     * @param string $resourceKey The ZGW resource key
     *
     * @return JSONResponse
     */
    public function reset(string $resourceKey): JSONResponse
    {
        $registerId = $this->settingsService->getConfigValue(key: 'register', default: '');
        if ($registerId === '') {
            return new JSONResponse(
                [
                        'success' => false,
                        'message' => 'No Procest register configured',
                    ]
            );
        }

        $loader   = new LoadDefaultZgwMappings(
            zgwMappingService: $this->zgwMappingService,
            settingsService: $this->settingsService,
            logger: $this->logger,
        );
        $defaults = $loader->getDefaultMappings(registerId: $registerId);

        $this->zgwMappingService->resetToDefault(resourceKey: $resourceKey, defaults: $defaults);

        return new JSONResponse(
            [
                    'success' => true,
                    'mapping' => $this->zgwMappingService->getMapping($resourceKey),
                ]
        );
    }
}
