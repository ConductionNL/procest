<?php

/**
 * Procest DRC (Documenten) Controller
 *
 * Controller for serving ZGW Documenten API endpoints (enkelvoudiginformatieobjecten,
 * objectinformatieobjecten, gebruiksrechten, verzendingen). Handles EIO-specific
 * features: base64 file content, document locking, and file downloads.
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
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * DRC (Documenten) API Controller
 *
 * Handles ZGW Documenten register resources with EIO-specific features:
 * base64 file content handling, document locking/unlocking, and downloads.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class DrcController extends Controller
{

    /**
     * The ZGW API identifier for the Documenten register.
     *
     * @var string
     */
    private const ZGW_API = 'documenten';

    /**
     * The EIO resource name.
     *
     * @var string
     */
    private const EIO_RESOURCE = 'enkelvoudiginformatieobjecten';

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
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * List resources of the given type.
     *
     * @param string $resource The ZGW resource name (e.g. enkelvoudiginformatieobjecten).
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

        // ObjectInformatieObjecten returns a plain array per ZGW spec.
        if ($resource === 'objectinformatieobjecten') {
            return $this->indexObjectInformatieObjecten();
        }

        return $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);
    }//end index()

    /**
     * List ObjectInformatieObjecten as a plain array (per ZGW spec).
     *
     * @return JSONResponse
     */
    private function indexObjectInformatieObjecten(): JSONResponse
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return $this->zgwService->unavailableResponse();
        }

        $resource      = 'objectinformatieobjecten';
        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
        }

        try {
            $params  = $this->request->getParams();
            $filters = $this->zgwService->translateQueryParams(
                params: $params,
                mappingConfig: $mappingConfig
            );

            $searchParams = array_merge($filters, ['_limit' => 100]);

            $query  = $objectService->buildSearchQuery(
                requestParams: $searchParams,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $result = $objectService->searchObjectsPaginated(query: $query);

            $objects         = $result['results'] ?? [];
            $baseUrl         = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
            $outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
            $mapped          = [];
            foreach ($objects as $object) {
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
                'DRC list OIO error: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => 'Internal server error'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end indexObjectInformatieObjecten()

    /**
     * Create a new resource of the given type.
     *
     * For EIO resources, handles base64 file content (inhoud field) by storing
     * the file separately via the document service after saving the object.
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

        // drc-006 (VNG): Gebruiksrechten create — set indicatieGebruiksrecht to true on EIO.
        if ($resource === 'gebruiksrechten') {
            $response = $this->zgwService->handleCreate($this->request, self::ZGW_API, $resource);
            if ($response->getStatus() === Http::STATUS_CREATED) {
                $this->updateIndicatieGebruiksrecht($response);
            }

            return $response;
        }

        // For non-EIO resources, use generic create.
        if ($resource !== self::EIO_RESOURCE) {
            return $this->zgwService->handleCreate($this->request, self::ZGW_API, $resource);
        }

        // EIO-specific: handle inhoud (base64 file content).
        if ($this->zgwService->getObjectService() === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
        }

        try {
            $body = $this->zgwService->getRequestBody($this->request);

            $ruleResult = $this->zgwService->getBusinessRulesService()->validate(
                zgwApi: self::ZGW_API,
                resource: $resource,
                action: 'create',
                body: $body,
                objectService: $this->zgwService->getObjectService(),
                mappingConfig: $mappingConfig
            );
            if ($ruleResult['valid'] === false) {
                return new JSONResponse(
                    data: $this->zgwService->buildValidationError($ruleResult),
                    statusCode: $ruleResult['status']
                );
            }

            $body = $ruleResult['enrichedBody'];

            $inhoud = $body['inhoud'] ?? null;

            $inboundMapping = $this->zgwService->createInboundMapping(mappingConfig: $mappingConfig);
            $englishData    = $this->zgwService->applyInboundMapping(
                body: $body,
                mapping: $inboundMapping,
                mappingConfig: $mappingConfig
            );

            if (empty($inhoud) === false) {
                unset($englishData['content']);
            }

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

            // Store file content.
            if (empty($inhoud) === false && $objectUuid !== '') {
                $fileName = $objectData['fileName'] ?? 'document';
                if ($fileName === '') {
                    $fileName = 'document';
                }

                $fileSize = $this->zgwService->getDocumentService()->storeBase64(
                    uuid: $objectUuid,
                    fileName: $fileName,
                    content: $inhoud
                );

                if (empty($objectData['fileSize']) === true) {
                    $objectData['fileSize'] = $fileSize;
                    $objectData['uuid']     = $objectUuid;
                    $this->zgwService->getObjectService()->saveObject(
                        register: $mappingConfig['sourceRegister'],
                        schema: $mappingConfig['sourceSchema'],
                        object: $objectData
                    );
                }
            }//end if

            $baseUrl         = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
            $outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
            $mapped          = $this->zgwService->applyOutboundMapping(
                objectData: $objectData,
                mapping: $outboundMapping,
                mappingConfig: $mappingConfig,
                baseUrl: $baseUrl
            );

            $this->zgwService->publishNotification(
                self::ZGW_API,
                $resource,
                $baseUrl.'/'.$objectUuid,
                'create'
            );

            return new JSONResponse(data: $mapped, statusCode: Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'DRC create error: '.$e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
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

        return $this->zgwService->handleShow($this->request, self::ZGW_API, $resource, $uuid);
    }//end show()

    /**
     * Full update (PUT) a resource by UUID.
     *
     * For EIO resources, checks document lock and handles inhoud.
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

        if ($resource === self::EIO_RESOURCE) {
            return $this->handleEioUpdate($resource, $uuid, false);
        }

        return $this->zgwService->handleUpdate($this->request, self::ZGW_API, $resource, $uuid, false);
    }//end update()

    /**
     * Partial update (PATCH) a resource by UUID.
     *
     * For EIO resources, checks document lock and handles inhoud.
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

        if ($resource === self::EIO_RESOURCE) {
            return $this->handleEioUpdate($resource, $uuid, true);
        }

        return $this->zgwService->handleUpdate($this->request, self::ZGW_API, $resource, $uuid, true);
    }//end patch()

    /**
     * Delete a resource by UUID.
     *
     * For EIO resources, deletes stored files after deleting the object.
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

        // drc-006 (VNG): Gebruiksrechten delete — update indicatieGebruiksrecht on EIO.
        if ($resource === 'gebruiksrechten') {
            $grData   = $this->getGebruiksrechtData($uuid);
            $response = $this->zgwService->handleDestroy($this->request, self::ZGW_API, $resource, $uuid);
            if ($response->getStatus() === Http::STATUS_NO_CONTENT && $grData !== null) {
                $this->checkAndClearIndicatieGebruiksrecht($grData['informatieobjectUuid']);
            }

            return $response;
        }

        if ($resource === self::EIO_RESOURCE && $this->zgwService->getObjectService() !== null) {
            $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
            if ($mappingConfig !== null) {
                try {
                    $existing     = $this->zgwService->getObjectService()->find(
                        $uuid,
                        register: $mappingConfig['sourceRegister'],
                        schema: $mappingConfig['sourceSchema']
                    );
                    $existingData = is_array($existing) ? $existing : $existing->jsonSerialize();
                    $fileName     = $existingData['fileName'] ?? 'document';
                    if ($fileName === '') {
                        $fileName = 'document';
                    }
                } catch (\Throwable $e) {
                    $fileName = null;
                }
            }
        }

        $response = $this->zgwService->handleDestroy($this->request, self::ZGW_API, $resource, $uuid);

        // Post-delete cleanup (only on successful deletion).
        if ($resource === self::EIO_RESOURCE
            && $response->getStatus() === Http::STATUS_NO_CONTENT
        ) {
            // drc-008 (VNG): Cascade delete gebruiksrechten after EIO deletion.
            $this->cascadeDeleteGebruiksrechten($uuid);

            // Delete stored files.
            if (isset($fileName) === true && $fileName !== null) {
                try {
                    $this->zgwService->getDocumentService()->deleteFile(uuid: $uuid, fileName: $fileName);
                } catch (\Throwable $e) {
                    $this->zgwService->getLogger()->warning(
                        'DRC file cleanup failed: '.$e->getMessage(),
                        ['exception' => $e]
                    );
                }
            }
        }

        return $response;
    }//end destroy()

    /**
     * Download the binary file content for an EIO document.
     *
     * @param string $uuid The document UUID.
     *
     * @return DataDownloadResponse|JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function download(string $uuid): DataDownloadResponse|JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        if ($this->zgwService->getObjectService() === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->getZgwMappingService()->getMapping('enkelvoudiginformatieobject');
        if ($mappingConfig === null) {
            return new JSONResponse(
                data: ['detail' => 'Document mapping not configured'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        try {
            $object     = $this->zgwService->getObjectService()->find(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                id: $uuid
            );
            $objectData = is_array($object) ? $object : $object->jsonSerialize();

            $fileName = $objectData['fileName'] ?? 'document';
            if ($fileName === '') {
                $fileName = 'document';
            }

            $format = $objectData['format'] ?? 'application/octet-stream';

            if ($this->zgwService->getDocumentService()->fileExists(uuid: $uuid, fileName: $fileName) === false) {
                return new JSONResponse(
                    data: ['detail' => 'Bestand niet gevonden.'],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            $content = $this->zgwService->getDocumentService()->getContent(uuid: $uuid, fileName: $fileName);

            return new DataDownloadResponse(data: $content, filename: $fileName, contentType: $format);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'DRC download error: '.$e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(
                data: ['detail' => 'Not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }//end try
    }//end download()

    /**
     * Lock an EIO document.
     *
     * Sets the document as locked and generates a lock identifier.
     *
     * @param string $uuid The document UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function lock(string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        if ($this->zgwService->getObjectService() === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, self::EIO_RESOURCE);
        }

        try {
            $existing     = $this->zgwService->getObjectService()->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $existingData = is_array($existing) ? $existing : $existing->jsonSerialize();

            $lockedVal = $existingData['locked'] ?? false;
            if ($lockedVal === 'true' || $lockedVal === '1' || $lockedVal === 1) {
                $lockedVal = true;
            }

            if ($lockedVal === true) {
                return new JSONResponse(
                    data: ['detail' => 'Document is al vergrendeld.'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            $lockId = bin2hex(random_bytes(16));

            unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
            $existingData['locked'] = true;
            $existingData['lockId'] = $lockId;

            $this->zgwService->getObjectService()->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $existingData,
                uuid: $uuid
            );

            return new JSONResponse(data: ['lock' => $lockId], statusCode: Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'DRC lock error: '.$e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end lock()

    /**
     * Unlock an EIO document.
     *
     * Verifies the lock identifier and sets the document as unlocked.
     *
     * @param string $uuid The document UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @CORS
     */
    public function unlock(string $uuid): JSONResponse
    {
        $authError = $this->zgwService->validateJwtAuth($this->request);
        if ($authError !== null) {
            return $authError;
        }

        if ($this->zgwService->getObjectService() === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, self::EIO_RESOURCE);
        }

        try {
            $existing     = $this->zgwService->getObjectService()->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $existingData = is_array($existing) ? $existing : $existing->jsonSerialize();

            $lockedVal = $existingData['locked'] ?? false;
            if ($lockedVal === 'true' || $lockedVal === '1' || $lockedVal === 1) {
                $lockedVal = true;
            }

            if ($lockedVal !== true) {
                return new JSONResponse(
                    data: ['detail' => 'Document is niet vergrendeld.'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            $body   = $this->zgwService->getRequestBody($this->request);
            $lockId = $body['lock'] ?? '';

            // Check if this is a force unlock (no lock ID or wrong lock ID).
            $storedLockId = $existingData['lockId'] ?? '';
            if ($lockId !== '' && $lockId !== $storedLockId) {
                // Non-empty but wrong lock ID — reject unless force scope is available.
                $hasForceScope = $this->zgwService->consumerHasScope(
                    $this->request,
                    'documenten',
                    'geforceerd-bijwerken'
                );
                if ($hasForceScope === false) {
                    return new JSONResponse(
                        data: [
                            'detail'        => 'Lock ID komt niet overeen en '.'geforceerd unlocken is niet toegestaan.',
                            'invalidParams' => [
                                [
                                    'name'   => 'nonFieldErrors',
                                    'code'   => 'incorrect-lock-id',
                                    'reason' => 'Lock ID komt niet overeen.',
                                ],
                            ],
                        ],
                        statusCode: Http::STATUS_BAD_REQUEST
                    );
                }
            }//end if

            // drc-009k: Empty lock ID = forced unlock (always allowed per ZGW spec).

            unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
            $existingData['locked'] = false;
            $existingData['lockId'] = '';

            $this->zgwService->getObjectService()->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $existingData,
                uuid: $uuid
            );

            return new JSONResponse(data: '', statusCode: Http::STATUS_NO_CONTENT);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'DRC unlock error: '.$e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end unlock()

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
     * Cascade delete all gebruiksrechten for an EIO (drc-008 VNG).
     *
     * @param string $eioUuid The EIO UUID
     *
     * @return void
     */
    private function cascadeDeleteGebruiksrechten(string $eioUuid): void
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $grConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'gebruiksrechten');
        if ($grConfig === null) {
            return;
        }

        try {
            $query  = $objectService->buildSearchQuery(
                requestParams: ['document' => $eioUuid, '_limit' => 100],
                register: $grConfig['sourceRegister'],
                schema: $grConfig['sourceSchema']
            );
            $result = $objectService->searchObjectsPaginated(query: $query);

            foreach (($result['results'] ?? []) as $gr) {
                $grData = is_array($gr) ? $gr : $gr->jsonSerialize();
                $grUuid = $grData['id'] ?? ($grData['@self']['id'] ?? '');
                if ($grUuid !== '') {
                    $objectService->deleteObject(uuid: $grUuid);
                }
            }
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'drc-008: Failed to cascade delete gebruiksrechten for EIO '.$eioUuid.': '.$e->getMessage()
            );
        }//end try
    }//end cascadeDeleteGebruiksrechten()

    /**
     * Update indicatieGebruiksrecht on an EIO after creating a gebruiksrecht (drc-006 VNG).
     *
     * Sets indicatieGebruiksrecht to true on the related informatieobject.
     *
     * @param JSONResponse $response The create response containing the gebruiksrecht data
     *
     * @return void
     */
    private function updateIndicatieGebruiksrecht(JSONResponse $response): void
    {
        $data = $response->getData();
        if (is_array($data) === false) {
            return;
        }

        $ioUrl = $data['informatieobject'] ?? '';
        if ($ioUrl === '') {
            return;
        }

        $this->setIndicatieGebruiksrecht($ioUrl, true);
    }//end updateIndicatieGebruiksrecht()

    /**
     * Get gebruiksrecht data before deletion (drc-006 VNG).
     *
     * @param string $uuid The gebruiksrecht UUID
     *
     * @return array|null Array with informatieobjectUuid, or null
     */
    private function getGebruiksrechtData(string $uuid): ?array
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'gebruiksrechten');
        if ($mappingConfig === null) {
            return null;
        }

        try {
            $obj  = $objectService->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $data = is_array($obj) ? $obj : $obj->jsonSerialize();

            $ioRef       = $data['document'] ?? ($data['informatieobject'] ?? '');
            $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
            if (preg_match($uuidPattern, (string) $ioRef, $grMatches) === 1) {
                return ['informatieobjectUuid' => $grMatches[1]];
            }
        } catch (\Throwable $e) {
            // Not found.
        }

        return null;
    }//end getGebruiksrechtData()

    /**
     * Check if EIO still has gebruiksrechten after deletion (drc-006 VNG).
     *
     * If no gebruiksrechten remain, sets indicatieGebruiksrecht to null.
     *
     * @param string $eioUuid The EIO UUID
     *
     * @return void
     */
    private function checkAndClearIndicatieGebruiksrecht(string $eioUuid): void
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $grConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'gebruiksrechten');
        if ($grConfig === null) {
            return;
        }

        try {
            $query  = $objectService->buildSearchQuery(
                requestParams: ['document' => $eioUuid, '_limit' => 1],
                register: $grConfig['sourceRegister'],
                schema: $grConfig['sourceSchema']
            );
            $result = $objectService->searchObjectsPaginated(query: $query);
            $total  = $result['total'] ?? count($result['results'] ?? []);

            if ($total === 0) {
                // No more gebruiksrechten — clear indicatieGebruiksrecht.
                $eioConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
                if ($eioConfig !== null) {
                    try {
                        $eioObj  = $objectService->find(
                            $eioUuid,
                            register: $eioConfig['sourceRegister'],
                            schema: $eioConfig['sourceSchema']
                        );
                        $eioData = is_array($eioObj) ? $eioObj : $eioObj->jsonSerialize();
                        $eioData['usageRightsIndication'] = null;

                        unset($eioData['@self'], $eioData['id'], $eioData['organisation']);
                        $objectService->saveObject(
                            register: $eioConfig['sourceRegister'],
                            schema: $eioConfig['sourceSchema'],
                            object: $eioData,
                            uuid: $eioUuid
                        );
                    } catch (\Throwable $e) {
                        $this->zgwService->getLogger()->warning(
                            'drc-006: Failed to clear indicatieGebruiksrecht: '.$e->getMessage()
                        );
                    }//end try
                }//end if
            }//end if
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'drc-006: Failed to check remaining gebruiksrechten: '.$e->getMessage()
            );
        }//end try
    }//end checkAndClearIndicatieGebruiksrecht()

    /**
     * Set indicatieGebruiksrecht on an EIO (drc-006 VNG).
     *
     * @param string    $ioUrl The informatieobject URL
     * @param bool|null $value The value to set (true or null)
     *
     * @return void
     */
    private function setIndicatieGebruiksrecht(string $ioUrl, ?bool $value): void
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return;
        }

        $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
        if (preg_match($uuidPattern, $ioUrl, $ioMatches) !== 1) {
            return;
        }

        $eioConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
        if ($eioConfig === null) {
            return;
        }

        try {
            $eioObj  = $objectService->find(
                $ioMatches[1],
                register: $eioConfig['sourceRegister'],
                schema: $eioConfig['sourceSchema']
            );
            $eioData = is_array($eioObj) ? $eioObj : $eioObj->jsonSerialize();
            $eioData['usageRightsIndication'] = $value;

            unset($eioData['@self'], $eioData['id'], $eioData['organisation']);
            $objectService->saveObject(
                register: $eioConfig['sourceRegister'],
                schema: $eioConfig['sourceSchema'],
                object: $eioData,
                uuid: $ioMatches[1]
            );
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'drc-006: Failed to set indicatieGebruiksrecht: '.$e->getMessage()
            );
        }//end try
    }//end setIndicatieGebruiksrecht()

    /**
     * Handle EIO-specific update with lock checking and inhoud handling.
     *
     * @param string $resource The ZGW resource name.
     * @param string $uuid     The resource UUID.
     * @param bool   $partial  Whether this is a partial (PATCH) update.
     *
     * @return JSONResponse
     */
    private function handleEioUpdate(string $resource, string $uuid, bool $partial): JSONResponse
    {
        if ($this->zgwService->getObjectService() === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
        if ($mappingConfig === null) {
            return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
        }

        try {
            $body = $this->zgwService->getRequestBody($this->request);

            // Check document lock (drc-009).
            $lockError = $this->checkDocumentLock(
                mappingConfig: $mappingConfig,
                uuid: $uuid,
                body: $body,
                partial: $partial
            );
            if ($lockError !== null) {
                return $lockError;
            }

            $ruleResult = $this->zgwService->getBusinessRulesService()->validate(
                zgwApi: self::ZGW_API,
                resource: $resource,
                action: ($partial === true ? 'partial_update' : 'update'),
                body: $body,
                objectService: $this->zgwService->getObjectService(),
                mappingConfig: $mappingConfig
            );
            if ($ruleResult['valid'] === false) {
                return new JSONResponse(
                    data: $this->zgwService->buildValidationError($ruleResult),
                    statusCode: $ruleResult['status']
                );
            }

            $body = $ruleResult['enrichedBody'];

            $inhoud = $body['inhoud'] ?? null;

            // Preserve lock state from existing object.
            $existing     = $this->zgwService->getObjectService()->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $existingData = is_array($existing) ? $existing : $existing->jsonSerialize();

            $inboundMapping = $this->zgwService->createInboundMapping(mappingConfig: $mappingConfig);
            $englishData    = $this->zgwService->applyInboundMapping(
                body: $body,
                mapping: $inboundMapping,
                mappingConfig: $mappingConfig
            );

            if (empty($inhoud) === false) {
                unset($englishData['content']);
            }

            if (is_array($englishData) === false) {
                return new JSONResponse(
                    data: ['detail' => 'Invalid mapping result'],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Preserve lock state.
            $englishData['locked'] = $existingData['locked'] ?? false;
            $englishData['lockId'] = $existingData['lockId'] ?? '';

            $object     = $this->zgwService->getObjectService()->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $englishData,
                uuid: $uuid
            );
            $objectData = is_array($object) ? $object : $object->jsonSerialize();
            $objectUuid = $objectData['id'] ?? ($objectData['@self']['id'] ?? $uuid);

            // Store file content.
            if (empty($inhoud) === false && $objectUuid !== '') {
                $fileName = $objectData['fileName'] ?? 'document';
                if ($fileName === '') {
                    $fileName = 'document';
                }

                $fileSize = $this->zgwService->getDocumentService()->storeBase64(
                    uuid: $objectUuid,
                    fileName: $fileName,
                    content: $inhoud
                );

                if (empty($objectData['fileSize']) === true) {
                    $objectData['fileSize'] = $fileSize;
                    $objectData['uuid']     = $objectUuid;
                    $this->zgwService->getObjectService()->saveObject(
                        register: $mappingConfig['sourceRegister'],
                        schema: $mappingConfig['sourceSchema'],
                        object: $objectData
                    );
                }
            }//end if

            $baseUrl         = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
            $outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
            $mapped          = $this->zgwService->applyOutboundMapping(
                objectData: $objectData,
                mapping: $outboundMapping,
                mappingConfig: $mappingConfig,
                baseUrl: $baseUrl
            );

            $this->zgwService->publishNotification(
                self::ZGW_API,
                $resource,
                $baseUrl.'/'.$objectUuid,
                'update'
            );

            return new JSONResponse(data: $mapped);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'DRC update error: '.$e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end try
    }//end handleEioUpdate()

    /**
     * Check document lock state before allowing update.
     *
     * Validates DRC business rules:
     * - drc-009a/b: Document must be locked for updates.
     * - drc-009d/e: Lock ID must be provided.
     * - drc-009h/i: Lock ID must match the stored lock.
     *
     * @param array  $mappingConfig The mapping configuration.
     * @param string $uuid          The document UUID.
     * @param array  $body          The request body.
     * @param bool   $partial       Whether this is a partial (PATCH) update.
     *
     * @return JSONResponse|null Error response if lock check fails, null if OK.
     */
    private function checkDocumentLock(
        array $mappingConfig,
        string $uuid,
        array $body,
        bool $partial=false,
    ): ?JSONResponse {
        try {
            $existing     = $this->zgwService->getObjectService()->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            $existingData = is_array($existing) ? $existing : $existing->jsonSerialize();
        } catch (\Throwable $e) {
            return new JSONResponse(
                data: ['detail' => 'Document not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        $isLocked     = $existingData['locked'] ?? false;
        $storedLockId = $existingData['lockId'] ?? '';

        // Normalize boolean — OpenRegister may store as string or int.
        if ($isLocked === 'true' || $isLocked === '1' || $isLocked === 1) {
            $isLocked = true;
        }

        // drc-009a/b: Document must be locked to allow updates.
        if ($isLocked !== true) {
            return new JSONResponse(
                data: [
                    'detail'        => 'Alleen vergrendelde documenten mogen bewerkt worden.',
                    'invalidParams' => [
                        [
                            'name'   => 'nonFieldErrors',
                            'code'   => 'unlocked',
                            'reason' => 'Het document is niet vergrendeld. '.'Vergrendel het document eerst.',
                        ],
                    ],
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $providedLockId = $body['lock'] ?? '';

        // drc-009d/e: Lock ID must be provided.
        if ($providedLockId === '') {
            // PUT (full update): lock is a required field (drc-009d).
            // PATCH (partial): lock is missing for lock enforcement (drc-009e).
            if ($partial === false) {
                $errorName = 'lock';
                $errorCode = 'required';
            } else {
                $errorName = 'nonFieldErrors';
                $errorCode = 'missing-lock-id';
            }

            return new JSONResponse(
                data: [
                    'detail'        => 'Lock ID is vereist voor het bewerken '.'van een vergrendeld document.',
                    'invalidParams' => [
                        [
                            'name'   => $errorName,
                            'code'   => $errorCode,
                            'reason' => 'Lock ID ontbreekt in het verzoek.',
                        ],
                    ],
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }//end if

        // drc-009h/i: Lock ID must match.
        if ($providedLockId !== $storedLockId) {
            return new JSONResponse(
                data: [
                    'detail'        => 'Lock ID komt niet overeen.',
                    'invalidParams' => [
                        [
                            'name'   => 'nonFieldErrors',
                            'code'   => 'incorrect-lock-id',
                            'reason' => 'Lock ID komt niet overeen met de '.'opgeslagen vergrendeling.',
                        ],
                    ],
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return null;
    }//end checkDocumentLock()
}//end class
