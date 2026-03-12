<?php

/**
 * Procest ZTC (Catalogi) Controller
 *
 * Controller for serving ZGW Catalogi API endpoints (catalogussen, zaaktypen,
 * statustypen, resultaattypen, roltypen, eigenschappen, informatieobjecttypen,
 * besluittypen, zaaktype-informatieobjecttypen). Delegates shared operations
 * to ZgwService and handles ZTC-specific publish logic.
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
 * ZTC (Catalogi) API Controller
 *
 * Handles ZGW Catalogi register resources with publish support for
 * zaaktypen, besluittypen, and informatieobjecttypen.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ZtcController extends Controller
{

    /**
     * The ZGW API identifier for the Catalogi register.
     *
     * @var string
     */
    private const ZGW_API = 'catalogi';

    /**
     * Resources that need URL validity filtering in responses.
     *
     * Maps resource name to the fields containing URL arrays that need filtering,
     * and the schema config key to look up each referenced type.
     *
     * @var array<string, array<string, array{schemaKey: string, nested: bool}>>
     */
    private const URL_FILTER_FIELDS = [
        'zaaktypen'    => [
            'informatieobjecttypen' => [
                'schemaKey' => 'document_type_schema',
                'nested'    => false,
            ],
            'besluittypen'          => [
                'schemaKey' => 'decision_type_schema',
                'nested'    => false,
            ],
            'deelzaaktypen'         => [
                'schemaKey' => 'case_type_schema',
                'nested'    => false,
            ],
            'gerelateerdeZaaktypen' => [
                'schemaKey' => 'case_type_schema',
                'nested'    => true,
            ],
        ],
        'besluittypen' => [
            'informatieobjecttypen' => [
                'schemaKey' => 'document_type_schema',
                'nested'    => false,
            ],
            'zaaktypen'             => [
                'schemaKey' => 'case_type_schema',
                'nested'    => false,
            ],
        ],
    ];

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
     * List resources of the given type.
     *
     * @param string $resource The ZGW resource name (e.g. catalogussen, zaaktypen).
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
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        $response = $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);

        if ($response->getStatus() !== Http::STATUS_OK) {
            return $response;
        }

        $data = $response->getData();
        if (isset($data['results']) === false || is_array($data['results']) === false) {
            return $response;
        }

        // ZTC datumGeldigheid: post-filter results by date validity.
        $datumGeldigheid = $this->request->getParam('datumGeldigheid');
        if ($datumGeldigheid !== null && $datumGeldigheid !== '') {
            $data['results'] = $this->filterByDatumGeldigheid(results: $data['results'], datumGeldigheid: $datumGeldigheid);
            $data['count']   = count($data['results']);
        }

        // Enrich cross-references and filter invalid URLs from paginated results.
        if (isset(self::URL_FILTER_FIELDS[$resource]) === true) {
            foreach ($data['results'] as $idx => $item) {
                $item = $this->enrichCrossReferences(resource: $resource, data: $item);
                $data['results'][$idx] = $this->filterValidUrls(resource: $resource, data: $item);
            }
        }

        return new JSONResponse(data: $data, statusCode: Http::STATUS_OK);
    }//end index()

    /**
     * Create a new resource of the given type.
     *
     * @param string $resource The ZGW resource name.
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

        // Ztc-010: Resolve parent zaaktype draft status for sub-resource creation.
        $body = $this->zgwService->getRequestBody($this->request);
        $parentZaaktypeDraft = $this->zgwService->resolveParentZaaktypeDraftFromBody($resource, $body);

        // Ztc-010m: For ZIOT, resolve informatieobjecttype by omschrijving if not a UUID/URL.
        if ($resource === 'zaaktype-informatieobjecttypen') {
            $this->resolveIotByOmschrijving(body: $body);
        }

        $response = $this->zgwService->handleCreate(
            $this->request,
            self::ZGW_API,
            $resource,
            parentZaaktypeDraft: $parentZaaktypeDraft
        );

        // Enrich cross-references on create response (without validity filtering
        // since referenced types may not yet be published at creation time).
        if (isset(self::URL_FILTER_FIELDS[$resource]) === true
            && $response->getStatus() === Http::STATUS_CREATED
        ) {
            $data = $response->getData();
            $data = $this->enrichCrossReferences(resource: $resource, data: $data);

            return new JSONResponse(data: $data, statusCode: Http::STATUS_CREATED);
        }

        return $response;
    }//end create()

    /**
     * Retrieve a single resource by UUID.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
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
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        $response = $this->zgwService->handleShow($this->request, self::ZGW_API, $resource, $uuid);

        // Enrich cross-references and filter invalid URLs.
        if (isset(self::URL_FILTER_FIELDS[$resource]) === true
            && $response->getStatus() === Http::STATUS_OK
        ) {
            $data     = $response->getData();
            $data     = $this->enrichCrossReferences(resource: $resource, data: $data);
            $filtered = $this->filterValidUrls(resource: $resource, data: $data);

            return new JSONResponse(data: $filtered, statusCode: Http::STATUS_OK);
        }

        return $response;
    }//end show()

    /**
     * Resolve the parent zaaktype draft status for a sub-resource.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
     *
     * @return bool|null The parent zaaktype draft status, or null if not applicable.
     */
    private function resolveParentDraft(string $resource, string $uuid): ?bool
    {
        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
        if ($mappingConfig === null || $this->zgwService->getObjectService() === null) {
            return null;
        }

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

            return $this->zgwService->resolveParentZaaktypeDraft($resource, $existingData);
        } catch (\Throwable $e) {
            // Proceed without parent zaaktype info.
            return null;
        }
    }//end resolveParentDraft()

    /**
     * Full update (PUT) a resource by UUID.
     *
     * For sub-resources of zaaktypen, resolves parentZaaktypeDraft before delegating.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
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

        $parentZtDraft = $this->resolveParentDraft(resource: $resource, uuid: $uuid);

        $response = $this->zgwService->handleUpdate(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            false,
            $parentZtDraft
        );

        // Enrich cross-references and filter invalid URLs.
        if (isset(self::URL_FILTER_FIELDS[$resource]) === true
            && $response->getStatus() === Http::STATUS_OK
        ) {
            $data     = $response->getData();
            $data     = $this->enrichCrossReferences(resource: $resource, data: $data);
            $filtered = $this->filterValidUrls(resource: $resource, data: $data);

            return new JSONResponse(data: $filtered, statusCode: Http::STATUS_OK);
        }

        return $response;
    }//end update()

    /**
     * Partial update (PATCH) a resource by UUID.
     *
     * For sub-resources of zaaktypen, resolves parentZaaktypeDraft before delegating.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
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

        $parentZtDraft = $this->resolveParentDraft(resource: $resource, uuid: $uuid);

        $response = $this->zgwService->handleUpdate(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            true,
            $parentZtDraft
        );

        // Enrich cross-references and filter invalid URLs.
        if (isset(self::URL_FILTER_FIELDS[$resource]) === true
            && $response->getStatus() === Http::STATUS_OK
        ) {
            $data     = $response->getData();
            $data     = $this->enrichCrossReferences(resource: $resource, data: $data);
            $filtered = $this->filterValidUrls(resource: $resource, data: $data);

            return new JSONResponse(data: $filtered, statusCode: Http::STATUS_OK);
        }

        return $response;
    }//end patch()

    /**
     * Delete a resource by UUID.
     *
     * For sub-resources of zaaktypen, resolves parentZaaktypeDraft before delegating.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
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

        $parentZtDraft = $this->resolveParentDraft(resource: $resource, uuid: $uuid);

        return $this->zgwService->handleDestroy(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            $parentZtDraft
        );
    }//end destroy()

    /**
     * Publish a ZTC resource by setting isDraft to false.
     *
     * Loads the existing object, sets isDraft=false, saves it back,
     * and returns the outbound-mapped result.
     *
     * @param string $resource The ZGW resource name (zaaktypen, besluittypen, informatieobjecttypen).
     * @param string $uuid     The resource UUID.
     *
     * @return JSONResponse
     */
    private function handlePublish(string $resource, string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        if ($this->zgwService->getObjectService() === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
        }

        try {
            $existing     = $this->zgwService->getObjectService()->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $existingData = $existing->jsonSerialize();
            unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
            $existingData['isDraft'] = false;

            if (isset($existingData['identifier']) === true && is_int($existingData['identifier']) === true) {
                $existingData['identifier'] = (string) $existingData['identifier'];
            }

            // Re-encode fields that are stored as JSON strings but auto-decoded
            // by jsonSerialize. Only string-typed schema fields need re-encoding.
            $jsonStringFields = ['productsOrServices', 'referenceProcess', 'relatedCaseTypes'];
            foreach ($jsonStringFields as $field) {
                if (isset($existingData[$field]) === true && is_array($existingData[$field]) === true) {
                    $existingData[$field] = json_encode($existingData[$field]);
                }
            }

            $object = $this->zgwService->getObjectService()->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $existingData,
                uuid: $uuid
            );
            if (is_array($object) === true) {
                $objectData = $object;
            } else {
                $objectData = $object->jsonSerialize();
            }

            $baseUrl         = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
            $outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
            $mapped          = $this->zgwService->applyOutboundMapping(
                objectData: $objectData,
                mapping: $outboundMapping,
                mappingConfig: $mappingConfig,
                baseUrl: $baseUrl
            );

            return new JSONResponse(data: $mapped, statusCode: Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'ZTC publish error: '.$e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(data: ['detail' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
        }//end try
    }//end handlePublish()

    /**
     * Publish a zaaktype (set isDraft to false).
     *
     * @param string $uuid The zaaktype UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function publishZaaktype(string $uuid): JSONResponse
    {
        return $this->handlePublish(resource: 'zaaktypen', uuid: $uuid);
    }//end publishZaaktype()

    /**
     * Publish a besluittype (set isDraft to false).
     *
     * @param string $uuid The besluittype UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function publishBesluittype(string $uuid): JSONResponse
    {
        return $this->handlePublish(resource: 'besluittypen', uuid: $uuid);
    }//end publishBesluittype()

    /**
     * Publish an informatieobjecttype (set isDraft to false).
     *
     * @param string $uuid The informatieobjecttype UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function publishInformatieobjecttype(string $uuid): JSONResponse
    {
        return $this->handlePublish(resource: 'informatieobjecttypen', uuid: $uuid);
    }//end publishInformatieobjecttype()

    /**
     * Filter URL arrays in a ZTC response to only include valid/existing references.
     *
     * Enrich response data with cross-reference URLs.
     *
     * For besluittypen: expand stored UUID arrays (documentTypes, caseTypes) to
     * full ZGW URLs so that the response includes informatieobjecttypen/zaaktypen.
     * For zaaktypen: query ZIOT records and besluittype records to populate
     * informatieobjecttypen and besluittypen arrays.
     *
     * @param string $resource The ZGW resource name.
     * @param array  $data     The outbound-mapped response data.
     *
     * @return array The enriched response data with cross-reference URLs.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function enrichCrossReferences(string $resource, array $data): array
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return $data;
        }

        $baseUrl = $this->request->getServerProtocol().'://'.$this->request->getServerHost().'/index.php/apps/procest/api/zgw/catalogi/v1';
        $uuid    = $data['uuid'] ?? '';

        if ($resource === 'besluittypen' && $uuid !== '') {
            $data = $this->enrichBesluittype(data: $data, baseUrl: $baseUrl, objectService: $objectService, uuid: $uuid);
        }

        if ($resource === 'zaaktypen' && $uuid !== '') {
            $data = $this->enrichZaaktype(data: $data, baseUrl: $baseUrl, objectService: $objectService, uuid: $uuid);

            // Ensure array fields default to [] instead of null.
            $arrayFields = [
                'deelzaaktypen',
                'gerelateerdeZaaktypen',
                'besluittypen',
                'informatieobjecttypen',
                'eigenschappen',
                'statustypen',
                'resultaattypen',
                'roltypen',
            ];
            foreach ($arrayFields as $field) {
                if (isset($data[$field]) === false || $data[$field] === null) {
                    $data[$field] = [];
                }
            }
        }

        return $data;
    }//end enrichCrossReferences()

    /**
     * Enrich besluittype with informatieobjecttypen and zaaktypen URLs.
     *
     * Reads stored UUIDs from the documentTypes/caseTypes fields and
     * expands them to full ZGW URLs.
     *
     * @param array  $data          The response data.
     * @param string $baseUrl       The base URL for building ZGW resource URLs.
     * @param object $objectService The OpenRegister object service.
     * @param string $uuid          The besluittype UUID.
     *
     * @return array The enriched response data.
     */
    private function enrichBesluittype(
        array $data,
        string $baseUrl,
        object $objectService,
        string $uuid
    ): array {
        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'besluittypen');
        if ($mappingConfig === null) {
            return $data;
        }

        try {
            $object = $objectService->find(
                id: $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($object) === true) {
                $objectData = $object;
            } else {
                $objectData = $object->jsonSerialize();
            }

            // Expand documentTypes UUIDs to informatieobjecttypen URLs.
            $docTypes = $objectData['documentTypes'] ?? '';
            if (is_string($docTypes) === true && $docTypes !== '') {
                $docTypeIds = json_decode($docTypes, true);
            } else if (is_array($docTypes) === true) {
                $docTypeIds = $docTypes;
            } else {
                $docTypeIds = [];
            }

            if (empty($docTypeIds) === false) {
                $urls = [];
                foreach ($docTypeIds as $iotUuid) {
                    if (is_string($iotUuid) === true && $iotUuid !== '') {
                        $urls[] = $baseUrl.'/informatieobjecttypen/'.$iotUuid;
                    }
                }

                $data['informatieobjecttypen'] = $urls;
            }

            // Expand caseTypes to zaaktypen URLs.
            $caseTypes = $objectData['caseTypes'] ?? '';
            if (is_string($caseTypes) === true && $caseTypes !== '') {
                $caseTypeIds = json_decode($caseTypes, true);
            } else if (is_array($caseTypes) === true) {
                $caseTypeIds = $caseTypes;
            } else {
                $caseTypeIds = [];
            }

            if (empty($caseTypeIds) === false) {
                $urls = [];
                foreach ($caseTypeIds as $ztUuid) {
                    if (is_string($ztUuid) === true && $ztUuid !== '') {
                        $urls[] = $baseUrl.'/zaaktypen/'.$ztUuid;
                    }
                }

                $data['zaaktypen'] = $urls;
            }
        } catch (\Throwable $e) {
            // Proceed without enrichment.
        }//end try

        return $data;
    }//end enrichBesluittype()

    /**
     * Enrich zaaktype with informatieobjecttypen and besluittypen URLs.
     *
     * Queries ZIOT records to find linked informatieobjecttypen, and
     * queries besluittypen by caseType to find linked besluittypen.
     *
     * @param array  $data          The response data.
     * @param string $baseUrl       The base URL for building ZGW resource URLs.
     * @param object $objectService The OpenRegister object service.
     * @param string $uuid          The zaaktype UUID.
     *
     * @return array The enriched response data.
     */
    private function enrichZaaktype(
        array $data,
        string $baseUrl,
        object $objectService,
        string $uuid
    ): array {
        // Populate deelzaaktypen from stored subCaseTypes UUIDs.
        $ztMapping = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaaktypen');
        if ($ztMapping !== null) {
            try {
                $object = $objectService->find(
                    id: $uuid,
                    register: $ztMapping['sourceRegister'],
                    schema: $ztMapping['sourceSchema']
                );
                if (is_array($object) === true) {
                    $objectData = $object;
                } else {
                    $objectData = $object->jsonSerialize();
                }

                $subCases = $objectData['subCaseTypes'] ?? [];
                if (is_array($subCases) === true && empty($subCases) === false) {
                    // Expand each stored UUID to all ZTs with the same identifier.
                    $urls = [];
                    foreach ($subCases as $ztUuid) {
                        if (is_string($ztUuid) === false || $ztUuid === '') {
                            continue;
                        }

                        try {
                            $refObj = $objectService->find(
                                id: $ztUuid,
                                register: $ztMapping['sourceRegister'],
                                schema: $ztMapping['sourceSchema']
                            );
                            if (is_array($refObj) === true) {
                                $refData = $refObj;
                            } else {
                                $refData = $refObj->jsonSerialize();
                            }

                            $ident = $refData['identifier'] ?? '';

                            if ($ident !== '') {
                                $query  = $objectService->buildSearchQuery(
                                    requestParams: ['identifier' => $ident, '_limit' => 100],
                                    register: $ztMapping['sourceRegister'],
                                    schema: $ztMapping['sourceSchema']
                                );
                                $result = $objectService->searchObjectsPaginated(query: $query);
                                foreach (($result['results'] ?? []) as $match) {
                                    if (is_array($match) === true) {
                                        $mData = $match;
                                    } else {
                                        $mData = $match->jsonSerialize();
                                    }

                                    $mId = $mData['id'] ?? ($mData['@self']['id'] ?? '');
                                    if ($mId !== '') {
                                        $urls[] = $baseUrl.'/zaaktypen/'.$mId;
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
                            $urls[] = $baseUrl.'/zaaktypen/'.$ztUuid;
                        }//end try
                    }//end foreach

                    $urls = array_values(array_unique($urls));
                    $data['deelzaaktypen'] = $urls;
                }//end if

                // Populate besluittypen from stored decisionTypes UUIDs.
                $decTypes = $objectData['decisionTypes'] ?? [];
                if (is_array($decTypes) === true && empty($decTypes) === false) {
                    $urls = [];
                    foreach ($decTypes as $btUuid) {
                        if (is_string($btUuid) === true && $btUuid !== '') {
                            $urls[] = $baseUrl.'/besluittypen/'.$btUuid;
                        }
                    }

                    $data['besluittypen'] = $urls;
                }
            } catch (\Throwable $e) {
                // Proceed without deelzaaktypen enrichment.
            }//end try
        }//end if

        // Expand UUIDs in gerelateerdeZaaktypen to all ZTs with same identifier.
        // Read from raw object's relatedCaseTypes (JSON-encoded string) since Twig
        // outbound mapping cannot handle array-of-objects.
        if (isset($objectData) === true) {
            $relatedRaw = $objectData['relatedCaseTypes'] ?? null;
        } else {
            $relatedRaw = null;
        }

        if ($relatedRaw === null) {
            $relatedRaw = $data['gerelateerdeZaaktypen'] ?? null;
        }

        if (is_string($relatedRaw) === true) {
            $relatedRaw = json_decode($relatedRaw, true);
        }

        if (is_array($relatedRaw) === true
            && empty($relatedRaw) === false
            && $ztMapping !== null
        ) {
            $expanded = [];
            foreach ($relatedRaw as $rel) {
                $ztRef = $rel['zaaktype'] ?? '';
                if (is_string($ztRef) === false || $ztRef === '') {
                    continue;
                }

                // Already a URL — keep as-is.
                if (str_starts_with($ztRef, 'http') === true) {
                    $expanded[] = $rel;
                    continue;
                }

                // Look up identifier, find all matching ZTs.
                try {
                    $refObj = $objectService->find(
                        id: $ztRef,
                        register: $ztMapping['sourceRegister'],
                        schema: $ztMapping['sourceSchema']
                    );
                    if (is_array($refObj) === true) {
                        $refData = $refObj;
                    } else {
                        $refData = $refObj->jsonSerialize();
                    }

                    $ident = $refData['identifier'] ?? '';

                    if ($ident !== '') {
                        $query  = $objectService->buildSearchQuery(
                            requestParams: ['identifier' => $ident, '_limit' => 100],
                            register: $ztMapping['sourceRegister'],
                            schema: $ztMapping['sourceSchema']
                        );
                        $result = $objectService->searchObjectsPaginated(query: $query);
                        foreach (($result['results'] ?? []) as $match) {
                            if (is_array($match) === true) {
                                $mData = $match;
                            } else {
                                $mData = $match->jsonSerialize();
                            }

                            $mId = $mData['id'] ?? ($mData['@self']['id'] ?? '');
                            if ($mId !== '') {
                                $entry = $rel;
                                $entry['zaaktype'] = $baseUrl.'/zaaktypen/'.$mId;
                                $expanded[]        = $entry;
                            }
                        }
                    }//end if
                } catch (\Throwable $e) {
                    $rel['zaaktype'] = $baseUrl.'/zaaktypen/'.$ztRef;
                    $expanded[]      = $rel;
                }//end try
            }//end foreach

            // Deduplicate by zaaktype URL.
            $seen   = [];
            $unique = [];
            foreach ($expanded as $entry) {
                $ztUrl = $entry['zaaktype'] ?? '';
                if (isset($seen[$ztUrl]) === false) {
                    $seen[$ztUrl] = true;
                    $unique[]     = $entry;
                }
            }

            $data['gerelateerdeZaaktypen'] = $unique;
        }//end if

        // Populate informatieobjecttypen from ZIOT records.
        // For each ZIOT, find the referenced IOT, then find ALL IOTs with the
        // same name (omschrijving) so filterValidUrls can select the valid ones.
        $ziotMapping = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaaktype-informatieobjecttypen');
        $iotMapping  = $this->zgwService->loadMappingConfig(self::ZGW_API, 'informatieobjecttypen');
        if ($ziotMapping !== null && $iotMapping !== null) {
            try {
                $query  = $objectService->buildSearchQuery(
                    requestParams: ['zaaktype' => $uuid, '_limit' => 100],
                    register: $ziotMapping['sourceRegister'],
                    schema: $ziotMapping['sourceSchema']
                );
                $result = $objectService->searchObjectsPaginated(query: $query);

                $iotUrls = [];
                foreach (($result['results'] ?? []) as $ziot) {
                    if (is_array($ziot) === true) {
                        $ziotData = $ziot;
                    } else {
                        $ziotData = $ziot->jsonSerialize();
                    }

                    $iotRef = $ziotData['informatieobjecttype'] ?? '';
                    if ($iotRef === '') {
                        continue;
                    }

                    // Look up the IOT to get its name, then find all IOTs with that name.
                    try {
                        $iotObj = $objectService->find(
                            id: $iotRef,
                            register: $iotMapping['sourceRegister'],
                            schema: $iotMapping['sourceSchema']
                        );
                        if (is_array($iotObj) === true) {
                            $iotData = $iotObj;
                        } else {
                            $iotData = $iotObj->jsonSerialize();
                        }

                        $iotName = $iotData['name'] ?? '';

                        if ($iotName !== '') {
                            // Find ALL IOTs with this name.
                            $iotQuery  = $objectService->buildSearchQuery(
                                requestParams: ['name' => $iotName, '_limit' => 100],
                                register: $iotMapping['sourceRegister'],
                                schema: $iotMapping['sourceSchema']
                            );
                            $iotResult = $objectService->searchObjectsPaginated(query: $iotQuery);
                            foreach (($iotResult['results'] ?? []) as $matchingIot) {
                                if (is_array($matchingIot) === true) {
                                    $mData = $matchingIot;
                                } else {
                                    $mData = $matchingIot->jsonSerialize();
                                }

                                $mId = $mData['id'] ?? ($mData['@self']['id'] ?? '');
                                if ($mId !== '') {
                                    $iotUrls[] = $baseUrl.'/informatieobjecttypen/'.$mId;
                                }
                            }
                        }//end if
                    } catch (\Throwable $e) {
                        // If IOT lookup fails, fall back to direct UUID.
                        $iotUrls[] = $baseUrl.'/informatieobjecttypen/'.$iotRef;
                    }//end try
                }//end foreach

                // Deduplicate URLs.
                $iotUrls = array_values(array_unique($iotUrls));
                if (empty($iotUrls) === false) {
                    $data['informatieobjecttypen'] = $iotUrls;
                }
            } catch (\Throwable $e) {
                // Proceed without ZIOT enrichment.
            }//end try
        }//end if

        // Fallback: populate besluittypen from BT records with caseType = this UUID.
        // Only if not already populated from stored decisionTypes.
        $btMapping = $this->zgwService->loadMappingConfig(self::ZGW_API, 'besluittypen');
        if ($btMapping !== null
            && (isset($data['besluittypen']) === false || empty($data['besluittypen']) === true)
        ) {
            try {
                $query  = $objectService->buildSearchQuery(
                    requestParams: ['caseType' => $uuid, '_limit' => 100],
                    register: $btMapping['sourceRegister'],
                    schema: $btMapping['sourceSchema']
                );
                $result = $objectService->searchObjectsPaginated(query: $query);

                $btUrls = [];
                foreach (($result['results'] ?? []) as $bt) {
                    if (is_array($bt) === true) {
                        $btData = $bt;
                    } else {
                        $btData = $bt->jsonSerialize();
                    }

                    $btUuid = $btData['id'] ?? ($btData['@self']['id'] ?? '');
                    if ($btUuid !== '') {
                        $btUrls[] = $baseUrl.'/besluittypen/'.$btUuid;
                    }
                }

                if (empty($btUrls) === false) {
                    $data['besluittypen'] = $btUrls;
                }
            } catch (\Throwable $e) {
                // Proceed without BT enrichment.
            }//end try
        }//end if

        // Populate eigenschappen, statustypen, resultaattypen, roltypen
        // by searching for sub-resources with caseType = this zaaktype UUID.
        $subResourceTypes = [
            'eigenschappen'  => 'eigenschappen',
            'statustypen'    => 'statustypen',
            'resultaattypen' => 'resultaattypen',
            'roltypen'       => 'roltypen',
        ];
        foreach ($subResourceTypes as $zgwField => $resourceName) {
            $subMapping = $this->zgwService->loadMappingConfig(self::ZGW_API, $resourceName);
            if ($subMapping === null) {
                continue;
            }

            try {
                $query  = $objectService->buildSearchQuery(
                    requestParams: ['caseType' => $uuid, '_limit' => 100],
                    register: $subMapping['sourceRegister'],
                    schema: $subMapping['sourceSchema']
                );
                $result = $objectService->searchObjectsPaginated(query: $query);

                $urls = [];
                foreach (($result['results'] ?? []) as $sub) {
                    if (is_array($sub) === true) {
                        $subData = $sub;
                    } else {
                        $subData = $sub->jsonSerialize();
                    }

                    $subUuid = $subData['id'] ?? ($subData['@self']['id'] ?? '');
                    if ($subUuid !== '') {
                        $urls[] = $baseUrl.'/'.$resourceName.'/'.$subUuid;
                    }
                }

                if (empty($urls) === false) {
                    $data[$zgwField] = $urls;
                }
            } catch (\Throwable $e) {
                // Proceed without sub-resource enrichment.
            }//end try
        }//end foreach

        return $data;
    }//end enrichZaaktype()

    /**
     * Filter a list of ZTC results by datumGeldigheid (date validity).
     *
     * Returns only items where beginGeldigheid <= datumGeldigheid and
     * (eindeGeldigheid >= datumGeldigheid or eindeGeldigheid is absent).
     *
     * @param array  $results         The array of outbound-mapped result items.
     * @param string $datumGeldigheid The validity date in Y-m-d format.
     *
     * @return array The filtered results (re-indexed).
     */
    private function filterByDatumGeldigheid(array $results, string $datumGeldigheid): array
    {
        $filtered = [];
        foreach ($results as $item) {
            $begin = $item['beginGeldigheid'] ?? null;
            $end   = $item['eindeGeldigheid'] ?? null;

            // BeginGeldigheid must be present and <= datumGeldigheid.
            if ($begin !== null && $begin !== '' && $begin > $datumGeldigheid) {
                continue;
            }

            // EindeGeldigheid, if present, must be >= datumGeldigheid.
            if ($end !== null && $end !== '' && $end < $datumGeldigheid) {
                continue;
            }

            $filtered[] = $item;
        }

        return $filtered;
    }//end filterByDatumGeldigheid()

    /**
     * For zaaktypen and besluittypen, removes URLs from array fields that point to
     * objects which are not published or not currently valid (date-wise).
     *
     * @param string $resource The ZGW resource name.
     * @param array  $data     The outbound-mapped response data.
     *
     * @return array The filtered response data.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function filterValidUrls(string $resource, array $data): array
    {
        $fieldConfigs = self::URL_FILTER_FIELDS[$resource] ?? [];
        if (empty($fieldConfigs) === true || $this->zgwService->getObjectService() === null) {
            return $data;
        }

        $today = date('Y-m-d');

        foreach ($fieldConfigs as $field => $config) {
            if (isset($data[$field]) === false || is_array($data[$field]) === false) {
                continue;
            }

            $schemaKey = $config['schemaKey'];
            $nested    = $config['nested'];

            $filtered = [];
            foreach ($data[$field] as $item) {
                if ($nested === true) {
                    // GerelateerdeZaaktypen: array of objects with 'zaaktype' URL field.
                    $url = $item['zaaktype'] ?? '';
                    if ($this->isUrlValid(url: $url, schemaKey: $schemaKey, today: $today) === true) {
                        $filtered[] = $item;
                    }
                } else {
                    // Simple URL string array.
                    if (is_string($item) === true
                        && $this->isUrlValid(url: $item, schemaKey: $schemaKey, today: $today) === true
                    ) {
                        $filtered[] = $item;
                    }
                }
            }

            $data[$field] = $filtered;
        }//end foreach

        return $data;
    }//end filterValidUrls()

    /**
     * Check if a ZGW URL points to a valid, published, and currently active object.
     *
     * Uses the mapping config's sourceRegister and sourceSchema to look up the object.
     * The schemaKey maps to a ZGW resource name for which we load its mapping config.
     *
     * @param string $url       The URL to validate.
     * @param string $schemaKey The settings config key identifying the target schema.
     * @param string $today     Today's date in Y-m-d format.
     *
     * @return bool True if the referenced object exists, is published, and is date-valid.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function isUrlValid(string $url, string $schemaKey, string $today): bool
    {
        if (empty($url) === true) {
            return false;
        }

        // Extract UUID from URL.
        if (preg_match(
            '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i',
            $url,
            $matches
        ) !== 1
        ) {
            return false;
        }

        $uuid = $matches[1];

        try {
            // Map schema config key to ZGW resource name for mapping lookup.
            $resourceMap = [
                'document_type_schema' => 'informatieobjecttypen',
                'decision_type_schema' => 'besluittypen',
                'case_type_schema'     => 'zaaktypen',
            ];

            $targetResource = $resourceMap[$schemaKey] ?? null;
            if ($targetResource === null) {
                return true;
            }

            $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $targetResource);
            if ($mappingConfig === null) {
                return true;
            }

            $object = $this->zgwService->getObjectService()->find(
                id: $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );

            if (is_array($object) === true) {
                $objectData = $object;
            } else {
                $objectData = $object->jsonSerialize();
            }

            // Must be published (isDraft=false / concept=false).
            $isDraft = $objectData['isDraft'] ?? ($objectData['concept'] ?? true);
            if ($isDraft === true || $isDraft === 'true' || $isDraft === '1' || $isDraft === 1) {
                return false;
            }

            // Check date validity: beginGeldigheid <= today.
            $begin = $objectData['validFrom'] ?? ($objectData['beginGeldigheid'] ?? null);
            if ($begin !== null && $begin !== '' && $begin > $today) {
                return false;
            }

            // Check date validity: eindeGeldigheid >= today (or no end date).
            $end = $objectData['validUntil'] ?? ($objectData['eindeGeldigheid'] ?? null);
            if ($end !== null && $end !== '' && $end < $today) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // If we can't look up the object, exclude the URL.
            return false;
        }//end try
    }//end isUrlValid()

    /**
     * List audit trail entries for a resource.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
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
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->zgwService->handleAudittrailIndex($this->request, self::ZGW_API, $resource, $uuid);
    }//end audittrailIndex()

    /**
     * Retrieve a single audit trail entry for a resource.
     *
     * @param string $resource  The ZGW resource name.
     * @param string $uuid      The resource UUID.
     * @param string $auditUuid The audit trail entry UUID.
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
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->zgwService->handleAudittrailShow(
            $this->request,
            self::ZGW_API,
            $resource,
            $uuid,
            $auditUuid
        );
    }//end audittrailShow()

    /**
     * Resolve informatieobjecttype by omschrijving when not a UUID/URL (ztc-010m).
     *
     * The ZGW standard allows referencing an IOT by omschrijving in ZIOT creation.
     * This method looks up the IOT by omschrijving and replaces it with its UUID.
     *
     * @param array $body The request body (modified in-place via cached body)
     *
     * @return void
     */
    private function resolveIotByOmschrijving(array $body): void
    {
        $iotValue = $body['informatieobjecttype'] ?? '';
        if ($iotValue === '') {
            return;
        }

        // Already a UUID or URL — no resolution needed.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $iotValue) === 1) {
            return;
        }

        if (filter_var($iotValue, FILTER_VALIDATE_URL) !== false) {
            return;
        }

        // Try to look up by omschrijving (internal field: name).
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $iotMapping = $this->zgwService->loadMappingConfig(self::ZGW_API, 'informatieobjecttypen');
        if ($iotMapping === null) {
            return;
        }

        try {
            $query  = $objectService->buildSearchQuery(
                requestParams: ['name' => $iotValue, '_limit' => 1],
                register: $iotMapping['sourceRegister'],
                schema: $iotMapping['sourceSchema']
            );
            $result = $objectService->searchObjectsPaginated(
                query: $query,
                _rbac: false,
                _multitenancy: false
            );

            if (($result['total'] ?? 0) === 0) {
                // Fallback: full-text search.
                $query  = $objectService->buildSearchQuery(
                    requestParams: ['_search' => $iotValue, '_limit' => 1],
                    register: $iotMapping['sourceRegister'],
                    schema: $iotMapping['sourceSchema']
                );
                $result = $objectService->searchObjectsPaginated(
                    query: $query,
                    _rbac: false,
                    _multitenancy: false
                );
            }

            if (($result['total'] ?? 0) > 0) {
                $iot = $result['results'][0];
                if (is_array($iot) === true) {
                    $iotData = $iot;
                } else {
                    $iotData = $iot->jsonSerialize();
                }

                $iotUuid = $iotData['id'] ?? ($iotData['@self']['id'] ?? '');
                if ($iotUuid !== '') {
                    $this->zgwService->updateCachedBodyField('informatieobjecttype', $iotUuid);
                }
            }
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->debug(
                'ztc-010m: Failed to resolve IOT by omschrijving: '.$e->getMessage()
            );
        }//end try
    }//end resolveIotByOmschrijving()
}//end class
