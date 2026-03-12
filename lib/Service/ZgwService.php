<?php

/**
 * Procest ZGW Service
 *
 * Shared service for ZGW-compliant API operations. Provides mapping,
 * authentication, pagination, and OpenRegister integration used by
 * all register-specific ZGW controllers.
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

use OCA\OpenRegister\Db\Mapping;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Shared ZGW API service.
 *
 * Contains all shared utility methods extracted from the monolithic ZgwController.
 * Register-specific controllers delegate common operations to this service.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class ZgwService
{
    /**
     * Map of ZGW API + resource to the config key suffix used in Procest.
     *
     * @var array<string, array<string, string>>
     */
    public const RESOURCE_MAP = [
        'zaken'        => [
            'zaken'                  => 'zaak',
            'statussen'              => 'status',
            'resultaten'             => 'resultaat',
            'rollen'                 => 'rol',
            'zaakeigenschappen'      => 'zaakeigenschap',
            'zaakinformatieobjecten' => 'zaakinformatieobject',
            'zaakobjecten'           => 'zaakobject',
            'klantcontacten'         => 'klantcontact',
        ],
        'catalogi'     => [
            'catalogussen'                   => 'catalogus',
            'zaaktypen'                      => 'zaaktype',
            'statustypen'                    => 'statustype',
            'resultaattypen'                 => 'resultaattype',
            'roltypen'                       => 'roltype',
            'eigenschappen'                  => 'eigenschap',
            'informatieobjecttypen'          => 'informatieobjecttype',
            'besluittypen'                   => 'besluittype',
            'zaaktype-informatieobjecttypen' => 'zaaktypeinformatieobjecttype',
        ],
        'besluiten'    => [
            'besluiten'                 => 'besluit',
            'besluittypen'              => 'besluittype',
            'besluitinformatieobjecten' => 'besluitinformatieobject',
        ],
        'autorisaties' => [
            'applicaties' => 'applicatie',
        ],
        'documenten'   => [
            'enkelvoudiginformatieobjecten' => 'enkelvoudiginformatieobject',
            'objectinformatieobjecten'      => 'objectinformatieobject',
            'gebruiksrechten'               => 'gebruiksrechten',
            'verzendingen'                  => 'verzending',
        ],
        'notificaties' => [
            'kanaal'     => 'kanaal',
            'abonnement' => 'abonnement',
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
     * Cached request body to avoid re-reading php://input.
     *
     * @var array|null
     */
    private ?array $cachedRequestBody = null;

    /**
     * The OpenRegister ConsumerMapper (loaded dynamically).
     *
     * @var object|null
     */
    private $consumerMapper = null;

    /**
     * The OpenRegister AuthorizationService (loaded dynamically).
     *
     * @var object|null
     */
    private $authorizationService = null;

    /**
     * Constructor.
     *
     * @param ZgwMappingService       $zgwMappingService    The ZGW mapping service
     * @param ZgwPaginationHelper     $paginationHelper     The pagination helper
     * @param ZgwDocumentService      $documentService      The document storage service
     * @param NotificatieService      $notificatieService   The notification service
     * @param ZgwBusinessRulesService $businessRulesService The business rules service
     * @param LoggerInterface         $logger               The logger
     *
     * @return void
     */
    public function __construct(
        private readonly ZgwMappingService $zgwMappingService,
        private readonly ZgwPaginationHelper $paginationHelper,
        private readonly ZgwDocumentService $documentService,
        private readonly NotificatieService $notificatieService,
        private readonly ZgwBusinessRulesService $businessRulesService,
        private readonly LoggerInterface $logger,
    ) {
        try {
            $container = \OC::$server;
            $this->openRegisterMappingService = $container->get(
                'OCA\OpenRegister\Service\MappingService'
            );
            $this->openRegisterObjectService  = $container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );
            $this->consumerMapper       = $container->get(
                'OCA\OpenRegister\Db\ConsumerMapper'
            );
            $this->authorizationService = $container->get(
                'OCA\OpenRegister\Service\AuthorizationService'
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ZgwService: OpenRegister services not available',
                ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object|null
     */
    public function getObjectService(): ?object
    {
        return $this->openRegisterObjectService;
    }

    /**
     * Get the OpenRegister ConsumerMapper.
     *
     * @return object|null
     */
    public function getConsumerMapper(): ?object
    {
        return $this->consumerMapper;
    }

    /**
     * Get the ZGW mapping service.
     *
     * @return ZgwMappingService
     */
    public function getZgwMappingService(): ZgwMappingService
    {
        return $this->zgwMappingService;
    }

    /**
     * Get the pagination helper.
     *
     * @return ZgwPaginationHelper
     */
    public function getPaginationHelper(): ZgwPaginationHelper
    {
        return $this->paginationHelper;
    }

    /**
     * Get the document service.
     *
     * @return ZgwDocumentService
     */
    public function getDocumentService(): ZgwDocumentService
    {
        return $this->documentService;
    }

    /**
     * Get the business rules service.
     *
     * @return ZgwBusinessRulesService
     */
    public function getBusinessRulesService(): ZgwBusinessRulesService
    {
        return $this->businessRulesService;
    }

    /**
     * Get the logger.
     *
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Load ZGW mapping configuration.
     *
     * @param string $zgwApi   The ZGW API group
     * @param string $resource The ZGW resource name
     *
     * @return array|null The mapping configuration or null if not found
     */
    public function loadMappingConfig(string $zgwApi, string $resource): ?array
    {
        $resourceKey = self::RESOURCE_MAP[$zgwApi][$resource] ?? null;
        if ($resourceKey === null) {
            return null;
        }

        return $this->zgwMappingService->getMapping(resourceKey: $resourceKey);
    }

    /**
     * Translate ZGW query parameters to OpenRegister filter parameters.
     *
     * @param array $params        The request query parameters
     * @param array $mappingConfig The ZGW mapping configuration
     *
     * @return array Translated filter parameters
     */
    public function translateQueryParams(array $params, array $mappingConfig): array
    {
        $queryMapping = $mappingConfig['queryParameterMapping'] ?? [];
        $filters      = [];

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

                if (
                    ($mapped['extractUuid'] ?? false) === true
                    && is_string($value) === true
                ) {
                    $parts = explode('/', rtrim($value, '/'));
                    $value = end($parts);
                }

                if ($operator !== null) {
                    $filters[$field . '.' . $operator] = $value;
                } else {
                    $filters[$field] = $value;
                }
            }
        }

        return $filters;
    }

    /**
     * Create a Mapping object for outbound (English to Dutch) transformation.
     *
     * @param array $mappingConfig The ZGW mapping configuration
     *
     * @return Mapping The outbound mapping entity
     */
    public function createOutboundMapping(array $mappingConfig): object
    {
        $mapping     = new Mapping();
        $mappingData = [
            'name'        => 'zgw-outbound-' . ($mappingConfig['zgwResource'] ?? 'unknown'),
            'mapping'     => $mappingConfig['propertyMapping'] ?? [],
            'unset'       => $mappingConfig['unset'] ?? [],
            'cast'        => $mappingConfig['cast'] ?? [],
            'passThrough' => false,
        ];
        $mapping->hydrate(object: $mappingData);

        return $mapping;
    }

    /**
     * Create a Mapping object for inbound (Dutch to English) transformation.
     *
     * @param array $mappingConfig The ZGW mapping configuration
     *
     * @return Mapping The inbound mapping entity
     */
    public function createInboundMapping(array $mappingConfig): object
    {
        $mapping     = new Mapping();
        $mappingData = [
            'name'        => 'zgw-inbound-' . ($mappingConfig['zgwResource'] ?? 'unknown'),
            'mapping'     => $mappingConfig['reverseMapping'] ?? [],
            'unset'       => $mappingConfig['reverseUnset'] ?? [],
            'cast'        => $mappingConfig['reverseCast'] ?? [],
            'passThrough' => false,
        ];
        $mapping->hydrate(object: $mappingData);

        return $mapping;
    }

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
    public function applyOutboundMapping(
        array $objectData,
        object $mapping,
        array $mappingConfig,
        string $baseUrl
    ): array {
        $objectData['_baseUrl']       = $baseUrl;
        $objectData['_valueMappings'] = $mappingConfig['valueMapping'] ?? [];
        $selfMeta            = $objectData['@self'] ?? [];
        $objectData['_uuid'] = $objectData['id'] ?? ($selfMeta['id'] ?? '');
        $objectData['_created'] = $selfMeta['created'] ?? '';
        $objectData['_updated'] = $selfMeta['updated'] ?? '';

        $zgwResource = $mappingConfig['zgwResource'] ?? '';
        if (
            $zgwResource === 'enkelvoudiginformatieobject'
            && $objectData['_uuid'] !== ''
        ) {
            $objectData['_downloadUrl'] = $baseUrl . '/' . $objectData['_uuid'] . '/download';
        }

        $mapped = $this->openRegisterMappingService->executeMapping(
            mapping: $mapping,
            input: $objectData
        );

        $nullableFields = $mappingConfig['nullableFields'] ?? [];
        foreach ($nullableFields as $field) {
            if (array_key_exists($field, $mapped) === true && $mapped[$field] === '') {
                $mapped[$field] = null;
            }
        }

        return $mapped;
    }

    /**
     * Apply inbound mapping (Dutch to English) to request data.
     *
     * @param array  $body          The Dutch-language request body
     * @param object $mapping       The inbound mapping entity
     * @param array  $mappingConfig The ZGW mapping configuration
     *
     * @return array The mapped English-language data
     */
    public function applyInboundMapping(
        array $body,
        object $mapping,
        array $mappingConfig
    ): array {
        $body['_valueMappings'] = $mappingConfig['valueMapping'] ?? [];
        unset($body['_route'], $body['zgwApi'], $body['resource'], $body['uuid']);

        $mapped = $this->openRegisterMappingService->executeMapping(
            mapping: $mapping,
            input: $body
        );

        // Remove empty-string values for nullable/date fields to prevent OpenRegister
        // from storing "" in date fields (which converts to today's date).
        $nullableKeys = $mappingConfig['inboundNullable'] ?? [
            'endDate',
            'plannedEndDate',
            'deadline',
            'archiveNomination',
            'archiveActionDate',
            'paymentIndication',
            'lastPaymentDate',
            'communicationChannel',
            'archiveStatus',
            'parentCase',
        ];
        foreach ($nullableKeys as $key) {
            if (isset($mapped[$key]) === true && $mapped[$key] === '') {
                unset($mapped[$key]);
            }
        }

        return $mapped;
    }

    /**
     * Get the request body, falling back to raw body parsing for malformed JSON.
     *
     * @param IRequest $request The request object
     *
     * @return array The parsed request body
     */
    public function getRequestBody(IRequest $request): array
    {
        // Return cached result if already parsed for this request.
        if ($this->cachedRequestBody !== null) {
            return $this->cachedRequestBody;
        }

        $routeKeys = ['_route', 'zgwApi', 'resource', 'uuid'];

        // Read php://input directly — Nextcloud's getParams() parser may
        // flatten/drop JSON array values (e.g. `["uuid"]` → `[]`).
        $rawBody = file_get_contents('php://input');
        if ($rawBody !== false && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if ($decoded === null) {
                // Attempt to fix malformed JSON (unquoted values).
                $fixed   = preg_replace(
                    '/("[\w]+")\s*:\s*(?![\s"{\[\dtfn-])([^\n,}]+)/m',
                    '$1: "$2"',
                    $rawBody
                );
                $decoded = json_decode($fixed, true);
            }

            if ($decoded !== null) {
                // Merge route params so they remain available downstream.
                $routeParams = $request->getParams();
                foreach ($routeKeys as $key) {
                    if (isset($routeParams[$key]) === true) {
                        $decoded[$key] = $routeParams[$key];
                    }
                }

                $this->cachedRequestBody = $decoded;

                return $decoded;
            }
        }

        // Fallback: use getParams() for non-JSON requests (multipart, form-encoded).
        $this->cachedRequestBody = $request->getParams();

        return $this->cachedRequestBody;
    }

    /**
     * Extract the UUID from the request URL path.
     *
     * Nextcloud's controller argument injection merges JSON body params into
     * getParam(), so a body "uuid" field overrides the route's {uuid} param.
     * This method extracts the UUID directly from the URL path to avoid that.
     *
     * @param IRequest $request The request object
     * @param string   $uuid    The controller-injected UUID (potentially wrong)
     *
     * @return string The correct UUID from the URL path, or the fallback
     */
    public function resolvePathUuid(IRequest $request, string $uuid): string
    {
        $uri = $request->getRequestUri();
        if (preg_match('/\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $uri, $matches) === 1) {
            return $matches[1];
        }

        return $uuid;
    }

    /**
     * Update a field in the cached request body.
     *
     * Used when pre-processing resolves a value (e.g., IOT omschrijving → UUID).
     *
     * @param string $key   The field name
     * @param mixed  $value The new value
     *
     * @return void
     */
    public function updateCachedBodyField(string $key, mixed $value): void
    {
        if ($this->cachedRequestBody !== null) {
            $this->cachedRequestBody[$key] = $value;
        }
    }

    /**
     * Build the base URL for ZGW API responses.
     *
     * @param IRequest $request  The request object
     * @param string   $zgwApi   The ZGW API group
     * @param string   $resource The ZGW resource name
     *
     * @return string The base URL
     */
    public function buildBaseUrl(IRequest $request, string $zgwApi, string $resource): string
    {
        $serverHost = $request->getServerHost();
        $scheme     = $request->getServerProtocol();

        return $scheme . '://' . $serverHost . '/index.php/apps/procest/api/zgw/' . $zgwApi . '/v1/' . $resource;
    }

    /**
     * Validate JWT-ZGW authentication from the Authorization header.
     *
     * @param IRequest $request The request object
     *
     * @return JSONResponse|null 401 response on failure, null on success
     */
    public function validateJwtAuth(IRequest $request): ?JSONResponse
    {
        $authHeader = $request->getHeader('Authorization');

        if ($authHeader === '' || $authHeader === null) {
            return new JSONResponse(
                data: [
                    'type'   => 'NotAuthenticated',
                    'code'   => 'not_authenticated',
                    'title'  => 'Authenticatiegegevens zijn niet opgegeven.',
                    'status' => 401,
                    'detail' => 'Authenticatiegegevens zijn niet opgegeven.',
                ],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $this->authorizationService->authorizeJwt(
                authorization: $authHeader
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: [
                    'type'   => 'NotAuthenticated',
                    'code'   => 'not_authenticated',
                    'title'  => 'Authenticatiegegevens zijn niet geldig.',
                    'status' => 403,
                    'detail' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return null;
    }

    /**
     * Check if the current JWT consumer has a specific scope.
     *
     * @param IRequest $request   The request object
     * @param string   $component The ZGW component (e.g. 'zrc', 'ztc', 'brc', 'drc')
     * @param string   $scope     The required scope
     *
     * @return bool True if the consumer has the scope or heeftAlleAutorisaties
     */
    public function consumerHasScope(IRequest $request, string $component, string $scope): bool
    {
        if ($this->consumerMapper === null) {
            return true;
        }

        try {
            $authHeader = $request->getHeader('Authorization');
            $token      = str_replace('Bearer ', '', $authHeader);
            $parts      = explode('.', $token);
            if (count($parts) !== 3) {
                return true;
            }

            $payload  = json_decode(base64_decode($parts[1]), true);
            $clientId = $payload['client_id'] ?? ($payload['iss'] ?? null);
            if ($clientId === null) {
                return true;
            }

            $consumers = $this->consumerMapper->findAll(
                filters: ['name' => $clientId]
            );
            if (empty($consumers) === true) {
                return true;
            }

            $consumer   = $consumers[0];
            $authConfig = [];
            if (method_exists($consumer, 'getAuthorizationConfiguration') === true) {
                $authConfig = $consumer->getAuthorizationConfiguration() ?? [];
            }

            if (($authConfig['superuser'] ?? false) === true) {
                return true;
            }

            $scopes = $authConfig['scopes'] ?? [];
            foreach ($scopes as $auth) {
                $authComponent = $auth['component'] ?? '';
                $authScopes    = $auth['scopes'] ?? [];
                if (
                    $authComponent === $component
                    && in_array($scope, $authScopes, true) === true
                ) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Could not check consumer scope: ' . $e->getMessage()
            );
            return true;
        }
    }

    /**
     * Get the consumer's authorization details for a component (for zrc-006).
     *
     * Returns the authorization entries (autorisaties) for the given component,
     * or null if the consumer has full access (superuser / no restrictions).
     *
     * @param IRequest $request   The request object
     * @param string   $component The ZGW component (e.g. 'zrc')
     *
     * @return array|null Array of autorisatie entries, or null if unrestricted
     */
    public function getConsumerAuthorisaties(IRequest $request, string $component): ?array
    {
        if ($this->consumerMapper === null) {
            return null;
        }

        try {
            $authHeader = $request->getHeader('Authorization');
            $token      = str_replace('Bearer ', '', $authHeader);
            $parts      = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            $payload  = json_decode(base64_decode($parts[1]), true);
            $clientId = $payload['client_id'] ?? ($payload['iss'] ?? null);
            if ($clientId === null) {
                return null;
            }

            $consumers = $this->consumerMapper->findAll(
                filters: ['name' => $clientId]
            );
            if (empty($consumers) === true) {
                return null;
            }

            $consumer   = $consumers[0];
            $authConfig = [];
            if (method_exists($consumer, 'getAuthorizationConfiguration') === true) {
                $authConfig = $consumer->getAuthorizationConfiguration() ?? [];
            }

            if (($authConfig['superuser'] ?? false) === true) {
                return null;
            }

            $result = [];
            $scopes = $authConfig['scopes'] ?? [];
            foreach ($scopes as $auth) {
                $authComponent = $auth['component'] ?? '';
                if ($authComponent === $component) {
                    $result[] = $auth;
                }
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Could not get consumer autorisaties: ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Publish a ZGW notification (non-blocking).
     *
     * @param string $zgwApi      The ZGW API group
     * @param string $resource    The ZGW resource name
     * @param string $resourceUrl The resource URL
     * @param string $actie       The action (create, update, destroy)
     *
     * @return void
     */
    public function publishNotification(
        string $zgwApi,
        string $resource,
        string $resourceUrl,
        string $actie
    ): void {
        $resourceKey = self::RESOURCE_MAP[$zgwApi][$resource] ?? $resource;

        $this->notificatieService->publish(
            kanaal: $zgwApi,
            hoofdObject: $resourceUrl,
            resource: $resourceKey,
            resourceUrl: $resourceUrl,
            actie: $actie
        );
    }

    /**
     * Build the error response data from a validation result.
     *
     * @param array $ruleResult The validation result from ZgwBusinessRulesService
     *
     * @return array The error response data with detail and optional invalidParams
     */
    public function buildValidationError(array $ruleResult): array
    {
        $data = ['detail' => $ruleResult['detail']];
        if (isset($ruleResult['code']) === true) {
            $data['code'] = $ruleResult['code'];
        }

        if (empty($ruleResult['invalidParams']) === false) {
            $data['invalidParams'] = $ruleResult['invalidParams'];
        }

        return $data;
    }

    /**
     * Return an "OpenRegister unavailable" error response.
     *
     * @return JSONResponse
     */
    public function unavailableResponse(): JSONResponse
    {
        return new JSONResponse(
            data: ['detail' => 'OpenRegister is not available'],
            statusCode: Http::STATUS_SERVICE_UNAVAILABLE
        );
    }

    /**
     * Return a "mapping not found" error response.
     *
     * @param string $zgwApi   The ZGW API group
     * @param string $resource The ZGW resource name
     *
     * @return JSONResponse
     */
    public function mappingNotFoundResponse(string $zgwApi, string $resource): JSONResponse
    {
        return new JSONResponse(
            data: ['detail' => "No ZGW mapping configured for {$zgwApi}/{$resource}"],
            statusCode: Http::STATUS_NOT_FOUND
        );
    }

    /**
     * Generic index (list) operation for a ZGW resource.
     *
     * @param IRequest $request  The request object
     * @param string   $zgwApi   The ZGW API group
     * @param string   $resource The ZGW resource name
     *
     * @return JSONResponse
     */
    public function handleIndex(IRequest $request, string $zgwApi, string $resource): JSONResponse
    {
        if ($this->openRegisterObjectService === null) {
            return $this->unavailableResponse();
        }

        $mappingConfig = $this->loadMappingConfig(zgwApi: $zgwApi, resource: $resource);
        if ($mappingConfig === null) {
            return $this->mappingNotFoundResponse(zgwApi: $zgwApi, resource: $resource);
        }

        if (($mappingConfig['enabled'] ?? true) === false) {
            return new JSONResponse(
                data: ['detail' => "ZGW mapping for {$zgwApi}/{$resource} is disabled"],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            $params  = $request->getParams();
            $filters = $this->translateQueryParams(params: $params, mappingConfig: $mappingConfig);

            $page     = max(1, (int) ($params['page'] ?? 1));
            $pageSize = max(1, min(100, (int) ($params['pageSize'] ?? 20)));

            $searchParams = array_merge(
                $filters,
                [
                    '_limit'  => $pageSize,
                    '_offset' => (($page - 1) * $pageSize),
                ]
            );

            $query  = $this->openRegisterObjectService->buildSearchQuery(
                requestParams: $searchParams,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $result = $this->openRegisterObjectService->searchObjectsPaginated(
                query: $query,
                _multitenancy: false
            );

            $objects    = $result['results'] ?? [];
            $totalCount = $result['total'] ?? count($objects);
            $baseUrl    = $this->buildBaseUrl(request: $request, zgwApi: $zgwApi, resource: $resource);

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

            $paginatedResult = $this->paginationHelper->wrapResults(
                mappedObjects: $mapped,
                totalCount: $totalCount,
                page: $page,
                pageSize: $pageSize,
                baseUrl: $baseUrl,
                queryParams: $params
            );

            return new JSONResponse(data: $paginatedResult);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ZGW list error: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => 'Internal server error'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Generic create operation for a ZGW resource.
     *
     * @param IRequest $request             The request object
     * @param string   $zgwApi              The ZGW API group
     * @param string   $resource            The ZGW resource name
     * @param bool     $zaakClosed          Whether the parent zaak is closed (zrc-007)
     * @param bool     $hasForceer          Whether the consumer has geforceerd-bijwerken scope
     * @param bool     $parentZaaktypeDraft Whether parent zaaktype is draft (ztc-010)
     *
     * @return JSONResponse
     */
    public function handleCreate(
        IRequest $request,
        string $zgwApi,
        string $resource,
        ?bool $zaakClosed = null,
        bool $hasForceer = true,
        ?bool $parentZaaktypeDraft = null
    ): JSONResponse {
        if ($this->openRegisterObjectService === null) {
            return $this->unavailableResponse();
        }

        $mappingConfig = $this->loadMappingConfig(zgwApi: $zgwApi, resource: $resource);
        if ($mappingConfig === null) {
            return $this->mappingNotFoundResponse(zgwApi: $zgwApi, resource: $resource);
        }

        try {
            $body = $this->getRequestBody(request: $request);

            $ruleResult = $this->businessRulesService->validate(
                zgwApi: $zgwApi,
                resource: $resource,
                action: 'create',
                body: $body,
                objectService: $this->openRegisterObjectService,
                mappingConfig: $mappingConfig,
                parentZaaktypeDraft: $parentZaaktypeDraft,
                zaakClosed: $zaakClosed,
                hasGeforceerd: $hasForceer
            );
            if ($ruleResult['valid'] === false) {
                return new JSONResponse(
                    data: $this->buildValidationError(ruleResult: $ruleResult),
                    statusCode: $ruleResult['status']
                );
            }

            $enrichedBody = $ruleResult['enrichedBody'];

            // Extract direct OpenRegister fields that bypass Twig inbound mapping
            // (used for array fields like documentTypes/caseTypes that Twig cannot handle).
            $directFields = $enrichedBody['_directFields'] ?? [];
            unset($enrichedBody['_directFields']);

            $inboundMapping = $this->createInboundMapping(mappingConfig: $mappingConfig);
            $englishData    = $this->applyInboundMapping(
                body: $enrichedBody,
                mapping: $inboundMapping,
                mappingConfig: $mappingConfig
            );

            if (is_array($englishData) === false) {
                return new JSONResponse(
                    data: ['detail' => 'Invalid mapping result'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Merge direct fields into mapped data (array fields that Twig drops).
            if (empty($directFields) === false) {
                $englishData = array_merge($englishData, $directFields);
            }

            $object = $this->openRegisterObjectService->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $englishData
            );

            if (is_array($object) === true) {
                $objectData = $object;
            } else {
                $objectData = $object->jsonSerialize();
            }

            $objectUuid = $objectData['id'] ?? ($objectData['@self']['id'] ?? '');

            $baseUrl         = $this->buildBaseUrl(request: $request, zgwApi: $zgwApi, resource: $resource);
            $outboundMapping = $this->createOutboundMapping(mappingConfig: $mappingConfig);

            $mapped = $this->applyOutboundMapping(
                objectData: $objectData,
                mapping: $outboundMapping,
                mappingConfig: $mappingConfig,
                baseUrl: $baseUrl
            );

            $resourceUrl = $baseUrl . '/' . $objectUuid;
            $this->publishNotification(
                zgwApi: $zgwApi,
                resource: $resource,
                resourceUrl: $resourceUrl,
                actie: 'create'
            );

            return new JSONResponse(data: $mapped, statusCode: Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ZGW create error: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }

    /**
     * Generic show (get single) operation for a ZGW resource.
     *
     * @param IRequest $request  The request object
     * @param string   $zgwApi   The ZGW API group
     * @param string   $resource The ZGW resource name
     * @param string   $uuid     The resource UUID
     *
     * @return JSONResponse
     */
    public function handleShow(
        IRequest $request,
        string $zgwApi,
        string $resource,
        string $uuid
    ): JSONResponse {
        if ($this->openRegisterObjectService === null) {
            return $this->unavailableResponse();
        }

        $mappingConfig = $this->loadMappingConfig(zgwApi: $zgwApi, resource: $resource);
        if ($mappingConfig === null) {
            return $this->mappingNotFoundResponse(zgwApi: $zgwApi, resource: $resource);
        }

        try {
            $object = $this->openRegisterObjectService->find(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                id: $uuid
            );

            $baseUrl         = $this->buildBaseUrl(request: $request, zgwApi: $zgwApi, resource: $resource);
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
        } catch (\Throwable $e) {
            $this->logger->error(
                'ZGW show error: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => 'Not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Generic update (PUT/PATCH) operation for a ZGW resource.
     *
     * @param IRequest $request       The request object
     * @param string   $zgwApi        The ZGW API group
     * @param string   $resource      The ZGW resource name
     * @param string   $uuid          The resource UUID
     * @param bool     $partial       Whether this is a partial update (PATCH)
     * @param bool     $parentZtDraft Whether parent zaaktype is draft (ztc-010)
     * @param bool     $zaakClosed    Whether the parent zaak is closed (zrc-007)
     * @param bool     $hasForceer    Whether consumer has geforceerd-bijwerken
     *
     * @return JSONResponse
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function handleUpdate(
        IRequest $request,
        string $zgwApi,
        string $resource,
        string $uuid,
        bool $partial = false,
        ?bool $parentZtDraft = null,
        ?bool $zaakClosed = null,
        bool $hasForceer = true
    ): JSONResponse {
        // Resolve UUID from URL path — Nextcloud's getParam() merges JSON body
        // into controller args, so a body "uuid" field can override the route's {uuid}.
        $uuid = $this->resolvePathUuid(request: $request, uuid: $uuid);

        if ($this->openRegisterObjectService === null) {
            return $this->unavailableResponse();
        }

        $mappingConfig = $this->loadMappingConfig(zgwApi: $zgwApi, resource: $resource);
        if ($mappingConfig === null) {
            return $this->mappingNotFoundResponse(zgwApi: $zgwApi, resource: $resource);
        }

        try {
            $body = $this->getRequestBody(request: $request);
            if ($partial === true) {
                $action = 'patch';
            } else {
                $action = 'update';
            }

            $existingObj = $this->openRegisterObjectService->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($existingObj) === true) {
                $existingData = $existingObj;
            } else {
                $existingData = $existingObj->jsonSerialize();
            }

            $ruleResult = $this->businessRulesService->validate(
                zgwApi: $zgwApi,
                resource: $resource,
                action: $action,
                body: $body,
                existingObject: $existingData,
                objectService: $this->openRegisterObjectService,
                mappingConfig: $mappingConfig,
                parentZaaktypeDraft: $parentZtDraft,
                zaakClosed: $zaakClosed,
                hasGeforceerd: $hasForceer
            );
            if ($ruleResult['valid'] === false) {
                return new JSONResponse(
                    data: $this->buildValidationError(ruleResult: $ruleResult),
                    statusCode: $ruleResult['status']
                );
            }

            $enrichedBody = $ruleResult['enrichedBody'];

            // Extract direct OpenRegister fields that bypass Twig inbound mapping.
            $directFields = $enrichedBody['_directFields'] ?? [];
            unset($enrichedBody['_directFields']);

            $inboundMapping = $this->createInboundMapping(mappingConfig: $mappingConfig);
            $englishData    = $this->applyInboundMapping(
                body: $enrichedBody,
                mapping: $inboundMapping,
                mappingConfig: $mappingConfig
            );

            // Merge direct fields into mapped data (array fields that Twig drops).
            if (empty($directFields) === false && is_array($englishData) === true) {
                $englishData = array_merge($englishData, $directFields);
            }

            $englishData['id'] = $uuid;

            // For partial updates (PATCH), merge with existing object data.
            if ($partial === true) {
                $existing     = $this->openRegisterObjectService->find(
                    $uuid,
                    register: $mappingConfig['sourceRegister'],
                    schema: $mappingConfig['sourceSchema']
                );
                $existingData = $existing->jsonSerialize();

                unset($existingData['@self'], $existingData['id'], $existingData['organisation']);

                if (
                    isset($existingData['identifier']) === true
                    && is_int($existingData['identifier']) === true
                ) {
                    $existingData['identifier'] = (string) $existingData['identifier'];
                }

                // Track which keys were originally arrays before json_encode for Twig.
                $arrayKeys = [];
                foreach ($existingData as $key => $value) {
                    if (is_array($value) === true) {
                        $arrayKeys[]        = $key;
                        $existingData[$key] = json_encode($value);
                    }
                }

                $bodyKeys   = array_keys($body);
                $reverseMap = $mappingConfig['reverseMapping'] ?? [];
                $validKeys  = [];
                foreach ($reverseMap as $engKey => $twigTpl) {
                    if (preg_match_all('/\{\{\s*(\w+)/', $twigTpl, $matches) === 1) {
                        foreach ($matches[1] as $zgwField) {
                            if (in_array($zgwField, $bodyKeys, true) === true) {
                                $validKeys[] = $engKey;
                            }
                        }
                    }
                }

                $patchData = [];
                foreach ($validKeys as $key) {
                    if (isset($englishData[$key]) === true) {
                        $patchData[$key] = $englishData[$key];
                    }
                }

                $englishData = array_merge($existingData, $patchData);

                // Determine which English fields are stored as JSON strings
                // (their reverse-mapping template uses json_encode).
                $jsonStringFields = [];
                foreach ($reverseMap as $engKey => $twigTpl) {
                    if (strpos($twigTpl, 'json_encode') !== false) {
                        $jsonStringFields[] = $engKey;
                    }
                }

                // Restore fields that were originally arrays, but skip fields
                // that are stored as JSON strings in the schema (productsOrServices,
                // referenceProcess, relatedCaseTypes, etc.). Those must remain as
                // JSON-encoded strings for OpenRegister validation.
                foreach ($arrayKeys as $key) {
                    if (in_array($key, $jsonStringFields, true) === true) {
                        continue;
                    }

                    if (isset($englishData[$key]) === true && is_string($englishData[$key]) === true) {
                        $decoded = json_decode($englishData[$key], true);
                        if (is_array($decoded) === true) {
                            $englishData[$key] = $decoded;
                        }
                    }
                }
            }

            // Apply _directFields after PATCH merge to ensure they override correctly.
            if (empty($directFields) === false && is_array($englishData) === true) {
                $englishData = array_merge($englishData, $directFields);
            }

            $object = $this->openRegisterObjectService->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $englishData,
                uuid: $uuid
            );

            $baseUrl         = $this->buildBaseUrl(request: $request, zgwApi: $zgwApi, resource: $resource);
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

            $this->publishNotification(
                zgwApi: $zgwApi,
                resource: $resource,
                resourceUrl: $baseUrl . '/' . $uuid,
                actie: 'update'
            );

            return new JSONResponse(data: $mapped);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ZGW update error (' . $resource . ' ' . $uuid . '): ' . $e->getMessage(),
                ['exception' => $e, 'trace' => $e->getTraceAsString()]
            );
            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }

    /**
     * Generic destroy (delete) operation for a ZGW resource.
     *
     * @param IRequest $request       The request object
     * @param string   $zgwApi        The ZGW API group
     * @param string   $resource      The ZGW resource name
     * @param string   $uuid          The resource UUID
     * @param bool     $parentZtDraft Whether parent zaaktype is draft (ztc-010)
     * @param bool     $zaakClosed    Whether the parent zaak is closed (zrc-007)
     * @param bool     $hasForceer    Whether consumer has geforceerd-bijwerken
     *
     * @return JSONResponse
     */
    public function handleDestroy(
        IRequest $request,
        string $zgwApi,
        string $resource,
        string $uuid,
        ?bool $parentZtDraft = null,
        ?bool $zaakClosed = null,
        bool $hasForceer = true
    ): JSONResponse {
        if ($this->openRegisterObjectService === null) {
            return $this->unavailableResponse();
        }

        $mappingConfig = $this->loadMappingConfig(zgwApi: $zgwApi, resource: $resource);
        if ($mappingConfig === null) {
            return $this->mappingNotFoundResponse(zgwApi: $zgwApi, resource: $resource);
        }

        try {
            $existingObj = $this->openRegisterObjectService->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($existingObj) === true) {
                $existingData = $existingObj;
            } else {
                $existingData = $existingObj->jsonSerialize();
            }

            $ruleResult = $this->businessRulesService->validate(
                zgwApi: $zgwApi,
                resource: $resource,
                action: 'destroy',
                body: [],
                existingObject: $existingData,
                objectService: $this->openRegisterObjectService,
                mappingConfig: $mappingConfig,
                parentZaaktypeDraft: $parentZtDraft,
                zaakClosed: $zaakClosed,
                hasGeforceerd: $hasForceer
            );
            if ($ruleResult['valid'] === false) {
                return new JSONResponse(
                    data: $this->buildValidationError(ruleResult: $ruleResult),
                    statusCode: $ruleResult['status']
                );
            }

            $this->openRegisterObjectService->deleteObject(uuid: $uuid);

            $baseUrl = $this->buildBaseUrl(request: $request, zgwApi: $zgwApi, resource: $resource);
            $this->publishNotification(
                zgwApi: $zgwApi,
                resource: $resource,
                resourceUrl: $baseUrl . '/' . $uuid,
                actie: 'destroy'
            );

            return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ZGW delete error: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => 'Not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }

    /**
     * Handle audit trail index — proxies to OpenRegister's audit trail.
     *
     * @param IRequest $request  The request object
     * @param string   $zgwApi   The ZGW API group
     * @param string   $resource The ZGW resource name
     * @param string   $uuid     The resource UUID
     *
     * @return JSONResponse
     */
    public function handleAudittrailIndex(
        IRequest $request,
        string $zgwApi,
        string $resource,
        string $uuid
    ): JSONResponse {
        $resourceUrl = $this->buildBaseUrl(
            request: $request,
            zgwApi: $zgwApi,
            resource: $resource
        ) . '/' . $uuid;

        // Fetch real audit trail from OpenRegister.
        $entries = [];
        if ($this->openRegisterObjectService !== null) {
            try {
                $logs = $this->openRegisterObjectService->getLogs($uuid, [], false, false);
                foreach ($logs as $log) {
                    $entries[] = $this->mapAuditTrailToZgw(
                        log: $log,
                        resourceUrl: $resourceUrl,
                        resource: $resource
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Failed to fetch audit trail for ' . $uuid . ': ' . $e->getMessage()
                );
            }
        }

        // If no entries found, return a synthetic creation entry.
        if (empty($entries) === true) {
            $entries[] = [
                'uuid'               => $uuid . '-audit-1',
                'bron'               => 'procest',
                'applicatieId'       => 'procest',
                'applicatieWeergave' => 'Procest',
                'actie'              => 'create',
                'actieWeergave'      => 'Object aangemaakt',
                'resultaat'          => 200,
                'hoofdObject'        => $resourceUrl,
                'resource'           => $resource,
                'resourceUrl'        => $resourceUrl,
                'resourceWeergave'   => $resource,
                'aanmaakdatum'       => date('c'),
            ];
        }

        return new JSONResponse(data: $entries);
    }

    /**
     * Handle audit trail show — proxies to OpenRegister's audit trail.
     *
     * @param IRequest $request   The request object
     * @param string   $zgwApi    The ZGW API group
     * @param string   $resource  The ZGW resource name
     * @param string   $uuid      The resource UUID
     * @param string   $auditUuid The audit trail entry UUID
     *
     * @return JSONResponse
     */
    public function handleAudittrailShow(
        IRequest $request,
        string $zgwApi,
        string $resource,
        string $uuid,
        string $auditUuid
    ): JSONResponse {
        $resourceUrl = $this->buildBaseUrl(
            request: $request,
            zgwApi: $zgwApi,
            resource: $resource
        ) . '/' . $uuid;

        // Try to find the specific audit trail entry from OpenRegister.
        if ($this->openRegisterObjectService !== null) {
            try {
                $logs = $this->openRegisterObjectService->getLogs($uuid, [], false, false);
                foreach ($logs as $log) {
                    if (is_array($log) === true) {
                        $logData = $log;
                    } else {
                        $logData = $log->jsonSerialize();
                    }

                    if (($logData['uuid'] ?? '') === $auditUuid) {
                        return new JSONResponse(
                            data: $this->mapAuditTrailToZgw(log: $log, resourceUrl: $resourceUrl, resource: $resource)
                        );
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Failed to fetch audit trail entry ' . $auditUuid . ': ' . $e->getMessage()
                );
            }
        }

        // Fallback: return a synthetic entry with the requested UUID.
        return new JSONResponse(
            data: [
                'uuid'               => $auditUuid,
                'bron'               => 'procest',
                'applicatieId'       => 'procest',
                'applicatieWeergave' => 'Procest',
                'actie'              => 'create',
                'actieWeergave'      => 'Object aangemaakt',
                'resultaat'          => 200,
                'hoofdObject'        => $resourceUrl,
                'resource'           => $resource,
                'resourceUrl'        => $resourceUrl,
                'resourceWeergave'   => $resource,
                'aanmaakdatum'       => date('c'),
            ]
        );
    }

    /**
     * Map an OpenRegister AuditTrail entry to ZGW audittrail format.
     *
     * @param object|array $log         The OpenRegister audit trail entry
     * @param string       $resourceUrl The ZGW resource URL
     * @param string       $resource    The ZGW resource name
     *
     * @return array ZGW-formatted audit trail entry
     */
    private function mapAuditTrailToZgw(
        object|array $log,
        string $resourceUrl,
        string $resource
    ): array {
        if (is_array($log) === true) {
            $logData = $log;
        } else {
            $logData = $log->jsonSerialize();
        }

        // Map OpenRegister action names to ZGW actie names.
        $actionMap = [
            'save'                                 => 'create',
            'create'                               => 'create',
            'update'                               => 'update',
            'patch'                                => 'partial_update',
            'delete'                               => 'destroy',
            'lock'                                 => 'create',
            'unlock'                               => 'destroy',
            'publish'                              => 'update',
            'depublish'                            => 'update',
            'referential_integrity.cascade_delete' => 'destroy',
        ];

        $actionDisplayMap = [
            'create'         => 'Object aangemaakt',
            'update'         => 'Object bijgewerkt',
            'partial_update' => 'Object deels bijgewerkt',
            'destroy'        => 'Object verwijderd',
            'list'           => 'Objecten opgevraagd',
            'retrieve'       => 'Object opgevraagd',
        ];

        $orAction = $logData['action'] ?? 'create';
        $zgwActie = $actionMap[$orAction] ?? $orAction;
        $weergave = $actionDisplayMap[$zgwActie] ?? ucfirst($orAction);

        return [
            'uuid'               => $logData['uuid'] ?? '',
            'bron'               => 'procest',
            'applicatieId'       => $logData['user'] ?? 'procest',
            'applicatieWeergave' => $logData['userName'] ?? 'Procest',
            'actie'              => $zgwActie,
            'actieWeergave'      => $weergave,
            'resultaat'          => 200,
            'hoofdObject'        => $resourceUrl,
            'resource'           => $resource,
            'resourceUrl'        => $resourceUrl,
            'resourceWeergave'   => $resource,
            'aanmaakdatum'       => $logData['created'] ?? date('c'),
        ];
    }

    /**
     * Resolve whether a zaak is closed (has einddatum set).
     *
     * @param string $resource     The ZGW resource name
     * @param array  $existingData The existing object data
     *
     * @return bool|null True if closed, false if open, null if N/A
     */
    public function resolveZaakClosed(string $resource, array $existingData): ?bool
    {
        if ($resource === 'zaken') {
            $endDate = $existingData['endDate'] ?? ($existingData['einddatum'] ?? null);
            return $endDate !== null && $endDate !== '';
        }

        $zaakSubResources = [
            'statussen',
            'resultaten',
            'rollen',
            'zaakeigenschappen',
            'zaakinformatieobjecten',
            'zaakobjecten',
            'klantcontacten',
        ];
        if (in_array($resource, $zaakSubResources, true) === false) {
            return null;
        }

        $zaakUuid = $existingData['case'] ?? ($existingData['zaak'] ?? null);
        if ($zaakUuid === null || $zaakUuid === '') {
            return null;
        }

        if (
            preg_match(
                '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i',
                (string) $zaakUuid,
                $matches
            ) === 1
        ) {
            $zaakUuid = $matches[1];
        }

        try {
            $zaakConfig = $this->zgwMappingService->getMapping('zaak');
            if ($zaakConfig === null) {
                return null;
            }

            $zaak = $this->openRegisterObjectService->find(
                $zaakUuid,
                register: $zaakConfig['sourceRegister'],
                schema: $zaakConfig['sourceSchema']
            );
            if ($zaak === null) {
                return null;
            }

            if (is_array($zaak) === true) {
                $zaakData = $zaak;
            } else {
                $zaakData = $zaak->jsonSerialize();
            }

            $endDate = $zaakData['endDate'] ?? ($zaakData['einddatum'] ?? null);

            return $endDate !== null && $endDate !== '';
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Could not resolve zaak closed status: ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Resolve whether a zaak is closed from a request body (for sub-resource creation).
     *
     * @param string $resource The ZGW resource name
     * @param array  $body     The request body
     *
     * @return bool|null True if closed, false if open, null if N/A
     */
    public function resolveZaakClosedFromBody(string $resource, array $body): ?bool
    {
        if ($resource === 'zaken') {
            return null;
        }

        $zaakSubResources = [
            'statussen',
            'resultaten',
            'rollen',
            'zaakeigenschappen',
            'zaakinformatieobjecten',
            'zaakobjecten',
            'klantcontacten',
        ];
        if (in_array($resource, $zaakSubResources, true) === false) {
            return null;
        }

        $zaakUrl = $body['zaak'] ?? null;
        if ($zaakUrl === null || $zaakUrl === '') {
            return null;
        }

        if (
            preg_match(
                '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i',
                (string) $zaakUrl,
                $matches
            ) === 1
        ) {
            $zaakUuid = $matches[1];
        } else {
            return null;
        }

        try {
            $zaakConfig = $this->zgwMappingService->getMapping('zaak');
            if ($zaakConfig === null) {
                return null;
            }

            $zaak = $this->openRegisterObjectService->find(
                $zaakUuid,
                register: $zaakConfig['sourceRegister'],
                schema: $zaakConfig['sourceSchema']
            );
            if ($zaak === null) {
                return null;
            }

            if (is_array($zaak) === true) {
                $zaakData = $zaak;
            } else {
                $zaakData = $zaak->jsonSerialize();
            }

            $endDate = $zaakData['endDate'] ?? ($zaakData['einddatum'] ?? null);

            return $endDate !== null && $endDate !== '';
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve whether the parent zaaktype is in draft (concept) state.
     *
     * @param string $resource     The ZGW resource name
     * @param array  $existingData The existing sub-resource object data
     *
     * @return bool|null True if draft, false if published, null if N/A
     */
    public function resolveParentZaaktypeDraft(string $resource, array $existingData): ?bool
    {
        $subResources = [
            'statustypen',
            'resultaattypen',
            'roltypen',
            'eigenschappen',
            'zaaktype-informatieobjecttypen',
        ];

        if (in_array($resource, $subResources, true) === false) {
            return null;
        }

        $zaaktypeUuid = $existingData['caseType'] ?? ($existingData['zaaktype'] ?? null);
        if ($zaaktypeUuid === null || $zaaktypeUuid === '') {
            return null;
        }

        if (
            preg_match(
                '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i',
                (string) $zaaktypeUuid,
                $matches
            ) === 1
        ) {
            $zaaktypeUuid = $matches[1];
        }

        try {
            $zaaktypeConfig = $this->zgwMappingService->getMapping('zaaktype');
            if ($zaaktypeConfig === null) {
                return null;
            }

            $zaaktype = $this->openRegisterObjectService->find(
                $zaaktypeUuid,
                register: $zaaktypeConfig['sourceRegister'],
                schema: $zaaktypeConfig['sourceSchema']
            );
            if ($zaaktype === null) {
                return null;
            }

            if (is_array($zaaktype) === true) {
                $ztData = $zaaktype;
            } else {
                $ztData = $zaaktype->jsonSerialize();
            }

            $isDraft = $ztData['isDraft'] ?? ($ztData['concept'] ?? true);

            if ($isDraft === false || $isDraft === 'false' || $isDraft === '0' || $isDraft === 0) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Could not resolve parent zaaktype draft status: ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Resolve parent zaaktype draft status from a request body (for sub-resource creation).
     *
     * Extracts the zaaktype URL/UUID from the body and looks up whether
     * the zaaktype is still in draft (concept) state.
     *
     * @param string $resource The ZGW resource name
     * @param array  $body     The request body (Dutch field names)
     *
     * @return bool|null True if draft, false if published, null if N/A
     */
    public function resolveParentZaaktypeDraftFromBody(string $resource, array $body): ?bool
    {
        $subResources = [
            'statustypen',
            'resultaattypen',
            'roltypen',
            'eigenschappen',
            'zaaktype-informatieobjecttypen',
        ];

        if (in_array($resource, $subResources, true) === false) {
            return null;
        }

        $zaaktypeRef = $body['zaaktype'] ?? null;
        if ($zaaktypeRef === null || $zaaktypeRef === '') {
            return null;
        }

        // Extract UUID from URL or plain UUID.
        if (
            preg_match(
                '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i',
                (string) $zaaktypeRef,
                $matches
            ) === 1
        ) {
            $zaaktypeUuid = $matches[1];
        } else {
            return null;
        }

        try {
            $zaaktypeConfig = $this->zgwMappingService->getMapping('zaaktype');
            if ($zaaktypeConfig === null) {
                return null;
            }

            $zaaktype = $this->openRegisterObjectService->find(
                $zaaktypeUuid,
                register: $zaaktypeConfig['sourceRegister'],
                schema: $zaaktypeConfig['sourceSchema']
            );
            if ($zaaktype === null) {
                return null;
            }

            if (is_array($zaaktype) === true) {
                $ztData = $zaaktype;
            } else {
                $ztData = $zaaktype->jsonSerialize();
            }

            $isDraft = $ztData['isDraft'] ?? ($ztData['concept'] ?? true);

            if ($isDraft === false || $isDraft === 'false' || $isDraft === '0' || $isDraft === 0) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Could not resolve parent zaaktype draft from body: ' . $e->getMessage()
            );
            return null;
        }
    }
}
