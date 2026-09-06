<?php

/**
 * Dossiq DRC (Documenten) Controller
 *
 * Controller for serving ZGW Documenten API endpoints (enkelvoudiginformatieobjecten,
 * objectinformatieobjecten, gebruiksrechten, verzendingen). Handles EIO-specific
 * features: base64 file content, document locking, and file downloads.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-procest/tasks.md#task-1
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class DrcController extends ZgwController {
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
	 * Default chunk size for bestandsdelen (10 MB).
	 *
	 * @var int
	 */
	private const DEFAULT_CHUNK_SIZE = 10485760;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The incoming request.
	 * @param ZgwService $zgwService The shared ZGW service.
	 * @param IL10N $l10n The localization service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ZgwService $zgwService,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List resources of the given type.
	 *
	 * @param string $resource The ZGW resource name (e.g. enkelvoudiginformatieobjecten).
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(string $resource): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// ObjectInformatieObjecten and Gebruiksrechten return a plain array per ZGW spec.
		if ($resource === 'objectinformatieobjecten' || $resource === 'gebruiksrechten') {
			return $this->indexFlatArray(resource: $resource);
		}

		return $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);
	}//end index()

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
	private function indexFlatArray(string $resource): JSONResponse {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			$params = $this->request->getParams();
			$filters = $this->zgwService->translateQueryParams(
				params: $params,
				mappingConfig: $mappingConfig
			);

			$searchParams = array_merge($filters, ['_limit' => 100]);

			$query = $objectService->buildSearchQuery(
				requestParams: $searchParams,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$result = $objectService->searchObjectsPaginated(query: $query);

			$objects = $result['results'] ?? [];
			$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
			$outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
			$mapped = [];
			foreach ($objects as $object) {
				$objectData = $this->objectToArray(row: $object);

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
		}//end try
	}//end indexFlatArray()

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
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function create(string $resource): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// C3: Gate creates on documenten.aanmaken scope.
		if ($this->zgwService->consumerHasScope($this->request, 'drc', 'documenten.aanmaken') === false) {
			return $this->scopeDeniedResponse(scope: 'documenten.aanmaken');
		}

		// Drc-006 (VNG): Gebruiksrechten create — set indicatieGebruiksrecht to true on EIO.
		if ($resource === 'gebruiksrechten') {
			$response = $this->zgwService->handleCreate($this->request, self::ZGW_API, $resource);
			if ($response->getStatus() === Http::STATUS_CREATED) {
				$this->updateIndicationGebruiksrecht(response: $response);
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
			$englishData = $this->zgwService->applyInboundMapping(
				body: $body,
				mapping: $inboundMapping,
				mappingConfig: $mappingConfig
			);

			if (empty($inhoud) === false) {
				unset($englishData['content']);
			}

			// @phpstan-ignore-next-line — defensive guard: applyInboundMapping may change
			if (is_array($englishData) === false) {
				return new JSONResponse(
					data: ['detail' => 'Invalid mapping result'],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			// Chunked upload: set fileParts BEFORE initial save to avoid
			// a second round-trip when bestandsomvang is given without inhoud.
			$bestandsomvang = (int)($body['bestandsomvang'] ?? 0);
			if ($bestandsomvang > 0 && empty($inhoud) === true) {
				$totalParts = (int)ceil($bestandsomvang / self::DEFAULT_CHUNK_SIZE);

				$englishData['fileParts'] = json_encode(
					[
						'pending' => true,
						'totalParts' => $totalParts,
						'chunkSize' => self::DEFAULT_CHUNK_SIZE,
						'fileSize' => $bestandsomvang,
					]
				);
			}

			$object = $this->zgwService->getObjectService()->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $englishData
			);
			$objectData = $this->objectToArray(row: $object);

			$objectUuid = $objectData['id'] ?? ($objectData['@self']['id'] ?? '');

			// Store file content (only when inhoud is provided).
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
					$objectData['uuid'] = $objectUuid;
					$this->zgwService->getObjectService()->saveObject(
						register: $mappingConfig['sourceRegister'],
						schema: $mappingConfig['sourceSchema'],
						object: $objectData
					);
				}
			}//end if

			$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
			$outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
			$mapped = $this->zgwService->applyOutboundMapping(
				objectData: $objectData,
				mapping: $outboundMapping,
				mappingConfig: $mappingConfig,
				baseUrl: $baseUrl
			);

			// Add bestandsdelen for chunked upload responses.
			$chunkInfo = $this->parseFileParts(objectData: $objectData);
			$mapped['bestandsdelen'] = [];
			if ($chunkInfo !== null && ($chunkInfo['pending'] ?? false) === true) {
				$mapped['bestandsdelen'] = $this->buildBestandsdelenArray(
					uuid: $objectUuid,
					fileSize: ($chunkInfo['fileSize'] ?? $bestandsomvang),
					totalParts: ($chunkInfo['totalParts'] ?? 1)
				);
			}

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
		}//end try
	}//end create()

	/**
	 * Retrieve a single resource by UUID.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		$response = $this->zgwService->handleShow($this->request, self::ZGW_API, $resource, $uuid);

		// Add bestandsdelen for EIO resources with pending chunked uploads.
		if ($resource === self::EIO_RESOURCE
			&& $response->getStatus() === Http::STATUS_OK
			&& $this->zgwService->getObjectService() !== null
		) {
			$this->enrichWithBestandsdelen(response: $response, uuid: $uuid);
		}

		return $response;
	}//end show()

	/**
	 * Full update (PUT) a resource by UUID.
	 *
	 * For EIO resources, checks document lock and handles inhoud.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function update(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// C3: Gate updates on documenten.bijwerken scope.
		if ($this->zgwService->consumerHasScope($this->request, 'drc', 'documenten.bijwerken') === false) {
			return $this->scopeDeniedResponse(scope: 'documenten.bijwerken');
		}

		if ($resource === self::EIO_RESOURCE) {
			return $this->handleEioUpdate(resource: $resource, uuid: $uuid, partial: false);
		}

		return $this->zgwService->handleUpdate($this->request, self::ZGW_API, $resource, $uuid, false);
	}//end update()

	/**
	 * Partial update (PATCH) a resource by UUID.
	 *
	 * For EIO resources, checks document lock and handles inhoud.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function patch(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// C3: Gate patches on documenten.bijwerken scope.
		if ($this->zgwService->consumerHasScope($this->request, 'drc', 'documenten.bijwerken') === false) {
			return $this->scopeDeniedResponse(scope: 'documenten.bijwerken');
		}

		if ($resource === self::EIO_RESOURCE) {
			return $this->handleEioUpdate(resource: $resource, uuid: $uuid, partial: true);
		}

		return $this->zgwService->handleUpdate($this->request, self::ZGW_API, $resource, $uuid, true);
	}//end patch()

	/**
	 * Delete a resource by UUID.
	 *
	 * For EIO resources, deletes stored files after deleting the object.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function destroy(string $resource, string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// C3: Gate destroys on documenten.verwijderen scope.
		if ($this->zgwService->consumerHasScope($this->request, 'drc', 'documenten.verwijderen') === false) {
			return $this->scopeDeniedResponse(scope: 'documenten.verwijderen');
		}

		// Drc-006 (VNG): Gebruiksrechten delete — update indicatieGebruiksrecht on EIO.
		if ($resource === 'gebruiksrechten') {
			$grData = $this->getGebruiksrechtData(uuid: $uuid);
			$response = $this->zgwService->handleDestroy($this->request, self::ZGW_API, $resource, $uuid);
			if ($response->getStatus() === Http::STATUS_NO_CONTENT && $grData !== null) {
				$this->checkAndClearIndicationGebruiksrecht(eioUuid: $grData['informatieobjectUuid']);
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
					$existingData = $this->objectToArray(row: $existing);

					$fileName = $existingData['fileName'] ?? 'document';
					if ($fileName === '') {
						$fileName = 'document';
					}
				} catch (\Throwable $e) {
					$fileName = null;
				}
			}//end if
		}//end if

		// Drc-008a (VNG): Block EIO deletion when OIO relations exist.
		if ($resource === self::EIO_RESOURCE && $this->zgwService->getObjectService() !== null) {
			$oioRelations = $this->findOioRelationsForEio(eioUuid: $uuid);
			if (empty($oioRelations) === false) {
				return new JSONResponse(
					[
						'detail' => $this->l10n->t('The document cannot be deleted: there are related ObjectInformatieObjecten.'),
						'invalidParams' => [
							[
								'name' => 'nonFieldErrors',
								'code' => 'pending-relations',
								'reason' => $this->l10n->t('The document cannot be deleted.'),
							],
						],
					],
					Http::STATUS_BAD_REQUEST
				);
			}
		}

		$response = $this->zgwService->handleDestroy($this->request, self::ZGW_API, $resource, $uuid);

		// Post-delete cleanup (only on successful deletion).
		if ($resource === self::EIO_RESOURCE
			&& $response->getStatus() === Http::STATUS_NO_CONTENT
		) {
			// Drc-008 (VNG): Cascade delete gebruiksrechten after EIO deletion.
			$this->cascadeDeleteGebruiksrechten(eioUuid: $uuid);

			// Delete stored files.
			if (isset($fileName) === true) {
				try {
					$this->zgwService->getDocumentService()->deleteFiles(uuid: $uuid);
				} catch (\Throwable $e) {
					$this->zgwService->getLogger()->warning(
						'DRC file cleanup failed: ' . $e->getMessage(),
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
	 * Rate-limit rationale: lower than the sibling reads — a download moves
	 * file bytes.
	 *
	 * @param string $uuid The document UUID.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 60, period: 60)]
	public function download(string $uuid): DataDownloadResponse|JSONResponse {
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
			$objectData = $this->objectToArray(row: $object);

			$fileName = $objectData['fileName'] ?? 'document';
			if ($fileName === '') {
				$fileName = 'document';
			}

			$format = $objectData['format'] ?? 'application/octet-stream';

			if ($this->zgwService->getDocumentService()->fileExists(uuid: $uuid, fileName: $fileName) === false) {
				return new JSONResponse(
					data: ['detail' => $this->l10n->t('File not found.')],
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
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function lock(string $uuid): JSONResponse {
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
		if ($mappingConfig !== null
			&& $this->resolveStoredLockId(
				objectService: $objectService,
				mappingConfig: $mappingConfig,
				uuid: $uuid
			) !== null
		) {
			return new JSONResponse(
				data: ['detail' => $this->l10n->t('Document is already locked.')],
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
				data: ['detail' => $this->l10n->t('Document is already locked.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		} catch (\Throwable $e) {
			// Fallback: OpenRegister lock may fail without a Nextcloud user
			// session (JWT-only context). Use manual lock via saveObject.
			return $this->lockFallback(objectService: $objectService, uuid: $uuid, original: $e);
		}//end try
	}//end lock()

	/**
	 * Fallback lock implementation for when OpenRegister's LockHandler
	 * fails due to missing Nextcloud user session (JWT-only context).
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $uuid The document UUID
	 * @param \Throwable $original The original exception
	 *
	 * @return JSONResponse
	 */
	private function lockFallback(object $objectService, string $uuid, \Throwable $original): JSONResponse {
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
			$existingData = $this->objectToArray(row: $existing);

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
		}//end try
	}//end lockFallback()

	/**
	 * Unlock an EIO document.
	 *
	 * Verifies the lock identifier and sets the document as unlocked.
	 *
	 * @param string $uuid The document UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function unlock(string $uuid): JSONResponse {
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
		$storedLockId = null;
		if ($mappingConfig !== null) {
			$storedLockId = $this->resolveStoredLockId(
				objectService: $objectService,
				mappingConfig: $mappingConfig,
				uuid: $uuid
			);
		}

		if ($storedLockId === null) {
			return new JSONResponse(
				data: ['detail' => $this->l10n->t('Document is not locked.')],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$body = $this->zgwService->getRequestBody($this->request);
		$lockId = $body['lock'] ?? '';

		// Determine if this is a forced unlock (wrong/empty lockId + scope).
		if ($lockId !== $storedLockId) {
			$hasForceScope = $this->zgwService->consumerHasScope(
				$this->request,
				'documenten',
				'geforceerd-bijwerken'
			);
			if ($hasForceScope === false) {
				$detail = $this->l10n->t('Lock ID does not match and forced unlocking is not allowed.');
				if ($lockId === '') {
					$detail = $this->l10n->t('Forced unlocking is not allowed without the correct scope.');
				}

				return new JSONResponse(
					data: [
						'detail' => $detail,
						'invalidParams' => [
							[
								'name' => 'nonFieldErrors',
								'code' => 'incorrect-lock-id',
								'reason' => $detail,
							],
						],
					],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}//end if
		}//end if

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
	}//end unlock()

	/**
	 * Fallback unlock for when OpenRegister's LockHandler fails (no NC session).
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param string $uuid The document UUID
	 * @param \Throwable $original The original exception
	 *
	 * @return JSONResponse
	 */
	private function unlockFallback(object $objectService, string $uuid, \Throwable $original): JSONResponse {
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
			$existingData = $this->objectToArray(row: $existing);

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
		}//end try
	}//end unlockFallback()

	/**
	 * List audit trail entries for a resource.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function audittrailIndex(string $resource, string $uuid): JSONResponse {
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
						data: ['detail' => $this->l10n->t('Not found.')],
						statusCode: Http::STATUS_NOT_FOUND
					);
				}
			}
		}

		return $this->zgwService->handleAudittrailIndex($this->request, self::ZGW_API, $resource, $uuid);
	}//end audittrailIndex()

	/**
	 * Retrieve a single audit trail entry for a resource.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 * @param string $auditUuid The audit trail entry UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function audittrailShow(string $resource, string $uuid, string $auditUuid): JSONResponse {
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
	 * Find relations for an EIO by UUID (drc-008a VNG).
	 *
	 * Checks OIO, ZIO, and BIO schemas for references to the given document.
	 *
	 * @param string $eioUuid The EIO UUID
	 *
	 * @return array List of related object IDs linked to this EIO
	 */
	private function findOioRelationsForEio(string $eioUuid): array {
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
				'schema' => $oioConfig['sourceSchema'],
			];
		}

		// ZIO (ZaakInformatieObject) — ZRC register.
		$zioConfig = $this->zgwService->loadMappingConfig('zaken', 'zaakinformatieobjecten');
		if ($zioConfig !== null) {
			$schemasToCheck[] = [
				'register' => $zioConfig['sourceRegister'],
				'schema' => $zioConfig['sourceSchema'],
			];
		}

		// BIO (BesluitInformatieObject) — BRC register.
		$bioConfig = $this->zgwService->loadMappingConfig('besluiten', 'besluitinformatieobjecten');
		if ($bioConfig !== null) {
			$schemasToCheck[] = [
				'register' => $bioConfig['sourceRegister'],
				'schema' => $bioConfig['sourceSchema'],
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
	}//end findOioRelationsForEio()

	/**
	 * Search for document relations in a specific schema.
	 *
	 * @param object $objectService The object service
	 * @param string $eioUuid The EIO UUID to search for
	 * @param string $register The register ID
	 * @param string $schema The schema ID
	 *
	 * @return array List of related object IDs
	 */
	private function searchRelationsInSchema(
		object $objectService,
		string $eioUuid,
		string $register,
		string $schema,
	): array {
		try {
			// Try exact UUID match (OIO may store just the UUID).
			$query = $objectService->buildSearchQuery(
				requestParams: ['document' => $eioUuid, '_limit' => 1],
				register: $register,
				schema: $schema
			);
			$result = $objectService->searchObjectsPaginated(query: $query);
			$ids = $this->extractIdsFromResults(result: $result);
			if (empty($ids) === false) {
				return $ids;
			}

			// Fallback: full-text search by UUID (document field stores
			// the full URL, and field-specific LIKE is not supported).
			$query = $objectService->buildSearchQuery(
				requestParams: ['_search' => $eioUuid, '_limit' => 1],
				register: $register,
				schema: $schema
			);
			$result = $objectService->searchObjectsPaginated(query: $query);
			$ids = $this->extractIdsFromResults(result: $result);
			if (empty($ids) === false) {
				return $ids;
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'drc-008a: Relation search failed for schema ' . $schema . ': ' . $e->getMessage()
			);
		}//end try

		return [];
	}//end searchRelationsInSchema()

	/**
	 * Extract IDs from a search result set.
	 *
	 * @param array $result The search result from searchObjectsPaginated
	 *
	 * @return array<string> Array of object IDs
	 */
	private function extractIdsFromResults(array $result): array {
		$ids = [];
		foreach (($result['results'] ?? []) as $obj) {
			$data = $this->objectToArray(row: $obj);

			$id = $data['id'] ?? ($data['@self']['id'] ?? null);
			if ($id !== null) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end extractIdsFromResults()

	/**
	 * Cascade delete all gebruiksrechten for an EIO (drc-008 VNG).
	 *
	 * @param string $eioUuid The EIO UUID
	 *
	 * @return void
	 */
	private function cascadeDeleteGebruiksrechten(string $eioUuid): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$grConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'gebruiksrechten');
		if ($grConfig === null) {
			return;
		}

		try {
			$query = $objectService->buildSearchQuery(
				requestParams: ['document' => '%' . $eioUuid . '%', '_limit' => 100],
				register: $grConfig['sourceRegister'],
				schema: $grConfig['sourceSchema']
			);
			$result = $objectService->searchObjectsPaginated(query: $query);

			foreach (($result['results'] ?? []) as $gr) {
				$grData = $this->objectToArray(row: $gr);

				$grUuid = $grData['id'] ?? ($grData['@self']['id'] ?? '');
				if ($grUuid !== '') {
					$objectService->deleteObject(uuid: $grUuid);
				}
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'drc-008: Failed to cascade delete gebruiksrechten for EIO ' . $eioUuid . ': ' . $e->getMessage()
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
	private function updateIndicationGebruiksrecht(JSONResponse $response): void {
		$data = $response->getData();
		if (is_array($data) === false) {
			return;
		}

		$ioUrl = $data['informatieobject'] ?? '';
		if ($ioUrl === '') {
			return;
		}

		$this->setIndicationGebruiksrecht(ioUrl: $ioUrl, value: true);
	}//end updateIndicatieGebruiksrecht()

	/**
	 * Get gebruiksrecht data before deletion (drc-006 VNG).
	 *
	 * @param string $uuid The gebruiksrecht UUID
	 *
	 * @return array|null Array with informatieobjectUuid, or null
	 */
	private function getGebruiksrechtData(string $uuid): ?array {
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
			$data = $this->objectToArray(row: $obj);

			$ioRef = $data['document'] ?? ($data['informatieobject'] ?? '');
			$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
			if (preg_match($uuidPattern, (string)$ioRef, $grMatches) === 1) {
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
	private function checkAndClearIndicationGebruiksrecht(string $eioUuid): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$grConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'gebruiksrechten');
		if ($grConfig === null) {
			return;
		}

		try {
			$query = $objectService->buildSearchQuery(
				requestParams: ['document' => $eioUuid, '_limit' => 1],
				register: $grConfig['sourceRegister'],
				schema: $grConfig['sourceSchema']
			);
			$result = $objectService->searchObjectsPaginated(query: $query);
			$total = $result['total'] ?? count($result['results'] ?? []);

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
						$eioData = $this->objectToArray(row: $eioObj);

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
					}//end try
				}//end if
			}//end if
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'drc-006: Failed to check remaining gebruiksrechten: ' . $e->getMessage()
			);
		}//end try
	}//end checkAndClearIndicatieGebruiksrecht()

	/**
	 * Set indicatieGebruiksrecht on an EIO (drc-006 VNG).
	 *
	 * @param string $ioUrl The informatieobject URL
	 * @param bool|null $value The value to set (true or null)
	 *
	 * @return void
	 */
	private function setIndicationGebruiksrecht(string $ioUrl, ?bool $value): void {
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
			$eioData = $this->objectToArray(row: $eioObj);

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
		}//end try
	}//end setIndicatieGebruiksrecht()

	/**
	 * Upload a chunk (bestandsdeel) for a document.
	 *
	 * Receives raw binary data for a single chunk and stores it.
	 * When all chunks have been uploaded, merges them into the final file.
	 *
	 * Rate-limit rationale: tight — each call accepts a chunk of file bytes,
	 * so this is the cheapest way for an anonymous caller to consume storage.
	 *
	 * @param string $uuid The document UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function uploadChunk(string $uuid): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, self::EIO_RESOURCE);
		}

		try {
			// Find the EIO object.
			$existing = $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$objectData = $this->objectToArray(row: $existing);

			// Verify this document has a pending chunked upload.
			$chunkInfo = $this->parseFileParts(objectData: $objectData);
			if ($chunkInfo === null || ($chunkInfo['pending'] ?? false) !== true) {
				return new JSONResponse(
					data: ['detail' => $this->l10n->t('This document has no pending chunked upload.')],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			$totalParts = (int)($chunkInfo['totalParts'] ?? 0);
			if ($totalParts <= 0) {
				return new JSONResponse(
					data: ['detail' => $this->l10n->t('Invalid chunk configuration.')],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			// Get volgnummer from query parameter or request body.
			$sequenceNumber = (int)($this->request->getParam('sequenceNumber') ?? 0);
			if ($sequenceNumber <= 0 || $sequenceNumber > $totalParts) {
				return new JSONResponse(
					data: ['detail' => $this->l10n->t('Invalid sequence number. Expected 1-%s.', [$totalParts])],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			// Read raw body content.
			$content = file_get_contents('php://input');
			if ($content === false || $content === '') {
				return new JSONResponse(
					data: ['detail' => $this->l10n->t('No file content received.')],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			// Store the chunk.
			$docService = $this->zgwService->getDocumentService();
			$chunkSize = $docService->storeChunk(
				uuid: $uuid,
				sequenceNumber: $sequenceNumber,
				content: $content
			);

			// Check if all chunks have been uploaded.
			$uploaded = $docService->getUploadedChunks(uuid: $uuid, totalParts: $totalParts);

			if (count($uploaded) === $totalParts) {
				// All chunks present — merge into final file.
				$fileName = $objectData['fileName'] ?? 'document';
				if ($fileName === '') {
					$fileName = 'document';
				}

				$mergedSize = $docService->mergeChunks(
					uuid: $uuid,
					fileName: $fileName,
					totalParts: $totalParts
				);

				// Update the object: clear chunk metadata, set file size.
				unset(
					$objectData['@self'],
					$objectData['id'],
					$objectData['organisation']
				);
				$objectData['fileParts'] = '';
				$objectData['fileSize'] = $mergedSize;

				$objectService->saveObject(
					register: $mappingConfig['sourceRegister'],
					schema: $mappingConfig['sourceSchema'],
					object: $objectData,
					uuid: $uuid
				);

				return new JSONResponse(
					data: [
						'sequenceNumber' => $sequenceNumber,
						'size' => $chunkSize,
						'uploadComplete' => true,
						'bestandsomvang' => $mergedSize,
						'uploadedParts' => count($uploaded),
						'totalParts' => $totalParts,
					],
					statusCode: Http::STATUS_OK
				);
			}//end if

			return new JSONResponse(
				data: [
					'sequenceNumber' => $sequenceNumber,
					'size' => $chunkSize,
					'uploadComplete' => false,
					'uploadedParts' => count($uploaded),
					'totalParts' => $totalParts,
				],
				statusCode: Http::STATUS_OK
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'DRC chunk upload error: ' . $e->getMessage(),
				['exception' => $e]
			);

			return new JSONResponse(
				data: ['detail' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end uploadChunk()

	/**
	 * Enrich a show response with bestandsdelen if a chunked upload is pending.
	 *
	 * @param JSONResponse $response The show response
	 * @param string $uuid The document UUID
	 *
	 * @return void
	 */
	private function enrichWithBestandsdelen(JSONResponse $response, string $uuid): void {
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, self::EIO_RESOURCE);
		if ($mappingConfig === null) {
			return;
		}

		try {
			$existing = $this->zgwService->getObjectService()->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$objectData = $this->objectToArray(row: $existing);

			$data = $response->getData();
			if (is_array($data) === false) {
				return;
			}

			$chunkInfo = $this->parseFileParts(objectData: $objectData);
			$data['bestandsdelen'] = [];
			if ($chunkInfo !== null && ($chunkInfo['pending'] ?? false) === true) {
				$data['bestandsdelen'] = $this->buildBestandsdelenArray(
					uuid: $uuid,
					fileSize: (int)($chunkInfo['fileSize'] ?? 0),
					totalParts: (int)($chunkInfo['totalParts'] ?? 1)
				);
			}

			$response->setData(data: $data);
		} catch (\Throwable $e) {
			// Silently skip enrichment on errors.
		}//end try
	}//end enrichWithBestandsdelen()

	/**
	 * Parse the fileParts JSON field from an object data array.
	 *
	 * @param array $objectData The object data array
	 *
	 * @return array|null Decoded chunk info, or null if not set
	 */
	private function parseFileParts(array $objectData): ?array {
		$raw = $objectData['fileParts'] ?? '';
		if ($raw === '') {
			return null;
		}

		if (is_string($raw) === true) {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return null;
		}

		if (is_array($raw) === true) {
			return $raw;
		}

		return null;
	}//end parseFileParts()

	/**
	 * Build the bestandsdelen array for a chunked upload response.
	 *
	 * @param string $uuid The document UUID
	 * @param int $fileSize The total file size in bytes
	 * @param int $totalParts The total number of parts
	 *
	 * @return array The bestandsdelen array with volgnummer, omvang, and url
	 */
	private function buildBestandsdelenArray(string $uuid, int $fileSize, int $totalParts): array {
		$baseUrl = $this->zgwService->buildBaseUrl(
			$this->request,
			self::ZGW_API,
			'bestandsdelen'
		);

		$bestandsdelen = [];
		$remaining = $fileSize;

		for ($i = 1; $i <= $totalParts; $i++) {
			$chunkSize = min(self::DEFAULT_CHUNK_SIZE, $remaining);
			$remaining -= $chunkSize;

			$bestandsdelen[] = [
				'url' => $baseUrl . '/' . $uuid . '?volgnummer=' . $i,
				'sequenceNumber' => $i,
				'size' => $chunkSize,
				'lock' => '',
			];
		}

		return $bestandsdelen;
	}//end buildBestandsdelenArray()

	/**
	 * Handle EIO-specific update with lock checking and inhoud handling.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid The resource UUID.
	 * @param bool $partial Whether this is a partial (PATCH) update.
	 *
	 * @return JSONResponse
	 */
	private function handleEioUpdate(string $resource, string $uuid, bool $partial): JSONResponse {
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

			$action = 'update';
			if ($partial === true) {
				$action = 'partial_update';
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
			$existingData = $this->objectToArray(row: $existing);

			$inboundMapping = $this->zgwService->createInboundMapping(mappingConfig: $mappingConfig);
			$englishData = $this->zgwService->applyInboundMapping(
				body: $body,
				mapping: $inboundMapping,
				mappingConfig: $mappingConfig
			);

			if (empty($inhoud) === false) {
				unset($englishData['content']);
			}

			// @phpstan-ignore-next-line — defensive guard: applyInboundMapping may change
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
			$objectData = $this->objectToArray(row: $object);

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
					$objectData['uuid'] = $objectUuid;
					$this->zgwService->getObjectService()->saveObject(
						register: $mappingConfig['sourceRegister'],
						schema: $mappingConfig['sourceSchema'],
						object: $objectData
					);
				}
			}//end if

			$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
			$outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
			$mapped = $this->zgwService->applyOutboundMapping(
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
	 * @param array $mappingConfig The mapping configuration.
	 * @param string $uuid The document UUID.
	 * @param array $body The request body.
	 * @param bool $partial Whether this is a partial (PATCH) update.
	 *
	 * @return JSONResponse|null Error response if lock check fails, null if OK.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — $partial distinguishes PUT vs PATCH lock semantics
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
					'detail' => $this->l10n->t('Only locked documents may be edited.'),
					'invalidParams' => [
						[
							'name' => 'nonFieldErrors',
							'code' => 'unlocked',
							'reason' => $this->l10n->t('The document is not locked. Lock the document first.'),
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
			$errorName = 'nonFieldErrors';
			$errorCode = 'missing-lock-id';
			if ($partial === false) {
				$errorName = 'lock';
				$errorCode = 'required';
			}

			return new JSONResponse(
				data: [
					'detail' => $this->l10n->t('Lock ID is required for editing a locked document.'),
					'invalidParams' => [
						[
							'name' => $errorName,
							'code' => $errorCode,
							'reason' => $this->l10n->t('Lock ID is missing from the request.'),
						],
					],
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end if

		// Drc-009h/i: Lock ID must match.
		if ($providedLockId !== $storedLockId) {
			return new JSONResponse(
				data: [
					'detail' => $this->l10n->t('Lock ID does not match.'),
					'invalidParams' => [
						[
							'name' => 'nonFieldErrors',
							'code' => 'incorrect-lock-id',
							'reason' => $this->l10n->t('Lock ID does not match the stored lock.'),
						],
					],
				],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end checkDocumentLock()

	/**
	 * Resolve the stored lock ID from either OpenRegister's LockHandler
	 * or the object data blob (fallback lock).
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array $mappingConfig The mapping configuration
	 * @param string $uuid The document UUID
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
			$existingData = $this->objectToArray(row: $existing);

			// Check for stored lockId first.
			$lockId = $existingData['lockId'] ?? null;
			if ($lockId !== null && $lockId !== '') {
				return (string)$lockId;
			}

			// Fallback: check locked field (boolean or entity lock structure).
			$isLocked = $existingData['locked'] ?? false;
			if ($isLocked === true || $isLocked === 'true'
				|| $isLocked === 1 || is_array($isLocked) === true
			) {
				return 'entity-lock';
			}
		} catch (\Throwable $e) {
			// Object not found — treat as not locked.
		}//end try

		return null;
	}//end resolveStoredLockId()

	/**
	 * Store a ZGW lockId in the object data blob.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array $mappingConfig The mapping configuration
	 * @param string $uuid The document UUID
	 * @param string $lockId The lock ID to store
	 *
	 * @return void
	 */
	private function storeLockIdInData(
		object $objectService,
		array $mappingConfig,
		string $uuid,
		string $lockId,
	): void {
		try {
			$existing = $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$existingData = $this->objectToArray(row: $existing);

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
		}//end try
	}//end storeLockIdInData()

	/**
	 * Clear the ZGW lockId from the object data blob after unlocking.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param array $mappingConfig The mapping configuration
	 * @param string $uuid The document UUID
	 *
	 * @return void
	 */
	private function clearLockIdInData(
		object $objectService,
		array $mappingConfig,
		string $uuid,
	): void {
		try {
			$existing = $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$existingData = $this->objectToArray(row: $existing);

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
		}//end try
	}//end clearLockIdInData()
}//end class
