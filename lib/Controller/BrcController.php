<?php

/**
 * Dossiq BRC (Besluiten) Controller
 *
 * Controller for serving ZGW Besluiten API endpoints (besluiten,
 * besluitinformatieobjecten). Implements BRC-specific business rules
 * including cross-register OIO sync, cascade delete, and immutability.
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

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\ZgwService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * BRC (Besluiten) API Controller
 *
 * Handles ZGW Besluiten register resources: besluiten and
 * besluitinformatieobjecten. Implements BRC-specific business rules:
 *
 * - brc-004: PUT/PATCH on besluitinformatieobjecten returns 405
 * - brc-005: Cross-register OIO sync on BIO create/delete
 * - brc-006: Zaak-besluit relation (via ZRC zaakbesluiten endpoint)
 * - brc-009: Cascade delete of BIOs and OIOs when deleting a besluit
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class BrcController extends ZgwController {
	/**
	 * The ZGW API identifier for the Besluiten register.
	 *
	 * @var string
	 */
	private const ZGW_API = 'besluiten';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The incoming request.
	 * @param ZgwService $zgwService The shared ZGW service.
	 * @param SettingsService $settingsService The settings service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ZgwService $zgwService,
		private readonly SettingsService $settingsService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List resources of the given type.
	 *
	 * @param string $resource The ZGW resource name (e.g. besluiten).
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

		// BesluitInformatieObjecten returns a plain array per ZGW spec.
		if ($resource === 'besluitinformatieobjecten') {
			return $this->indexBesluitInformatieObjecten();
		}

		return $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);
	}//end index()

	/**
	 * Create a new resource of the given type.
	 *
	 * For besluitinformatieobjecten, also creates an ObjectInformatieObject
	 * in the DRC register (brc-005a).
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

		// SB2: Gate creates on besluiten.aanmaken scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'brc', 'besluiten.aanmaken') === false) {
			return $this->scopeDeniedResponse(scope: 'besluiten.aanmaken');
		}

		// For besluitinformatieobjecten: use custom create with OIO sync.
		if ($resource === 'besluitinformatieobjecten') {
			return $this->createBesluitInformatieObject();
		}

		// Brc-006: For besluiten with a zaak, sync zaakbesluit to ZRC after creation.
		if ($resource === 'besluiten') {
			return $this->createDecisionWithCaseSync();
		}

		return $this->zgwService->handleCreate($this->request, self::ZGW_API, $resource);
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

		return $this->zgwService->handleShow($this->request, self::ZGW_API, $resource, $uuid);
	}//end show()

	/**
	 * Full update (PUT) a resource by UUID.
	 *
	 * For besluitinformatieobjecten, returns 405 Method Not Allowed (brc-004a).
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

		// SB2: Gate updates on besluiten.bijwerken scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'brc', 'besluiten.bijwerken') === false) {
			return $this->scopeDeniedResponse(scope: 'besluiten.bijwerken');
		}

		// Brc-004a: BesluitInformatieObject is immutable — PUT returns 405.
		if ($resource === 'besluitinformatieobjecten') {
			return new JSONResponse(
				data: ['detail' => 'Method not allowed'],
				statusCode: Http::STATUS_METHOD_NOT_ALLOWED
			);
		}

		return $this->zgwService->handleUpdate(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			false
		);
	}//end update()

	/**
	 * Partial update (PATCH) a resource by UUID.
	 *
	 * For besluitinformatieobjecten, returns 405 Method Not Allowed (brc-004b).
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

		// SB2: Gate patches on besluiten.bijwerken scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'brc', 'besluiten.bijwerken') === false) {
			return $this->scopeDeniedResponse(scope: 'besluiten.bijwerken');
		}

		// Brc-004b: BesluitInformatieObject is immutable — PATCH returns 405.
		if ($resource === 'besluitinformatieobjecten') {
			return new JSONResponse(
				data: ['detail' => 'Method not allowed'],
				statusCode: Http::STATUS_METHOD_NOT_ALLOWED
			);
		}

		return $this->zgwService->handleUpdate(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			true
		);
	}//end patch()

	/**
	 * Delete a resource by UUID.
	 *
	 * For besluiten: cascade deletes related BesluitInformatieObjecten
	 * and their OIOs in DRC (brc-009).
	 * For besluitinformatieobjecten: also deletes the OIO in DRC (brc-005b).
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

		// SB2: Gate destroys on besluiten.verwijderen scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'brc', 'besluiten.verwijderen') === false) {
			return $this->scopeDeniedResponse(scope: 'besluiten.verwijderen');
		}

		// Brc-009: Cascade delete for besluiten.
		if ($resource === 'besluiten') {
			return $this->destroyDecision(uuid: $uuid);
		}

		// Brc-005b: Delete OIO when deleting BIO.
		if ($resource === 'besluitinformatieobjecten') {
			return $this->destroyBesluitInformatieObject(uuid: $uuid);
		}

		return $this->zgwService->handleDestroy(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid
		);
	}//end destroy()

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

		// Brc-009d: Verify the parent resource exists before returning audit trail.
		$objectService = $this->zgwService->getObjectService();
		if ($objectService !== null) {
			$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
			if ($mappingConfig !== null) {
				try {
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
	 * Create a besluit with zaak-besluit sync to ZRC (brc-006).
	 *
	 * After creating the besluit, if it references a zaak, creates a
	 * zaakbesluit relation in the ZRC register.
	 *
	 * @return JSONResponse
	 */
	private function createDecisionWithCaseSync(): JSONResponse {
		$response = $this->zgwService->handleCreate($this->request, self::ZGW_API, 'besluiten');

		// Brc-006: If created successfully and has a zaak, sync to ZRC.
		if ($response->getStatus() === Http::STATUS_CREATED) {
			$data = $response->getData();
			$caseUrl = '';
			if (is_array($data) === true) {
				$caseUrl = $data['case'] ?? '';

				if ($caseUrl !== '') {
					$decisionUrl = $data['url'] ?? '';
					if ($decisionUrl !== '') {
						$this->syncCaseDecisionToZrc(caseUrl: $caseUrl, decisionUrl: $decisionUrl);
					}
				}
			}
		}

		return $response;
	}//end createBesluitWithZaakSync()

	/**
	 * Sync a zaak-besluit relation to ZRC (brc-006).
	 *
	 * Creates a "zaakbesluit" record linking the zaak to the besluit
	 * in the ZRC register.
	 *
	 * @param string $caseUrl The zaak URL
	 * @param string $decisionUrl The besluit URL
	 *
	 * @return void
	 */
	private function syncCaseDecisionToZrc(string $caseUrl, string $decisionUrl): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		// Look for a zaakbesluit mapping/schema.
		$zbSchema = $this->settingsService->getConfigValue(key: 'case_decision_schema');
		if ($zbSchema === '') {
			$this->zgwService->getLogger()->debug(
				'brc-006: case_decision_schema not configured, skipping zaakbesluit sync'
			);
			return;
		}

		$uuidPattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
		$zaakUuid = '';
		if (preg_match($uuidPattern, $caseUrl, $match) === 1) {
			$zaakUuid = $match[1];
		}

		if ($zaakUuid === '') {
			return;
		}

		// Use the zaken mapping config for register.
		$casesConfig = $this->zgwService->loadMappingConfig('zaken', 'zaken');
		$register = $casesConfig['sourceRegister'] ?? '';
		if ($register === '') {
			return;
		}

		try {
			$zbData = [
				'case' => $zaakUuid,
				'decision' => $decisionUrl,
			];

			$objectService->saveObject(
				register: $register,
				schema: $zbSchema,
				object: $zbData
			);

			$this->zgwService->getLogger()->info(
				'brc-006: Created zaakbesluit for zaak=' . $zaakUuid . ' besluit=' . $decisionUrl
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'brc-006: Failed to create zaakbesluit: ' . $e->getMessage()
			);
		}
	}//end syncZaakBesluitToZrc()

	/**
	 * List BesluitInformatieObjecten as a plain array (per ZGW spec).
	 *
	 * Unlike paginated resources, besluitinformatieobjecten returns a flat array.
	 *
	 * @return JSONResponse
	 */
	private function indexBesluitInformatieObjecten(): JSONResponse {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$resource = 'besluitinformatieobjecten';
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
				'BRC list BIO error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => 'Internal server error'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end indexBesluitInformatieObjecten()

	/**
	 * Create a BesluitInformatieObject with cross-register OIO sync (brc-005a).
	 *
	 * After creating the BIO, also creates an ObjectInformatieObject in the
	 * DRC register with objectType=besluit.
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	private function createBesluitInformatieObject(): JSONResponse {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$resource = 'besluitinformatieobjecten';
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			$body = $this->zgwService->getRequestBody($this->request);

			// Run business rules (brc-003a, brc-008a).
			$ruleResult = $this->zgwService->getBusinessRulesService()->validate(
				zgwApi: self::ZGW_API,
				resource: $resource,
				action: 'create',
				body: $body,
				objectService: $objectService,
				mappingConfig: $mappingConfig
			);
			if ($ruleResult['valid'] === false) {
				return new JSONResponse(
					data: $this->zgwService->buildValidationError($ruleResult),
					statusCode: $ruleResult['status']
				);
			}

			$enrichedBody = $ruleResult['enrichedBody'];

			// Create the BIO via standard mapping flow.
			$inboundMapping = $this->zgwService->createInboundMapping(mappingConfig: $mappingConfig);
			$englishData = $this->zgwService->applyInboundMapping(
				body: $enrichedBody,
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

			$object = $objectService->saveObject(
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema'],
				object: $englishData
			);
			$objectData = $this->objectToArray(row: $object);

			$objectUuid = $objectData['id'] ?? ($objectData['@self']['id'] ?? '');

			$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
			$outboundMapping = $this->zgwService->createOutboundMapping(mappingConfig: $mappingConfig);
			$mapped = $this->zgwService->applyOutboundMapping(
				objectData: $objectData,
				mapping: $outboundMapping,
				mappingConfig: $mappingConfig,
				baseUrl: $baseUrl
			);

			// Brc-005a: Create OIO in DRC.
			$decisionUrl = $enrichedBody['decision'] ?? '';
			$ioUrl = $enrichedBody['informatieobject'] ?? '';
			if ($decisionUrl !== '' && $ioUrl !== '') {
				$this->createOioInDrc(decisionUrl: $decisionUrl, ioUrl: $ioUrl);
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
				'BRC create BIO error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end createBesluitInformatieObject()

	/**
	 * Create an ObjectInformatieObject in the DRC register (brc-005a).
	 *
	 * @param string $decisionUrl The besluit URL (full ZGW URL)
	 * @param string $ioUrl The informatieobject URL (full ZGW URL)
	 *
	 * @return void
	 */
	private function createOioInDrc(string $decisionUrl, string $ioUrl): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$oioMappingConfig = $this->zgwService->loadMappingConfig('documenten', 'objectinformatieobjecten');
		if ($oioMappingConfig === null) {
			return;
		}

		try {
			$oioData = [
				'document' => $ioUrl,
				'object' => $decisionUrl,
				'objectType' => 'decision',
			];

			$objectService->saveObject(
				register: $oioMappingConfig['sourceRegister'],
				schema: $oioMappingConfig['sourceSchema'],
				object: $oioData
			);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'brc-005a: Failed to create OIO in DRC: ' . $e->getMessage()
			);
		}
	}//end createOioInDrc()

	/**
	 * Delete ObjectInformatieObjecten from DRC for a given besluit (brc-005b/009).
	 *
	 * @param string $decisionUrl The besluit URL to match OIOs against
	 *
	 * @return void
	 */
	private function deleteOiosForDecision(string $decisionUrl): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$oioMappingConfig = $this->zgwService->loadMappingConfig('documenten', 'objectinformatieobjecten');
		if ($oioMappingConfig === null) {
			return;
		}

		try {
			$query = $objectService->buildSearchQuery(
				requestParams: ['object' => $decisionUrl],
				register: $oioMappingConfig['sourceRegister'],
				schema: $oioMappingConfig['sourceSchema']
			);
			$result = $objectService->searchObjectsPaginated(query: $query);

			foreach (($result['results'] ?? []) as $oio) {
				$oioData = $this->objectToArray(row: $oio);

				$oioUuid = $oioData['id'] ?? ($oioData['@self']['id'] ?? '');
				if ($oioUuid !== '') {
					$objectService->deleteObject(
						uuid: $oioUuid,
						_rbac: false,
						_multitenancy: false
					);
				}
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'BRC: Failed to delete OIOs for besluit: ' . $e->getMessage()
			);
		}//end try
	}//end deleteOiosForBesluit()

	/**
	 * Delete a BesluitInformatieObject and its OIO in DRC (brc-005b).
	 *
	 * @param string $uuid The BIO UUID to delete
	 *
	 * @return JSONResponse
	 */
	private function destroyBesluitInformatieObject(string $uuid): JSONResponse {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$resource = 'besluitinformatieobjecten';
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			// Read the BIO to get besluit URL before deletion.
			$bioObj = $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$bioData = $this->objectToArray(row: $bioObj);

			// Build the besluit URL from the stored decision UUID.
			$decisionUuid = $bioData['decision'] ?? '';
			$decisionUrl = '';
			if ($decisionUuid !== '') {
				$decisionUrl = $this->zgwService->buildBaseUrl(
					$this->request,
					self::ZGW_API,
					'besluiten'
				) . '/' . $decisionUuid;
			}

			$ioUrl = $bioData['document'] ?? '';

			// Delete the BIO.
			$objectService->deleteObject(uuid: $uuid);

			// Brc-005b: Delete matching OIO in DRC.
			if ($decisionUrl !== '' && $ioUrl !== '') {
				$this->deleteOioByDecisionAndIo(decisionUrl: $decisionUrl, ioUrl: $ioUrl);
			}

			$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
			$this->zgwService->publishNotification(
				self::ZGW_API,
				$resource,
				$baseUrl . '/' . $uuid,
				'destroy'
			);

			return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'BRC delete BIO error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => 'Not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}//end try
	}//end destroyBesluitInformatieObject()

	/**
	 * Delete an OIO from DRC matching a specific besluit and informatieobject (brc-005b).
	 *
	 * @param string $decisionUrl The besluit URL
	 * @param string $ioUrl The informatieobject URL
	 *
	 * @return void
	 */
	private function deleteOioByDecisionAndIo(string $decisionUrl, string $ioUrl): void {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$oioMappingConfig = $this->zgwService->loadMappingConfig('documenten', 'objectinformatieobjecten');
		if ($oioMappingConfig === null) {
			return;
		}

		try {
			$query = $objectService->buildSearchQuery(
				requestParams: [
					'object' => $decisionUrl,
					'document' => $ioUrl,
				],
				register: $oioMappingConfig['sourceRegister'],
				schema: $oioMappingConfig['sourceSchema']
			);
			$result = $objectService->searchObjectsPaginated(query: $query);

			foreach (($result['results'] ?? []) as $oio) {
				$oioData = $this->objectToArray(row: $oio);

				$oioUuid = $oioData['id'] ?? ($oioData['@self']['id'] ?? '');
				if ($oioUuid !== '') {
					$objectService->deleteObject(
						uuid: $oioUuid,
						_rbac: false,
						_multitenancy: false
					);
				}
			}
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->warning(
				'brc-005b: Failed to delete OIO: ' . $e->getMessage()
			);
		}//end try
	}//end deleteOioByBesluitAndIo()

	/**
	 * Delete a besluit with cascade to BIOs and OIOs (brc-009).
	 *
	 * @param string $uuid The besluit UUID to delete
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function destroyDecision(string $uuid): JSONResponse {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return $this->zgwService->unavailableResponse();
		}

		$resource = 'besluiten';
		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return $this->zgwService->mappingNotFoundResponse(self::ZGW_API, $resource);
		}

		try {
			// Validate the besluit exists (will throw if not found).
			$existingObj = $objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
			$existingData = $this->objectToArray(row: $existingObj);

			// Run destroy business rules.
			$ruleResult = $this->zgwService->getBusinessRulesService()->validate(
				zgwApi: self::ZGW_API,
				resource: $resource,
				action: 'destroy',
				body: [],
				existingObject: $existingData
			);
			if ($ruleResult['valid'] === false) {
				return new JSONResponse(
					data: $this->zgwService->buildValidationError($ruleResult),
					statusCode: $ruleResult['status']
				);
			}

			// Build the besluit URL for OIO cleanup.
			$decisionUrl = $this->zgwService->buildBaseUrl(
				$this->request,
				self::ZGW_API,
				'besluiten'
			) . '/' . $uuid;

			// Cascade delete of BesluitInformatieObjecten is handled by
			// OpenRegister via onDelete: CASCADE on decisionDocument.decision.
			// Brc-009: Sync-delete OIOs in DRC (cross-component side-effect).
			$this->deleteOiosForDecision(decisionUrl: $decisionUrl);

			// Delete the besluit itself.
			$objectService->deleteObject(uuid: $uuid);

			$baseUrl = $this->zgwService->buildBaseUrl($this->request, self::ZGW_API, $resource);
			$this->zgwService->publishNotification(
				self::ZGW_API,
				$resource,
				$baseUrl . '/' . $uuid,
				'destroy'
			);

			return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
		} catch (\Throwable $e) {
			$this->zgwService->getLogger()->error(
				'BRC delete besluit error: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['detail' => 'Not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}//end try
	}//end destroyBesluit()
}//end class
