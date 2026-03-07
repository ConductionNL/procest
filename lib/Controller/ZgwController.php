<?php

/**
 * Procest ZGW Controller
 *
 * Controller for serving ZGW-compliant API endpoints on top of English-language
 * OpenRegister data. Uses bidirectional mapping (English <-> Dutch) with Twig templates.
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

use OCA\OpenRegister\Db\Mapping;
use OCA\Procest\Service\ZgwMappingService;
use OCA\Procest\Service\ZgwPaginationHelper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * ZGW API Controller
 *
 * Dispatches ZGW API requests to the correct OpenRegister schema based on
 * mapping configuration stored in Procest's IAppConfig.
 *
 * Route pattern: /api/zgw/{zgwApi}/v1/{resource}/{uuid?}
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class ZgwController extends Controller
{

    /**
     * Map of ZGW API + resource to the config key suffix used in Procest.
     *
     * @var array<string, array<string, string>>
     */
    private const RESOURCE_MAP = [
        'zaken'     => [
            'zaken'      => 'zaak',
            'statussen'  => 'status',
            'resultaten' => 'resultaat',
            'rollen'     => 'rol',
        ],
        'catalogi'  => [
            'zaaktypen'             => 'zaaktype',
            'statustypen'           => 'statustype',
            'resultaattypen'        => 'resultaattype',
            'roltypen'              => 'roltype',
            'eigenschappen'         => 'eigenschap',
            'informatieobjecttypen' => 'informatieobjecttype',
            'besluittypen'          => 'besluittype',
        ],
        'besluiten' => [
            'besluiten'    => 'besluit',
            'besluittypen' => 'besluittype',
        ],
    ];

    /**
     * The OpenRegister MappingService (loaded dynamically).
     *
     * @var object|null
     */
    private $openRegisterMappingService = null;

    /**
     * The OpenRegister ObjectService (loaded dynamically).
     *
     * @var object|null
     */
    private $openRegisterObjectService = null;

    /**
     * Constructor
     *
     * @param string              $appName           The app name
     * @param IRequest            $request           The request
     * @param ZgwMappingService   $zgwMappingService The ZGW mapping service
     * @param ZgwPaginationHelper $paginationHelper  The pagination helper
     * @param LoggerInterface     $logger            The logger
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ZgwMappingService $zgwMappingService,
        private readonly ZgwPaginationHelper $paginationHelper,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

        // Dynamically load OpenRegister services.
        try {
            $container = \OC::$server;
            $this->openRegisterMappingService = $container->get(
                'OCA\OpenRegister\Service\MappingService'
            );
            $this->openRegisterObjectService  = $container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ZgwController: OpenRegister services not available',
                ['exception' => $e->getMessage()]
            );
        }
    }//end __construct()

    /**
     * List ZGW resources (GET collection).
     *
     * @param string $zgwApi   The ZGW API group (zaken, catalogi, besluiten)
     * @param string $resource The ZGW resource name (zaken, zaaktypen, etc.)
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(string $zgwApi, string $resource): JSONResponse
    {
        if ($this->openRegisterObjectService === null) {
            return new JSONResponse(
                data: ['detail' => 'OpenRegister is not available'],
                statusCode: Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        $mappingConfig = $this->loadMappingConfig(
            zgwApi: $zgwApi,
            resource: $resource
        );
        if ($mappingConfig === null) {
            return new JSONResponse(
                data: ['detail' => "No ZGW mapping configured for {$zgwApi}/{$resource}"],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        if (($mappingConfig['enabled'] ?? true) === false) {
            return new JSONResponse(
                data: ['detail' => "ZGW mapping for {$zgwApi}/{$resource} is disabled"],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            // Translate ZGW query params to OpenRegister filters.
            $params  = $this->request->getParams();
            $filters = $this->translateQueryParams(
                params: $params,
                mappingConfig: $mappingConfig
            );

            $page     = max(1, (int) ($params['page'] ?? 1));
            $pageSize = max(1, min(100, (int) ($params['pageSize'] ?? 20)));

            // Build query params for OpenRegister searchObjectsPaginated.
            $searchParams = array_merge(
                $filters,
                [
                    '_limit'  => $pageSize,
                    '_offset' => (($page - 1) * $pageSize),
                ]
            );

            // Set register/schema context and search.
            $query  = $this->openRegisterObjectService->buildSearchQuery(
                requestParams: $searchParams,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $result = $this->openRegisterObjectService->searchObjectsPaginated(
                query: $query
            );

            $objects    = $result['results'] ?? [];
            $totalCount = $result['total'] ?? count($objects);
            $baseUrl    = $this->buildBaseUrl(zgwApi: $zgwApi, resource: $resource);

            // Apply outbound mapping to each result.
            $outboundMapping = $this->createOutboundMapping(mappingConfig: $mappingConfig);
            $mapped          = [];
            foreach ($objects as $object) {
                if (is_array($object) === true) {
                    $objectData = $object;
                } else {
                    $objectData = $object->jsonSerialize();
                }

                $mapped[] = $this->applyOutboundMapping(
                    objectData: $objectData,
                    mapping: $outboundMapping,
                    mappingConfig: $mappingConfig,
                    baseUrl: $baseUrl
                );
            }

            // Wrap in ZGW pagination.
            $paginatedResult = $this->paginationHelper->wrapResults(
                mappedObjects: $mapped,
                totalCount: $totalCount,
                page: $page,
                pageSize: $pageSize,
                baseUrl: $baseUrl,
                queryParams: $params
            );

            return new JSONResponse(data: $paginatedResult);
        } catch (\Exception $e) {
            $this->logger->error(
                'ZGW list error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => 'Internal server error'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end index()

    /**
     * Create a ZGW resource (POST collection).
     *
     * @param string $zgwApi   The ZGW API group
     * @param string $resource The ZGW resource name
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function create(string $zgwApi, string $resource): JSONResponse
    {
        if ($this->openRegisterObjectService === null) {
            return new JSONResponse(
                data: ['detail' => 'OpenRegister is not available'],
                statusCode: Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        $mappingConfig = $this->loadMappingConfig(
            zgwApi: $zgwApi,
            resource: $resource
        );
        if ($mappingConfig === null) {
            return new JSONResponse(
                data: ['detail' => "No ZGW mapping configured for {$zgwApi}/{$resource}"],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            // Apply inbound mapping (Dutch to English).
            $body           = $this->request->getParams();
            $inboundMapping = $this->createInboundMapping(mappingConfig: $mappingConfig);
            $englishData    = $this->applyInboundMapping(
                body: $body,
                mapping: $inboundMapping,
                mappingConfig: $mappingConfig
            );

            // Save via ObjectService.
            $object = $this->openRegisterObjectService->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $englishData
            );

            // Apply outbound mapping for response.
            $baseUrl         = $this->buildBaseUrl(zgwApi: $zgwApi, resource: $resource);
            $outboundMapping = $this->createOutboundMapping(mappingConfig: $mappingConfig);
            if (is_array($object) === true) {
                $objectData = $object;
            } else {
                $objectData = $object->jsonSerialize();
            }

            $mapped = $this->applyOutboundMapping(
                objectData: $objectData,
                mapping: $outboundMapping,
                mappingConfig: $mappingConfig,
                baseUrl: $baseUrl
            );

            return new JSONResponse(data: $mapped, statusCode: Http::STATUS_CREATED);
        } catch (\Exception $e) {
            $this->logger->error(
                'ZGW create error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end create()

    /**
     * Get a single ZGW resource (GET item).
     *
     * @param string $zgwApi   The ZGW API group
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(string $zgwApi, string $resource, string $uuid): JSONResponse
    {
        if ($this->openRegisterObjectService === null) {
            return new JSONResponse(
                data: ['detail' => 'OpenRegister is not available'],
                statusCode: Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        $mappingConfig = $this->loadMappingConfig(
            zgwApi: $zgwApi,
            resource: $resource
        );
        if ($mappingConfig === null) {
            return new JSONResponse(
                data: ['detail' => "No ZGW mapping configured for {$zgwApi}/{$resource}"],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            $object = $this->openRegisterObjectService->find(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                id: $uuid
            );

            $baseUrl         = $this->buildBaseUrl(zgwApi: $zgwApi, resource: $resource);
            $outboundMapping = $this->createOutboundMapping(mappingConfig: $mappingConfig);
            if (is_array($object) === true) {
                $objectData = $object;
            } else {
                $objectData = $object->jsonSerialize();
            }

            $mapped = $this->applyOutboundMapping(
                objectData: $objectData,
                mapping: $outboundMapping,
                mappingConfig: $mappingConfig,
                baseUrl: $baseUrl
            );

            return new JSONResponse(data: $mapped);
        } catch (\Exception $e) {
            $this->logger->error(
                'ZGW show error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => 'Not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end show()

    /**
     * Update a ZGW resource (PUT item).
     *
     * @param string $zgwApi   The ZGW API group
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function update(string $zgwApi, string $resource, string $uuid): JSONResponse
    {
        return $this->handleUpdate(zgwApi: $zgwApi, resource: $resource, uuid: $uuid);
    }//end update()

    /**
     * Partial update a ZGW resource (PATCH item).
     *
     * @param string $zgwApi   The ZGW API group
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function patch(string $zgwApi, string $resource, string $uuid): JSONResponse
    {
        return $this->handleUpdate(zgwApi: $zgwApi, resource: $resource, uuid: $uuid);
    }//end patch()

    /**
     * Delete a ZGW resource (DELETE item).
     *
     * @param string $zgwApi   The ZGW API group
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(string $zgwApi, string $resource, string $uuid): JSONResponse
    {
        if ($this->openRegisterObjectService === null) {
            return new JSONResponse(
                data: ['detail' => 'OpenRegister is not available'],
                statusCode: Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        $mappingConfig = $this->loadMappingConfig(
            zgwApi: $zgwApi,
            resource: $resource
        );
        if ($mappingConfig === null) {
            return new JSONResponse(
                data: ['detail' => "No ZGW mapping configured for {$zgwApi}/{$resource}"],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            $this->openRegisterObjectService->deleteObject(uuid: $uuid);

            return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
        } catch (\Exception $e) {
            $this->logger->error(
                'ZGW delete error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => 'Not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }//end destroy()

    /**
     * Handle PUT/PATCH update requests.
     *
     * @param string $zgwApi   The ZGW API group
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
     *
     * @return JSONResponse
     */
    private function handleUpdate(string $zgwApi, string $resource, string $uuid): JSONResponse
    {
        if ($this->openRegisterObjectService === null) {
            return new JSONResponse(
                data: ['detail' => 'OpenRegister is not available'],
                statusCode: Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        $mappingConfig = $this->loadMappingConfig(
            zgwApi: $zgwApi,
            resource: $resource
        );
        if ($mappingConfig === null) {
            return new JSONResponse(
                data: ['detail' => "No ZGW mapping configured for {$zgwApi}/{$resource}"],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            $body           = $this->request->getParams();
            $inboundMapping = $this->createInboundMapping(mappingConfig: $mappingConfig);
            $englishData    = $this->applyInboundMapping(
                body: $body,
                mapping: $inboundMapping,
                mappingConfig: $mappingConfig
            );

            // Include the UUID so ObjectService can locate the existing object.
            $englishData['uuid'] = $uuid;

            $object = $this->openRegisterObjectService->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $englishData
            );

            $baseUrl         = $this->buildBaseUrl(zgwApi: $zgwApi, resource: $resource);
            $outboundMapping = $this->createOutboundMapping(mappingConfig: $mappingConfig);
            if (is_array($object) === true) {
                $objectData = $object;
            } else {
                $objectData = $object->jsonSerialize();
            }

            $mapped = $this->applyOutboundMapping(
                objectData: $objectData,
                mapping: $outboundMapping,
                mappingConfig: $mappingConfig,
                baseUrl: $baseUrl
            );

            return new JSONResponse(data: $mapped);
        } catch (\Exception $e) {
            $this->logger->error(
                'ZGW update error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end handleUpdate()

    /**
     * Load ZGW mapping configuration.
     *
     * @param string $zgwApi   The ZGW API group
     * @param string $resource The ZGW resource name
     *
     * @return array|null The mapping configuration or null if not found
     */
    private function loadMappingConfig(string $zgwApi, string $resource): ?array
    {
        $resourceKey = self::RESOURCE_MAP[$zgwApi][$resource] ?? null;
        if ($resourceKey === null) {
            return null;
        }

        return $this->zgwMappingService->getMapping(resourceKey: $resourceKey);
    }//end loadMappingConfig()

    /**
     * Translate ZGW query parameters to OpenRegister filter parameters.
     *
     * @param array $params        The request query parameters
     * @param array $mappingConfig The ZGW mapping configuration
     *
     * @return array Translated filter parameters
     */
    private function translateQueryParams(array $params, array $mappingConfig): array
    {
        $queryMapping = $mappingConfig['queryParameterMapping'] ?? [];
        $filters      = [];

        // Remove framework params.
        $reserved = [
            'page',
            'pageSize',
            '_route',
            'zgwApi',
            'resource',
            'uuid',
        ];

        foreach ($params as $key => $value) {
            if (in_array($key, $reserved, true) === true) {
                continue;
            }

            if (isset($queryMapping[$key]) === true) {
                $mapped   = $queryMapping[$key];
                $field    = $mapped['field'] ?? $key;
                $operator = $mapped['operator'] ?? null;

                // Extract UUID from URL if configured.
                if (($mapped['extractUuid'] ?? false) === true
                    && is_string($value) === true
                ) {
                    $parts = explode('/', rtrim($value, '/'));
                    $value = end($parts);
                }

                if ($operator !== null) {
                    $filters[$field.'.'.$operator] = $value;
                } else {
                    $filters[$field] = $value;
                }
            }

            // Unmapped parameters are ignored per ZGW spec.
        }//end foreach

        return $filters;
    }//end translateQueryParams()

    /**
     * Create a Mapping object for outbound (English to Dutch) transformation.
     *
     * @param array $mappingConfig The ZGW mapping configuration
     *
     * @return Mapping The outbound mapping entity
     */
    private function createOutboundMapping(array $mappingConfig): object
    {
        $mapping     = new Mapping();
        $mappingData = [
            'name'        => 'zgw-outbound-'.($mappingConfig['zgwResource'] ?? 'unknown'),
            'mapping'     => $mappingConfig['propertyMapping'] ?? [],
            'unset'       => $mappingConfig['unset'] ?? [],
            'cast'        => $mappingConfig['cast'] ?? [],
            'passThrough' => false,
        ];
        $mapping->hydrate(object: $mappingData);

        return $mapping;
    }//end createOutboundMapping()

    /**
     * Create a Mapping object for inbound (Dutch to English) transformation.
     *
     * @param array $mappingConfig The ZGW mapping configuration
     *
     * @return Mapping The inbound mapping entity
     */
    private function createInboundMapping(array $mappingConfig): object
    {
        $mapping     = new Mapping();
        $mappingData = [
            'name'        => 'zgw-inbound-'.($mappingConfig['zgwResource'] ?? 'unknown'),
            'mapping'     => $mappingConfig['reverseMapping'] ?? [],
            'unset'       => $mappingConfig['reverseUnset'] ?? [],
            'cast'        => $mappingConfig['reverseCast'] ?? [],
            'passThrough' => false,
        ];
        $mapping->hydrate(object: $mappingData);

        return $mapping;
    }//end createInboundMapping()

    /**
     * Apply outbound mapping (English to Dutch) to an object.
     *
     * @param array  $objectData    The English-language object data
     * @param object $mapping       The outbound mapping entity
     * @param array  $mappingConfig The ZGW mapping configuration
     * @param string $baseUrl       The base URL for ZGW URL references
     *
     * @return array The mapped Dutch-language object
     */
    private function applyOutboundMapping(
        array $objectData,
        object $mapping,
        array $mappingConfig,
        string $baseUrl
    ): array {
        // Inject template context variables and @self metadata.
        $objectData['_baseUrl']       = $baseUrl;
        $objectData['_valueMappings'] = $mappingConfig['valueMapping'] ?? [];
        $selfMeta            = $objectData['@self'] ?? [];
        $objectData['_uuid'] = $objectData['id'] ?? ($selfMeta['id'] ?? '');
        $objectData['_created'] = $selfMeta['created'] ?? '';
        $objectData['_updated'] = $selfMeta['updated'] ?? '';

        return $this->openRegisterMappingService->executeMapping(
            mapping: $mapping,
            input: $objectData
        );
    }//end applyOutboundMapping()

    /**
     * Apply inbound mapping (Dutch to English) to request data.
     *
     * @param array  $body          The Dutch-language request body
     * @param object $mapping       The inbound mapping entity
     * @param array  $mappingConfig The ZGW mapping configuration
     *
     * @return array The mapped English-language data
     */
    private function applyInboundMapping(
        array $body,
        object $mapping,
        array $mappingConfig
    ): array {
        // Inject _valueMappings for reverse enum lookup.
        $body['_valueMappings'] = $mappingConfig['valueMapping'] ?? [];

        // Remove framework parameters from the body.
        unset($body['_route'], $body['zgwApi'], $body['resource'], $body['uuid']);

        return $this->openRegisterMappingService->executeMapping(
            mapping: $mapping,
            input: $body
        );
    }//end applyInboundMapping()

    /**
     * Build the base URL for ZGW API responses.
     *
     * @param string $zgwApi   The ZGW API group
     * @param string $resource The ZGW resource name
     *
     * @return string The base URL
     */
    private function buildBaseUrl(string $zgwApi, string $resource): string
    {
        $serverHost = $this->request->getServerHost();
        $scheme     = $this->request->getServerProtocol();

        return $scheme.'://'.$serverHost.'/index.php/apps/procest/api/zgw/'.$zgwApi.'/v1/'.$resource;
    }//end buildBaseUrl()
}//end class
