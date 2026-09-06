<?php

/**
 * Dossiq KCC Contact Controller
 *
 * REST endpoints for KCC contact moments and callback requests. All endpoints
 * require an authenticated user; reads/writes are scoped to the calling agent
 * by the underlying services (IDOR-safe), with team-leads/admins able to see
 * across agents.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-16
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Kcc\CallbackService;
use OCA\Dossiq\Service\Kcc\ContactMomentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller exposing KCC contact-moment and callback endpoints.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-16
 */
class KccContactController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ContactMomentService $contactMomentService The contact-moment service.
	 * @param CallbackService $callbackService The callback service.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager The group manager.
	 */
	public function __construct(
		IRequest $request,
		private ContactMomentService $contactMomentService,
		private CallbackService $callbackService,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List contact moments (scoped to the agent unless privileged).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-16
	 */
	public function index(): JSONResponse {
		$agentId = $this->requireAgentId();
		if ($agentId === null) {
			return $this->unauthorized();
		}

		$filters = [
			'channel' => $this->request->getParam('channel', ''),
			'outcome' => $this->request->getParam('outcome', ''),
			'assignedTeam' => $this->request->getParam('assignedTeam', ''),
		];

		try {
			$moments = $this->contactMomentService->list(
				filters: $filters,
				agentId: $agentId,
				isPrivileged: $this->isPrivileged(userId: $agentId),
			);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['results' => $moments]);
	}//end index()

	/**
	 * Create a contact moment.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-16
	 */
	public function create(): JSONResponse {
		$agentId = $this->requireAgentId();
		if ($agentId === null) {
			return $this->unauthorized();
		}

		try {
			$moment = $this->contactMomentService->create(data: $this->bodyParams(), agentId: $agentId);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($moment, Http::STATUS_CREATED);
	}//end create()

	/**
	 * Show a single contact moment.
	 *
	 * @param string $id The contact moment id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-16
	 */
	public function show(string $id): JSONResponse {
		$agentId = $this->requireAgentId();
		if ($agentId === null) {
			return $this->unauthorized();
		}

		try {
			$moment = $this->contactMomentService->get(
				id: $id,
				agentId: $agentId,
				isPrivileged: $this->isPrivileged(userId: $agentId),
			);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($moment);
	}//end show()

	/**
	 * Update a contact moment.
	 *
	 * @param string $id The contact moment id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-16
	 */
	public function update(string $id): JSONResponse {
		$agentId = $this->requireAgentId();
		if ($agentId === null) {
			return $this->unauthorized();
		}

		try {
			$moment = $this->contactMomentService->update(
				id: $id,
				data: $this->bodyParams(),
				agentId: $agentId,
				isPrivileged: $this->isPrivileged(userId: $agentId),
			);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($moment);
	}//end update()

	/**
	 * List related contact moments for the same customer.
	 *
	 * @param string $id The contact moment id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-16
	 */
	public function related(string $id): JSONResponse {
		$agentId = $this->requireAgentId();
		if ($agentId === null) {
			return $this->unauthorized();
		}

		try {
			$related = $this->contactMomentService->related(
				id: $id,
				agentId: $agentId,
				isPrivileged: $this->isPrivileged(userId: $agentId),
			);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['results' => $related]);
	}//end related()

	/**
	 * Schedule a callback.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-16
	 */
	public function scheduleCallback(): JSONResponse {
		$agentId = $this->requireAgentId();
		if ($agentId === null) {
			return $this->unauthorized();
		}

		try {
			$callback = $this->callbackService->schedule(data: $this->bodyParams(), agentId: $agentId);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($callback, Http::STATUS_CREATED);
	}//end scheduleCallback()

	/**
	 * List callback requests (scoped to the agent unless privileged).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-16
	 */
	public function indexCallbacks(): JSONResponse {
		$agentId = $this->requireAgentId();
		if ($agentId === null) {
			return $this->unauthorized();
		}

		$filters = ['status' => $this->request->getParam('status', '')];

		try {
			$callbacks = $this->callbackService->list(
				filters: $filters,
				agentId: $agentId,
				isPrivileged: $this->isPrivileged(userId: $agentId),
			);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['results' => $callbacks]);
	}//end indexCallbacks()

	/**
	 * Cancel a callback request.
	 *
	 * @param string $id The callback id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-16
	 */
	public function cancelCallback(string $id): JSONResponse {
		$agentId = $this->requireAgentId();
		if ($agentId === null) {
			return $this->unauthorized();
		}

		try {
			$callback = $this->callbackService->cancel(
				id: $id,
				agentId: $agentId,
				isPrivileged: $this->isPrivileged(userId: $agentId),
			);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($callback);
	}//end cancelCallback()

	/**
	 * Resolve the authenticated agent's user id, or null when unauthenticated.
	 *
	 * @return string|null The user id.
	 */
	private function requireAgentId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end requireAgentId()

	/**
	 * Determine whether the user is a KCC team-lead / admin (cross-agent view).
	 *
	 * @param string $userId The user id.
	 *
	 * @return bool True when privileged.
	 */
	private function isPrivileged(string $userId): bool {
		return $this->groupManager->isAdmin($userId);
	}//end isPrivileged()

	/**
	 * Read the JSON / form body parameters, excluding routing params.
	 *
	 * @return array<string, mixed> The body parameters.
	 */
	private function bodyParams(): array {
		$params = $this->request->getParams();
		unset($params['id'], $params['uuid'], $params['@self'], $params['_route']);
		return $params;
	}//end bodyParams()

	/**
	 * Build a 401 Unauthorized response.
	 *
	 * @return JSONResponse
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(['error' => 'Authenticatie vereist'], Http::STATUS_UNAUTHORIZED);
	}//end unauthorized()
}//end class
