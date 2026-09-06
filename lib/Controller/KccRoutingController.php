<?php

/**
 * Dossiq KCC Routing Controller
 *
 * REST endpoints for KCC routing-rule management and routing evaluation.
 * Rule CRUD is restricted to admins/team-leads (ADR-005); the evaluate
 * endpoint is available to any authenticated agent.
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
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Kcc\RoutingRuleService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller exposing KCC routing-rule and routing-evaluation endpoints.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
 */
class KccRoutingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param RoutingRuleService $routingRuleService The routing-rule service.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager The group manager.
	 */
	public function __construct(
		IRequest $request,
		private RoutingRuleService $routingRuleService,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List routing rules.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
	 */
	public function index(): JSONResponse {
		$unauthorized = $this->requireAuthenticated();
		if ($unauthorized !== null) {
			return $unauthorized;
		}

		try {
			$rules = $this->routingRuleService->listRules();
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['results' => $rules]);
	}//end index()

	/**
	 * Create a routing rule (admin / team-lead only).
	 *
	 * The body calls `requireAdmin()`, which makes this endpoint admin-only
	 * at runtime. NC's SecurityMiddleware already enforces admin-only as the
	 * default when @NoAdminRequired is absent (see hydra reference note
	 * `nc-security-defaults`), so we drop the annotation. Gate-9
	 * (semantic-auth) flagged the previous combination as
	 * `no-admin-required-annotation-with-admin-body`.
	 *
	 * @return JSONResponse
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function create(): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden !== null) {
			return $forbidden;
		}

		try {
			$rule = $this->routingRuleService->createRule(data: $this->bodyParams());
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($rule, Http::STATUS_CREATED);
	}//end create()

	/**
	 * Update a routing rule (admin / team-lead only).
	 *
	 * Admin-only via the body `requireAdmin()` check + NC's default
	 * SecurityMiddleware behaviour (no @NoAdminRequired).
	 *
	 * @param string $id The rule id.
	 *
	 * @return JSONResponse
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function update(string $id): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden !== null) {
			return $forbidden;
		}

		try {
			$rule = $this->routingRuleService->updateRule(id: $id, data: $this->bodyParams());
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($rule);
	}//end update()

	/**
	 * Delete a routing rule (admin / team-lead only).
	 *
	 * Admin-only via the body `requireAdmin()` check + NC's default
	 * SecurityMiddleware behaviour (no @NoAdminRequired).
	 *
	 * @param string $id The rule id.
	 *
	 * @return JSONResponse
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function destroy(string $id): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden !== null) {
			return $forbidden;
		}

		try {
			$this->routingRuleService->deleteRule(id: $id);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['success' => true]);
	}//end destroy()

	/**
	 * Evaluate routing for a contact moment and return suggested agents.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
	 */
	public function evaluate(): JSONResponse {
		$unauthorized = $this->requireAuthenticated();
		if ($unauthorized !== null) {
			return $unauthorized;
		}

		$contactMoment = $this->bodyParams();

		try {
			$result = $this->routingRuleService->route(contactMoment: $contactMoment);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}//end evaluate()

	/**
	 * Require an authenticated user; return a response otherwise.
	 *
	 * Read endpoints (index, evaluate) accept any authenticated user — this
	 * guard ensures unauthenticated callers cannot reach the routing service.
	 *
	 * @return JSONResponse|null Null when authorised, a response when blocked.
	 */
	private function requireAuthenticated(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->unauthorized();
		}

		return null;
	}//end requireAuthenticated()

	/**
	 * Require an authenticated admin / team-lead; return a response otherwise.
	 *
	 * @return JSONResponse|null Null when authorised, a response when blocked.
	 */
	private function requireAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthorized();
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => 'Admin-rechten vereist'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end requireAdmin()

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
