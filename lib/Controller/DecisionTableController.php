<?php

/**
 * Dossiq Decision Table Controller
 *
 * REST endpoints for DMN decision-table CRUD (admin/coordinator only,
 * mirroring `KccRoutingController`) and standalone decision evaluation
 * (any authenticated user).
 *
 * DEPRECATED (dossiq-decisions-to-decidiq): decision tables move to
 * OpenRegister's flow-decision-tables, which is being built in parallel.
 * These endpoints keep working until that change lands and are retired then.
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
 * @spec openspec/specs/dmn-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionEvaluationException;
use OCA\Dossiq\Service\Dmn\DecisionTableService;
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
 * Controller exposing decision-table CRUD and evaluation endpoints.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/dmn-decision-tables/spec.md
 */
class DecisionTableController extends Controller {

	/**
	 * HTTP status per {@see DecisionEvaluationException} error code.
	 *
	 * @var array<string, int>
	 */
	private const ERROR_STATUS = [
		'unknown_input' => Http::STATUS_BAD_REQUEST,
		'missing_input' => Http::STATUS_BAD_REQUEST,
		'type_mismatch' => Http::STATUS_BAD_REQUEST,
		'invalid_expression' => Http::STATUS_BAD_REQUEST,
		'hit_policy_not_implemented' => Http::STATUS_BAD_REQUEST,
		'no_rule_matched' => Http::STATUS_UNPROCESSABLE_ENTITY,
		'hit_policy_violation' => Http::STATUS_UNPROCESSABLE_ENTITY,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param DecisionTableService $tableService The decision-table storage service.
	 * @param DecisionTableEvaluator $engine The pure evaluation engine.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager The group manager.
	 */
	public function __construct(
		IRequest $request,
		private DecisionTableService $tableService,
		private DecisionTableEvaluator $engine,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List decision tables.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	public function index(): JSONResponse {
		$unauthorized = $this->requireAuthenticated();
		if ($unauthorized !== null) {
			return $unauthorized;
		}

		try {
			$tables = $this->tableService->listTables();
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['results' => $tables]);
	}//end index()

	/**
	 * Create a decision table (admin only).
	 *
	 * @return JSONResponse
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function create(): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden !== null) {
			return $forbidden;
		}

		try {
			$table = $this->tableService->createTable(data: $this->bodyParams());
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($table, Http::STATUS_CREATED);
	}//end create()

	/**
	 * Update a decision table (admin only).
	 *
	 * @param string $id The table id.
	 *
	 * @return JSONResponse
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function update(string $id): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden !== null) {
			return $forbidden;
		}

		try {
			$table = $this->tableService->updateTable(id: $id, data: $this->bodyParams());
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($table);
	}//end update()

	/**
	 * Delete a decision table (admin only).
	 *
	 * @param string $id The table id.
	 *
	 * @return JSONResponse
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function destroy(string $id): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden !== null) {
			return $forbidden;
		}

		try {
			$this->tableService->deleteTable(id: $id);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['success' => true]);
	}//end destroy()

	/**
	 * Evaluate a decision table against the posted inputs. Open to any
	 * authenticated user (like `KccRoutingController::evaluate()`) so the
	 * capability is directly testable/consumable outside a case lifecycle.
	 *
	 * @param string $id The decision table id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	public function evaluate(string $id): JSONResponse {
		$unauthorized = $this->requireAuthenticated();
		if ($unauthorized !== null) {
			return $unauthorized;
		}

		try {
			$table = $this->tableService->getTable(id: $id);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		if ($table === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$result = $this->engine->evaluate(decisionTable: $table, inputs: $this->bodyParams());
		} catch (DecisionEvaluationException $e) {
			$status = self::ERROR_STATUS[$e->getErrorCode()] ?? Http::STATUS_BAD_REQUEST;
			return new JSONResponse(['error' => $e->getErrorCode(), 'details' => $e->getDetails()], $status);
		}

		return new JSONResponse($result);
	}//end evaluate()

	/**
	 * Require an authenticated user; return a response otherwise.
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
	 * Require an authenticated admin; return a response otherwise.
	 *
	 * @return JSONResponse|null Null when authorised, a response when blocked.
	 */
	private function requireAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthorized();
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => 'Admin rights required'], Http::STATUS_FORBIDDEN);
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
		return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
	}//end unauthorized()
}//end class
