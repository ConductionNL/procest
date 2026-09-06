<?php

/**
 * Dossiq NRC (Notificaties) Controller
 *
 * Controller for serving ZGW Notificaties API endpoints (kanaal, abonnement,
 * notificaties). Delegates standard CRUD to ZgwService and provides a
 * simple notificatie acceptance endpoint.
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
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * NRC (Notificaties) API Controller
 *
 * Handles ZGW Notificaties register resources: kanaal and abonnement,
 * plus a notificatie acceptance endpoint.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class NrcController extends ZgwController {
	/**
	 * The ZGW API identifier for the Notificaties register.
	 *
	 * @var string
	 */
	private const ZGW_API = 'notificaties';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The incoming request.
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
	 * @param string $resource The ZGW resource name (e.g. kanaal, abonnement).
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

		return $this->zgwService->handleIndex($this->request, self::ZGW_API, $resource);
	}//end index()

	/**
	 * Create a new resource of the given type.
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

		// SB2: Gate creates on notificaties.publiceren scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'nrc', 'notificaties.publiceren') === false) {
			return $this->scopeDeniedResponse(scope: 'notificaties.publiceren');
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

		// SB2: Gate updates on notificaties.publiceren scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'nrc', 'notificaties.publiceren') === false) {
			return $this->scopeDeniedResponse(scope: 'notificaties.publiceren');
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

		// SB2: Gate patches on notificaties.publiceren scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'nrc', 'notificaties.publiceren') === false) {
			return $this->scopeDeniedResponse(scope: 'notificaties.publiceren');
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

		// SB2: Gate destroys on notificaties.publiceren scope (canonical format).
		if ($this->zgwService->consumerHasScope($this->request, 'nrc', 'notificaties.publiceren') === false) {
			return $this->scopeDeniedResponse(scope: 'notificaties.publiceren');
		}

		return $this->zgwService->handleDestroy(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid
		);
	}//end destroy()

	/**
	 * Accept a notificatie (echo back the body with 201).
	 *
	 * This endpoint receives incoming ZGW notifications and acknowledges them.
	 *
	 * Rate-limit rationale: tight — a notification fans out to every
	 * subscribed channel, so one cheap call can generate a lot of downstream
	 * delivery work.
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
	public function notificatieCreate(): JSONResponse {
		$authError = $this->zgwService->validateJwtAuth($this->request);
		if ($authError !== null) {
			return $authError;
		}

		$body = $this->request->getParams();
		unset($body['_route']);

		return new JSONResponse(data: $body, statusCode: Http::STATUS_CREATED);
	}//end notificatieCreate()

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
}//end class
