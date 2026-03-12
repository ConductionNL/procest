<?php

/**
 * Procest AC (Autorisaties) Controller
 *
 * Controller for serving ZGW Autorisaties API endpoints (applicaties).
 * Uses ConsumerMapper from OpenRegister to manage API consumers as ZGW
 * applicaties. Does NOT use OpenRegister objects directly.
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

use OCA\Procest\Service\ZgwService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * AC (Autorisaties) API Controller
 *
 * Manages ZGW applicaties backed by OpenRegister ConsumerMapper entries.
 * Completely custom logic — no OpenRegister object storage.
 *
 * Implements VNG AC business rules:
 * - ac-001: ClientId uniqueness across applicaties
 * - ac-002: heeftAlleAutorisaties consistency with autorisaties array
 * - ac-003: Scope-based field requirements per component
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class AcController extends Controller
{
    /**
     * Constructor.
     *
     * @param string     $appName    The app name.
     * @param IRequest   $request    The incoming request.
     * @param ZgwService $zgwService The shared ZGW service.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ZgwService $zgwService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List all applicaties, optionally filtered by clientId.
     *
     * Supports both 'clientId' and 'clientIds' query parameters.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function index(): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        if ($this->zgwService->getConsumerMapper() === null) {
            return new JSONResponse(
                data: ['detail' => 'Consumer service not available'],
                statusCode: Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        try {
            $clientId       = $this->request->getParam('clientId');
            $clientIds      = $this->request->getParam('clientIds');
            $filterClientId = null;

            if ($clientId !== null && $clientId !== '') {
                $filterClientId = $clientId;
            } else if ($clientIds !== null && $clientIds !== '') {
                $filterClientId = $clientIds;
            }

            if ($filterClientId !== null) {
                // Search by name (primary clientId) first.
                $consumers = $this->zgwService->getConsumerMapper()->findAll(
                    filters: ['name' => $filterClientId]
                );

                // Also search all consumers for extra clientIds stored in authConfig.
                if (count($consumers) === 0) {
                    $allConsumers = $this->zgwService->getConsumerMapper()->findAll();
                    foreach ($allConsumers as $consumer) {
                        $authConfig     = $consumer->getAuthorizationConfiguration() ?? [];
                        $extraClientIds = $authConfig['clientIds'] ?? [];
                        if (in_array($filterClientId, $extraClientIds, true) === true) {
                            $consumers[] = $consumer;
                        }
                    }
                }
            } else {
                $consumers = $this->zgwService->getConsumerMapper()->findAll();
            }

            $baseUrl = $this->zgwService->buildBaseUrl($this->request, 'autorisaties', 'applicaties');
            $results = [];
            foreach ($consumers as $consumer) {
                $results[] = $this->consumerToApplicatie(consumer: $consumer, baseUrl: $baseUrl);
            }

            return new JSONResponse(
                    data: [
                        'count'    => count($results),
                        'next'     => null,
                        'previous' => null,
                        'results'  => $results,
                    ]
                    );
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error('AC list error: '.$e->getMessage(), ['exception' => $e]);

            return new JSONResponse(
                data: ['detail' => 'Internal server error'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end index()

    /**
     * Create a new applicatie.
     *
     * Validates ac-001 (clientId uniqueness), ac-002 (heeftAlleAutorisaties
     * consistency), and ac-003 (scope-based field requirements) before saving.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function create(): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        if ($this->zgwService->getConsumerMapper() === null) {
            return new JSONResponse(
                data: ['detail' => 'Consumer service not available'],
                statusCode: Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        try {
            $body = $this->zgwService->getRequestBody($this->request);

            // Run AC business rules validation.
            $validationError = $this->validateApplicatieBody(body: $body);
            if ($validationError !== null) {
                return $validationError;
            }

            $consumerData = $this->applicatieToConsumer(body: $body);
            $consumer     = $this->zgwService->getConsumerMapper()->createFromArray(object: $consumerData);

            $baseUrl = $this->zgwService->buildBaseUrl($this->request, 'autorisaties', 'applicaties');
            $mapped  = $this->consumerToApplicatie(consumer: $consumer, baseUrl: $baseUrl);

            return new JSONResponse(data: $mapped, statusCode: Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error('AC create error: '.$e->getMessage(), ['exception' => $e]);

            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end create()

    /**
     * Retrieve a single applicatie by UUID.
     *
     * If uuid is 'consumer', delegates to index() with clientId filter.
     *
     * @param string $uuid The applicatie UUID or 'consumer'.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function show(string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        if ($uuid === 'consumer') {
            return $this->index();
        }

        if ($this->zgwService->getConsumerMapper() === null) {
            return new JSONResponse(
                data: ['detail' => 'Consumer service not available'],
                statusCode: Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        try {
            $consumer = $this->findConsumerByUuid(uuid: $uuid);
            if ($consumer === null) {
                return new JSONResponse(
                    data: ['detail' => 'Not found'],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            $baseUrl = $this->zgwService->buildBaseUrl($this->request, 'autorisaties', 'applicaties');
            $mapped  = $this->consumerToApplicatie(consumer: $consumer, baseUrl: $baseUrl);

            return new JSONResponse(data: $mapped);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error('AC show error: '.$e->getMessage(), ['exception' => $e]);

            return new JSONResponse(
                data: ['detail' => 'Not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end show()

    /**
     * Full update (PUT) an applicatie by UUID.
     *
     * @param string $uuid The applicatie UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function update(string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        if ($this->zgwService->getConsumerMapper() === null) {
            return new JSONResponse(
                data: ['detail' => 'Consumer service not available'],
                statusCode: Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        try {
            $consumer = $this->findConsumerByUuid(uuid: $uuid);
            if ($consumer === null) {
                return new JSONResponse(
                    data: ['detail' => 'Not found'],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            $body         = $this->zgwService->getRequestBody($this->request);
            $consumerData = $this->applicatieToConsumer(body: $body);

            foreach ($consumerData as $key => $value) {
                $setter = 'set'.ucfirst($key);
                if (method_exists($consumer, $setter) === true) {
                    $consumer->$setter($value);
                }
            }

            $updated = $this->zgwService->getConsumerMapper()->update(entity: $consumer);
            $baseUrl = $this->zgwService->buildBaseUrl($this->request, 'autorisaties', 'applicaties');
            $mapped  = $this->consumerToApplicatie(consumer: $updated, baseUrl: $baseUrl);

            return new JSONResponse(data: $mapped);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error('AC update error: '.$e->getMessage(), ['exception' => $e]);

            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end update()

    /**
     * Partial update (PATCH) an applicatie by UUID.
     *
     * @param string $uuid The applicatie UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function patch(string $uuid): JSONResponse
    {
        return $this->update(uuid: $uuid);
    }//end patch()

    /**
     * Delete an applicatie by UUID.
     *
     * @param string $uuid The applicatie UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function destroy(string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        if ($this->zgwService->getConsumerMapper() === null) {
            return new JSONResponse(
                data: ['detail' => 'Consumer service not available'],
                statusCode: Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        try {
            $consumer = $this->findConsumerByUuid(uuid: $uuid);
            if ($consumer === null) {
                return new JSONResponse(
                    data: ['detail' => 'Not found'],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            $this->zgwService->getConsumerMapper()->delete(entity: $consumer);

            return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error('AC delete error: '.$e->getMessage(), ['exception' => $e]);

            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }//end destroy()

    /**
     * Find a consumer entity by its UUID.
     *
     * @param string $uuid The consumer UUID.
     *
     * @return object|null The consumer entity, or null if not found.
     */
    private function findConsumerByUuid(string $uuid): ?object
    {
        $consumers = $this->zgwService->getConsumerMapper()->findAll(filters: ['uuid' => $uuid]);
        if (count($consumers) === 0) {
            return null;
        }

        return $consumers[0];
    }//end findConsumerByUuid()

    /**
     * Validate an applicatie request body against AC business rules.
     *
     * Checks ac-001 (clientId uniqueness), ac-002 (heeftAlleAutorisaties
     * consistency), and ac-003 (scope-based field requirements).
     *
     * @param array       $body        The request body.
     * @param string|null $excludeUuid UUID to exclude from uniqueness check (for updates).
     *
     * @return JSONResponse|null Validation error response or null if valid.
     */
    private function validateApplicatieBody(array $body, ?string $excludeUuid=null): ?JSONResponse
    {
        // Ac-002: Check heeftAlleAutorisaties consistency (before uniqueness).
        $authConsistencyError = $this->validateAutorisatieConsistency(body: $body);
        if ($authConsistencyError !== null) {
            return $authConsistencyError;
        }

        // Ac-003: Check scope-based field requirements (before uniqueness).
        $scopeError = $this->validateAutorisatieScopes(body: $body);
        if ($scopeError !== null) {
            return $scopeError;
        }

        // Ac-001: Check clientId uniqueness (after content validation).
        $clientIdError = $this->validateClientIdUniqueness(body: $body, excludeUuid: $excludeUuid);
        if ($clientIdError !== null) {
            return $clientIdError;
        }

        return null;
    }//end validateApplicatieBody()

    /**
     * Validate that clientIds are not already used by another applicatie (ac-001).
     *
     * @param array       $body        The request body.
     * @param string|null $excludeUuid UUID to exclude from check (for updates).
     *
     * @return JSONResponse|null Error response or null if valid.
     */
    private function validateClientIdUniqueness(array $body, ?string $excludeUuid=null): ?JSONResponse
    {
        $clientIds = $body['clientIds'] ?? [];
        if (is_array($clientIds) === false || count($clientIds) === 0) {
            return null;
        }

        $allConsumers = $this->zgwService->getConsumerMapper()->findAll();

        foreach ($allConsumers as $consumer) {
            $data = $consumer->jsonSerialize();

            // Skip self when updating.
            if ($excludeUuid !== null && ($data['uuid'] ?? '') === $excludeUuid) {
                continue;
            }

            // Check if any of the existing consumer's clientIds overlap.
            $existingClientIds = $this->getConsumerClientIds(consumer: $consumer);

            foreach ($clientIds as $requestedId) {
                if (in_array($requestedId, $existingClientIds, true) === true) {
                    return new JSONResponse(
                        data: [
                            'invalidParams' => [
                                [
                                    'name'   => 'clientIds',
                                    'code'   => 'clientId-exists',
                                    'reason' => "clientId \"{$requestedId}\" is already used by another applicatie.",
                                ],
                            ],
                        ],
                        statusCode: Http::STATUS_BAD_REQUEST
                    );
                }
            }
        }//end foreach

        return null;
    }//end validateClientIdUniqueness()

    /**
     * Validate heeftAlleAutorisaties consistency with autorisaties (ac-002).
     *
     * If heeftAlleAutorisaties=true, autorisaties must be empty.
     * If heeftAlleAutorisaties=false, autorisaties must be non-empty.
     *
     * @param array $body The request body.
     *
     * @return JSONResponse|null Error response or null if valid.
     */
    private function validateAutorisatieConsistency(array $body): ?JSONResponse
    {
        $heeftAlle    = $body['heeftAlleAutorisaties'] ?? false;
        $autorisaties = $body['autorisaties'] ?? [];

        // Normalize boolean.
        if ($heeftAlle === 'true' || $heeftAlle === '1' || $heeftAlle === 1) {
            $heeftAlle = true;
        } else if ($heeftAlle === 'false' || $heeftAlle === '0' || $heeftAlle === 0) {
            $heeftAlle = false;
        }

        // Ac-002a: heeftAlleAutorisaties=true + non-empty autorisaties.
        if ($heeftAlle === true && is_array($autorisaties) === true && count($autorisaties) > 0) {
            return new JSONResponse(
                data: [
                    'invalidParams' => [
                        [
                            'name'   => 'nonFieldErrors',
                            'code'   => 'ambiguous-authorizations-specified',
                            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                            'reason' => 'Wanneer heeftAlleAutorisaties op true staat, mag autorisaties niet opgegeven worden. Indien heeftAlleAutorisaties false is, dan moet autorisaties opgegeven worden.',
                        ],
                    ],
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        // Ac-002b: heeftAlleAutorisaties=false + empty autorisaties.
        if ($heeftAlle === false
            && is_array($autorisaties) === true
            && count($autorisaties) === 0
            && array_key_exists('autorisaties', $body) === true
        ) {
            return new JSONResponse(
                data: [
                    'invalidParams' => [
                        [
                            'name'   => 'nonFieldErrors',
                            'code'   => 'missing-authorizations',
                            'reason' => 'Wanneer heeftAlleAutorisaties false is, dan moet autorisaties opgegeven worden.',
                        ],
                    ],
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return null;
    }//end validateAutorisatieConsistency()

    /**
     * Validate autorisatie entries have required fields based on component and scope (ac-003).
     *
     * For zrc with scope containing "zaken": requires zaaktype and maxVertrouwelijkheidaanduiding.
     * For drc with scope containing "documenten": requires informatieobjecttype and maxVertrouwelijkheidaanduiding.
     * For brc with scope containing "besluiten": requires besluittype.
     *
     * @param array $body The request body.
     *
     * @return JSONResponse|null Error response or null if valid.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function validateAutorisatieScopes(array $body): ?JSONResponse
    {
        $autorisaties = $body['autorisaties'] ?? [];
        if (is_array($autorisaties) === false) {
            return null;
        }

        $invalidParams = [];

        foreach ($autorisaties as $index => $autorisatie) {
            $component = $autorisatie['component'] ?? '';
            $scopes    = $autorisatie['scopes'] ?? [];

            // Check if any scope relates to the component's domain.
            $hasZakenScope      = $this->scopesContain(scopes: $scopes, keyword: 'zaken');
            $hasDocumentenScope = $this->scopesContain(scopes: $scopes, keyword: 'documenten');

            // Ac-003a/003b: ZRC with zaken-related scope.
            if ($component === 'zrc' && $hasZakenScope === true) {
                $zaaktype = $autorisatie['zaaktype'] ?? null;
                $maxVertr = $autorisatie['maxVertrouwelijkheidaanduiding'] ?? null;

                if ($zaaktype === null || $zaaktype === '') {
                    $invalidParams[] = [
                        'name'   => "autorisaties.{$index}.zaaktype",
                        'code'   => 'required',
                        'reason' => 'zaaktype is verplicht wanneer een scope m.b.t. zaken is opgegeven.',
                    ];
                }

                if ($maxVertr === null || $maxVertr === '') {
                    $invalidParams[] = [
                        'name'   => "autorisaties.{$index}.maxVertrouwelijkheidaanduiding",
                        'code'   => 'required',
                        'reason' => 'maxVertrouwelijkheidaanduiding is verplicht wanneer een scope m.b.t. zaken is opgegeven.',
                    ];
                }
            }//end if

            // Ac-003c/003d: DRC with documenten-related scope.
            if ($component === 'drc' && $hasDocumentenScope === true) {
                $infoType = $autorisatie['informatieobjecttype'] ?? null;
                $maxVertr = $autorisatie['maxVertrouwelijkheidaanduiding'] ?? null;

                if ($infoType === null || $infoType === '') {
                    $invalidParams[] = [
                        'name'   => "autorisaties.{$index}.informatieobjecttype",
                        'code'   => 'required',
                        'reason' => 'informatieobjecttype is verplicht wanneer een scope m.b.t. documenten is opgegeven.',
                    ];
                }

                if ($maxVertr === null || $maxVertr === '') {
                    $invalidParams[] = [
                        'name'   => "autorisaties.{$index}.maxVertrouwelijkheidaanduiding",
                        'code'   => 'required',
                        // phpcs:ignore Generic.Files.LineLength.TooLong
                        'reason' => 'maxVertrouwelijkheidaanduiding is verplicht wanneer een scope m.b.t. documenten is opgegeven.',
                    ];
                }
            }//end if

            // Ac-003e (not tested but included): BRC with besluiten-related scope.
            $hasBesluitenScope = $this->scopesContain(scopes: $scopes, keyword: 'besluiten');
            if ($component === 'brc' && $hasBesluitenScope === true) {
                $besluittype = $autorisatie['besluittype'] ?? null;
                if ($besluittype === null || $besluittype === '') {
                    $invalidParams[] = [
                        'name'   => "autorisaties.{$index}.besluittype",
                        'code'   => 'required',
                        'reason' => 'besluittype is verplicht wanneer een scope m.b.t. besluiten is opgegeven.',
                    ];
                }
            }
        }//end foreach

        if (count($invalidParams) > 0) {
            return new JSONResponse(
                data: ['invalidParams' => $invalidParams],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return null;
    }//end validateAutorisatieScopes()

    /**
     * Check whether any scope in the array contains the given keyword.
     *
     * @param array  $scopes  The scopes array.
     * @param string $keyword The keyword to search for (e.g. 'zaken', 'documenten').
     *
     * @return bool True if any scope contains the keyword.
     */
    private function scopesContain(array $scopes, string $keyword): bool
    {
        foreach ($scopes as $scope) {
            if (is_string($scope) === true && str_contains($scope, $keyword) === true) {
                return true;
            }
        }

        return false;
    }//end scopesContain()

    /**
     * Get all clientIds for a consumer (primary name + any extras).
     *
     * @param object $consumer The consumer entity.
     *
     * @return array List of all clientIds.
     */
    private function getConsumerClientIds(object $consumer): array
    {
        $data      = $consumer->jsonSerialize();
        $clientIds = [];

        // Primary clientId is the consumer name.
        if (isset($data['name']) === true && $data['name'] !== '' && $data['name'] !== null) {
            $clientIds[] = $data['name'];
        }

        // Extra clientIds stored in authorizationConfiguration.
        $authConfig     = $consumer->getAuthorizationConfiguration() ?? [];
        $extraClientIds = $authConfig['clientIds'] ?? [];
        if (is_array($extraClientIds) === true) {
            $clientIds = array_merge($clientIds, $extraClientIds);
        }

        return $clientIds;
    }//end getConsumerClientIds()

    /**
     * Convert a consumer entity to a ZGW applicatie representation.
     *
     * @param object $consumer The consumer entity.
     * @param string $baseUrl  The base URL for building resource URLs.
     *
     * @return array The ZGW applicatie array.
     */
    private function consumerToApplicatie(object $consumer, string $baseUrl): array
    {
        $authConfig = [];
        if (method_exists($consumer, 'getAuthorizationConfiguration') === true) {
            $authConfig = $consumer->getAuthorizationConfiguration() ?? [];
        }

        $data = $consumer->jsonSerialize();

        // Build clientIds: primary name + any extras from authConfig.
        $clientIds = [];
        if (isset($data['name']) === true && $data['name'] !== '' && $data['name'] !== null) {
            $clientIds[] = $data['name'];
        }

        $extraClientIds = $authConfig['clientIds'] ?? [];
        if (is_array($extraClientIds) === true) {
            $clientIds = array_merge($clientIds, $extraClientIds);
        }

        // Ensure clientIds is never empty.
        if (count($clientIds) === 0) {
            $clientIds = [$data['name'] ?? ''];
        }

        return [
            'url'                   => $baseUrl.'/'.$data['uuid'],
            'uuid'                  => $data['uuid'],
            'clientIds'             => $clientIds,
            'label'                 => ($data['description'] ?? ''),
            'heeftAlleAutorisaties' => ($authConfig['superuser'] ?? false),
            'autorisaties'          => ($authConfig['scopes'] ?? []),
        ];
    }//end consumerToApplicatie()

    /**
     * Convert a ZGW applicatie request body to consumer data.
     *
     * Stores the first clientId as the consumer name and any additional
     * clientIds in the authorizationConfiguration.
     *
     * @param array $body The request body.
     *
     * @return array The consumer data array.
     */
    private function applicatieToConsumer(array $body): array
    {
        $clientIds = $body['clientIds'] ?? [];
        if (is_array($clientIds) === true && count($clientIds) > 0) {
            $name           = $clientIds[0];
            $extraClientIds = array_slice($clientIds, 1);
        } else {
            $name           = ($body['label'] ?? 'unknown');
            $extraClientIds = [];
        }

        $authConfig = [
            'superuser' => ($body['heeftAlleAutorisaties'] ?? false),
            'scopes'    => ($body['autorisaties'] ?? []),
            'algorithm' => 'HS256',
        ];

        // Store extra clientIds beyond the first.
        if (count($extraClientIds) > 0) {
            $authConfig['clientIds'] = $extraClientIds;
        }

        if (isset($body['secret']) === true) {
            $authConfig['publicKey'] = $body['secret'];
        }

        return [
            'name'                       => $name,
            'description'                => ($body['label'] ?? ''),
            'authorizationType'          => 'jwt-zgw',
            'authorizationConfiguration' => $authConfig,
        ];
    }//end applicatieToConsumer()
}//end class
