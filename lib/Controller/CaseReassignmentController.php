<?php

/**
 * Dossiq CaseReassignmentController.
 *
 * REST API for coordinator bulk reassignment of cases from one handler to
 * another, with a dry-run preview ahead of execution.
 *
 * Split out of SubstitutionController along the resource seam: these endpoints
 * address `/api/reassignments`, not a substitution, and are the only
 * coordinator-exclusive operations in that surface — a substitution is
 * something a handler arranges for themselves, whereas a bulk reassignment is
 * something a coordinator does to someone else's workload. Both endpoints are
 * #[NoAdminRequired] with an explicit coordinator guard that fails closed
 * (ADR-005 Rule 3).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseReassignmentService;
use OCA\Dossiq\Service\SelectionReassignmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for coordinator-only bulk case reassignment.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class CaseReassignmentController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param CaseReassignmentService      $reassignmentService Bulk reassignment.
	 * @param SelectionReassignmentService $selectionService    Reassignment of a hand-picked selection.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager Group manager (admin checks).
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseReassignmentService $reassignmentService,
		private readonly SelectionReassignmentService $selectionService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Preview a bulk reassignment. Coordinator-only.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	#[NoAdminRequired]
	public function reassignPreview(): JSONResponse {
		$guard = $this->requireCoordinator();
		if ($guard !== null) {
			return $guard;
		}

		try {
			$preview = $this->reassignmentService->preview(
				fromUser: (string)$this->request->getParam('fromUser', ''),
				filter: $this->reassignmentFilter()
			);
			return new JSONResponse($preview);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Reassignment preview failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end reassignPreview()

	/**
	 * Execute a bulk reassignment. Coordinator-only.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	#[NoAdminRequired]
	public function reassignExecute(): JSONResponse {
		$guard = $this->requireCoordinator();
		if ($guard !== null) {
			return $guard;
		}

		$user = $this->userSession->getUser();
		$actorId = '';
		if ($user !== null) {
			$actorId = $user->getUID();
		}

		try {
			$result = $this->reassignmentService->execute(
				fromUser: (string)$this->request->getParam('fromUser', ''),
				toUser: (string)$this->request->getParam('toUser', ''),
				filter: $this->reassignmentFilter(),
				actorId: $actorId
			);
			return new JSONResponse($result);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Reassignment execute failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end reassignExecute()

	/**
	 * Reassign an explicitly selected set of cases.
	 *
	 * This is what the Cases page's bulk action calls. It differs from
	 * {@see self::reassignExecute()} in the question it answers: that one moves
	 * everything belonging to one handler, this one moves the rows a user
	 * ticked, whose assignees may differ.
	 *
	 * @return JSONResponse The per-case outcome.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md
	 */
	public function reassignSelection(): JSONResponse {
		$guard = $this->requireCoordinator();
		if ($guard !== null) {
			return $guard;
		}

		$user = $this->userSession->getUser();
		$actorId = '';
		if ($user !== null) {
			$actorId = $user->getUID();
		}

		$caseIds = $this->request->getParam('caseIds', []);
		if (is_array($caseIds) === false) {
			return new JSONResponse(['error' => 'caseIds must be an array'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->selectionService->executeForCases(
				caseIds: $caseIds,
				toUser: (string)$this->request->getParam('toUser', ''),
				actorId: $actorId
			);

			return new JSONResponse($result);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Reassignment of a selection failed', ['error' => $e->getMessage()]);

			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end reassignSelection()

	/**
	 * Build the optional reassignment filter from request params.
	 *
	 * @return array<string, mixed>|null
	 */
	private function reassignmentFilter(): ?array {
		$caseType = (string)$this->request->getParam('caseType', '');
		if ($caseType === '') {
			return null;
		}

		return ['caseType' => $caseType];
	}//end reassignmentFilter()

	/**
	 * Require a coordinator; returns a JSONResponse to short-circuit on failure.
	 *
	 * @return JSONResponse|null Null when the caller is a coordinator.
	 */
	private function requireCoordinator(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authorised'], Http::STATUS_FORBIDDEN);
		}

		$userId = $user->getUID();
		if ($userId === '' || $this->groupManager->isAdmin($userId) === false) {
			return new JSONResponse(
				['error' => 'This action requires the coordinator role'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end requireCoordinator()
}//end class
