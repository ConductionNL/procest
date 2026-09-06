<?php

/**
 * Dossiq ZRC (Zaken Register) Controller
 *
 * Handles the ZGW Zaken register API endpoints: zaken, statussen, resultaten,
 * rollen, zaakeigenschappen, zaakinformatieobjecten, zaakobjecten, klantcontacten.
 *
 * Delegates shared operations to ZgwService while implementing ZRC-specific
 * behaviour such as zaak-closed resolution, eindstatus side effects,
 * authorization-based filtering (zrc-006), and OIO cross-register sync (zrc-005).
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

use DateInterval;
use DateTime;
use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
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
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class ZrcController extends ZgwController {
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
		'openbaar' => 1,
		'beperkt_openbaar' => 2,
		'intern' => 3,
		'zaakvertrouwelijk' => 4,
		'vertrouwelijk' => 5,
		'confidentieel' => 6,
		'geheim' => 7,
		'zeer_geheim' => 8,
	];

	/**
	 * Constructor.
	 *
	 * @param string $appName The application name
	 * @param IRequest $request The incoming request
	 * @param ZgwService $zgwService The shared ZGW service
	 * @param IL10N $l10n The localization service
	 * @param CaseRelationService $caseRelationService Typed peer-relation service
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ZgwService $zgwService,
		private readonly IL10N $l10n,
		private readonly CaseRelationService $caseRelationService,
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

		$response = $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);

		// Zrc-006a: Filter zaken results based on consumer's vertrouwelijkheidaanduiding.
		if ($resource === 'zaken' && $response->getStatus() === Http::STATUS_OK) {
			$response = $this->filterCasesByAuthorisation(response: $response);
			// Related-case-linking: populate relevanteAndereZaken per result.
			$response = $this->enrichCasesListRelevanteAndereCases(response: $response);
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

		// Zrc-006c / M3: Check write scope for all create operations.
		// Zaken require zaken.aanmaken; all other sub-resources require zaken.bijwerken.
		$requiredScope = 'zaken.bijwerken';
		if ($resource === 'zaken') {
			$requiredScope = 'zaken.aanmaken';
		}

		if ($this->zgwService->consumerHasScope($this->request, 'zrc', $requiredScope) === false) {
			return $this->permissionDeniedResponse();
		}

		if ($this->zgwService->getObjectService() === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			$body = $this->zgwService->getRequestBody($this->request);
			$originalBody = $body;

			// ZRC-specific: resolve zaak closed from body before validation.
			$caseClosed = $this->zgwService->resolveZaakClosedFromBody($resource, $body);
			$hasGeforceerd = true;
			if ($caseClosed === true) {
				$hasGeforceerd = $this->zgwService->consumerHasScope(
					$this->request,
					'zrc',
					'zaken.geforceerd-bijwerken'
				);
			}

			$ruleResult = $this->zgwService->getBusinessRulesService()->validate(
				zgwApi: self::ZGW_API,
				resource: $resource,
				action: 'create',
				body: $body,
				objectService: $this->zgwService->getObjectService(),
				mappingConfig: $mappingConfig,
				caseClosed: $caseClosed,
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
			$englishData = $this->zgwService->applyInboundMapping(
				body: $body,
				mapping: $inboundMapping,
				mappingConfig: $mappingConfig
			);

			// @phpstan-ignore-next-line — defensive guard: applyInboundMapping may change
			if (is_array($englishData) === false) {
				return new JSONResponse(
					data: ['detail' => 'Invalid mapping result'],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			// Zrc-008c: Before saving a status, check if it would reopen a closed zaak
			// and require the zaken.heropenen scope.
			if ($resource === 'statussen') {
				$reopenError = $this->checkReopenScope(body: $originalBody);
				if ($reopenError !== null) {
					return $reopenError;
				}

				// Zrc-007q: Before adding an eindstatus, verify all linked IOs
				// have indicatieGebruiksrecht set (not null).
				$gebruiksrechtError = $this->checkIndicationGebruiksrechtBeforeClose(body: $originalBody);
				if ($gebruiksrechtError !== null) {
					return $gebruiksrechtError;
				}
			}

			$object = $this->zgwService->getObjectService()->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $englishData
			);
			$objectData = $this->objectToArray(row: $object);

			$objectUuid = $objectData['id'] ?? ($objectData['@self']['id'] ?? '');

			// Related-case-linking: route inbound relevanteAndereZaken through
			// the guarded, symmetric case-relation service. A relation URL that
			// does not resolve to a local case is rejected with the standard ZGW
			// validation error shape.
			if ($resource === 'zaken') {
				$relError = $this->applyInboundRelevanteAndereCases(
					caseUuid: (string)$objectUuid,
					body: $originalBody
				);
				if ($relError !== null) {
					return $relError;
				}
			}

			// ZRC-specific: handle eindstatus / heropenen effect for statussen.
			if ($resource === 'statussen') {
				$this->handleEindstatusEffect(body: $originalBody, objectData: $objectData);
			}

			// Zrc-021: When a resultaat is created, derive archiefactiedatum
			// and archiefnominatie on the parent zaak from the resultaattype.
			if ($resource === 'resultaten') {
				$this->handleResultCreated(body: $originalBody, objectData: $objectData);
			}

			$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
			$outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
			$mapped = $this->zgwService->applyOutboundMapping(
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
				$caseUrl = $originalBody['case'] ?? ($body['case'] ?? '');
				$ioUrl = $originalBody['informatieobject'] ?? ($body['informatieobject'] ?? '');
				$this->syncCreateObjectInformatieObject(caseUrl: $caseUrl, ioUrl: $ioUrl);
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
				'ZRC create error: ' . $e->getMessage(),
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
	 * @param string $uuid The resource UUID
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

		// Zrc-006b: Check zaken.lezen scope and vertrouwelijkheidaanduiding.
		if ($resource === 'zaken') {
			$scopeError = $this->checkCaseReadAccess(uuid: $uuid);
			if ($scopeError !== null) {
				return $scopeError;
			}
		}

		$response = $this->zgwService->handleShow($this->request, self::ZGW_API, $resource, $uuid);

		// Related-case-linking: populate relevanteAndereZaken from relatedCases.
		if ($resource === 'zaken' && $response->getStatus() === Http::STATUS_OK) {
			$response = $this->enrichCaseRelevanteAndereCases(response: $response);
		}

		return $response;
	}//end show()

	/**
	 * Full update a resource.
	 *
	 * ZRC-specific: resolves zaak-closed from existing data before delegating.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
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
		// Resolve UUID from URL path — body "uuid" can override controller args.
		$uuid = $this->zgwService->resolvePathUuid($this->request, $uuid);

		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// C3: Gate updates on zrc.bijwerken scope.
		if ($this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.bijwerken') === false) {
			return $this->permissionDeniedResponse();
		}

		// Zrc-010/zrc-015: Pre-validate body fields that don't require
		// the existing object, so validation errors are returned even
		// when the OpenRegister find() call fails transiently.
		if ($resource === 'zaken') {
			$preValidation = $this->preValidateCaseBody(isPatch: false);
			if ($preValidation !== null) {
				return $preValidation;
			}
		}

		[$caseClosed, $hasGeforceerd] = $this->resolveCaseClosedForExisting(resource: $resource, uuid: $uuid);

		$response = $this->zgwService->handleUpdate(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			false,
			null,
			$caseClosed,
			$hasGeforceerd
		);

		// Zrc-004b: Enrich ZIO response with immutable aardRelatieWeergave.
		if ($resource === 'zaakinformatieobjecten' && $response->getStatus() === Http::STATUS_OK) {
			$response = $this->enrichZioJsonResponse(response: $response);
		}

		// Related-case-linking: route inbound relevanteAndereZaken (PUT) through
		// the guarded, symmetric case-relation service and re-emit on success.
		if ($resource === 'zaken' && $response->getStatus() === Http::STATUS_OK) {
			$relError = $this->applyInboundRelevanteAndereCases(
				caseUuid: $uuid,
				body: $this->zgwService->getRequestBody($this->request)
			);
			if ($relError !== null) {
				return $relError;
			}

			$response = $this->enrichCaseRelevanteAndereCases(response: $response);
		}

		return $response;
	}//end update()

	/**
	 * Partial update a resource.
	 *
	 * ZRC-specific: resolves zaak-closed from existing data before delegating.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
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
		// Resolve UUID from URL path — body "uuid" can override controller args.
		$uuid = $this->zgwService->resolvePathUuid($this->request, $uuid);

		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		// C3: Gate patches on zrc.bijwerken scope.
		if ($this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.bijwerken') === false) {
			return $this->permissionDeniedResponse();
		}

		// Zrc-010/zrc-015: Pre-validate body fields that don't require
		// the existing object, so validation errors are returned even
		// when the OpenRegister find() call fails transiently.
		if ($resource === 'zaken') {
			$preValidation = $this->preValidateCaseBody(isPatch: true);
			if ($preValidation !== null) {
				return $preValidation;
			}
		}

		[$caseClosed, $hasGeforceerd] = $this->resolveCaseClosedForExisting(resource: $resource, uuid: $uuid);

		$response = $this->zgwService->handleUpdate(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			true,
			null,
			$caseClosed,
			$hasGeforceerd
		);

		// Zrc-004c: Enrich ZIO response with immutable aardRelatieWeergave.
		if ($resource === 'zaakinformatieobjecten' && $response->getStatus() === Http::STATUS_OK) {
			$response = $this->enrichZioJsonResponse(response: $response);
		}

		// Related-case-linking: route inbound relevanteAndereZaken (PATCH) through
		// the guarded, symmetric case-relation service and re-emit on success.
		if ($resource === 'zaken' && $response->getStatus() === Http::STATUS_OK) {
			$relError = $this->applyInboundRelevanteAndereCases(
				caseUuid: $uuid,
				body: $this->zgwService->getRequestBody($this->request)
			);
			if ($relError !== null) {
				return $relError;
			}

			$response = $this->enrichCaseRelevanteAndereCases(response: $response);
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
	 * @param string $uuid The resource UUID
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

		// Zrc-023: Cascade delete for zaken — scope check is inside destroyZaak (C4).
		if ($resource === 'zaken') {
			return $this->destroyCase(uuid: $uuid);
		}

		// C3: Gate sub-resource destroys on zaken.verwijderen scope.
		if ($this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.verwijderen') === false) {
			return $this->permissionDeniedResponse();
		}

		// Zrc-005b: Before deleting, capture ZIO data for OIO cleanup.
		$zioData = null;
		if ($resource === 'zaakinformatieobjecten') {
			$zioData = $this->getZioDataForOioSync(uuid: $uuid);
		}

		[$caseClosed, $hasGeforceerd] = $this->resolveCaseClosedForExisting(resource: $resource, uuid: $uuid);

		$response = $this->zgwService->handleDestroy(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			null,
			$caseClosed,
			$hasGeforceerd
		);

		// Zrc-005b: If ZIO deletion succeeded, also delete the OIO in DRC.
		if ($resource === 'zaakinformatieobjecten'
			&& $response->getStatus() === Http::STATUS_NO_CONTENT
			&& $zioData !== null
		) {
			$this->syncDeleteObjectInformatieObject(
				caseUrl: $zioData['zaakUrl'],
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
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function zaakeigenschappenIndex(string $zaakUuid): JSONResponse {
		return $this->index(resource: 'zaakeigenschappen');
	}//end zaakeigenschappenIndex()

	/**
	 * Create a zaakeigenschap for a zaak.
	 *
	 * @param string $zaakUuid The zaak UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function zaakeigenschappenCreate(string $zaakUuid): JSONResponse {
		return $this->create(resource: 'zaakeigenschappen');
	}//end zaakeigenschappenCreate()

	/**
	 * Show a specific zaakeigenschap.
	 *
	 * @param string $zaakUuid The zaak UUID
	 * @param string $uuid The zaakeigenschap UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function zaakeigenschappenShow(string $zaakUuid, string $uuid): JSONResponse {
		return $this->show(resource: 'zaakeigenschappen', uuid: $uuid);
	}//end zaakeigenschappenShow()

	/**
	 * Update a zaakeigenschap.
	 *
	 * @param string $zaakUuid The zaak UUID
	 * @param string $uuid The zaakeigenschap UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function zaakeigenschappenUpdate(string $zaakUuid, string $uuid): JSONResponse {
		return $this->update(resource: 'zaakeigenschappen', uuid: $uuid);
	}//end zaakeigenschappenUpdate()

	/**
	 * Partial update a zaakeigenschap.
	 *
	 * @param string $zaakUuid The zaak UUID
	 * @param string $uuid The zaakeigenschap UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function zaakeigenschappenPatch(string $zaakUuid, string $uuid): JSONResponse {
		return $this->patch(resource: 'zaakeigenschappen', uuid: $uuid);
	}//end zaakeigenschappenPatch()

	/**
	 * Delete a zaakeigenschap.
	 *
	 * @param string $zaakUuid The zaak UUID
	 * @param string $uuid The zaakeigenschap UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $zaakUuid required by route pattern
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function zaakeigenschappenDestroy(string $zaakUuid, string $uuid): JSONResponse {
		return $this->destroy(resource: 'zaakeigenschappen', uuid: $uuid);
	}//end zaakeigenschappenDestroy()

	/**
	 * List zaakbesluiten for a zaak.
	 *
	 * @param string $zaakUuid The zaak UUID
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
	public function zaakbesluitenIndex(string $zaakUuid): JSONResponse {
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
			$query = $this->zgwService->getObjectService()->buildSearchQuery(
				requestParams: ['case' => $zaakUuid],
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

			$baseUrl = $this->zgwService->buildBaseUrl($this->request, 'besluiten', 'besluiten');
			$outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
			$mapped = [];
			foreach (($result['results'] ?? []) as $object) {
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
				'ZRC zaakbesluiten error: ' . $e->getMessage(),
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
	 * Rate-limit rationale: lower than a plain read — zoek is the most
	 * expensive query in this controller, and it is reachable anonymously.
	 *
	 * @return JSONResponse
	 *
	 * @NoCSRFRequired
	 * @PublicPage
	 * @CORS
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AnonRateLimit(limit: 60, period: 60)]
	public function zoek(): JSONResponse {
		$indexResponse = $this->index(resource: 'zaken');
		// The zoek endpoint reuses the list handler but returns 201 Created.
		// No instanceof guard: index() is declared to return JSONResponse, so the
		// check was always true (PHPStan 2: instanceof.alwaysTrue).
		$responseData = ($indexResponse->getData() ?? []);

		return new JSONResponse(data: $responseData, statusCode: Http::STATUS_CREATED);
	}//end zoek()

	/**
	 * Get audit trail for a resource.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
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

		return $this->zgwService->handleAudittrailIndex($this->request, self::ZGW_API, $resource, $uuid);
	}//end audittrailIndex()

	/**
	 * Get a specific audit trail entry.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 * @param string $auditUuid The audit trail entry UUID
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

		return $this->zgwService->handleAudittrailShow($this->request, self::ZGW_API, $resource, $uuid, $auditUuid);
	}//end audittrailShow()

	/**
	 * Check zaak read access based on consumer scopes and vertrouwelijkheidaanduiding (zrc-006b).
	 *
	 * @param string $uuid The zaak UUID
	 *
	 * @return JSONResponse|null Permission denied response, or null if access is allowed
	 */
	private function checkCaseReadAccess(string $uuid): ?JSONResponse {
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

			$caseObj = $this->zgwService->getObjectService()->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$caseData = $this->objectToArray(row: $caseObj);

			$caseVa = $caseData['confidentiality'] ?? ($caseData['vertrouwelijkheidaanduiding'] ?? 'openbaar');
			$caseLevel = self::VERTROUWELIJKHEID_LEVELS[$caseVa] ?? 1;

			// Check zaaktype + maxVertrouwelijkheidaanduiding from consumer autorisaties.
			foreach ($autorisaties as $auth) {
				$scopes = $auth['scopes'] ?? [];
				if (in_array('zaken.lezen', $scopes, true) === false) {
					continue;
				}

				$maxVa = $auth['maxVertrouwelijkheidaanduiding'] ?? ($auth['max_vertrouwelijkheidaanduiding'] ?? null);
				$maxLevel = 99;
				if ($maxVa !== null) {
					$maxLevel = self::VERTROUWELIJKHEID_LEVELS[$maxVa] ?? 99;
				}

				if ($caseLevel <= $maxLevel) {
					return null;
				}
			}

			// No matching autorisatie allows this vertrouwelijkheidaanduiding.
			return $this->permissionDeniedResponse();
		} catch (\InvalidArgumentException $e) {
			// Expected: zaak or mapping config not found — deny to be safe.
			$this->zgwService->getLogger()->warning(
				'zrc-006b: Zaak read access check failed (expected), denying: ' . $e->getMessage()
			);
			return $this->permissionDeniedResponse();
		} catch (\Throwable $e) {
			// Unexpected failure (OR down, schema rename, etc.) — deny rather than
			// silently allow access to potentially confidential data (fail-closed).
			$this->zgwService->getLogger()->error(
				'zrc-006b: Zaak read access check threw unexpected exception, denying: ' . $e->getMessage()
			);
			return $this->permissionDeniedResponse();
		}//end try
	}//end checkZaakReadAccess()

	/**
	 * Filter zaken results based on consumer's vertrouwelijkheidaanduiding (zrc-006a).
	 *
	 * @param JSONResponse $response The original index response
	 *
	 * @return JSONResponse The filtered response
	 */
	private function filterCasesByAuthorisation(JSONResponse $response): JSONResponse {
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
				$data['count'] = 0;
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
		foreach ($data['results'] as $case) {
			$caseVa = $case['vertrouwelijkheidaanduiding'] ?? 'openbaar';
			$caseLevel = self::VERTROUWELIJKHEID_LEVELS[$caseVa] ?? 1;

			foreach ($lezenAuths as $auth) {
				$maxVa = $auth['maxVertrouwelijkheidaanduiding'] ?? ($auth['max_vertrouwelijkheidaanduiding'] ?? null);
				$maxLevel = 99;
				if ($maxVa !== null) {
					$maxLevel = self::VERTROUWELIJKHEID_LEVELS[$maxVa] ?? 99;
				}

				if ($caseLevel <= $maxLevel) {
					$filtered[] = $case;
					break;
				}
			}
		}

		$data['count'] = count($filtered);
		$data['results'] = $filtered;
		$response->setData($data);

		return $response;
	}//end filterZakenByAuthorisation()

	/**
	 * Build a permission denied response (zrc-006/zrc-007).
	 *
	 * @return JSONResponse
	 */
	private function permissionDeniedResponse(): JSONResponse {
		return new JSONResponse(
			data: [
				'detail' => $this->l10n->t('You do not have the correct permissions for this action.'),
				'code' => 'permission_denied',
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
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $isPatch reserved for partial-update validation
	 *
	 * @psalm-suppress UnusedParam — $isPatch reserved for partial-update validation
	 */
	private function preValidateCaseBody(bool $isPatch): ?JSONResponse {
		try {
			$body = $this->zgwService->getRequestBody($this->request);

			// Zrc-010: Validate communicatiekanaal URL.
			$commChannel = $body['communicatiekanaal'] ?? null;
			if ($commChannel !== null && $commChannel !== '') {
				if (filter_var($commChannel, FILTER_VALIDATE_URL) === false) {
					return new JSONResponse(
						data: [
							'detail' => 'De communicatiekanaal URL is ongeldig.',
							'invalidParams' => [
								[
									'name' => 'communicatiekanaal',
									'code' => 'bad-url',
									'reason' => 'De communicatiekanaal URL is ongeldig.',
								],
							],
						],
						statusCode: Http::STATUS_BAD_REQUEST
					);
				}

				// Check if URL ends with a valid UUID (resource endpoint, not collection).
				$path = (string)parse_url($commChannel, PHP_URL_PATH);
				$hasUuid = preg_match(
					'/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/?$/i',
					$path
				) === 1;

				if ($hasUuid === false) {
					// Determine error code: garbled UUID → bad-url, collection endpoint → invalid-resource.
					$segments = array_filter(explode('/', trim($path, '/')));
					$last = end($segments);
					$looksLikeUuid = preg_match('/[0-9a-f]{4,}-/i', (string)$last) === 1;
					$code = 'invalid-resource';
					if ($looksLikeUuid === true) {
						$code = 'bad-url';
					}

					return new JSONResponse(
						data: [
							'detail' => 'De communicatiekanaal URL is ongeldig.',
							'invalidParams' => [
								[
									'name' => 'communicatiekanaal',
									'code' => $code,
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
				$caseTypeUrl = $body['caseType'] ?? '';
				if (empty($caseTypeUrl) === false) {
					$error = $this->preValidateProductenOfDiensten(
						producten: $producten,
						caseTypeUrl: $caseTypeUrl
					);
					if ($error !== null) {
						return $error;
					}
				}
			}
		} catch (\Throwable $e) {
			// Pre-validation is best-effort; fall through to handleUpdate.
			$this->zgwService->getLogger()->debug(
				'preValidateZaakBody: ' . $e->getMessage()
			);
		}//end try

		return null;
	}//end preValidateZaakBody()

	/**
	 * Pre-validate productenOfDiensten against zaaktype (zrc-015).
	 *
	 * @param array $producten The productenOfDiensten URLs
	 * @param string $caseTypeUrl The zaaktype URL
	 *
	 * @return JSONResponse|null A 400 response if invalid, null if valid
	 */
	private function preValidateProductenOfDiensten(
		array $producten,
		string $caseTypeUrl,
	): ?JSONResponse {
		$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
		if (preg_match($uuidPattern, $caseTypeUrl, $matches) !== 1) {
			return null;
		}

		$ztConfig = $this->zgwService->getZgwMappingService()->getMapping('caseType');
		if ($ztConfig === null) {
			return null;
		}

		try {
			$ztObj = $this->zgwService->getObjectService()->find(
				$matches[1],
				register: $ztConfig['sourceRegister'],
				schema: $ztConfig['sourceSchema']
			);
			$ztData = $this->objectToArray(row: $ztObj);
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
						'detail' => $this->l10n->t('productenOfDiensten contains a value not present in the zaaktype.'),
						'invalidParams' => [
							[
								'name' => 'productenOfDiensten',
								'code' => 'invalid-products-services',
								'reason' => $this->l10n->t('Product \'%s\' is not allowed for this zaaktype.', [$product]),
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
	private function destroyCase(string $uuid): JSONResponse {
		// C4: Require zaken.verwijderen scope for all zaak deletions.
		if ($this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.verwijderen') === false) {
			return $this->permissionDeniedResponse();
		}

		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, 'zaken');
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, 'zaken');
		}

		// C4: Load the zaak to verify it exists and inspect its archive status.
		try {
			$caseObj = $objectService->find(
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

		// C4: Treat a null return from find() as not-found.
		if ($caseObj === null) {
			return new JSONResponse(
				data: ['detail' => 'Not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$caseData = $this->objectToArray(row: $caseObj);

		// C4: Refuse to delete archived zaken without the geforceerd-verwijderen scope.
		$isArchived = ($caseData['archiefstatus'] ?? '') !== '' && ($caseData['archiefstatus'] ?? '') !== 'nog_te_archiveren';
		if ($isArchived === true
			&& $this->zgwService->consumerHasScope($this->request, 'zrc', 'zaken.geforceerd-verwijderen') === false
		) {
			return new JSONResponse(
				data: [
					'detail' => $this->l10n->t('Archived zaken cannot be deleted without the zaken.geforceerd-verwijderen scope.'),
					'code' => 'permission_denied',
				],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		// Zrc-005b: Before deleting the zaak, sync-delete OIOs in DRC
		// for any linked ZaakInformatieObjecten. This cross-component
		// side-effect cannot be handled by OpenRegister's cascade delete.
		// L1: Paginate through all ZIOs to avoid orphan OIOs on large zaken.
		$zioConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakinformatieobject');
		if ($zioConfig !== null) {
			try {
				$page = 1;
				do {
					$query = $objectService->buildSearchQuery(
						requestParams: ['case' => $uuid, '_limit' => 100, '_page' => $page],
						register: $zioConfig['sourceRegister'],
						schema: $zioConfig['sourceSchema']
					);
					$result = $objectService->searchObjectsPaginated(query: $query);
					$objects = $result['results'] ?? [];

					foreach ($objects as $obj) {
						$data = $this->objectToArray(row: $obj);

						$subUuid = $data['id'] ?? ($data['@self']['id'] ?? '');
						if ($subUuid === '') {
							continue;
						}

						$zioData = $this->getZioDataForOioSync(uuid: $subUuid);
						if ($zioData !== null) {
							$this->syncDeleteObjectInformatieObject(
								caseUrl: $zioData['zaakUrl'],
								ioUrl: $zioData['ioUrl']
							);
						}
					}

					$page++;
					$hasMore = count($objects) === 100;
				} while ($hasMore === true);
			} catch (\Throwable $e) {
				$this->zgwService->getLogger()->warning(
					'zrc-023: Failed to sync-delete OIOs for zaak ' . $uuid . ': ' . $e->getMessage()
				);
			}//end try
		}//end if

		// Related-case-linking: strip this case's entries from every counterpart
		// case's relatedCases BEFORE deletion so no dangling peer references
		// survive (mirrors the deelzaak orphan cleanup). Run while the case is
		// still readable so its own relation list can be dereferenced.
		try {
			$this->caseRelationService->cleanupForDeletedCase(caseId: $uuid);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'related-case-linking: relation cleanup failed for deleted zaak ' . $uuid . ': ' . $e->getMessage()
			);
		}

		// Cascade delete of sub-resources (rol, status, resultaat, etc.)
		// is handled by OpenRegister via onDelete: CASCADE in schema definitions.
		try {
			$objectService->deleteObject(uuid: $uuid);
		} catch (\Throwable $e) {
			return new JSONResponse(
				data: ['detail' => 'Failed to delete case: ' . $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, 'zaken');
		$this->zgwService->publishNotification(
			self::ZGW_API,
			'zaken',
			$baseUrl . '/' . $uuid,
			'destroy'
		);

		$this->zgwService->getLogger()->info(
			'zrc-023: Cascade deleted zaak ' . $uuid . ' with all sub-resources'
		);

		return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
	}//end destroyZaak()

	/**
	 * Resolve zaak-closed state and geforceerd scope for an existing resource.
	 *
	 * @param string $resource The ZGW resource name
	 * @param string $uuid The resource UUID
	 *
	 * @return array{0: ?bool, 1: bool} [zaakClosed, hasGeforceerd]
	 */
	private function resolveCaseClosedForExisting(string $resource, string $uuid): array {
		$caseClosed = null;
		$hasGeforceerd = true;

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig !== null && $this->zgwService->getObjectService() !== null) {
			try {
				$existingObj = $this->zgwService->getObjectService()->find(
					$uuid,
					register: $mappingConfig['sourceRegister'],
					schema: $mappingConfig['sourceSchema']
				);
				$existingData = $this->objectToArray(row: $existingObj);

				$caseClosed = $this->zgwService->resolveZaakClosed($resource, $existingData);
				$hasGeforceerd = true;
				if ($caseClosed === true) {
					$hasGeforceerd = $this->zgwService->consumerHasScope(
						$this->request,
						'zrc',
						'zaken.geforceerd-bijwerken'
					);
				}
			} catch (\Throwable $e) {
				// WF3c fix: fail-CLOSED on any unexpected error. Returning
				// [true, false] (zaak=closed, no geforceerd scope) causes the
				// upstream caller to emit a 403 rather than silently allowing
				// modification of a legally-finalised zaak (zrc-007, Awb 4:5).
				$this->zgwService->getLogger()->error(
					'Could not resolve zaakClosed for ' . $resource . '/' . $uuid . ' — denying (fail-closed)',
					['exception' => $e->getMessage()]
				);
				return [true, false];
			}//end try
		}//end if

		return [$caseClosed, $hasGeforceerd];
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
	private function checkReopenScope(array $body): ?JSONResponse {
		try {
			$caseUrl = $body['case'] ?? '';
			$statustypeUrl = $body['statustype'] ?? '';
			if ($caseUrl === '' || $statustypeUrl === '') {
				return null;
			}

			$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

			// Find the zaak.
			if (preg_match($uuidPattern, $caseUrl, $caseMatches) !== 1) {
				return null;
			}

			$caseConfig = $this->zgwService->getZgwMappingService()->getMapping('case');
			if ($caseConfig === null) {
				return null;
			}

			$case = $this->zgwService->getObjectService()->find(
				$caseMatches[1],
				register: $caseConfig['sourceRegister'],
				schema: $caseConfig['sourceSchema']
			);
			$caseData = $this->objectToArray(row: $case);

			$endDate = $caseData['endDate'] ?? null;

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
			$stData = $this->objectToArray(row: $statustype);

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
			// WF2 fix: fail-CLOSED on any unexpected error. If we cannot
			// determine whether the zaak is closed and a scope gate applies,
			// we must deny rather than silently allow. Returning
			// permissionDeniedResponse() here prevents a transient OR error
			// (or a crafted UUID that causes an exception) from bypassing the
			// heropenen gate and re-opening a legally-finalised zaak.
			$this->zgwService->getLogger()->error(
				'zrc-008c: Unexpected error in checkReopenScope — denying request (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return $this->permissionDeniedResponse();
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
	private function checkIndicationGebruiksrechtBeforeClose(array $body): ?JSONResponse {
		try {
			$caseUrl = $body['case'] ?? '';
			$statustypeUrl = $body['statustype'] ?? '';
			if ($caseUrl === '' || $statustypeUrl === '') {
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

			$stData = $this->objectToArray(row: $statustype);

			$isEindstatus = $stData['isFinal'] ?? ($stData['isFinalStatus'] ?? ($stData['isEindstatus'] ?? false));

			// Normalize boolean.
			if ($isEindstatus === 'true' || $isEindstatus === '1' || $isEindstatus === 1 || $isEindstatus === true) {
				$isEindstatus = true;
			}

			// Also check by highest volgnummer if not explicitly set.
			if ($isEindstatus !== true) {
				$isEindstatus = $this->isEindstatusBySequenceNumber(
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
			if (preg_match($uuidPattern, $caseUrl, $caseMatches) !== 1) {
				return null;
			}

			// Check if zaak is already closed (has endDate).
			$caseConfig = $this->zgwService->getZgwMappingService()->getMapping('case');
			$caseAlreadyClosed = false;
			if ($caseConfig !== null) {
				$caseObj = $this->zgwService->getObjectService()->find(
					$caseMatches[1],
					register: $caseConfig['sourceRegister'],
					schema: $caseConfig['sourceSchema']
				);
				if ($caseObj !== null) {
					$caseData = $this->objectToArray(row: $caseObj);

					$endDate = $caseData['endDate'] ?? null;
					$caseAlreadyClosed = ($endDate !== null && $endDate !== '');
				}
			}

			// Zrc-007b: Only derive indicatieGebruiksrecht on first close.
			if ($caseAlreadyClosed === false) {
				$this->setIndicationGebruiksrechtOnClose(zaakUuid: $caseMatches[1]);
			}

			// Zrc-007q: Now verify all linked IOs have indicatieGebruiksrecht set.
			$zioConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakinformatieobject');
			$docConfig = $this->zgwService->getZgwMappingService()->getMapping('enkelvoudiginformatieobject');
			if ($zioConfig === null || $docConfig === null) {
				return null;
			}

			$query = $this->zgwService->getObjectService()->buildSearchQuery(
				requestParams: ['case' => $caseMatches[1], '_limit' => 100],
				register: $zioConfig['sourceRegister'],
				schema: $zioConfig['sourceSchema']
			);
			$zioResult = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

			foreach (($zioResult['results'] ?? []) as $zioObj) {
				$zioData = $this->objectToArray(row: $zioObj);

				$docUuid = $zioData['document'] ?? ($zioData['informatieobject'] ?? '');

				if (preg_match($uuidPattern, (string)$docUuid, $docMatches) !== 1) {
					continue;
				}

				$docObj = $this->zgwService->getObjectService()->find(
					$docMatches[1],
					register: $docConfig['sourceRegister'],
					schema: $docConfig['sourceSchema']
				);
				$docData = $this->objectToArray(row: $docObj);

				$indGr = $docData['usageRightsIndication'] ?? ($docData['usageRightsIndicator'] ?? ($docData['indicatieGebruiksrecht'] ?? null));

				if ($indGr === null || $indGr === '') {
					$detail = 'Zaak kan niet afgesloten worden: niet alle informatieobjecten hebben indicatieGebruiksrecht gezet.';
					return new JSONResponse(
						data: [
							'detail' => $detail,
							'code' => 'indicatiegebruiksrecht-unset',
							'invalidParams' => [
								[
									'name' => 'nonFieldErrors',
									'code' => 'indicatiegebruiksrecht-unset',
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
				'zrc-007q: Could not check indicatieGebruiksrecht: ' . $e->getMessage()
			);
		}//end try

		return null;
	}//end checkIndicatieGebruiksrechtBeforeClose()

	/**
	 * Check if a statustype is the eindstatus by having the highest volgnummer.
	 *
	 * @param array $stData The statustype data
	 * @param array $stConfig The statustype mapping config
	 * @param string $uuidPattern The UUID regex pattern
	 *
	 * @return bool True if this statustype has the highest volgnummer
	 */
	private function isEindstatusBySequenceNumber(array $stData, array $stConfig, string $uuidPattern): bool {
		$caseTypeUuid = (string)($stData['caseType'] ?? '');
		if (preg_match($uuidPattern, $caseTypeUuid, $ctMatches) === 1) {
			$caseTypeUuid = $ctMatches[1];
		}

		$thisOrder = (int)($stData['order'] ?? ($stData['sequenceNumber'] ?? 0));
		if ($caseTypeUuid === '' || $thisOrder <= 0) {
			return false;
		}

		try {
			$query = $this->zgwService->getObjectService()->buildSearchQuery(
				requestParams: ['caseType' => $caseTypeUuid, '_limit' => 100],
				register: $stConfig['sourceRegister'],
				schema: $stConfig['sourceSchema']
			);
			$result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);
		} catch (\Throwable $e) {
			$result = $this->zgwService->getObjectService()->searchObjectsPaginated(
				query: [
					'@self' => [
						'register' => (int)$stConfig['sourceRegister'],
						'schema' => (int)$stConfig['sourceSchema'],
					],
					'caseType' => $caseTypeUuid,
				]
			);
		}

		$maxOrder = 0;
		foreach (($result['results'] ?? []) as $st) {
			$stObj = $this->objectToArray(row: $st);

			$order = (int)($stObj['order'] ?? ($stObj['sequenceNumber'] ?? 0));
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
	 * @param array $body The original request body
	 * @param array $objectData The created object data
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	private function handleEindstatusEffect(array $body, array $objectData): void {
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

			$stData = $this->objectToArray(row: $statustype);

			$isEindstatus = $stData['isFinal'] ?? ($stData['isFinalStatus'] ?? ($stData['isEindstatus'] ?? false));

			// Normalize boolean from OpenRegister (may be string/int).
			if ($isEindstatus === 'true' || $isEindstatus === '1' || $isEindstatus === 1 || $isEindstatus === true) {
				$isEindstatus = true;
			}

			// ZGW standard: if isFinal not explicitly set, the statustype with
			// the highest volgnummer for this zaaktype is the eindstatus.
			if ($isEindstatus !== true) {
				$caseTypeUuid = (string)($stData['caseType'] ?? '');
				// Extract UUID in case caseType is stored as a URL.
				if (preg_match($uuidPattern, $caseTypeUuid, $ctMatches) === 1) {
					$caseTypeUuid = $ctMatches[1];
				}

				$thisOrder = (int)($stData['order'] ?? ($stData['sequenceNumber'] ?? 0));
				if ($caseTypeUuid !== '' && $thisOrder > 0) {
					// Search for all statustypen of this zaaktype.
					try {
						$query = $this->zgwService->getObjectService()->buildSearchQuery(
							requestParams: ['caseType' => $caseTypeUuid, '_limit' => 100],
							register: $stConfig['sourceRegister'],
							schema: $stConfig['sourceSchema']
						);
						$result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);
					} catch (\Throwable $e) {
						// Fallback: try direct query without buildSearchQuery.
						$result = $this->zgwService->getObjectService()->searchObjectsPaginated(
							query: [
								'@self' => [
									'register' => (int)$stConfig['sourceRegister'],
									'schema' => (int)$stConfig['sourceSchema'],
								],
								'caseType' => $caseTypeUuid,
							]
						);
					}

					$maxOrder = 0;
					foreach (($result['results'] ?? []) as $st) {
						$stObj = $this->objectToArray(row: $st);

						$order = (int)($stObj['order'] ?? ($stObj['sequenceNumber'] ?? 0));
						if ($order > $maxOrder) {
							$maxOrder = $order;
						}
					}

					if ($thisOrder >= $maxOrder && $maxOrder > 0) {
						$isEindstatus = true;
					}
				}//end if
			}//end if

			$caseUrl = $body['case'] ?? '';
			if ($caseUrl === '') {
				return;
			}

			if (preg_match($uuidPattern, $caseUrl, $caseMatches) !== 1) {
				return;
			}

			$caseConfig = $this->zgwService->getZgwMappingService()->getMapping('case');
			if ($caseConfig === null) {
				return;
			}

			$case = $this->zgwService->getObjectService()->find(
				$caseMatches[1],
				register: $caseConfig['sourceRegister'],
				schema: $caseConfig['sourceSchema']
			);
			if ($case === null) {
				return;
			}

			$caseData = $this->objectToArray(row: $case);

			// Strip metadata that confuses saveObject on re-save.
			unset($caseData['@self'], $caseData['organisation']);

			// Ensure field types match schema expectations for re-save.
			// OpenRegister may store numeric-looking strings as integers, but the
			// schema expects string types for fields like bronorganisatie.
			$stringFields = ['title', 'assignee', 'sourceOrganisation', 'identifier'];
			foreach ($stringFields as $field) {
				if (isset($caseData[$field]) === true && is_int($caseData[$field]) === true) {
					$caseData[$field] = (string)$caseData[$field];
				}

				if ($field === 'title' && isset($caseData[$field]) === false) {
					$caseData[$field] = '';
				}
			}

			if ($isEindstatus === true) {
				// Zrc-007a: Set zaak einddatum when eindstatus is created.
				$dateStatusGezet = $body['datumStatusGezet'] ?? ($objectData['statusSetDate'] ?? date('Y-m-d'));
				if (strlen($dateStatusGezet) > 10) {
					$dateStatusGezet = substr($dateStatusGezet, 0, 10);
				}

				$caseData['endDate'] = $dateStatusGezet;

				// Zrc-021: Derive archiefactiedatum from resultaat.resultaattype.brondatumArchiefprocedure.
				$caseData = $this->deriveArchiveActionDate(
					caseData: $caseData,
					caseConfig: $caseConfig,
					dateStatusGezet: $dateStatusGezet
				);

				$caseData['id'] = $caseMatches[1];
				$this->zgwService->getObjectService()->saveObject(
					register: $caseConfig['sourceRegister'],
					schema: $caseConfig['sourceSchema'],
					object: $caseData,
					uuid: $caseMatches[1]
				);

				// Zrc-007b: Set indicatieGebruiksrecht on all related informatieobjecten.
				$this->setIndicationGebruiksrechtOnClose(zaakUuid: $caseMatches[1]);
			}//end if

			if ($isEindstatus === false) {
				// Zrc-008: Heropenen zaak — when a non-eindstatus is created on
				// a zaak that already has an endDate, clear endDate, archiefactiedatum,
				// and archiefnominatie (reopen the zaak).
				$existingEndDate = $caseData['endDate'] ?? null;
				if ($existingEndDate !== null && $existingEndDate !== '') {
					$caseData['endDate'] = null;
					$caseData['archiveActionDate'] = null;
					$caseData['archiveNomination'] = null;
					$caseData['id'] = $caseMatches[1];
					$this->zgwService->getObjectService()->saveObject(
						register: $caseConfig['sourceRegister'],
						schema: $caseConfig['sourceSchema'],
						object: $caseData,
						uuid: $caseMatches[1]
					);

					$this->zgwService->getLogger()->info(
						'zrc-008: Heropened zaak ' . $caseMatches[1] . ' — cleared endDate, archiveActionDate, archiveNomination'
					);
				}
			}//end if
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'handleEindstatusEffect failed: ' . $e->getMessage(),
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
	private function setIndicationGebruiksrechtOnClose(string $zaakUuid): void {
		try {
			$zioConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakinformatieobject');
			$docConfig = $this->zgwService->getZgwMappingService()->getMapping('enkelvoudiginformatieobject');
			if ($zioConfig === null || $docConfig === null) {
				return;
			}

			// Find all ZIOs for this zaak.
			$query = $this->zgwService->getObjectService()->buildSearchQuery(
				requestParams: ['case' => $zaakUuid, '_limit' => 100],
				register: $zioConfig['sourceRegister'],
				schema: $zioConfig['sourceSchema']
			);
			$result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

			foreach (($result['results'] ?? []) as $zioObj) {
				$zioData = $this->objectToArray(row: $zioObj);

				$docUuid = $zioData['document'] ?? ($zioData['informatieobject'] ?? '');

				$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
				if (preg_match($uuidPattern, (string)$docUuid, $docMatches) !== 1) {
					continue;
				}

				try {
					$docObj = $this->zgwService->getObjectService()->find(
						$docMatches[1],
						register: $docConfig['sourceRegister'],
						schema: $docConfig['sourceSchema']
					);
					$docData = $this->objectToArray(row: $docObj);

					// Check if indicatieGebruiksrecht is already set.
					$indGr = $docData['usageRightsIndication'] ?? ($docData['usageRightsIndicator'] ?? ($docData['indicatieGebruiksrecht'] ?? null));

					if ($indGr === null || $indGr === '') {
						// Check if gebruiksrechten exist for this document.
						$grConfig = $this->zgwService->getZgwMappingService()->getMapping('gebruiksrechten');
						$hasGr = false;
						if ($grConfig !== null) {
							try {
								$grQuery = $this->zgwService->getObjectService()->buildSearchQuery(
									requestParams: ['document' => $docMatches[1], '_limit' => 1],
									register: $grConfig['sourceRegister'],
									schema: $grConfig['sourceSchema']
								);
								$grResult = $this->zgwService->getObjectService()
									->searchObjectsPaginated(query: $grQuery);
								$hasGr = empty($grResult['results'] ?? []) === false;
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
						'zrc-007b: Could not update indicatieGebruiksrecht for doc ' . $docMatches[1] . ': ' . $e->getMessage()
					);
				}//end try
			}//end foreach
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'zrc-007b: Failed to set indicatieGebruiksrecht: ' . $e->getMessage()
			);
		}//end try
	}//end setIndicatieGebruiksrechtOnClose()

	/**
	 * Handle resultaat creation side-effects (zrc-021).
	 *
	 * When a resultaat is created, derive archiefactiedatum and
	 * archiefnominatie on the parent zaak from the resultaattype.
	 *
	 * @param array $body The original request body (Dutch names)
	 * @param array $objectData The created resultaat object data
	 *
	 * @return void
	 *
	 * @psalm-suppress UnusedParam — $objectData reserved for future use in result processing
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $objectData reserved for future result processing
	 */
	private function handleResultCreated(array $body, array $objectData): void {
		try {
			$caseUrl = $body['case'] ?? '';
			if ($caseUrl === '') {
				return;
			}

			$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
			if (preg_match($uuidPattern, $caseUrl, $caseMatches) !== 1) {
				return;
			}

			$caseConfig = $this->zgwService->getZgwMappingService()->getMapping('case');
			if ($caseConfig === null) {
				return;
			}

			$caseObj = $this->zgwService->getObjectService()->find(
				$caseMatches[1],
				register: $caseConfig['sourceRegister'],
				schema: $caseConfig['sourceSchema']
			);
			$caseData = $this->objectToArray(row: $caseObj);

			// Use the zaak endDate as einddatum (may be null if zaak isn't closed yet).
			$endDate = $caseData['endDate'] ?? date('Y-m-d');

			$caseData = $this->deriveArchiveActionDate(
				caseData: $caseData,
				caseConfig: $caseConfig,
				dateStatusGezet: $endDate
			);

			// Type coercion for re-save (OpenRegister stores numeric strings as ints).
			$stringFields = ['title', 'assignee', 'sourceOrganisation', 'identifier'];
			foreach ($stringFields as $field) {
				if (isset($caseData[$field]) === true && is_int($caseData[$field]) === true) {
					$caseData[$field] = (string)$caseData[$field];
				}

				if ($field === 'title' && isset($caseData[$field]) === false) {
					$caseData[$field] = '';
				}
			}

			// Save the updated zaak.
			$caseData['id'] = $caseMatches[1];
			$this->zgwService->getObjectService()->saveObject(
				register: $caseConfig['sourceRegister'],
				schema: $caseConfig['sourceSchema'],
				object: $caseData,
				uuid: $caseMatches[1]
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'zrc-021: handleResultaatCreated failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try
	}//end handleResultaatCreated()

	/**
	 * Derive archiefactiedatum from resultaat's resultaattype brondatumArchiefprocedure (zrc-021).
	 *
	 * @param array $caseData The zaak data
	 * @param array $caseConfig The zaak mapping config
	 * @param string $dateStatusGezet The datumStatusGezet (einddatum)
	 *
	 * @return array The zaak data with derived archiving parameters
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	private function deriveArchiveActionDate(array $caseData, array $caseConfig, string $dateStatusGezet): array {
		try {
			// Find the zaak's resultaat to get the resultaattype.
			$zaakUuid = $caseData['id'] ?? ($caseData['@self']['id'] ?? '');
			if ($zaakUuid === '') {
				return $caseData;
			}

			$resultConfig = $this->zgwService->getZgwMappingService()->getMapping('result');
			if ($resultConfig === null) {
				return $caseData;
			}

			// Search for resultaat linked to this zaak.
			$query = $this->zgwService->getObjectService()->buildSearchQuery(
				requestParams: ['case' => $zaakUuid, '_limit' => 1],
				register: $resultConfig['sourceRegister'],
				schema: $resultConfig['sourceSchema']
			);
			$result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

			$results = $result['results'] ?? [];
			if (empty($results) === true) {
				return $caseData;
			}

			$resultaat = $results[0];
			$resultData = $this->objectToArray(row: $resultaat);

			// Get the resultaattype to find brondatumArchiefprocedure.
			$resultaattypeId = $resultData['resultType'] ?? ($resultData['resultaattype'] ?? '');
			if (empty($resultaattypeId) === true) {
				return $caseData;
			}

			$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
			if (preg_match($uuidPattern, (string)$resultaattypeId, $rtMatches) !== 1) {
				return $caseData;
			}

			$rtConfig = $this->zgwService->getZgwMappingService()->getMapping('resultaattype');
			if ($rtConfig === null) {
				return $caseData;
			}

			$rtObj = $this->zgwService->getObjectService()->find(
				$rtMatches[1],
				register: $rtConfig['sourceRegister'],
				schema: $rtConfig['sourceSchema']
			);
			if ($rtObj === null) {
				return $caseData;
			}

			$rtData = $this->objectToArray(row: $rtObj);

			// Get brondatumArchiefprocedure.
			$brondatum = $rtData['sourceDateArchiveProcedure'] ?? ($rtData['brondatumArchiefprocedure'] ?? null);
			if (is_string($brondatum) === true) {
				$brondatum = json_decode($brondatum, true);
			}

			if ($brondatum === null || is_array($brondatum) === false) {
				return $caseData;
			}

			$afleidingswijze = $brondatum['derivationMethod'] ?? ($brondatum['afleidingswijze'] ?? '');
			// Archiefactietermijn lives on the ResultaatType, not inside brondatumArchiefprocedure.
			$procestermijn = $rtData['archivalPeriod'] ?? ($rtData['archiefactietermijn'] ?? null);

			// Determine the base date based on afleidingswijze.
			$baseDate = $this->resolveArchiveBaseDate(
				afleidingswijze: $afleidingswijze,
				endDate: $dateStatusGezet,
				caseData: $caseData,
				caseConfig: $caseConfig,
				brondatum: $brondatum
			);

			if ($baseDate === null) {
				// Base date unresolvable — set archiefactiedatum to null but still derive archiefnominatie.
				$caseData['archiveActionDate'] = null;

				$nomination = $rtData['archivalAction'] ?? ($rtData['archiveNomination'] ?? ($rtData['archiefnominatie'] ?? ''));
				if ($nomination !== '') {
					$caseData['archiveNomination'] = $nomination;
				}

				return $caseData;
			}

			// Add procestermijn (ISO 8601 duration) to the base date.
			$archiveActionDate = $baseDate;
			if ($procestermijn !== null && $procestermijn !== '') {
				try {
					$dateObj = new DateTime($baseDate);
					$interval = new DateInterval($procestermijn);
					$dateObj->add($interval);
					$archiveActionDate = $dateObj->format('Y-m-d');
				} catch (\Throwable $e) {
					$this->zgwService->getLogger()->debug(
						'zrc-021: Invalid procestermijn: ' . $procestermijn
					);
				}
			}

			$caseData['archiveActionDate'] = $archiveActionDate;

			// Zrc-021: Also set archiveNomination from the resultaattype.
			$nomination = $rtData['archivalAction'] ?? ($rtData['archiveNomination'] ?? ($rtData['archiefnominatie'] ?? ''));
			if ($nomination !== '') {
				$caseData['archiveNomination'] = $nomination;
			}

			$this->zgwService->getLogger()->info(
				'zrc-021: Derived archiefactiedatum=' . $archiveActionDate . ' (afleidingswijze=' . $afleidingswijze . ')'
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'zrc-021: Failed to derive archiefactiedatum: ' . $e->getMessage()
			);
		}//end try

		return $caseData;
	}//end deriveArchiefactiedatum()

	/**
	 * Resolve the base date for archive action date derivation (zrc-021).
	 *
	 * @param string $afleidingswijze The derivation method
	 * @param string $endDate The zaak end date
	 * @param array $caseData The zaak data
	 * @param array $caseConfig The zaak mapping config
	 * @param array $brondatum The brondatumArchiefprocedure data
	 *
	 * @return string|null The base date, or null if not resolvable
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function resolveArchiveBaseDate(
		string $afleidingswijze,
		string $endDate,
		array $caseData,
		array $caseConfig,
		array $brondatum,
	): ?string {
		switch ($afleidingswijze) {
			case 'handled':
			case 'termijn':
				return $endDate;
			case 'ander_datumkenmerk':
				// Cannot be automatically determined — requires external datumkenmerk.
				return null;
			case 'hoofdzaak':
				$mainCaseId = $caseData['parentCase'] ?? ($caseData['mainCase'] ?? ($caseData['hoofdzaak'] ?? ''));
				if (empty($mainCaseId) === true) {
					return $endDate;
				}

				$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
				if (preg_match($uuidPattern, (string)$mainCaseId, $matches) === 1) {
					try {
						$mainCase = $this->zgwService->getObjectService()->find(
							$matches[1],
							register: $caseConfig['sourceRegister'],
							schema: $caseConfig['sourceSchema']
						);
						$mainData = $this->objectToArray(row: $mainCase);

						$mainEnd = $mainData['endDate'] ?? null;
						if ($mainEnd !== null && $mainEnd !== '') {
							if (is_string($mainEnd) === true) {
								return substr($mainEnd, 0, 10);
							}

							return $endDate;
						}
					} catch (\Throwable $e) {
						// Fall through to einddatum.
					}//end try
				}//end if
				return $endDate;
			case 'eigenschap':
				$datumkenmerk = $brondatum['objectAttribute'] ?? ($brondatum['datumkenmerk'] ?? '');
				if ($datumkenmerk !== '' && $this->zgwService->getObjectService() !== null) {
					return $this->resolveAttributeDate(caseData: $caseData, datumkenmerk: $datumkenmerk) ?? $endDate;
				}
				return $endDate;
			case 'ingangsdatum_besluit':
				return $this->resolveDecisionDate(
					caseData: $caseData,
					englishField: 'effectiveDate',
					dutchField: 'effectiveDate'
				) ?? $endDate;

			case 'vervaldatum_besluit':
				return $this->resolveDecisionDate(
					caseData: $caseData,
					englishField: 'expiryDate',
					dutchField: 'vervaldatum'
				) ?? $endDate;

			default:
				return null;
		}//end switch
	}//end resolveArchiveBaseDate()

	/**
	 * Resolve a zaakeigenschap date value for archive derivation (zrc-021 eigenschap).
	 *
	 * @param array $caseData The zaak data
	 * @param string $datumkenmerk The eigenschap name/key to look up
	 *
	 * @return string|null The date value, or null if not found
	 */
	private function resolveAttributeDate(array $caseData, string $datumkenmerk): ?string {
		$zaakUuid = $caseData['id'] ?? ($caseData['@self']['id'] ?? '');
		if ($zaakUuid === '') {
			return null;
		}

		$propConfig = $this->zgwService->getZgwMappingService()->getMapping('zaakeigenschap');
		if ($propConfig === null) {
			return null;
		}

		try {
			$query = $this->zgwService->getObjectService()->buildSearchQuery(
				requestParams: ['case' => $zaakUuid, 'name' => $datumkenmerk],
				register: $propConfig['sourceRegister'],
				schema: $propConfig['sourceSchema']
			);
			$result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

			$results = $result['results'] ?? [];
			if (empty($results) === false) {
				$propObj = $results[0];
				$propData = $this->objectToArray(row: $propObj);

				$value = $propData['value'] ?? '';
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
	 * @param array $caseData The zaak data
	 * @param string $englishField The English field name
	 * @param string $dutchField The Dutch field name (fallback)
	 *
	 * @return string|null The date value, or null if not found
	 */
	private function resolveDecisionDate(array $caseData, string $englishField, string $dutchField): ?string {
		$zaakUuid = $caseData['id'] ?? ($caseData['@self']['id'] ?? '');
		if ($zaakUuid === '') {
			return null;
		}

		$decisionConfig = $this->zgwService->getZgwMappingService()->getMapping('decision');
		if ($decisionConfig === null) {
			return null;
		}

		try {
			$query = $this->zgwService->getObjectService()->buildSearchQuery(
				requestParams: ['case' => $zaakUuid, '_limit' => 100],
				register: $decisionConfig['sourceRegister'],
				schema: $decisionConfig['sourceSchema']
			);
			$result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

			$results = $result['results'] ?? [];
			if (empty($results) === true) {
				return null;
			}

			// Find the latest (maximum) date among all besluiten for this zaak.
			$latestDate = null;
			foreach ($results as $decisionObj) {
				$decisionData = $this->objectToArray(row: $decisionObj);

				$dateVal = $decisionData[$englishField] ?? ($decisionData[$dutchField] ?? '');
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
	 * @param array $body The enriched request body (from business rules)
	 *
	 * @return array The enriched mapped data
	 */
	private function enrichZioResponse(array $mapped, array $body): array {
		// Zrc-004a: aardRelatieWeergave is always "Hoort bij, omgekeerd: kent".
		$mapped['natureRelationshipDisplay'] = 'Hoort bij, omgekeerd: kent';

		// Zrc-004a: registratiedatum from the enriched body (set by business rules).
		if (isset($body['registrationDate']) === true
			&& isset($mapped['registrationDate']) === false
		) {
			$mapped['registrationDate'] = $body['registrationDate'];
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
	private function enrichZioJsonResponse(JSONResponse $response): JSONResponse {
		$data = $response->getData();
		if (is_array($data) === true) {
			$data['natureRelationshipDisplay'] = 'Hoort bij, omgekeerd: kent';
			$response->setData($data);
		}

		return $response;
	}//end enrichZioJsonResponse()

	/**
	 * Build the ZRC relevanteAndereZaken array for a single zaak from its
	 * relatedCases field (outbound). Emits absolute zaak URLs and the
	 * aardRelatie; never emits the dossiq-local toelichting. Always an array
	 * (empty when there are no relations), per VNG schema compliance.
	 *
	 * @param array<string, mixed> $caseData The mapped zaak response data.
	 *
	 * @return array<int, array{url: string, aardRelatie: string}>
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function buildRelevanteAndereCases(array $caseData): array {
		$uuid = (string)($caseData['uuid'] ?? ($caseData['identificatie'] ?? ''));
		$pattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';

		// Prefer a UUID embedded in the id field, else fall back to the self URL.
		// When neither yields one the original id is kept verbatim.
		foreach ([$uuid, (string)($caseData['url'] ?? '')] as $candidate) {
			if ($candidate !== '' && preg_match($pattern, $candidate, $matches) === 1) {
				$uuid = $matches[1];
				break;
			}
		}

		if ($uuid === '') {
			return [];
		}

		$relations = $this->caseRelationService->listRelations(caseId: $uuid);
		if ($relations === []) {
			return [];
		}

		$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, 'zaken');
		$out = [];
		foreach ($relations as $relation) {
			$targetId = (string)($relation['caseId'] ?? '');
			$nature = (string)($relation['aardRelatie'] ?? '');
			if ($targetId === '' || $nature === '') {
				continue;
			}

			$out[] = [
				'url' => $baseUrl . '/' . $targetId,
				'aardRelatie' => $nature,
			];
		}

		return $out;
	}//end buildRelevanteAndereZaken()

	/**
	 * Set relevanteAndereZaken on a single-zaak (show/update/patch) response.
	 *
	 * @param JSONResponse $response The zaak response.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function enrichCaseRelevanteAndereCases(JSONResponse $response): JSONResponse {
		$data = $response->getData();
		if (is_array($data) === true) {
			$data['relevanteAndereZaken'] = $this->buildRelevanteAndereCases(caseData: $data);
			$response->setData($data);
		}

		return $response;
	}//end enrichZaakRelevanteAndereZaken()

	/**
	 * Set relevanteAndereZaken on every result of a zaken list response.
	 *
	 * @param JSONResponse $response The zaken list response.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function enrichCasesListRelevanteAndereCases(JSONResponse $response): JSONResponse {
		$data = $response->getData();
		if (is_array($data) === false || isset($data['results']) === false || is_array($data['results']) === false) {
			return $response;
		}

		foreach ($data['results'] as $idx => $case) {
			if (is_array($case) === true) {
				$case['relevanteAndereZaken'] = $this->buildRelevanteAndereCases(caseData: $case);
				$data['results'][$idx] = $case;
			}
		}

		$response->setData($data);

		return $response;
	}//end enrichZakenListRelevanteAndereZaken()

	/**
	 * Resolve an inbound relevanteAndereZaken array on a zaak write into local
	 * case UUIDs and route each through the guarded, symmetric
	 * CaseRelationService. A relation URL that does not resolve to a local case
	 * is rejected with the capability's standard ZGW validation error shape.
	 *
	 * @param string $caseUuid The local UUID of the written zaak.
	 * @param array<string, mixed> $body The original (Dutch) request body.
	 *
	 * @return JSONResponse|null A 400 validation error, or null on success.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function applyInboundRelevanteAndereCases(string $caseUuid, array $body): ?JSONResponse {
		$relevanteCases = ($body['relevanteAndereZaken'] ?? null);
		if (is_array($relevanteCases) === false || $relevanteCases === [] || $caseUuid === '') {
			return null;
		}

		$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
		foreach ($relevanteCases as $idx => $relCase) {
			if (is_array($relCase) === false) {
				continue;
			}

			$url = (string)($relCase['url'] ?? '');
			$nature = (string)($relCase['aardRelatie'] ?? '');
			if ($url === '') {
				continue;
			}

			// Resolve the URL to a local case UUID.
			$targetUuid = '';
			if (preg_match($uuidPattern, $url, $matches) === 1) {
				$targetUuid = $matches[1];
			}

			$result = null;
			if ($targetUuid !== '') {
				$result = $this->caseRelationService->addRelation(
					caseId: $caseUuid,
					targetId: $targetUuid,
					natureRelationship: $nature,
				);
			}

			// Unresolvable URL (no local case) or access/guard failure that
			// means the referenced zaak is not a usable local case → reject.
			if ($targetUuid === '' || ($result !== null && $result['ok'] === false && ($result['reason'] ?? '') === 'access_denied')) {
				return new JSONResponse(
					data: [
						'type' => 'ValidationError',
						'code' => 'invalid',
						'title' => 'Ongeldige invoer.',
						'status' => 400,
						'detail' => 'relevanteAndereZaken verwijst naar een onbekende zaak.',
						'invalidParams' => [
							[
								'name' => "relevanteAndereZaken.{$idx}.url",
								'code' => 'unknown-zaak',
								'reason' => 'De zaak-URL verwijst niet naar een bekende lokale zaak.',
							],
						],
					],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}//end if
		}//end foreach

		return null;
	}//end applyInboundRelevanteAndereZaken()

	/**
	 * Create an ObjectInformatieObject in the DRC when a ZaakInformatieObject is created (zrc-005a).
	 *
	 * @param string $caseUrl The zaak URL
	 * @param string $ioUrl The informatieobject URL
	 *
	 * @return void
	 */
	private function syncCreateObjectInformatieObject(string $caseUrl, string $ioUrl): void {
		if ($caseUrl === '' || $ioUrl === '') {
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
				'object' => $caseUrl,
				'objectType' => 'case',
				'informatieobject' => $ioUrl,
			];

			$inboundMapping = $this->zgwService->createInboundMapping(mappingConfig: $oioConfig);
			$englishData = $this->zgwService->applyInboundMapping(
				body: $oioData,
				mapping: $inboundMapping,
				mappingConfig: $oioConfig
			);

			// @phpstan-ignore-next-line — defensive guard: applyInboundMapping may change
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
				'zrc-005a: Failed to create ObjectInformatieObject: ' . $e->getMessage()
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
	private function getZioDataForOioSync(string $uuid): ?array {
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
			$zioData = $this->objectToArray(row: $zioObj);

			// The ZIO stores 'case' as a UUID (format: uuid with $ref) and
			// 'document' as a full URL (format: uri). Build the zaak URL from
			// the case UUID, and use the document URL directly.
			$zaakUuid = $zioData['case'] ?? ($zioData['zaak'] ?? '');
			$ioUrl = $zioData['document'] ?? ($zioData['informatieobject'] ?? '');

			if ($zaakUuid === '' || $ioUrl === '') {
				return null;
			}

			// Build zaak URL from the UUID (case field stores UUID).
			$caseBaseUrl = $this->zgwService->buildBaseUrl($this->request, 'zaken', 'zaken');

			return [
				'zaakUrl' => $caseBaseUrl . '/' . $zaakUuid,
				'ioUrl' => $ioUrl,
			];
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->debug(
				'zrc-005b: Could not get ZIO data for OIO sync: ' . $e->getMessage()
			);
			return null;
		}//end try
	}//end getZioDataForOioSync()

	/**
	 * Delete the ObjectInformatieObject in DRC when a ZaakInformatieObject is deleted (zrc-005b).
	 *
	 * @param string $caseUrl The zaak URL
	 * @param string $ioUrl The informatieobject URL
	 *
	 * @return void
	 */
	private function syncDeleteObjectInformatieObject(string $caseUrl, string $ioUrl): void {
		try {
			$oioConfig = $this->zgwService->getZgwMappingService()->getMapping('objectinformatieobject');
			if ($oioConfig === null) {
				return;
			}

			// The OIO schema (documentLink) stores 'object' and 'document' as
			// full URLs (format: uri). Search by the full URL values directly.
			if ($caseUrl === '' || $ioUrl === '') {
				return;
			}

			$query = $this->zgwService->getObjectService()->buildSearchQuery(
				requestParams: ['object' => $caseUrl, 'document' => $ioUrl],
				register: $oioConfig['sourceRegister'],
				schema: $oioConfig['sourceSchema']
			);
			$result = $this->zgwService->getObjectService()->searchObjectsPaginated(query: $query);

			foreach (($result['results'] ?? []) as $oioObj) {
				$oioData = $this->objectToArray(row: $oioObj);

				$oioUuid = $oioData['id'] ?? ($oioData['@self']['id'] ?? '');
				if ($oioUuid !== '') {
					$this->zgwService->getObjectService()->deleteObject(uuid: $oioUuid);
					$this->zgwService->getLogger()->info(
						'zrc-005b: Deleted ObjectInformatieObject ' . $oioUuid
					);
				}
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'zrc-005b: Failed to delete ObjectInformatieObject: ' . $e->getMessage()
			);
		}//end try
	}//end syncDeleteObjectInformatieObject()
}//end class
