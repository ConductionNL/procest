<?php

/**
 * Procest ZRC (Zaken Register) Controller
 *
 * Handles the ZGW Zaken register API endpoints: zaken, statussen, resultaten,
 * rollen, zaakeigenschappen, zaakinformatieobjecten, zaakobjecten, klantcontacten.
 *
 * Delegates shared operations to ZgwService while implementing ZRC-specific
 * behaviour such as zaak-closed resolution, eindstatus side effects,
 * authorization-based filtering (zrc-006), and OIO cross-register sync (zrc-005).
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
 * ZRC (Zaken Register) Controller
 *
 * Serves ZGW-compliant Zaken API endpoints on top of English-language
 * OpenRegister data with bidirectional mapping.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class ZrcController extends Controller
{

    /**
     * The ZGW API group for this controller.
     *
     * @var string
     */
    private const ZGW_API = 'zaken';

    /**
     * Ordered vertrouwelijkheidaanduiding levels for authorization filtering.
     *
     * @var array<string, int>
     */
    private const VERTROUWELIJKHEID_LEVELS = [
        'openbaar'          => 1,
        'beperkt_openbaar'  => 2,
        'intern'            => 3,
        'zaakvertrouwelijk' => 4,
        'vertrouwelijk'     => 5,
        'confidentieel'     => 6,
        'geheim'            => 7,
        'zeer_geheim'       => 8,
    ];

    /**
     * Constructor.
     *
     * @param string     $appName    The application name
     * @param IRequest   $request    The incoming request
     * @param ZgwService $zgwService The shared ZGW service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ZgwService $zgwService,
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * List resources.
     *
     * ZRC-specific: for zaken, applies authorization-based filtering (zrc-006a).
     *
     * @param string $resource The ZGW resource name
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function index(string $resource): JSONResponse
    {
        $response = $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);

        // zrc-006a: Filter zaken results based on consumer's vertrouwelijkheidaanduiding.
        if ($resource === 'zaken' && $response->getStatus() === Http::STATUS_OK) {
            $response = $this->filterZakenByAuthorisation($response);
        }

        return $response;
    }//end index()

    /**
     * Create a resource.
     *
     * ZRC-specific: resolves zaak-closed from the request body before validation,
     * triggers eindstatus side effects when creating statussen, checks scopes
     * for zaken creation (zrc-006c), and syncs OIO for zaakinformatieobjecten (zrc-005a).
     *
     * @param string $resource The ZGW resource name
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function create(string $resource): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        // zrc-006c: Check zaken.aanmaken scope for zaak creation.
        if ($resource === 'zaken') {
            $hasScope = $this->zgwService->consumerHasScope(
                $this->request,
                'zrc',
                'zaken.aanmaken'
            );
            if ($hasScope === false) {
                return $this->permissionDeniedResponse();
            }
        }

        if ($this->zgwService->getObjectService() === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
        }

        try {
            $body         = $this->zgwService->getRequestBody($this->request);
            $originalBody = $body;

            // ZRC-specific: resolve zaak closed from body before validation.
            $zaakClosed    = $this->zgwService->resolveZaakClosedFromBody($resource, $body);
            $hasGeforceerd = $zaakClosed === true ? $this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.geforceerd-bijwerken') : true;

            $ruleResult = $this->zgwService->getBusinessRulesService()->validate(
                zgwApi: self::ZGW_API,
                resource: $resource,
                action: 'create',
                body: $body,
                objectService: $this->zgwService->getObjectService(),
                mappingConfig: $mappingConfig,
                zaakClosed: $zaakClosed,
                hasGeforceerd: $hasGeforceerd
            );
            if ($ruleResult['valid'] === false) {
                return new JSONResponse(
                    data: $this->zgwService->buildValidationError($ruleResult),
                    statusCode: $ruleResult['status']
                );
            }

            $body = $ruleResult['enrichedBody'];

            $inboundMapping = $this->zgwService->createInboundMapping(mappingConfig: $mappingConfig);
            $englishData    = $this->zgwService->applyInboundMapping(
                body: $body,
                mapping: $inboundMapping,
                mappingConfig: $mappingConfig
            );

            if (is_array($englishData) === false) {
                return new JSONResponse(
                    data: ['detail' => 'Invalid mapping result'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            $object     = $this->zgwService->getObjectService()->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $englishData
            );
            $objectData = is_array($object) ? $object : $object->jsonSerialize();
            $objectUuid = $objectData['id'] ?? ($objectData['@self']['id'] ?? '');

            // ZRC-specific: handle eindstatus / heropenen effect for statussen.
            if ($resource === 'statussen') {
                $this->handleEindstatusEffect($originalBody, $objectData);
            }

            $baseUrl         = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
            $outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
            $mapped          = $this->zgwService->applyOutboundMapping(
                objectData: $objectData,
                mapping: $outboundMapping,
                mappingConfig: $mappingConfig,
                baseUrl: $baseUrl
            );

            // zrc-004a/zrc-005a: ZaakInformatieObject enrichment and OIO sync.
            if ($resource === 'zaakinformatieobjecten') {
                // zrc-004a: Ensure aardRelatieWeergave and registratiedatum in response.
                $mapped = $this->enrichZioResponse($mapped, $body);

                // zrc-005a: Create ObjectInformatieObject in DRC.
                $zaakUrl = $originalBody['zaak'] ?? ($body['zaak'] ?? '');
                $ioUrl   = $originalBody['informatieobject'] ?? ($body['informatieobject'] ?? '');
                $this->syncCreateObjectInformatieObject($zaakUrl, $ioUrl);
            }

            $this->zgwService->publishNotification(
                self::ZGW_API,
                $resource,
                $baseUrl.'/'.$objectUuid,
                'create'
            );

            return new JSONResponse(data: $mapped, statusCode: Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'ZRC create error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end create()

    /**
     * Show a specific resource.
     *
     * ZRC-specific: for zaken, checks zaken.lezen scope and vertrouwelijkheidaanduiding (zrc-006b).
     *
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function show(string $resource, string $uuid): JSONResponse
    {
        // zrc-006b: Check zaken.lezen scope and vertrouwelijkheidaanduiding.
        if ($resource === 'zaken') {
            $authError = $this->zgwService->validateJwtAuth($this->request);
            if ($authError !== null) {
                return $authError;
            }

            $scopeError = $this->checkZaakReadAccess($uuid);
            if ($scopeError !== null) {
                return $scopeError;
            }
        }

        return $this->zgwService->handleShow($this->request, self::ZGW_API, $resource, $uuid);
    }//end show()

    /**
     * Full update a resource.
     *
     * ZRC-specific: resolves zaak-closed from existing data before delegating.
     *
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function update(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        [$zaakClosed, $hasGeforceerd] = $this->resolveZaakClosedForExisting($resource, $uuid);

        $response = $this->zgwService->handleUpdate(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            false,
            null,
            $zaakClosed,
            $hasGeforceerd
        );

        // zrc-004b: Enrich ZIO response with immutable aardRelatieWeergave.
        if ($resource === 'zaakinformatieobjecten' && $response->getStatus() === Http::STATUS_OK) {
            $response = $this->enrichZioJsonResponse($response);
        }

        return $response;
    }//end update()

    /**
     * Partial update a resource.
     *
     * ZRC-specific: resolves zaak-closed from existing data before delegating.
     *
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function patch(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        [$zaakClosed, $hasGeforceerd] = $this->resolveZaakClosedForExisting($resource, $uuid);

        $response = $this->zgwService->handleUpdate(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            true,
            null,
            $zaakClosed,
            $hasGeforceerd
        );

        // zrc-004c: Enrich ZIO response with immutable aardRelatieWeergave.
        if ($resource === 'zaakinformatieobjecten' && $response->getStatus() === Http::STATUS_OK) {
            $response = $this->enrichZioJsonResponse($response);
        }

        return $response;
    }//end patch()

    /**
     * Delete a resource.
     *
     * ZRC-specific: resolves zaak-closed from existing data before delegating.
     * For zaakinformatieobjecten, syncs ObjectInformatieObject deletion in DRC (zrc-005b).
     *
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function destroy(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        // zrc-023: Cascade delete for zaken.
        if ($resource === 'zaken') {
            return $this->destroyZaak($uuid);
        }

        // zrc-005b: Before deleting, capture ZIO data for OIO cleanup.
        $zioData = null;
        if ($resource === 'zaakinformatieobjecten') {
            $zioData = $this->getZioDataForOioSync($uuid);
        }

        [$zaakClosed, $hasGeforceerd] = $this->resolveZaakClosedForExisting($resource, $uuid);

        $response = $this->zgwService->handleDestroy(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            null,
            $zaakClosed,
            $hasGeforceerd
        );

        // zrc-005b: If ZIO deletion succeeded, also delete the OIO in DRC.
        if ($resource === 'zaakinformatieobjecten'
            && $response->getStatus() === Http::STATUS_NO_CONTENT
            && $zioData !== null
        ) {
            $this->syncDeleteObjectInformatieObject(
                $zioData['zaakUrl'],
                $zioData['ioUrl']
            );
        }

        return $response;
    }//end destroy()

    /**
     * List zaakeigenschappen for a zaak.
     *
     * @param string $zaakUuid The zaak UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function zaakeigenschappenIndex(string $zaakUuid): JSONResponse
    {
        return $this->index(resource: 'zaakeigenschappen');
    }//end zaakeigenschappenIndex()

    /**
     * Create a zaakeigenschap for a zaak.
     *
     * @param string $zaakUuid The zaak UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function zaakeigenschappenCreate(string $zaakUuid): JSONResponse
    {
        return $this->create(resource: 'zaakeigenschappen');
    }//end zaakeigenschappenCreate()

    /**
     * Show a specific zaakeigenschap.
     *
     * @param string $zaakUuid The zaak UUID
     * @param string $uuid     The zaakeigenschap UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function zaakeigenschappenShow(string $zaakUuid, string $uuid): JSONResponse
    {
        return $this->show(resource: 'zaakeigenschappen', uuid: $uuid);
    }//end zaakeigenschappenShow()

    /**
     * Update a zaakeigenschap.
     *
     * @param string $zaakUuid The zaak UUID
     * @param string $uuid     The zaakeigenschap UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function zaakeigenschappenUpdate(string $zaakUuid, string $uuid): JSONResponse
    {
        return $this->update(resource: 'zaakeigenschappen', uuid: $uuid);
    }//end zaakeigenschappenUpdate()

    /**
     * Partial update a zaakeigenschap.
     *
     * @param string $zaakUuid The zaak UUID
     * @param string $uuid     The zaakeigenschap UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function zaakeigenschappenPatch(string $zaakUuid, string $uuid): JSONResponse
    {
        return $this->patch(resource: 'zaakeigenschappen', uuid: $uuid);
    }//end zaakeigenschappenPatch()

    /**
     * Delete a zaakeigenschap.
     *
     * @param string $zaakUuid The zaak UUID
     * @param string $uuid     The zaakeigenschap UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function zaakeigenschappenDestroy(string $zaakUuid, string $uuid): JSONResponse
    {
        return $this->destroy(resource: 'zaakeigenschappen', uuid: $uuid);
    }//end zaakeigenschappenDestroy()

    /**
     * List zaakbesluiten for a zaak.
     *
     * @param string $zaakUuid The zaak UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function zaakbesluitenIndex(string $zaakUuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        if ($this->zgwService->getObjectService() === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->loadMappingConfig('besluiten', 'besluiten');
        if ($mappingConfig === null) {
            return new JSONResponse(
                data: ['detail' => 'Besluit mapping not configured'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['case' => $zaakUuid],
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            $baseUrl         = $this->zgwService->buildBaseUrl($this->request, 'besluiten', 'besluiten');
            $outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
            $mapped          = [];
            foreach (($result['results'] ?? []) as $object) {
                $objectData = is_array($object) ? $object : $object->jsonSerialize();
                $mapped[]   = $this->zgwService->applyOutboundMapping(
                    objectData: $objectData,
                    mapping: $outboundMapping,
                    mappingConfig: $mappingConfig,
                    baseUrl: $baseUrl
                );
            }

            return new JSONResponse(data: $mapped);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'ZRC zaakbesluiten error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => 'Internal server error'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end zaakbesluitenIndex()

    /**
     * Search zaken (POST /zaken/v1/zaken/_zoek).
     *
     * Delegates to index and returns HTTP 201 per the ZGW specification.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function zoek(): JSONResponse
    {
        $response = $this->index(resource: 'zaken');
        $response->setStatus(Http::STATUS_CREATED);

        return $response;
    }//end zoek()

    /**
     * Get audit trail for a resource.
     *
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function audittrailIndex(string $resource, string $uuid): JSONResponse
    {
        return $this->zgwService->handleAudittrailIndex($this->request, self::ZGW_API, $resource, $uuid);
    }//end audittrailIndex()

    /**
     * Get a specific audit trail entry.
     *
     * @param string $resource  The ZGW resource name
     * @param string $uuid      The resource UUID
     * @param string $auditUuid The audit trail entry UUID
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function audittrailShow(string $resource, string $uuid, string $auditUuid): JSONResponse
    {
        return $this->zgwService->handleAudittrailShow($this->request, self::ZGW_API, $resource, $uuid, $auditUuid);
    }//end audittrailShow()

    /**
     * Check zaak read access based on consumer scopes and vertrouwelijkheidaanduiding (zrc-006b).
     *
     * @param string $uuid The zaak UUID
     *
     * @return JSONResponse|null Permission denied response, or null if access is allowed
     */
    private function checkZaakReadAccess(string $uuid): ?JSONResponse
    {
        $autorisaties = $this->zgwService->getConsumerAuthorisaties($this->request, 'zrc');
        if ($autorisaties === null) {
            // Unrestricted (superuser or no consumer found).
            return null;
        }

        // Check if any autorisatie grants zaken.lezen.
        $hasLezenScope = false;
        foreach ($autorisaties as $auth) {
            $scopes = $auth['scopes'] ?? [];
            if (in_array('zaken.lezen', $scopes, true) === true) {
                $hasLezenScope = true;
                break;
            }
        }

        if ($hasLezenScope === false) {
            return $this->permissionDeniedResponse();
        }

        // Check vertrouwelijkheidaanduiding of the zaak.
        try {
            $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaken');
            if ($mappingConfig === null) {
                return null;
            }

            $zaakObj  = $this->zgwService->getObjectService()->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $zaakData = is_array($zaakObj) ? $zaakObj : $zaakObj->jsonSerialize();

            $zaakVa    = $zaakData['confidentiality'] ?? ($zaakData['vertrouwelijkheidaanduiding'] ?? 'openbaar');
            $zaakLevel = self::VERTROUWELIJKHEID_LEVELS[$zaakVa] ?? 1;

            // Check zaaktype + maxVertrouwelijkheidaanduiding from consumer autorisaties.
            $zaakTypeUuid = $zaakData['caseType'] ?? ($zaakData['zaaktype'] ?? '');

            foreach ($autorisaties as $auth) {
                $scopes = $auth['scopes'] ?? [];
                if (in_array('zaken.lezen', $scopes, true) === false) {
                    continue;
                }

                $maxVa    = $auth['maxVertrouwelijkheidaanduiding'] ?? ($auth['max_vertrouwelijkheidaanduiding'] ?? null);
                $maxLevel = $maxVa !== null ? (self::VERTROUWELIJKHEID_LEVELS[$maxVa] ?? 99) : 99;

                if ($zaakLevel <= $maxLevel) {
                    return null;
                }
            }

            // No matching autorisatie allows this vertrouwelijkheidaanduiding.
            return $this->permissionDeniedResponse();
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->debug(
                'zrc-006b: Could not check zaak read access: '.$e->getMessage()
            );
            return null;
        }//end try
    }//end checkZaakReadAccess()

    /**
     * Filter zaken results based on consumer's vertrouwelijkheidaanduiding (zrc-006a).
     *
     * @param JSONResponse $response The original index response
     *
     * @return JSONResponse The filtered response
     */
    private function filterZakenByAuthorisation(JSONResponse $response): JSONResponse
    {
        $autorisaties = $this->zgwService->getConsumerAuthorisaties($this->request, 'zrc');
        if ($autorisaties === null) {
            // Unrestricted — return all.
            return $response;
        }

        // Check if any autorisatie grants zaken.lezen.
        $lezenAuths = [];
        foreach ($autorisaties as $auth) {
            $scopes = $auth['scopes'] ?? [];
            if (in_array('zaken.lezen', $scopes, true) === true) {
                $lezenAuths[] = $auth;
            }
        }

        if (empty($lezenAuths) === true) {
            // No zaken.lezen scope at all — return empty.
            $data = $response->getData();
            if (is_array($data) === true) {
                $data['count']   = 0;
                $data['results'] = [];
                $response->setData($data);
            }

            return $response;
        }

        $data = $response->getData();
        if (is_array($data) === false || isset($data['results']) === false) {
            return $response;
        }

        $filtered = [];
        foreach ($data['results'] as $zaak) {
            $zaakVa    = $zaak['vertrouwelijkheidaanduiding'] ?? 'openbaar';
            $zaakLevel = self::VERTROUWELIJKHEID_LEVELS[$zaakVa] ?? 1;

            foreach ($lezenAuths as $auth) {
                $maxVa    = $auth['maxVertrouwelijkheidaanduiding'] ?? ($auth['max_vertrouwelijkheidaanduiding'] ?? null);
                $maxLevel = $maxVa !== null ? (self::VERTROUWELIJKHEID_LEVELS[$maxVa] ?? 99) : 99;

                if ($zaakLevel <= $maxLevel) {
                    $filtered[] = $zaak;
                    break;
                }
            }
        }

        $data['count']   = count($filtered);
        $data['results'] = $filtered;
        $response->setData($data);

        return $response;
    }//end filterZakenByAuthorisation()

    /**
     * Build a permission denied response (zrc-006/zrc-007).
     *
     * @return JSONResponse
     */
    private function permissionDeniedResponse(): JSONResponse
    {
        return new JSONResponse(
            data: [
                'detail' => 'U heeft niet de juiste rechten voor deze actie.',
                'code'   => 'permission_denied',
            ],
            statusCode: Http::STATUS_FORBIDDEN
        );
    }//end permissionDeniedResponse()

    /**
     * Delete a zaak with cascade delete of all sub-resources (zrc-023).
     *
     * Deletes: statussen, resultaten, rollen, zaakeigenschappen,
     * zaakinformatieobjecten (+ OIO sync), zaakobjecten.
     *
     * @param string $uuid The zaak UUID to delete
     *
     * @return JSONResponse
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function destroyZaak(string $uuid): JSONResponse
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaken');
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, 'zaken');
        }

        try {
            // Verify the zaak exists.
            $objectService->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['detail' => 'Not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        // zrc-023: Cascade delete sub-resources.
        $subResources = [
            'status'               => 'status_schema',
            'resultaat'            => 'result_schema',
            'rol'                  => 'role_schema',
            'zaakeigenschap'       => 'case_property_schema',
            'zaakinformatieobject' => 'case_document_schema',
            'zaakobject'           => 'case_object_schema',
        ];

        foreach ($subResources as $mappingKey => $schemaKey) {
            $subConfig = $this->zgwService->getZgwMappingService()->getMapping($mappingKey);
            if ($subConfig === null) {
                continue;
            }

            try {
                $query  = $objectService->buildSearchQuery(
                    requestParams: ['case' => $uuid, '_limit' => 100],
                    register: $subConfig['sourceRegister'],
                    schema: $subConfig['sourceSchema']
                );
                $result = $objectService->searchObjectsPaginated(query: $query);

                foreach (($result['results'] ?? []) as $obj) {
                    $data    = is_array($obj) ? $obj : $obj->jsonSerialize();
                    $subUuid = $data['id'] ?? ($data['@self']['id'] ?? '');
                    if ($subUuid === '') {
                        continue;
                    }

                    // zrc-005b: For ZIOs, also delete the OIO in DRC.
                    if ($mappingKey === 'zaakinformatieobject') {
                        $zioData = $this->getZioDataForOioSync($subUuid);
                        if ($zioData !== null) {
                            $this->syncDeleteObjectInformatieObject(
                                $zioData['zaakUrl'],
                                $zioData['ioUrl']
                            );
                        }
                    }

                    $objectService->deleteObject(uuid: $subUuid);
                }
            } catch (\Throwable $e) {
                $this->zgwService->getLogger()->warning(
                    'zrc-023: Failed to cascade delete '.$mappingKey.' for zaak '.$uuid.': '.$e->getMessage()
                );
            }//end try
        }//end foreach

        // Delete the zaak itself.
        try {
            $objectService->deleteObject(uuid: $uuid);
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['detail' => 'Failed to delete zaak: '.$e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, 'zaken');
        $this->zgwService->publishNotification(
            self::ZGW_API,
            'zaken',
            $baseUrl.'/'.$uuid,
            'destroy'
        );

        $this->zgwService->getLogger()->info(
            'zrc-023: Cascade deleted zaak '.$uuid.' with all sub-resources'
        );

        return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
    }//end destroyZaak()

    /**
     * Resolve zaak-closed state and geforceerd scope for an existing resource.
     *
     * @param string $resource The ZGW resource name
     * @param string $uuid     The resource UUID
     *
     * @return array{0: ?bool, 1: bool} [zaakClosed, hasGeforceerd]
     */
    private function resolveZaakClosedForExisting(string $resource, string $uuid): array
    {
        $zaakClosed    = null;
        $hasGeforceerd = true;

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
        if ($mappingConfig !== null && $this->zgwService->getObjectService() !== null) {
            try {
                $existingObj   = $this->zgwService->getObjectService()->find(
                    $uuid,
                    register: $mappingConfig['sourceRegister'],
                    schema: $mappingConfig['sourceSchema']
                );
                $existingData  = is_array($existingObj) ? $existingObj : $existingObj->jsonSerialize();
                $zaakClosed    = $this->zgwService->resolveZaakClosed($resource, $existingData);
                $hasGeforceerd = $zaakClosed === true ? $this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.geforceerd-bijwerken') : true;
            } catch (\Throwable $e) {
                // Proceed without zaak closed info.
                $this->zgwService->getLogger()->debug(
                    'Could not resolve zaakClosed for '.$resource.'/'.$uuid.': '.$e->getMessage()
                );
            }//end try
        }//end if

        return [$zaakClosed, $hasGeforceerd];
    }//end resolveZaakClosedForExisting()

    /**
     * Handle eindstatus side effect when creating a status.
     *
     * When the created status's statustype has isEindstatus=true, sets the
     * parent zaak's einddatum to the datumStatusGezet value.
     *
     * @param array $body       The original request body
     * @param array $objectData The created object data
     *
     * @return void
     */
    private function handleEindstatusEffect(array $body, array $objectData): void
    {
        try {
            $statustypeUrl = $body['statustype'] ?? '';
            if ($statustypeUrl === '') {
                return;
            }

            $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
            if (preg_match($uuidPattern, $statustypeUrl, $matches) !== 1) {
                return;
            }

            $stConfig = $this->zgwService->getZgwMappingService()->getMapping('statustype');
            if ($stConfig === null) {
                return;
            }

            $statustype = $this->zgwService->getObjectService()->find(
                $matches[1],
                register: $stConfig['sourceRegister'],
                schema: $stConfig['sourceSchema']
            );
            if ($statustype === null) {
                return;
            }

            $stData       = is_array($statustype) ? $statustype : $statustype->jsonSerialize();
            $isEindstatus = $stData['isFinal'] ?? ($stData['isFinalStatus'] ?? ($stData['isEindstatus'] ?? false));

            if ($isEindstatus === 'true' || $isEindstatus === '1' || $isEindstatus === 1) {
                $isEindstatus = true;
            }

            $zaakUrl = $body['zaak'] ?? '';
            if ($zaakUrl === '') {
                return;
            }

            if (preg_match($uuidPattern, $zaakUrl, $zaakMatches) !== 1) {
                return;
            }

            $zaakConfig = $this->zgwService->getZgwMappingService()->getMapping('zaak');
            if ($zaakConfig === null) {
                return;
            }

            $zaak = $this->zgwService->getObjectService()->find(
                $zaakMatches[1],
                register: $zaakConfig['sourceRegister'],
                schema: $zaakConfig['sourceSchema']
            );
            if ($zaak === null) {
                return;
            }

            $zaakData = is_array($zaak) ? $zaak : $zaak->jsonSerialize();

            // Strip metadata that confuses saveObject on re-save.
            unset($zaakData['@self'], $zaakData['organisation']);

            if ($isEindstatus === true) {
                // zrc-007: Set zaak einddatum when eindstatus is created.
                $datumStatusGezet = $body['datumStatusGezet'] ?? ($objectData['statusSetDate'] ?? date('Y-m-d'));
                if (strlen($datumStatusGezet) > 10) {
                    $datumStatusGezet = substr($datumStatusGezet, 0, 10);
                }

                $zaakData['endDate'] = $datumStatusGezet;

                // zrc-021: Derive archiefactiedatum from resultaat.resultaattype.brondatumArchiefprocedure.
                $zaakData = $this->deriveArchiefactiedatum($zaakData, $zaakConfig, $datumStatusGezet);

                $zaakData['id'] = $zaakMatches[1];
                $this->zgwService->getObjectService()->saveObject(
                    register: $zaakConfig['sourceRegister'],
                    schema: $zaakConfig['sourceSchema'],
                    object: $zaakData,
                    uuid: $zaakMatches[1]
                );

                $this->zgwService->getLogger()->info(
                    "Set zaak einddatum to {$datumStatusGezet} after eindstatus creation"
                );
            } else {
                // zrc-008: Heropenen zaak — when a non-eindstatus is created on
                // a zaak that already has an endDate, clear endDate, archiefactiedatum,
                // and archiefnominatie (reopen the zaak).
                $existingEndDate = $zaakData['endDate'] ?? null;
                if ($existingEndDate !== null && $existingEndDate !== '') {
                    $zaakData['endDate']           = '';
                    $zaakData['archiveActionDate'] = '';
                    $zaakData['archiveNomination'] = '';
                    $zaakData['id'] = $zaakMatches[1];
                    $this->zgwService->getObjectService()->saveObject(
                        register: $zaakConfig['sourceRegister'],
                        schema: $zaakConfig['sourceSchema'],
                        object: $zaakData,
                        uuid: $zaakMatches[1]
                    );

                    $this->zgwService->getLogger()->info(
                        'zrc-008: Heropened zaak '.$zaakMatches[1].' — cleared endDate, archiveActionDate, archiveNomination'
                    );
                }
            }//end if
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'handleEindstatusEffect failed: '.$e->getMessage()
            );
        }//end try
    }//end handleEindstatusEffect()

    /**
     * Derive archiefactiedatum from resultaat's resultaattype brondatumArchiefprocedure (zrc-021).
     *
     * Supported afleidingswijze values:
     * - afgehandeld: archiefactiedatum = einddatum + procestermijn
     * - hoofdzaak: archiefactiedatum = hoofdzaak.einddatum + procestermijn
     * - eigenschap: archiefactiedatum = zaakeigenschap value + procestermijn
     * - ander_datumkenmerk: archiefactiedatum = einddatum (custom, same as afgehandeld)
     * - zaakobject: not implemented (requires external object resolution)
     * - termijn: archiefactiedatum = einddatum + procestermijn
     * - ingangsdatum_besluit: archiefactiedatum = besluit.ingangsdatum + procestermijn
     * - vervaldatum_besluit: archiefactiedatum = besluit.vervaldatum + procestermijn
     *
     * @param array  $zaakData         The zaak data
     * @param array  $zaakConfig       The zaak mapping config
     * @param string $datumStatusGezet The datumStatusGezet (einddatum)
     *
     * @return array The zaak data with derived archiving parameters
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function deriveArchiefactiedatum(array $zaakData, array $zaakConfig, string $datumStatusGezet): array
    {
        try {
            // Find the zaak's resultaat to get the resultaattype.
            $zaakUuid = $zaakData['id'] ?? ($zaakData['@self']['id'] ?? '');
            if ($zaakUuid === '') {
                return $zaakData;
            }

            $resultaatConfig = $this->zgwService->getZgwMappingService()->getMapping('resultaat');
            if ($resultaatConfig === null) {
                return $zaakData;
            }

            // Search for resultaat linked to this zaak.
            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['case' => $zaakUuid, '_limit' => 1],
                register: $resultaatConfig['sourceRegister'],
                schema: $resultaatConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            $results = $result['results'] ?? [];
            if (empty($results) === true) {
                return $zaakData;
            }

            $resultaat     = $results[0];
            $resultaatData = is_array($resultaat) ? $resultaat : $resultaat->jsonSerialize();

            // Get the resultaattype to find brondatumArchiefprocedure.
            $resultaattypeId = $resultaatData['resultType'] ?? ($resultaatData['resultaattype'] ?? '');
            if (empty($resultaattypeId) === true) {
                return $zaakData;
            }

            $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
            if (preg_match($uuidPattern, (string) $resultaattypeId, $rtMatches) !== 1) {
                return $zaakData;
            }

            $rtConfig = $this->zgwService->getZgwMappingService()->getMapping('resultaattype');
            if ($rtConfig === null) {
                return $zaakData;
            }

            $rtObj = $this->zgwService->getObjectService()->find(
                $rtMatches[1],
                register: $rtConfig['sourceRegister'],
                schema: $rtConfig['sourceSchema']
            );
            if ($rtObj === null) {
                return $zaakData;
            }

            $rtData = is_array($rtObj) ? $rtObj : $rtObj->jsonSerialize();

            // Get brondatumArchiefprocedure.
            $brondatum = $rtData['sourceDateArchiveProcedure'] ?? ($rtData['brondatumArchiefprocedure'] ?? null);
            if ($brondatum === null || is_array($brondatum) === false) {
                return $zaakData;
            }

            $afleidingswijze = $brondatum['derivationMethod'] ?? ($brondatum['afleidingswijze'] ?? '');
            $procestermijn   = $brondatum['processDuration'] ?? ($brondatum['procestermijn'] ?? null);

            // Determine the base date based on afleidingswijze.
            $baseDate = $this->resolveArchiveBaseDate(
                $afleidingswijze,
                $datumStatusGezet,
                $zaakData,
                $zaakConfig,
                $brondatum
            );

            if ($baseDate === null) {
                return $zaakData;
            }

            // Add procestermijn (ISO 8601 duration) to the base date.
            $archiefactiedatum = $baseDate;
            if ($procestermijn !== null && $procestermijn !== '') {
                try {
                    $dateObj  = new \DateTime($baseDate);
                    $interval = new \DateInterval($procestermijn);
                    $dateObj->add($interval);
                    $archiefactiedatum = $dateObj->format('Y-m-d');
                } catch (\Throwable $e) {
                    $this->zgwService->getLogger()->debug(
                        'zrc-021: Invalid procestermijn: '.$procestermijn
                    );
                }
            }

            $zaakData['archiveActionDate'] = $archiefactiedatum;

            // zrc-021: Also set archiveNomination from the resultaattype.
            $nomination = $rtData['archivalAction']
                ?? ($rtData['archiveNomination']
                ?? ($rtData['archiefnominatie'] ?? ''));
            if ($nomination !== '') {
                $zaakData['archiveNomination'] = $nomination;
            }

            $this->zgwService->getLogger()->info(
                'zrc-021: Derived archiefactiedatum='.$archiefactiedatum.' (afleidingswijze='.$afleidingswijze.')'
            );
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'zrc-021: Failed to derive archiefactiedatum: '.$e->getMessage()
            );
        }//end try

        return $zaakData;
    }//end deriveArchiefactiedatum()

    /**
     * Resolve the base date for archive action date derivation (zrc-021).
     *
     * @param string $afleidingswijze The derivation method
     * @param string $einddatum       The zaak end date
     * @param array  $zaakData        The zaak data
     * @param array  $zaakConfig      The zaak mapping config
     * @param array  $brondatum       The brondatumArchiefprocedure data
     *
     * @return string|null The base date, or null if not resolvable
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function resolveArchiveBaseDate(
        string $afleidingswijze,
        string $einddatum,
        array $zaakData,
        array $zaakConfig,
        array $brondatum
    ): ?string {
        switch ($afleidingswijze) {
            case 'afgehandeld':
            case 'termijn':
            case 'ander_datumkenmerk':
                return $einddatum;

            case 'hoofdzaak':
                $mainCaseId = $zaakData['parentCase'] ?? ($zaakData['mainCase'] ?? ($zaakData['hoofdzaak'] ?? ''));
                if (empty($mainCaseId) === true) {
                    return $einddatum;
                }

                $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
                if (preg_match($uuidPattern, (string) $mainCaseId, $matches) === 1) {
                    try {
                        $mainZaak = $this->zgwService->getObjectService()->find(
                            $matches[1],
                            register: $zaakConfig['sourceRegister'],
                            schema: $zaakConfig['sourceSchema']
                        );
                        $mainData = is_array($mainZaak) ? $mainZaak : $mainZaak->jsonSerialize();
                        $mainEnd  = $mainData['endDate'] ?? null;
                        if ($mainEnd !== null && $mainEnd !== '') {
                            return is_string($mainEnd) === true ? substr($mainEnd, 0, 10) : $einddatum;
                        }
                    } catch (\Throwable $e) {
                        // Fall through to einddatum.
                    }
                }
                return $einddatum;

            case 'eigenschap':
                $datumkenmerk = $brondatum['objectAttribute'] ?? ($brondatum['datumkenmerk'] ?? '');
                if ($datumkenmerk !== '' && $this->zgwService->getObjectService() !== null) {
                    return $this->resolveEigenschapDate($zaakData, $datumkenmerk) ?? $einddatum;
                }
                return $einddatum;

            case 'ingangsdatum_besluit':
                return $this->resolveBesluitDate($zaakData, 'startDate', 'ingangsdatum') ?? $einddatum;

            case 'vervaldatum_besluit':
                return $this->resolveBesluitDate($zaakData, 'expirationDate', 'vervaldatum') ?? $einddatum;

            default:
                return null;
        }//end switch
    }//end resolveArchiveBaseDate()

    /**
     * Resolve a zaakeigenschap date value for archive derivation (zrc-021 eigenschap).
     *
     * @param array  $zaakData     The zaak data
     * @param string $datumkenmerk The eigenschap name/key to look up
     *
     * @return string|null The date value, or null if not found
     */
    private function resolveEigenschapDate(array $zaakData, string $datumkenmerk): ?string
    {
        $zaakUuid = $zaakData['id'] ?? ($zaakData['@self']['id'] ?? '');
        if ($zaakUuid === '') {
            return null;
        }

        $propConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakeigenschap');
        if ($propConfig === null) {
            return null;
        }

        try {
            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['case' => $zaakUuid, 'name' => $datumkenmerk],
                register: $propConfig['sourceRegister'],
                schema: $propConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            $results = $result['results'] ?? [];
            if (empty($results) === false) {
                $propObj  = $results[0];
                $propData = is_array($propObj) ? $propObj : $propObj->jsonSerialize();
                $value    = $propData['value'] ?? ($propData['waarde'] ?? '');
                if ($value !== '' && strtotime($value) !== false) {
                    return substr($value, 0, 10);
                }
            }
        } catch (\Throwable $e) {
            // Not found — return null.
        }

        return null;
    }//end resolveEigenschapDate()

    /**
     * Resolve a besluit date field for archive derivation (zrc-021 ingangsdatum/vervaldatum).
     *
     * @param array  $zaakData     The zaak data
     * @param string $englishField The English field name
     * @param string $dutchField   The Dutch field name (fallback)
     *
     * @return string|null The date value, or null if not found
     */
    private function resolveBesluitDate(array $zaakData, string $englishField, string $dutchField): ?string
    {
        $zaakUuid = $zaakData['id'] ?? ($zaakData['@self']['id'] ?? '');
        if ($zaakUuid === '') {
            return null;
        }

        $besluitConfig = $this->zgwService->getZgwMappingService()->getMapping('besluit');
        if ($besluitConfig === null) {
            return null;
        }

        try {
            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['case' => $zaakUuid, '_limit' => 1],
                register: $besluitConfig['sourceRegister'],
                schema: $besluitConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            $results = $result['results'] ?? [];
            if (empty($results) === false) {
                $besluitObj  = $results[0];
                $besluitData = is_array($besluitObj) ? $besluitObj : $besluitObj->jsonSerialize();
                $dateVal     = $besluitData[$englishField] ?? ($besluitData[$dutchField] ?? '');
                if ($dateVal !== '' && strtotime($dateVal) !== false) {
                    return substr($dateVal, 0, 10);
                }
            }
        } catch (\Throwable $e) {
            // Not found — return null.
        }

        return null;
    }//end resolveBesluitDate()

    /**
     * Enrich a ZaakInformatieObject outbound-mapped array with aardRelatieWeergave and registratiedatum.
     *
     * @param array $mapped The outbound-mapped data
     * @param array $body   The enriched request body (from business rules)
     *
     * @return array The enriched mapped data
     */
    private function enrichZioResponse(array $mapped, array $body): array
    {
        // zrc-004a: aardRelatieWeergave is always "Hoort bij, omgekeerd: kent".
        $mapped['aardRelatieWeergave'] = 'Hoort bij, omgekeerd: kent';

        // zrc-004a: registratiedatum from the enriched body (set by business rules).
        if (isset($body['registratiedatum']) === true
            && isset($mapped['registratiedatum']) === false
        ) {
            $mapped['registratiedatum'] = $body['registratiedatum'];
        }

        return $mapped;
    }//end enrichZioResponse()

    /**
     * Enrich a ZaakInformatieObject JSONResponse with aardRelatieWeergave (zrc-004b/c).
     *
     * Used for update/patch responses where we intercept the JSONResponse from handleUpdate.
     *
     * @param JSONResponse $response The response to enrich
     *
     * @return JSONResponse The enriched response
     */
    private function enrichZioJsonResponse(JSONResponse $response): JSONResponse
    {
        $data = $response->getData();
        if (is_array($data) === true) {
            $data['aardRelatieWeergave'] = 'Hoort bij, omgekeerd: kent';
            $response->setData($data);
        }

        return $response;
    }//end enrichZioJsonResponse()

    /**
     * Create an ObjectInformatieObject in the DRC when a ZaakInformatieObject is created (zrc-005a).
     *
     * @param string $zaakUrl The zaak URL
     * @param string $ioUrl   The informatieobject URL
     *
     * @return void
     */
    private function syncCreateObjectInformatieObject(string $zaakUrl, string $ioUrl): void
    {
        if ($zaakUrl === '' || $ioUrl === '') {
            return;
        }

        try {
            $oioConfig = $this->zgwService->getZgwMappingService()->getMapping('objectinformatieobject');
            if ($oioConfig === null) {
                $this->zgwService->getLogger()->debug(
                    'zrc-005a: objectinformatieobject mapping not configured'
                );
                return;
            }

            $oioData = [
                'object'           => $zaakUrl,
                'objectType'       => 'zaak',
                'informatieobject' => $ioUrl,
            ];

            $inboundMapping = $this->zgwService->createInboundMapping(mappingConfig: $oioConfig);
            $englishData    = $this->zgwService->applyInboundMapping(
                body: $oioData,
                mapping: $inboundMapping,
                mappingConfig: $oioConfig
            );

            if (is_array($englishData) === false) {
                $englishData = $oioData;
            }

            $this->zgwService->getObjectService()->saveObject(
                register: $oioConfig['sourceRegister'],
                schema: $oioConfig['sourceSchema'],
                object: $englishData
            );

            $this->zgwService->getLogger()->info(
                'zrc-005a: Created ObjectInformatieObject for zaak/io sync'
            );
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'zrc-005a: Failed to create ObjectInformatieObject: '.$e->getMessage()
            );
        }//end try
    }//end syncCreateObjectInformatieObject()

    /**
     * Get ZaakInformatieObject data needed for OIO sync before deletion.
     *
     * @param string $uuid The ZaakInformatieObject UUID
     *
     * @return array|null The zaakUrl and ioUrl, or null if not found
     */
    private function getZioDataForOioSync(string $uuid): ?array
    {
        try {
            $zioConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaakinformatieobjecten');
            if ($zioConfig === null) {
                return null;
            }

            $zioObj  = $this->zgwService->getObjectService()->find(
                $uuid,
                register: $zioConfig['sourceRegister'],
                schema: $zioConfig['sourceSchema']
            );
            $zioData = is_array($zioObj) ? $zioObj : $zioObj->jsonSerialize();

            // Build the zaak URL from the case UUID.
            $zaakUuid = $zioData['case'] ?? ($zioData['zaak'] ?? '');
            $ioUuid   = $zioData['document'] ?? ($zioData['informatieobject'] ?? '');

            if ($zaakUuid === '' || $ioUuid === '') {
                return null;
            }

            // Build full URLs from UUIDs.
            $zaakBaseUrl = $this->zgwService->buildBaseUrl($this->request, 'zaken', 'zaken');
            $ioBaseUrl   = $this->zgwService->buildBaseUrl($this->request, 'documenten', 'enkelvoudiginformatieobjecten');

            return [
                'zaakUrl' => $zaakBaseUrl.'/'.$zaakUuid,
                'ioUrl'   => $ioBaseUrl.'/'.$ioUuid,
            ];
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->debug(
                'zrc-005b: Could not get ZIO data for OIO sync: '.$e->getMessage()
            );
            return null;
        }//end try
    }//end getZioDataForOioSync()

    /**
     * Delete the ObjectInformatieObject in DRC when a ZaakInformatieObject is deleted (zrc-005b).
     *
     * @param string $zaakUrl The zaak URL
     * @param string $ioUrl   The informatieobject URL
     *
     * @return void
     */
    private function syncDeleteObjectInformatieObject(string $zaakUrl, string $ioUrl): void
    {
        try {
            $oioConfig = $this->zgwService->getZgwMappingService()->getMapping('objectinformatieobject');
            if ($oioConfig === null) {
                return;
            }

            // Search for matching OIOs by object (zaak) and informatieobject.
            $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

            // Extract UUIDs for searching in OpenRegister.
            $zaakUuid = '';
            if (preg_match($uuidPattern, $zaakUrl, $zaakM) === 1) {
                $zaakUuid = $zaakM[1];
            }

            $ioUuid = '';
            if (preg_match($uuidPattern, $ioUrl, $ioM) === 1) {
                $ioUuid = $ioM[1];
            }

            if ($zaakUuid === '' || $ioUuid === '') {
                return;
            }

            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['relatedObject' => $zaakUuid, 'document' => $ioUuid],
                register: $oioConfig['sourceRegister'],
                schema: $oioConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            foreach (($result['results'] ?? []) as $oioObj) {
                $oioData = is_array($oioObj) ? $oioObj : $oioObj->jsonSerialize();
                $oioUuid = $oioData['id'] ?? ($oioData['@self']['id'] ?? '');
                if ($oioUuid !== '') {
                    $this->zgwService->getObjectService()->deleteObject(uuid: $oioUuid);
                    $this->zgwService->getLogger()->info(
                        'zrc-005b: Deleted ObjectInformatieObject '.$oioUuid
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'zrc-005b: Failed to delete ObjectInformatieObject: '.$e->getMessage()
            );
        }//end try
    }//end syncDeleteObjectInformatieObject()
}//end class
