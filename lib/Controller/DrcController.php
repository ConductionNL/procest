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
        parent::__construct(appName: $appName, request: $request);
    }

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

        // ObjectInformatieObjecten and Gebruiksrechten return a plain array per ZGW spec.
        if ($resource === 'objectinformatieobjecten' || $resource === 'gebruiksrechten') {
            return $this->indexFlatArray(resource: $resource);
        }

        return $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);
    }

    /**
     * List DRC resources as a plain array (per ZGW spec).
     *
     * Used for objectinformatieobjecten and gebruiksrechten which return
     * flat arrays instead of paginated results.
     *
     * @param string $resource The ZGW resource name
     *
     * @return JSONResponse
     */
    private function indexFlatArray(string $resource): JSONResponse
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return $this->zgwService->unavailableResponse();
        }

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
                'DRC list ' . $resource . ' error: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                data: ['detail' => 'Internal server error'],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

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

        // Drc-006 (VNG): Gebruiksrechten create — set indicatieGebruiksrecht to true on EIO.
        if ($resource === 'gebruiksrechten') {
            $response = $this->zgwService->handleCreate($this->request, self::ZGW_API, $resource);
            if ($response->getStatus() === Http::STATUS_CREATED) {
                $this->updateIndicatieGebruiksrecht(response: $response);
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
            }

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
                $baseUrl . '/' . $objectUuid,
                'create'
            );

            return new JSONResponse(data: $mapped, statusCode: Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'DRC create error: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }

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
    }

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
            return $this->handleEioUpdate(resource: $resource, uuid: $uuid, partial: false);
        }

        return $this->zgwService->handleUpdate($this->request, self::ZGW_API, $resource, $uuid, false);
    }

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
            return $this->handleEioUpdate(resource: $resource, uuid: $uuid, partial: true);
        }

        return $this->zgwService->handleUpdate($this->request, self::ZGW_API, $resource, $uuid, true);
    }

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

        // Drc-006 (VNG): Gebruiksrechten delete — update indicatieGebruiksrecht on EIO.
        if ($resource === 'gebruiksrechten') {
            $grData   = $this->getGebruiksrechtData(uuid: $uuid);
            $response = $this->zgwService->handleDestroy($this->request, self::ZGW_API, $resource, $uuid);
            if ($response->getStatus() === Http::STATUS_NO_CONTENT && $grData !== null) {
                $this->checkAndClearIndicatieGebruiksrecht(eioUuid: $grData['informatieobjectUuid']);
            }

            return $response;
        }

        if ($resource === self::EIO_RESOURCE && $this->zgwService->getObjectService() !== null) {
            $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
            if ($mappingConfig !== null) {
                try {
                    $existing = $this->zgwService->getObjectService()->find(
                        $uuid,
                        register: $mappingConfig['sourceRegister'],
                        schema: $mappingConfig['sourceSchema']
                    );
                    if (is_array($existing) === true) {
                        $existingData = $existing;
                    } else {
                        $existingData = $existing->jsonSerialize();
                    }

                    $fileName = $existingData['fileName'] ?? 'document';
                    if ($fileName === '') {
                        $fileName = 'document';
                    }
                } catch (\Throwable $e) {
                    $fileName = null;
                }
            }
        }

        // Drc-008a (VNG): Block EIO deletion when OIO relations exist.
        if ($resource === self::EIO_RESOURCE && $this->zgwService->getObjectService() !== null) {
            $oioRelations = $this->findOioRelationsForEio(eioUuid: $uuid);
            if (empty($oioRelations) === false) {
                return new JSONResponse(
                    [
                        'detail'        => 'Het informatieobject kan niet verwijderd worden:'
                            . ' er zijn gerelateerde ObjectInformatieObjecten.',
                        'invalidParams' => [
                            [
                                'name'   => 'nonFieldErrors',
                                'code'   => 'pending-relations',
                                'reason' => 'Het informatieobject kan niet verwijderd worden.',
                            ],
                        ],
                    ],
                    Http::STATUS_BAD_REQUEST
                );
            }
        }

        $response = $this->zgwService->handleDestroy($this->request, self::ZGW_API, $resource, $uuid);

        // Post-delete cleanup (only on successful deletion).
        if (
            $resource === self::EIO_RESOURCE
            && $response->getStatus() === Http::STATUS_NO_CONTENT
        ) {
            // Drc-008 (VNG): Cascade delete gebruiksrechten after EIO deletion.
            $this->cascadeDeleteGebruiksrechten(eioUuid: $uuid);

            // Delete stored files.
            if (isset($fileName) === true && $fileName !== null) {
                try {
                    $this->zgwService->getDocumentService()->deleteFile(uuid: $uuid, fileName: $fileName);
                } catch (\Throwable $e) {
                    $this->zgwService->getLogger()->warning(
                        'DRC file cleanup failed: ' . $e->getMessage(),
                        ['exception' => $e]
                    );
                }
            }
        }

        return $response;
    }

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
            $object = $this->zgwService->getObjectService()->find(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                id: $uuid
            );
            if (is_array($object) === true) {
                $objectData = $object;
            } else {
                $objectData = $object->jsonSerialize();
            }

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
                'DRC download error: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(
                data: ['detail' => 'Not found'],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }
    }

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

        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return $this->zgwService->unavailableResponse();
        }

        // Check if already locked (entity lock or data blob fallback).
        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
        if (
            $mappingConfig !== null
            && $this->resolveStoredLockId(
                objectService: $objectService,
                mappingConfig: $mappingConfig,
                uuid: $uuid
            ) !== null
        ) {
            return new JSONResponse(
                data: ['detail' => 'Document is al vergrendeld.'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $objectService->lockObject(identifier: $uuid);

            // OpenRegister's lock system doesn't produce a ZGW lockId.
            // Generate one and store it in the data blob for verification.
            $lockId = bin2hex(random_bytes(16));
            if ($mappingConfig !== null) {
                $this->storeLockIdInData(
                    objectService: $objectService,
                    mappingConfig: $mappingConfig,
                    uuid: $uuid,
                    lockId: $lockId
                );
            }

            return new JSONResponse(
                data: ['lock' => $lockId],
                statusCode: Http::STATUS_OK
            );
        } catch (\OCA\OpenRegister\Exception\LockedException $e) {
            return new JSONResponse(
                data: ['detail' => 'Document is al vergrendeld.'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            // Fallback: OpenRegister lock may fail without a Nextcloud user
            // session (JWT-only context). Use manual lock via saveObject.
            return $this->lockFallback(objectService: $objectService, uuid: $uuid, original: $e);
        }
    }

    /**
     * Fallback lock implementation for when OpenRegister's LockHandler
     * fails due to missing Nextcloud user session (JWT-only context).
     *
     * @param object     $objectService The OpenRegister ObjectService
     * @param string     $uuid          The document UUID
     * @param \Throwable $original      The original exception
     *
     * @return JSONResponse
     */
    private function lockFallback(object $objectService, string $uuid, \Throwable $original): JSONResponse
    {
        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
        if ($mappingConfig === null) {
            return new JSONResponse(
                data: ['detail' => $original->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $existing = $objectService->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($existing) === true) {
                $existingData = $existing;
            } else {
                $existingData = $existing->jsonSerialize();
            }

            $lockId = bin2hex(random_bytes(16));

            unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
            $existingData['locked'] = true;
            $existingData['lockId'] = $lockId;

            $objectService->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $existingData,
                uuid: $uuid
            );

            return new JSONResponse(data: ['lock' => $lockId], statusCode: Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'DRC lock fallback error: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }

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

        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return $this->zgwService->unavailableResponse();
        }

        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);

        // Check if the document is actually locked (entity or data blob).
        if ($mappingConfig !== null) {
            $storedLockId = $this->resolveStoredLockId(
                objectService: $objectService,
                mappingConfig: $mappingConfig,
                uuid: $uuid
            );
        } else {
            $storedLockId = null;
        }

        if ($storedLockId === null) {
            return new JSONResponse(
                data: ['detail' => 'Document is niet vergrendeld.'],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $body   = $this->zgwService->getRequestBody($this->request);
        $lockId = $body['lock'] ?? '';

        // Determine if this is a forced unlock (wrong/empty lockId + scope).
        $force = false;
        if ($lockId !== $storedLockId) {
            $hasForceScope = $this->zgwService->consumerHasScope(
                $this->request,
                'documenten',
                'geforceerd-bijwerken'
            );
            if ($hasForceScope === false) {
                if ($lockId === '') {
                    $detail = 'Geforceerd unlocken is niet toegestaan zonder juiste scope.';
                } else {
                    $detail = 'Lock ID komt niet overeen en geforceerd unlocken is niet toegestaan.';
                }

                return new JSONResponse(
                    data: [
                        'detail'        => $detail,
                        'invalidParams' => [
                            [
                                'name'   => 'nonFieldErrors',
                                'code'   => 'incorrect-lock-id',
                                'reason' => $detail,
                            ],
                        ],
                    ],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            $force = true;
        }

        // Try OpenRegister's LockHandler, fall back to clearing data blob.
        try {
            $objectService->unlockObject(identifier: $uuid);

            // Clear lockId from the data blob.
            if ($mappingConfig !== null) {
                $this->clearLockIdInData(objectService: $objectService, mappingConfig: $mappingConfig, uuid: $uuid);
            }

            return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
        } catch (\Throwable $e) {
            // Fallback: unlock via saveObject when LockHandler fails
            // (e.g., no Nextcloud user session in JWT-only context).
            return $this->unlockFallback(objectService: $objectService, uuid: $uuid, original: $e);
        }
    }

    /**
     * Fallback unlock for when OpenRegister's LockHandler fails (no NC session).
     *
     * @param object     $objectService The OpenRegister ObjectService
     * @param string     $uuid          The document UUID
     * @param \Throwable $original      The original exception
     *
     * @return JSONResponse
     */
    private function unlockFallback(object $objectService, string $uuid, \Throwable $original): JSONResponse
    {
        $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
        if ($mappingConfig === null) {
            return new JSONResponse(
                data: ['detail' => $original->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $existing = $objectService->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($existing) === true) {
                $existingData = $existing;
            } else {
                $existingData = $existing->jsonSerialize();
            }

            unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
            $existingData['locked'] = false;
            $existingData['lockId'] = '';

            foreach ($existingData as $key => $value) {
                if ($value === null) {
                    unset($existingData[$key]);
                }
            }

            $objectService->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $existingData,
                uuid: $uuid
            );

            return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'DRC unlock fallback error: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }

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

        // Drc-008c (VNG): Return 404 if the parent resource no longer exists.
        if ($this->zgwService->getObjectService() !== null) {
            $mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
            if ($mappingConfig !== null) {
                try {
                    $this->zgwService->getObjectService()->find(
                        $uuid,
                        register: $mappingConfig['sourceRegister'],
                        schema: $mappingConfig['sourceSchema']
                    );
                } catch (\Throwable $e) {
                    return new JSONResponse(
                        data: ['detail' => 'Niet gevonden.'],
                        statusCode: Http::STATUS_NOT_FOUND
                    );
                }
            }
        }

        return $this->zgwService->handleAudittrailIndex($this->request, self::ZGW_API, $resource, $uuid);
    }

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
    }

    /**
     * Find relations for an EIO by UUID (drc-008a VNG).
     *
     * Checks OIO, ZIO, and BIO schemas for references to the given document.
     *
     * @param string $eioUuid The EIO UUID
     *
     * @return array List of related object IDs linked to this EIO
     */
    private function findOioRelationsForEio(string $eioUuid): array
    {
        $objectService = $this->zgwService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        // Check OIO, ZIO, and BIO schemas for references to this EIO.
        $schemasToCheck = [];

        // OIO (ObjectInformatieObject) — DRC register.
        $oioConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'objectinformatieobjecten');
        if ($oioConfig !== null) {
            $schemasToCheck[] = [
                'register' => $oioConfig['sourceRegister'],
                'schema'   => $oioConfig['sourceSchema'],
            ];
        }

        // ZIO (ZaakInformatieObject) — ZRC register.
        $zioConfig = $this->zgwService->loadMappingConfig('zaken', 'zaakinformatieobjecten');
        if ($zioConfig !== null) {
            $schemasToCheck[] = [
                'register' => $zioConfig['sourceRegister'],
                'schema'   => $zioConfig['sourceSchema'],
            ];
        }

        // BIO (BesluitInformatieObject) — BRC register.
        $bioConfig = $this->zgwService->loadMappingConfig('besluiten', 'besluitinformatieobjecten');
        if ($bioConfig !== null) {
            $schemasToCheck[] = [
                'register' => $bioConfig['sourceRegister'],
                'schema'   => $bioConfig['sourceSchema'],
            ];
        }

        foreach ($schemasToCheck as $schemaInfo) {
            $ids = $this->searchRelationsInSchema(
                objectService: $objectService,
                eioUuid: $eioUuid,
                register: $schemaInfo['register'],
                schema: $schemaInfo['schema']
            );
            if (empty($ids) === false) {
                return $ids;
            }
        }

        return [];
    }

    /**
     * Search for document relations in a specific schema.
     *
     * @param object $objectService The object service
     * @param string $eioUuid       The EIO UUID to search for
     * @param string $register      The register ID
     * @param string $schema        The schema ID
     *
     * @return array List of related object IDs
     */
    private function searchRelationsInSchema(
        object $objectService,
        string $eioUuid,
        string $register,
        string $schema
    ): array {
        try {
            // Try exact UUID match (OIO may store just the UUID).
            $query  = $objectService->buildSearchQuery(
                requestParams: ['document' => $eioUuid, '_limit' => 1],
                register: $register,
                schema: $schema
            );
            $result = $objectService->searchObjectsPaginated(query: $query);
            $ids    = $this->extractIdsFromResults(result: $result);
            if (empty($ids) === false) {
                return $ids;
            }

            // Fallback: full-text search by UUID (document field stores
            // the full URL, and field-specific LIKE is not supported).
            $query  = $objectService->buildSearchQuery(
                requestParams: ['_search' => $eioUuid, '_limit' => 1],
                register: $register,
                schema: $schema
            );
            $result = $objectService->searchObjectsPaginated(query: $query);
            $ids    = $this->extractIdsFromResults(result: $result);
            if (empty($ids) === false) {
                return $ids;
            }
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'drc-008a: Relation search failed for schema ' . $schema . ': ' . $e->getMessage()
            );
        }

        return [];
    }

    /**
     * Extract IDs from a search result set.
     *
     * @param array $result The search result from searchObjectsPaginated
     *
     * @return array<string> Array of object IDs
     */
    private function extractIdsFromResults(array $result): array
    {
        $ids = [];
        foreach (($result['results'] ?? []) as $obj) {
            if (is_array($obj) === true) {
                $data = $obj;
            } else {
                $data = $obj->jsonSerialize();
            }

            $id = $data['id'] ?? ($data['@self']['id'] ?? null);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

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
                requestParams: ['document' => '%' . $eioUuid . '%', '_limit' => 100],
                register: $grConfig['sourceRegister'],
                schema: $grConfig['sourceSchema']
            );
            $result = $objectService->searchObjectsPaginated(query: $query);

            foreach (($result['results'] ?? []) as $gr) {
                if (is_array($gr) === true) {
                    $grData = $gr;
                } else {
                    $grData = $gr->jsonSerialize();
                }

                $grUuid = $grData['id'] ?? ($grData['@self']['id'] ?? '');
                if ($grUuid !== '') {
                    $objectService->deleteObject(uuid: $grUuid);
                }
            }
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'drc-008: Failed to cascade delete gebruiksrechten for EIO ' . $eioUuid . ': ' . $e->getMessage()
            );
        }
    }

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

        $this->setIndicatieGebruiksrecht(ioUrl: $ioUrl, value: true);
    }

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
            $obj = $objectService->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($obj) === true) {
                $data = $obj;
            } else {
                $data = $obj->jsonSerialize();
            }

            $ioRef       = $data['document'] ?? ($data['informatieobject'] ?? '');
            $uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
            if (preg_match($uuidPattern, (string) $ioRef, $grMatches) === 1) {
                return ['informatieobjectUuid' => $grMatches[1]];
            }
        } catch (\Throwable $e) {
            // Not found.
        }

        return null;
    }

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
                        $eioObj = $objectService->find(
                            $eioUuid,
                            register: $eioConfig['sourceRegister'],
                            schema: $eioConfig['sourceSchema']
                        );
                        if (is_array($eioObj) === true) {
                            $eioData = $eioObj;
                        } else {
                            $eioData = $eioObj->jsonSerialize();
                        }

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
                            'drc-006: Failed to clear indicatieGebruiksrecht: ' . $e->getMessage()
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'drc-006: Failed to check remaining gebruiksrechten: ' . $e->getMessage()
            );
        }
    }

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
            $eioObj = $objectService->find(
                $ioMatches[1],
                register: $eioConfig['sourceRegister'],
                schema: $eioConfig['sourceSchema']
            );
            if (is_array($eioObj) === true) {
                $eioData = $eioObj;
            } else {
                $eioData = $eioObj->jsonSerialize();
            }

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
                'drc-006: Failed to set indicatieGebruiksrecht: ' . $e->getMessage()
            );
        }
    }

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

            if ($partial === true) {
                $action = 'partial_update';
            } else {
                $action = 'update';
            }

            $ruleResult = $this->zgwService->getBusinessRulesService()->validate(
                zgwApi: self::ZGW_API,
                resource: $resource,
                action: $action,
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
            $existing = $this->zgwService->getObjectService()->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($existing) === true) {
                $existingData = $existing;
            } else {
                $existingData = $existing->jsonSerialize();
            }

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

            $object = $this->zgwService->getObjectService()->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $englishData,
                uuid: $uuid
            );
            if (is_array($object) === true) {
                $objectData = $object;
            } else {
                $objectData = $object->jsonSerialize();
            }

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
            }

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
                $baseUrl . '/' . $objectUuid,
                'update'
            );

            return new JSONResponse(data: $mapped);
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->error(
                'DRC update error: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return new JSONResponse(
                data: ['detail' => $e->getMessage()],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }
    }

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
        bool $partial = false,
    ): ?JSONResponse {
        $objectService = $this->zgwService->getObjectService();

        // Drc-009a/b: Document must be locked to allow updates.
        // Try OpenRegister's LockHandler first, then check the object data
        // blob (used by lockFallback in JWT-only contexts).
        $storedLockId = $this->resolveStoredLockId(
            objectService: $objectService,
            mappingConfig: $mappingConfig,
            uuid: $uuid
        );

        if ($storedLockId === null) {
            return new JSONResponse(
                data: [
                    'detail'        => 'Alleen vergrendelde documenten mogen bewerkt worden.',
                    'invalidParams' => [
                        [
                            'name'   => 'nonFieldErrors',
                            'code'   => 'unlocked',
                            'reason' => 'Het document is niet vergrendeld. Vergrendel het document eerst.',
                        ],
                    ],
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        $providedLockId = $body['lock'] ?? '';

        // Drc-009d/e: Lock ID must be provided.
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
                    'detail'        => 'Lock ID is vereist voor het bewerken van een vergrendeld document.',
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
        }

        // Drc-009h/i: Lock ID must match.
        if ($providedLockId !== $storedLockId) {
            return new JSONResponse(
                data: [
                    'detail'        => 'Lock ID komt niet overeen.',
                    'invalidParams' => [
                        [
                            'name'   => 'nonFieldErrors',
                            'code'   => 'incorrect-lock-id',
                            'reason' => 'Lock ID komt niet overeen met de opgeslagen vergrendeling.',
                        ],
                    ],
                ],
                statusCode: Http::STATUS_BAD_REQUEST
            );
        }

        return null;
    }

    /**
     * Resolve the stored lock ID from either OpenRegister's LockHandler
     * or the object data blob (fallback lock).
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param array  $mappingConfig The mapping configuration
     * @param string $uuid          The document UUID
     *
     * @return string|null The stored lock ID, or null if not locked
     */
    private function resolveStoredLockId(
        object $objectService,
        array $mappingConfig,
        string $uuid,
    ): ?string {
        // Try OpenRegister's dedicated lock system first.
        try {
            if (method_exists($objectService, 'getLockInfo') === true) {
                $lockInfo = $objectService->getLockInfo($uuid);
                if ($lockInfo !== null) {
                    $lockId = $lockInfo['lock_id'] ?? null;
                    if ($lockId !== null && $lockId !== '') {
                        return $lockId;
                    }
                }
            }
        } catch (\Throwable $e) {
            // GetLockInfo not available — fall through to data blob check.
        }

        // Check the object data blob for lockId (stored by lock/lockFallback).
        try {
            $existing = $objectService->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($existing) === true) {
                $existingData = $existing;
            } else {
                $existingData = $existing->jsonSerialize();
            }

            // Check for stored lockId first.
            $lockId = $existingData['lockId'] ?? null;
            if ($lockId !== null && $lockId !== '') {
                return (string) $lockId;
            }

            // Fallback: check locked field (boolean or entity lock structure).
            $isLocked = $existingData['locked'] ?? false;
            if (
                $isLocked === true || $isLocked === 'true'
                || $isLocked === 1 || is_array($isLocked) === true
            ) {
                return 'entity-lock';
            }
        } catch (\Throwable $e) {
            // Object not found — treat as not locked.
        }

        return null;
    }

    /**
     * Store a ZGW lockId in the object data blob.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param array  $mappingConfig The mapping configuration
     * @param string $uuid          The document UUID
     * @param string $lockId        The lock ID to store
     *
     * @return void
     */
    private function storeLockIdInData(
        object $objectService,
        array $mappingConfig,
        string $uuid,
        string $lockId
    ): void {
        try {
            $existing = $objectService->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($existing) === true) {
                $existingData = $existing;
            } else {
                $existingData = $existing->jsonSerialize();
            }

            unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
            $existingData['locked'] = true;
            $existingData['lockId'] = $lockId;

            $objectService->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $existingData,
                uuid: $uuid
            );
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'DRC: Failed to store lockId in data blob: ' . $e->getMessage()
            );
        }
    }

    /**
     * Clear the ZGW lockId from the object data blob after unlocking.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param array  $mappingConfig The mapping configuration
     * @param string $uuid          The document UUID
     *
     * @return void
     */
    private function clearLockIdInData(
        object $objectService,
        array $mappingConfig,
        string $uuid
    ): void {
        try {
            $existing = $objectService->find(
                $uuid,
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema']
            );
            if (is_array($existing) === true) {
                $existingData = $existing;
            } else {
                $existingData = $existing->jsonSerialize();
            }

            unset($existingData['@self'], $existingData['id'], $existingData['organisation']);
            $existingData['locked'] = false;
            $existingData['lockId'] = '';

            $objectService->saveObject(
                register: $mappingConfig['sourceRegister'],
                schema: $mappingConfig['sourceSchema'],
                object: $existingData,
                uuid: $uuid
            );
        } catch (\Throwable $e) {
            $this->zgwService->getLogger()->warning(
                'DRC: Failed to clear lockId in data blob: ' . $e->getMessage()
            );
        }
    }
}
