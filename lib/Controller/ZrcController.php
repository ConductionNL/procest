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
        parent::__construct(appName: $appName, request: $request);
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

        // Zrc-006a: Filter zaken results based on consumer's vertrouwelijkheidaanduiding.
        if ($resource === 'zaken' && $response->getStatus() === Http::STATUS_OK) {
            $response = $this->filterZakenByAuthorisation(response: $response);
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

        // Zrc-006c: Check zaken.aanmaken scope for zaak creation.
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
            $zaakClosed = $this->zgwService->resolveZaakClosedFromBody($resource, $body);
            if ($zaakClosed === true) {
                $hasGeforceerd = $this->zgwService->consumerHasScope(
                    $this->request,
                    'zrc',
                    'zaken.geforceerd-bijwerken'
                );
            } else {
                $hasGeforceerd = true;
            }

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

            // Zrc-008c: Before saving a status, check if it would reopen a closed zaak
            // and require the zaken.heropenen scope.
            if ($resource === 'statussen') {
                $reopenError = $this->checkReopenScope(originalBody: $originalBody);
                if ($reopenError !== null) {
                    return $reopenError;
                }

                // Zrc-007q: Before adding an eindstatus, verify all linked IOs
                // have indicatieGebruiksrecht set (not null).
                $gebruiksrechtError = $this->checkIndicatieGebruiksrechtBeforeClose(originalBody: $originalBody);
                if ($gebruiksrechtError !== null) {
                    return $gebruiksrechtError;
                }
            }

            $object = $this->zgwService->getObjectService()->saveObject(
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

            // ZRC-specific: handle eindstatus / heropenen effect for statussen.
            if ($resource === 'statussen') {
                $this->handleEindstatusEffect(originalBody: $originalBody, objectData: $objectData);
            }

            // Zrc-021: When a resultaat is created, derive archiefactiedatum
            // and archiefnominatie on the parent zaak from the resultaattype.
            if ($resource === 'resultaten') {
                $this->handleResultaatCreated(originalBody: $originalBody, objectData: $objectData);
            }

            $baseUrl         = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
            $outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
            $mapped          = $this->zgwService->applyOutboundMapping(
                objectData: $objectData,
                mapping: $outboundMapping,
                mappingConfig: $mappingConfig,
                baseUrl: $baseUrl
            );

            // Zrc-004a/zrc-005a: ZaakInformatieObject enrichment and OIO sync.
            if ($resource === 'zaakinformatieobjecten') {
                // Zrc-004a: Ensure aardRelatieWeergave and registratiedatum in response.
                $mapped = $this->enrichZioResponse(mapped: $mapped, body: $body);

                // Zrc-005a: Create ObjectInformatieObject in DRC.
                $zaakUrl = $originalBody['zaak'] ?? ($body['zaak'] ?? '');
                $ioUrl   = $originalBody['informatieobject'] ?? ($body['informatieobject'] ?? '');
                $this->syncCreateObjectInformatieObject(zaakUrl: $zaakUrl, ioUrl: $ioUrl);
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
        // Zrc-006b: Check zaken.lezen scope and vertrouwelijkheidaanduiding.
        if ($resource === 'zaken') {
            $authError = $this->zgwService->validateJwtAuth($this->request);
            if ($authError !== null) {
                return $authError;
            }

            $scopeError = $this->checkZaakReadAccess(uuid: $uuid);
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
        // Resolve UUID from URL path — body "uuid" can override controller args.
        $uuid = $this->zgwService->resolvePathUuid($this->request, $uuid);

        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        // Zrc-010/zrc-015: Pre-validate body fields that don't require
        // the existing object, so validation errors are returned even
        // when the OpenRegister find() call fails transiently.
        if ($resource === 'zaken') {
            $preValidation = $this->preValidateZaakBody(partial: false);
            if ($preValidation !== null) {
                return $preValidation;
            }
        }

        [$zaakClosed, $hasGeforceerd] = $this->resolveZaakClosedForExisting(resource: $resource, uuid: $uuid);

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

        // Zrc-004b: Enrich ZIO response with immutable aardRelatieWeergave.
        if ($resource === 'zaakinformatieobjecten' && $response->getStatus() === Http::STATUS_OK) {
            $response = $this->enrichZioJsonResponse(response: $response);
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
        // Resolve UUID from URL path — body "uuid" can override controller args.
        $uuid = $this->zgwService->resolvePathUuid($this->request, $uuid);

        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        // Zrc-010/zrc-015: Pre-validate body fields that don't require
        // the existing object, so validation errors are returned even
        // when the OpenRegister find() call fails transiently.
        if ($resource === 'zaken') {
            $preValidation = $this->preValidateZaakBody(partial: true);
            if ($preValidation !== null) {
                return $preValidation;
            }
        }

        [$zaakClosed, $hasGeforceerd] = $this->resolveZaakClosedForExisting(resource: $resource, uuid: $uuid);

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

        // Zrc-004c: Enrich ZIO response with immutable aardRelatieWeergave.
        if ($resource === 'zaakinformatieobjecten' && $response->getStatus() === Http::STATUS_OK) {
            $response = $this->enrichZioJsonResponse(response: $response);
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

        // Zrc-023: Cascade delete for zaken.
        if ($resource === 'zaken') {
            return $this->destroyZaak(uuid: $uuid);
        }

        // Zrc-005b: Before deleting, capture ZIO data for OIO cleanup.
        $zioData = null;
        if ($resource === 'zaakinformatieobjecten') {
            $zioData = $this->getZioDataForOioSync(uuid: $uuid);
        }

        [$zaakClosed, $hasGeforceerd] = $this->resolveZaakClosedForExisting(resource: $resource, uuid: $uuid);

        $response = $this->zgwService->handleDestroy(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            null,
            $zaakClosed,
            $hasGeforceerd
        );

        // Zrc-005b: If ZIO deletion succeeded, also delete the OIO in DRC.
        if ($resource === 'zaakinformatieobjecten'
            && $response->getStatus() === Http::STATUS_NO_CONTENT
            && $zioData !== null
        ) {
            $this->syncDeleteObjectInformatieObject(
                zaakUrl: $zioData['zaakUrl'],
                ioUrl: $zioData['ioUrl']
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
                if (is_array($object) === true) {
                    $objectData = $object;
                } else {
                    $objectData = $object->jsonSerialize();
                }

                $mapped[] = $this->zgwService->applyOutboundMapping(
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

            $zaakObj = $this->zgwService->getObjectService()->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($zaakObj) === true) {
                $zaakData = $zaakObj;
            } else {
                $zaakData = $zaakObj->jsonSerialize();
            }

            $zaakVa    = $zaakData['confidentiality'] ?? ($zaakData['vertrouwelijkheidaanduiding'] ?? 'openbaar');
            $zaakLevel = self::VERTROUWELIJKHEID_LEVELS[$zaakVa] ?? 1;

            // Check zaaktype + maxVertrouwelijkheidaanduiding from consumer autorisaties.
            $zaakTypeUuid = $zaakData['caseType'] ?? ($zaakData['zaaktype'] ?? '');

            foreach ($autorisaties as $auth) {
                $scopes = $auth['scopes'] ?? [];
                if (in_array('zaken.lezen', $scopes, true) === false) {
                    continue;
                }

                $maxVa = $auth['maxVertrouwelijkheidaanduiding'] ?? ($auth['max_vertrouwelijkheidaanduiding'] ?? null);
                if ($maxVa !== null) {
                    $maxLevel = (self::VERTROUWELIJKHEID_LEVELS[$maxVa] ?? 99);
                } else {
                    $maxLevel = 99;
                }

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
                $maxVa = $auth['maxVertrouwelijkheidaanduiding'] ?? ($auth['max_vertrouwelijkheidaanduiding'] ?? null);
                if ($maxVa !== null) {
                    $maxLevel = (self::VERTROUWELIJKHEID_LEVELS[$maxVa] ?? 99);
                } else {
                    $maxLevel = 99;
                }

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
     * Pre-validate zaak body fields before calling handleUpdate (zrc-010/zrc-015).
     *
     * Validates communicatiekanaal URL format and productenOfDiensten
     * without requiring the existing object from OpenRegister.
     * This ensures validation errors are returned with proper invalidParams
     * even when OpenRegister's find() call fails transiently.
     *
     * @param bool $isPatch Whether this is a PATCH operation
     *
     * @return JSONResponse|null A 400 response if validation fails, null if valid
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function preValidateZaakBody(bool $isPatch): ?JSONResponse
    {
        try {
            $body = $this->zgwService->getRequestBody($this->request);

            // Zrc-010: Validate communicatiekanaal URL.
            $commKanaal = $body['communicatiekanaal'] ?? null;
            if ($commKanaal !== null && $commKanaal !== '') {
                if (filter_var($commKanaal, FILTER_VALIDATE_URL) === false) {
                    return new JSONResponse(
                        data: [
                            'detail'        => 'De communicatiekanaal URL is ongeldig.',
                            'invalidParams' => [
                                [
                                    'name'   => 'communicatiekanaal',
                                    'code'   => 'bad-url',
                                    'reason' => 'De communicatiekanaal URL is ongeldig.',
                                ],
                            ],
                        ],
                        statusCode: Http::STATUS_BAD_REQUEST
                    );
                }

                // Check if URL ends with a valid UUID (resource endpoint, not collection).
                $path    = (string) parse_url($commKanaal, PHP_URL_PATH);
                $hasUuid = preg_match(
                    '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/?$/i',
                    $path
                ) === 1;

                if ($hasUuid === false) {
                    // Determine error code: garbled UUID → bad-url, collection endpoint → invalid-resource.
                    $segments      = array_filter(explode('/', trim($path, '/')));
                    $last          = end($segments);
                    $looksLikeUuid = preg_match('/[0-9a-f]{4,}-/i', (string) $last) === 1;
                    if ($looksLikeUuid === true) {
                        $code = 'bad-url';
                    } else {
                        $code = 'invalid-resource';
                    }

                    return new JSONResponse(
                        data: [
                            'detail'        => 'De communicatiekanaal URL is ongeldig.',
                            'invalidParams' => [
                                [
                                    'name'   => 'communicatiekanaal',
                                    'code'   => $code,
                                    'reason' => 'De communicatiekanaal URL is ongeldig.',
                                ],
                            ],
                        ],
                        statusCode: Http::STATUS_BAD_REQUEST
                    );
                }//end if
            }//end if

            // Zrc-015: Validate productenOfDiensten.
            $producten = $body['productenOfDiensten'] ?? null;
            if (is_array($producten) === true
                && empty($producten) === false
                && $this->zgwService->getObjectService() !== null
            ) {
                $zaaktypeUrl = $body['zaaktype'] ?? '';
                if (empty($zaaktypeUrl) === false) {
                    $error = $this->preValidateProductenOfDiensten(
                        producten: $producten,
                        zaaktypeUrl: $zaaktypeUrl
                    );
                    if ($error !== null) {
                        return $error;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Pre-validation is best-effort; fall through to handleUpdate.
            $this->zgwService->getLogger()->debug(
                'preValidateZaakBody: '.$e->getMessage()
            );
        }//end try

        return null;
    }//end preValidateZaakBody()

    /**
     * Pre-validate productenOfDiensten against zaaktype (zrc-015).
     *
     * @param array  $producten   The productenOfDiensten URLs
     * @param string $zaaktypeUrl The zaaktype URL
     *
     * @return JSONResponse|null A 400 response if invalid, null if valid
     */
    private function preValidateProductenOfDiensten(
        array $producten,
        string $zaaktypeUrl
    ): ?JSONResponse {
        $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
        if (preg_match($uuidPattern, $zaaktypeUrl, $matches) !== 1) {
            return null;
        }

        $ztConfig = $this->zgwService->getZgwMappingService()->getMapping('zaaktype');
        if ($ztConfig === null) {
            return null;
        }

        try {
            $ztObj = $this->zgwService->getObjectService()->find(
                $matches[1],
                register: $ztConfig['sourceRegister'],
                schema: $ztConfig['sourceSchema']
            );
            if (is_array($ztObj) === true) {
                $ztData = $ztObj;
            } else {
                $ztData = $ztObj->jsonSerialize();
            }
        } catch (\Throwable $e) {
            return null;
        }

        $allowed = $ztData['productsOrServices'] ?? ($ztData['productsAndServices'] ?? ($ztData['productenOfDiensten'] ?? []));
        if (is_string($allowed) === true) {
            $allowed = json_decode($allowed, true) ?? [];
        }

        if (is_array($allowed) === false || empty($allowed) === true) {
            return null;
        }

        foreach ($producten as $product) {
            if (in_array($product, $allowed, true) === false) {
                return new JSONResponse(
                    data: [
                        'detail'        => 'productenOfDiensten bevat een waarde die niet in het zaaktype voorkomt.',
                        'invalidParams' => [
                            [
                                'name'   => 'productenOfDiensten',
                                'code'   => 'invalid-products-services',
                                'reason' => "Product '{$product}' is niet toegestaan voor dit zaaktype.",
                            ],
                        ],
                    ],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }
        }

        return null;
    }//end preValidateProductenOfDiensten()

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

        // Zrc-005b: Before deleting the zaak, sync-delete OIOs in DRC
        // for any linked ZaakInformatieObjecten. This cross-component
        // side-effect cannot be handled by OpenRegister's cascade delete.
        $zioConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakinformatieobject');
        if ($zioConfig !== null) {
            try {
                $query  = $objectService->buildSearchQuery(
                    requestParams: ['case' => $uuid, '_limit' => 100],
                    register: $zioConfig['sourceRegister'],
                    schema: $zioConfig['sourceSchema']
                );
                $result = $objectService->searchObjectsPaginated(query: $query);

                foreach (($result['results'] ?? []) as $obj) {
                    if (is_array($obj) === true) {
                        $data = $obj;
                    } else {
                        $data = $obj->jsonSerialize();
                    }

                    $subUuid = $data['id'] ?? ($data['@self']['id'] ?? '');
                    if ($subUuid === '') {
                        continue;
                    }

                    $zioData = $this->getZioDataForOioSync(uuid: $subUuid);
                    if ($zioData !== null) {
                        $this->syncDeleteObjectInformatieObject(
                            zaakUrl: $zioData['zaakUrl'],
                            ioUrl: $zioData['ioUrl']
                        );
                    }
                }
            } catch (\Throwable $e) {
                $this->zgwService->getLogger()->warning(
                    'zrc-023: Failed to sync-delete OIOs for zaak '.$uuid.': '.$e->getMessage()
                );
            }//end try
        }//end if

        // Cascade delete of sub-resources (rol, status, resultaat, etc.)
        // is handled by OpenRegister via onDelete: CASCADE in schema definitions.
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
                $existingObj = $this->zgwService->getObjectService()->find(
                    $uuid,
                    register: $mappingConfig['sourceRegister'],
                    schema: $mappingConfig['sourceSchema']
                );
                if (is_array($existingObj) === true) {
                    $existingData = $existingObj;
                } else {
                    $existingData = $existingObj->jsonSerialize();
                }

                $zaakClosed = $this->zgwService->resolveZaakClosed($resource, $existingData);
                if ($zaakClosed === true) {
                    $hasGeforceerd = $this->zgwService->consumerHasScope(
                        $this->request,
                        'zrc',
                        'zaken.geforceerd-bijwerken'
                    );
                } else {
                    $hasGeforceerd = true;
                }
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
     * Check if creating a status would reopen a closed zaak and require the
     * zaken.heropenen scope (zrc-008c).
     *
     * @param array $body The original request body
     *
     * @return JSONResponse|null A 403 response if scope is missing, null otherwise
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function checkReopenScope(array $body): ?JSONResponse
    {
        try {
            $zaakUrl       = $body['zaak'] ?? '';
            $statustypeUrl = $body['statustype'] ?? '';
            if ($zaakUrl === '' || $statustypeUrl === '') {
                return null;
            }

            $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

            // Find the zaak.
            if (preg_match($uuidPattern, $zaakUrl, $zaakMatches) !== 1) {
                return null;
            }

            $zaakConfig = $this->zgwService->getZgwMappingService()->getMapping('zaak');
            if ($zaakConfig === null) {
                return null;
            }

            $zaak = $this->zgwService->getObjectService()->find(
                $zaakMatches[1],
                register: $zaakConfig['sourceRegister'],
                schema: $zaakConfig['sourceSchema']
            );
            if (is_array($zaak) === true) {
                $zaakData = $zaak;
            } else {
                $zaakData = $zaak->jsonSerialize();
            }

            $endDate = $zaakData['endDate'] ?? null;

            // Zaak is not closed — no reopen check needed.
            if ($endDate === null || $endDate === '') {
                return null;
            }

            // Zaak is closed. Check if statustype is eindstatus.
            if (preg_match($uuidPattern, $statustypeUrl, $stMatches) !== 1) {
                return null;
            }

            $stConfig = $this->zgwService->getZgwMappingService()->getMapping('statustype');
            if ($stConfig === null) {
                return null;
            }

            $statustype = $this->zgwService->getObjectService()->find(
                $stMatches[1],
                register: $stConfig['sourceRegister'],
                schema: $stConfig['sourceSchema']
            );
            if (is_array($statustype) === true) {
                $stData = $statustype;
            } else {
                $stData = $statustype->jsonSerialize();
            }

            $isEindstatus = $stData['isFinal'] ?? ($stData['isFinalStatus'] ?? ($stData['isEindstatus'] ?? false));

            if ($isEindstatus === 'true' || $isEindstatus === '1' || $isEindstatus === 1 || $isEindstatus === true) {
                return null;
            }

            // Non-eindstatus on a closed zaak = reopen attempt → check scope.
            $hasScope = $this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.heropenen');
            if ($hasScope === false) {
                return $this->permissionDeniedResponse();
            }
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->debug(
                'zrc-008c: Could not check reopen scope: '.$e->getMessage()
            );
        }//end try

        return null;
    }//end checkReopenScope()

    /**
     * Set indicatieGebruiksrecht on all linked IOs and then verify none remain
     * null before allowing an eindstatus (zrc-007b + zrc-007q).
     *
     * First attempts to set indicatieGebruiksrecht on all linked IOs (zrc-007b).
     * Then checks that all linked IOs have indicatieGebruiksrecht set. If any
     * still have null after setting, returns 400 (zrc-007q).
     *
     * @param array $body The original request body
     *
     * @return JSONResponse|null A 400 response if any IO has null indicatieGebruiksrecht, null otherwise
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function checkIndicatieGebruiksrechtBeforeClose(array $body): ?JSONResponse
    {
        try {
            $zaakUrl       = $body['zaak'] ?? '';
            $statustypeUrl = $body['statustype'] ?? '';
            if ($zaakUrl === '' || $statustypeUrl === '') {
                return null;
            }

            $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

            // Check if this is an eindstatus.
            if (preg_match($uuidPattern, $statustypeUrl, $stMatches) !== 1) {
                return null;
            }

            $stConfig = $this->zgwService->getZgwMappingService()->getMapping('statustype');
            if ($stConfig === null) {
                return null;
            }

            $statustype = $this->zgwService->getObjectService()->find(
                $stMatches[1],
                register: $stConfig['sourceRegister'],
                schema: $stConfig['sourceSchema']
            );
            if ($statustype === null) {
                return null;
            }

            if (is_array($statustype) === true) {
                $stData = $statustype;
            } else {
                $stData = $statustype->jsonSerialize();
            }

            $isEindstatus = $stData['isFinal'] ?? ($stData['isFinalStatus'] ?? ($stData['isEindstatus'] ?? false));

            // Normalize boolean.
            if ($isEindstatus === 'true' || $isEindstatus === '1' || $isEindstatus === 1 || $isEindstatus === true) {
                $isEindstatus = true;
            }

            // Also check by highest volgnummer if not explicitly set.
            if ($isEindstatus !== true) {
                $isEindstatus = $this->isEindstatusByVolgnummer(
                    stData: $stData,
                    stConfig: $stConfig,
                    uuidPattern: $uuidPattern
                );
            }

            if ($isEindstatus !== true) {
                return null;
            }

            // This is an eindstatus — check indicatieGebruiksrecht (zrc-007q).
            // Only derive values (zrc-007b) on the FIRST close (no endDate yet).
            // If zaak is already closed, just check raw values without deriving.
            if (preg_match($uuidPattern, $zaakUrl, $zaakMatches) !== 1) {
                return null;
            }

            // Check if zaak is already closed (has endDate).
            $zaakConfig        = $this->zgwService->getZgwMappingService()->getMapping('zaak');
            $zaakAlreadyClosed = false;
            if ($zaakConfig !== null) {
                $zaakObj = $this->zgwService->getObjectService()->find(
                    $zaakMatches[1],
                    register: $zaakConfig['sourceRegister'],
                    schema: $zaakConfig['sourceSchema']
                );
                if ($zaakObj !== null) {
                    if (is_array($zaakObj) === true) {
                        $zaakData = $zaakObj;
                    } else {
                        $zaakData = $zaakObj->jsonSerialize();
                    }

                    $endDate           = $zaakData['endDate'] ?? ($zaakData['einddatum'] ?? null);
                    $zaakAlreadyClosed = ($endDate !== null && $endDate !== '');
                }
            }

            // Zrc-007b: Only derive indicatieGebruiksrecht on first close.
            if ($zaakAlreadyClosed === false) {
                $this->setIndicatieGebruiksrechtOnClose(zaakUuid: $zaakMatches[1]);
            }

            // Zrc-007q: Now verify all linked IOs have indicatieGebruiksrecht set.
            $zioConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakinformatieobject');
            $docConfig = $this->zgwService->getZgwMappingService()->getMapping('enkelvoudiginformatieobject');
            if ($zioConfig === null || $docConfig === null) {
                return null;
            }

            $query     = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['case' => $zaakMatches[1], '_limit' => 100],
                register: $zioConfig['sourceRegister'],
                schema: $zioConfig['sourceSchema']
            );
            $zioResult = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            foreach (($zioResult['results'] ?? []) as $zioObj) {
                if (is_array($zioObj) === true) {
                    $zioData = $zioObj;
                } else {
                    $zioData = $zioObj->jsonSerialize();
                }

                $docUuid = $zioData['document'] ?? ($zioData['informatieobject'] ?? '');

                if (preg_match($uuidPattern, (string) $docUuid, $docMatches) !== 1) {
                    continue;
                }

                $docObj = $this->zgwService->getObjectService()->find(
                    $docMatches[1],
                    register: $docConfig['sourceRegister'],
                    schema: $docConfig['sourceSchema']
                );
                if (is_array($docObj) === true) {
                    $docData = $docObj;
                } else {
                    $docData = $docObj->jsonSerialize();
                }

                $indGr = $docData['usageRightsIndication'] ?? ($docData['usageRightsIndicator'] ?? ($docData['indicatieGebruiksrecht'] ?? null));

                if ($indGr === null || $indGr === '') {
                    $detail = 'Zaak kan niet afgesloten worden: niet alle informatieobjecten hebben indicatieGebruiksrecht gezet.';
                    return new JSONResponse(
                        data: [
                            'detail'        => $detail,
                            'code'          => 'indicatiegebruiksrecht-unset',
                            'invalidParams' => [
                                [
                                    'name'   => 'nonFieldErrors',
                                    'code'   => 'indicatiegebruiksrecht-unset',
                                    'reason' => $detail,
                                ],
                            ],
                        ],
                        statusCode: Http::STATUS_BAD_REQUEST
                    );
                }
            }//end foreach
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->debug(
                'zrc-007q: Could not check indicatieGebruiksrecht: '.$e->getMessage()
            );
        }//end try

        return null;
    }//end checkIndicatieGebruiksrechtBeforeClose()

    /**
     * Check if a statustype is the eindstatus by having the highest volgnummer.
     *
     * @param array  $stData      The statustype data
     * @param array  $stConfig    The statustype mapping config
     * @param string $uuidPattern The UUID regex pattern
     *
     * @return bool True if this statustype has the highest volgnummer
     */
    private function isEindstatusByVolgnummer(array $stData, array $stConfig, string $uuidPattern): bool
    {
        $caseTypeUuid = (string) ($stData['caseType'] ?? '');
        if (preg_match($uuidPattern, $caseTypeUuid, $ctMatches) === 1) {
            $caseTypeUuid = $ctMatches[1];
        }

        $thisOrder = (int) ($stData['order'] ?? ($stData['volgnummer'] ?? 0));
        if ($caseTypeUuid === '' || $thisOrder <= 0) {
            return false;
        }

        try {
            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['caseType' => $caseTypeUuid, '_limit' => 100],
                register: $stConfig['sourceRegister'],
                schema: $stConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);
        } catch (\Throwable $e) {
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(
                query: [
                    '@self'    => [
                        'register' => (int) $stConfig['sourceRegister'],
                        'schema'   => (int) $stConfig['sourceSchema'],
                    ],
                    'caseType' => $caseTypeUuid,
                ]
            );
        }

        $maxOrder = 0;
        foreach (($result['results'] ?? []) as $st) {
            if (is_array($st) === true) {
                $stObj = $st;
            } else {
                $stObj = $st->jsonSerialize();
            }

            $order = (int) ($stObj['order'] ?? ($stObj['volgnummer'] ?? 0));
            if ($order > $maxOrder) {
                $maxOrder = $order;
            }
        }

        return $thisOrder >= $maxOrder && $maxOrder > 0;
    }//end isEindstatusByVolgnummer()

    /**
     * Handle eindstatus side effect when creating a status.
     *
     * When the created status's statustype has isEindstatus=true, sets the
     * parent zaak's einddatum to the datumStatusGezet value.
     * Also handles zrc-007b (set indicatieGebruiksrecht on linked documents).
     *
     * @param array $body       The original request body
     * @param array $objectData The created object data
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
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

            if (is_array($statustype) === true) {
                $stData = $statustype;
            } else {
                $stData = $statustype->jsonSerialize();
            }

            $isEindstatus = $stData['isFinal'] ?? ($stData['isFinalStatus'] ?? ($stData['isEindstatus'] ?? false));

            // Normalize boolean from OpenRegister (may be string/int).
            if ($isEindstatus === 'true' || $isEindstatus === '1' || $isEindstatus === 1 || $isEindstatus === true) {
                $isEindstatus = true;
            }

            // ZGW standard: if isFinal not explicitly set, the statustype with
            // the highest volgnummer for this zaaktype is the eindstatus.
            if ($isEindstatus !== true) {
                $caseTypeUuid = (string) ($stData['caseType'] ?? '');
                // Extract UUID in case caseType is stored as a URL.
                if (preg_match($uuidPattern, $caseTypeUuid, $ctMatches) === 1) {
                    $caseTypeUuid = $ctMatches[1];
                }

                $thisOrder = (int) ($stData['order'] ?? ($stData['volgnummer'] ?? 0));
                if ($caseTypeUuid !== '' && $thisOrder > 0) {
                    // Search for all statustypen of this zaaktype.
                    try {
                        $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                            requestParams: ['caseType' => $caseTypeUuid, '_limit' => 100],
                            register: $stConfig['sourceRegister'],
                            schema: $stConfig['sourceSchema']
                        );
                        $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);
                    } catch (\Throwable $e) {
                        // Fallback: try direct query without buildSearchQuery.
                        $result = $this->zgwService->getObjectService()->searchObjectsPaginated(
                            query: [
                                '@self'    => [
                                    'register' => (int) $stConfig['sourceRegister'],
                                    'schema'   => (int) $stConfig['sourceSchema'],
                                ],
                                'caseType' => $caseTypeUuid,
                            ]
                        );
                    }

                    $maxOrder = 0;
                    foreach (($result['results'] ?? []) as $st) {
                        if (is_array($st) === true) {
                            $stObj = $st;
                        } else {
                            $stObj = $st->jsonSerialize();
                        }

                        $order = (int) ($stObj['order'] ?? ($stObj['volgnummer'] ?? 0));
                        if ($order > $maxOrder) {
                            $maxOrder = $order;
                        }
                    }

                    if ($thisOrder >= $maxOrder && $maxOrder > 0) {
                        $isEindstatus = true;
                    }
                }//end if
            }//end if

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

            if (is_array($zaak) === true) {
                $zaakData = $zaak;
            } else {
                $zaakData = $zaak->jsonSerialize();
            }

            // Strip metadata that confuses saveObject on re-save.
            unset($zaakData['@self'], $zaakData['organisation']);

            // Ensure field types match schema expectations for re-save.
            // OpenRegister may store numeric-looking strings as integers, but the
            // schema expects string types for fields like bronorganisatie.
            $stringFields = ['title', 'assignee', 'sourceOrganisation', 'identifier'];
            foreach ($stringFields as $field) {
                if (isset($zaakData[$field]) === true && is_int($zaakData[$field]) === true) {
                    $zaakData[$field] = (string) $zaakData[$field];
                }

                if ($field === 'title' && (isset($zaakData[$field]) === false || $zaakData[$field] === null)) {
                    $zaakData[$field] = '';
                }
            }

            if ($isEindstatus === true) {
                // Zrc-007a: Set zaak einddatum when eindstatus is created.
                $datumStatusGezet = $body['datumStatusGezet'] ?? ($objectData['statusSetDate'] ?? date('Y-m-d'));
                if (strlen($datumStatusGezet) > 10) {
                    $datumStatusGezet = substr($datumStatusGezet, 0, 10);
                }

                $zaakData['endDate'] = $datumStatusGezet;

                // Zrc-021: Derive archiefactiedatum from resultaat.resultaattype.brondatumArchiefprocedure.
                $zaakData = $this->deriveArchiefactiedatum(
                    zaakData: $zaakData,
                    zaakConfig: $zaakConfig,
                    einddatum: $datumStatusGezet
                );

                $zaakData['id'] = $zaakMatches[1];
                $this->zgwService->getObjectService()->saveObject(
                    register: $zaakConfig['sourceRegister'],
                    schema: $zaakConfig['sourceSchema'],
                    object: $zaakData,
                    uuid: $zaakMatches[1]
                );

                // Zrc-007b: Set indicatieGebruiksrecht on all related informatieobjecten.
                $this->setIndicatieGebruiksrechtOnClose(zaakUuid: $zaakMatches[1]);
            } else {
                // Zrc-008: Heropenen zaak — when a non-eindstatus is created on
                // a zaak that already has an endDate, clear endDate, archiefactiedatum,
                // and archiefnominatie (reopen the zaak).
                $existingEndDate = $zaakData['endDate'] ?? null;
                if ($existingEndDate !== null && $existingEndDate !== '') {
                    $zaakData['endDate']           = null;
                    $zaakData['archiveActionDate'] = null;
                    $zaakData['archiveNomination'] = null;
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
            $this->zgwService->getLogger()->error(
                'handleEindstatusEffect failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try
    }//end handleEindstatusEffect()

    /**
     * Set indicatieGebruiksrecht on all informatieobjecten linked to a zaak (zrc-007b).
     *
     * When a zaak is closed, all related informatieobjecten must have
     * indicatieGebruiksrecht set (not null).
     *
     * @param string $zaakUuid The zaak UUID
     *
     * @return void
     */
    private function setIndicatieGebruiksrechtOnClose(string $zaakUuid): void
    {
        try {
            $zioConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakinformatieobject');
            $docConfig = $this->zgwService->getZgwMappingService()->getMapping('enkelvoudiginformatieobject');
            if ($zioConfig === null || $docConfig === null) {
                return;
            }

            // Find all ZIOs for this zaak.
            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['case' => $zaakUuid, '_limit' => 100],
                register: $zioConfig['sourceRegister'],
                schema: $zioConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            foreach (($result['results'] ?? []) as $zioObj) {
                if (is_array($zioObj) === true) {
                    $zioData = $zioObj;
                } else {
                    $zioData = $zioObj->jsonSerialize();
                }

                $docUuid = $zioData['document'] ?? ($zioData['informatieobject'] ?? '');

                $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
                if (preg_match($uuidPattern, (string) $docUuid, $docMatches) !== 1) {
                    continue;
                }

                try {
                    $docObj = $this->zgwService->getObjectService()->find(
                        $docMatches[1],
                        register: $docConfig['sourceRegister'],
                        schema: $docConfig['sourceSchema']
                    );
                    if (is_array($docObj) === true) {
                        $docData = $docObj;
                    } else {
                        $docData = $docObj->jsonSerialize();
                    }

                    // Check if indicatieGebruiksrecht is already set.
                    $indGr = $docData['usageRightsIndication'] ?? ($docData['usageRightsIndicator'] ?? ($docData['indicatieGebruiksrecht'] ?? null));

                    if ($indGr === null || $indGr === '') {
                        // Check if gebruiksrechten exist for this document.
                        $grConfig = $this->zgwService->getZgwMappingService()->getMapping('gebruiksrechten');
                        $hasGr    = false;
                        if ($grConfig !== null) {
                            try {
                                $grQuery  = $this->zgwService->getObjectService()->buildSearchQuery(
                                    requestParams: ['document' => $docMatches[1], '_limit' => 1],
                                    register: $grConfig['sourceRegister'],
                                    schema: $grConfig['sourceSchema']
                                );
                                $grResult = $this->zgwService->getObjectService()
                                    ->searchObjectsPaginated(query: $grQuery);
                                $hasGr    = empty($grResult['results'] ?? []) === false;
                            } catch (\Throwable $e) {
                                // No gebruiksrechten schema — default to false.
                            }
                        }

                        // Set indicatieGebruiksrecht based on whether gebruiksrechten exist.
                        unset($docData['@self'], $docData['organisation']);
                        $docData['usageRightsIndication'] = $hasGr;
                        $docData['id'] = $docMatches[1];
                        $this->zgwService->getObjectService()->saveObject(
                            register: $docConfig['sourceRegister'],
                            schema: $docConfig['sourceSchema'],
                            object: $docData,
                            uuid: $docMatches[1]
                        );
                    }//end if
                } catch (\Throwable $e) {
                    $this->zgwService->getLogger()->debug(
                        'zrc-007b: Could not update indicatieGebruiksrecht for doc '.$docMatches[1].': '.$e->getMessage()
                    );
                }//end try
            }//end foreach
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'zrc-007b: Failed to set indicatieGebruiksrecht: '.$e->getMessage()
            );
        }//end try
    }//end setIndicatieGebruiksrechtOnClose()

    /**
     * Handle resultaat creation side-effects (zrc-021).
     *
     * When a resultaat is created, derive archiefactiedatum and
     * archiefnominatie on the parent zaak from the resultaattype.
     *
     * @param array $body       The original request body (Dutch names)
     * @param array $objectData The created resultaat object data
     *
     * @return void
     */
    private function handleResultaatCreated(array $body, array $objectData): void
    {
        try {
            $zaakUrl = $body['zaak'] ?? '';
            if ($zaakUrl === '') {
                return;
            }

            $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
            if (preg_match($uuidPattern, $zaakUrl, $zaakMatches) !== 1) {
                return;
            }

            $zaakConfig = $this->zgwService->getZgwMappingService()->getMapping('zaak');
            if ($zaakConfig === null) {
                return;
            }

            $zaakObj = $this->zgwService->getObjectService()->find(
                $zaakMatches[1],
                register: $zaakConfig['sourceRegister'],
                schema: $zaakConfig['sourceSchema']
            );
            if (is_array($zaakObj) === true) {
                $zaakData = $zaakObj;
            } else {
                $zaakData = $zaakObj->jsonSerialize();
            }

            // Use the zaak endDate as einddatum (may be null if zaak isn't closed yet).
            $einddatum = $zaakData['endDate'] ?? date('Y-m-d');

            $zaakData = $this->deriveArchiefactiedatum(
                zaakData: $zaakData,
                zaakConfig: $zaakConfig,
                einddatum: $einddatum
            );

            // Type coercion for re-save (OpenRegister stores numeric strings as ints).
            $stringFields = ['title', 'assignee', 'sourceOrganisation', 'identifier'];
            foreach ($stringFields as $field) {
                if (isset($zaakData[$field]) === true && is_int($zaakData[$field]) === true) {
                    $zaakData[$field] = (string) $zaakData[$field];
                }

                if ($field === 'title' && (isset($zaakData[$field]) === false || $zaakData[$field] === null)) {
                    $zaakData[$field] = '';
                }
            }

            // Save the updated zaak.
            $zaakData['id'] = $zaakMatches[1];
            $this->zgwService->getObjectService()->saveObject(
                register: $zaakConfig['sourceRegister'],
                schema: $zaakConfig['sourceSchema'],
                object: $zaakData,
                uuid: $zaakMatches[1]
            );
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'zrc-021: handleResultaatCreated failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try
    }//end handleResultaatCreated()

    /**
     * Derive archiefactiedatum from resultaat's resultaattype brondatumArchiefprocedure (zrc-021).
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

            $resultaat = $results[0];
            if (is_array($resultaat) === true) {
                $resultaatData = $resultaat;
            } else {
                $resultaatData = $resultaat->jsonSerialize();
            }

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

            if (is_array($rtObj) === true) {
                $rtData = $rtObj;
            } else {
                $rtData = $rtObj->jsonSerialize();
            }

            // Get brondatumArchiefprocedure.
            $brondatum = $rtData['sourceDateArchiveProcedure'] ?? ($rtData['brondatumArchiefprocedure'] ?? null);
            if (is_string($brondatum) === true) {
                $brondatum = json_decode($brondatum, true);
            }

            if ($brondatum === null || is_array($brondatum) === false) {
                return $zaakData;
            }

            $afleidingswijze = $brondatum['derivationMethod'] ?? ($brondatum['afleidingswijze'] ?? '');
            // Archiefactietermijn lives on the ResultaatType, not inside brondatumArchiefprocedure.
            $procestermijn = $rtData['archivalPeriod'] ?? ($rtData['archiefactietermijn'] ?? null);

            // Determine the base date based on afleidingswijze.
            $baseDate = $this->resolveArchiveBaseDate(
                afleidingswijze: $afleidingswijze,
                einddatum: $datumStatusGezet,
                zaakData: $zaakData,
                zaakConfig: $zaakConfig,
                brondatum: $brondatum
            );

            if ($baseDate === null) {
                // Base date unresolvable — set archiefactiedatum to null but still derive archiefnominatie.
                $zaakData['archiveActionDate'] = null;

                $nomination = $rtData['archivalAction'] ?? ($rtData['archiveNomination'] ?? ($rtData['archiefnominatie'] ?? ''));
                if ($nomination !== '') {
                    $zaakData['archiveNomination'] = $nomination;
                }

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

            // Zrc-021: Also set archiveNomination from the resultaattype.
            $nomination = $rtData['archivalAction'] ?? ($rtData['archiveNomination'] ?? ($rtData['archiefnominatie'] ?? ''));
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
                return $einddatum;

            case 'ander_datumkenmerk':
                // Cannot be automatically determined — requires external datumkenmerk.
                return null;

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
                        if (is_array($mainZaak) === true) {
                            $mainData = $mainZaak;
                        } else {
                            $mainData = $mainZaak->jsonSerialize();
                        }

                        $mainEnd = $mainData['endDate'] ?? null;
                        if ($mainEnd !== null && $mainEnd !== '') {
                            if (is_string($mainEnd) === true) {
                                return substr($mainEnd, 0, 10);
                            }

                            return $einddatum;
                        }
                    } catch (\Throwable $e) {
                        // Fall through to einddatum.
                    }//end try
                }//end if
                return $einddatum;

            case 'eigenschap':
                $datumkenmerk = $brondatum['objectAttribute'] ?? ($brondatum['datumkenmerk'] ?? '');
                if ($datumkenmerk !== '' && $this->zgwService->getObjectService() !== null) {
                    return $this->resolveEigenschapDate(zaakData: $zaakData, datumkenmerk: $datumkenmerk) ?? $einddatum;
                }
                return $einddatum;

            case 'ingangsdatum_besluit':
                return $this->resolveBesluitDate(
                    zaakData: $zaakData,
                    englishField: 'effectiveDate',
                    dutchField: 'ingangsdatum'
                ) ?? $einddatum;

            case 'vervaldatum_besluit':
                return $this->resolveBesluitDate(
                    zaakData: $zaakData,
                    englishField: 'expiryDate',
                    dutchField: 'vervaldatum'
                ) ?? $einddatum;

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
                $propObj = $results[0];
                if (is_array($propObj) === true) {
                    $propData = $propObj;
                } else {
                    $propData = $propObj->jsonSerialize();
                }

                $value = $propData['value'] ?? ($propData['waarde'] ?? '');
                if ($value !== '' && strtotime($value) !== false) {
                    return substr($value, 0, 10);
                }
            }
        } catch (\Throwable $e) {
            // Not found — return null.
        }//end try

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
                requestParams: ['case' => $zaakUuid, '_limit' => 100],
                register: $besluitConfig['sourceRegister'],
                schema: $besluitConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            $results = $result['results'] ?? [];
            if (empty($results) === true) {
                return null;
            }

            // Find the latest (maximum) date among all besluiten for this zaak.
            $latestDate = null;
            foreach ($results as $besluitObj) {
                if (is_array($besluitObj) === true) {
                    $besluitData = $besluitObj;
                } else {
                    $besluitData = $besluitObj->jsonSerialize();
                }

                $dateVal = $besluitData[$englishField] ?? ($besluitData[$dutchField] ?? '');
                if ($dateVal !== '' && strtotime($dateVal) !== false) {
                    $dateStr = substr($dateVal, 0, 10);
                    if ($latestDate === null || $dateStr > $latestDate) {
                        $latestDate = $dateStr;
                    }
                }
            }

            return $latestDate;
        } catch (\Throwable $e) {
            // Not found — return null.
        }//end try

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
        // Zrc-004a: aardRelatieWeergave is always "Hoort bij, omgekeerd: kent".
        $mapped['aardRelatieWeergave'] = 'Hoort bij, omgekeerd: kent';

        // Zrc-004a: registratiedatum from the enriched body (set by business rules).
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

            $zioObj = $this->zgwService->getObjectService()->find(
                $uuid,
                register: $zioConfig['sourceRegister'],
                schema: $zioConfig['sourceSchema']
            );
            if (is_array($zioObj) === true) {
                $zioData = $zioObj;
            } else {
                $zioData = $zioObj->jsonSerialize();
            }

            // The ZIO stores 'case' as a UUID (format: uuid with $ref) and
            // 'document' as a full URL (format: uri). Build the zaak URL from
            // the case UUID, and use the document URL directly.
            $zaakUuid = $zioData['case'] ?? ($zioData['zaak'] ?? '');
            $ioUrl    = $zioData['document'] ?? ($zioData['informatieobject'] ?? '');

            if ($zaakUuid === '' || $ioUrl === '') {
                return null;
            }

            // Build zaak URL from the UUID (case field stores UUID).
            $zaakBaseUrl = $this->zgwService->buildBaseUrl($this->request, 'zaken', 'zaken');

            return [
                'zaakUrl' => $zaakBaseUrl.'/'.$zaakUuid,
                'ioUrl'   => $ioUrl,
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

            // The OIO schema (documentLink) stores 'object' and 'document' as
            // full URLs (format: uri). Search by the full URL values directly.
            if ($zaakUrl === '' || $ioUrl === '') {
                return;
            }

            $query  = $this->zgwService->getObjectService()->buildSearchQuery(
                requestParams: ['object' => $zaakUrl, 'document' => $ioUrl],
                register: $oioConfig['sourceRegister'],
                schema: $oioConfig['sourceSchema']
            );
            $result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

            foreach (($result['results'] ?? []) as $oioObj) {
                if (is_array($oioObj) === true) {
                    $oioData = $oioObj;
                } else {
                    $oioData = $oioObj->jsonSerialize();
                }

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
