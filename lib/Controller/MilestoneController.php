<?php

/**
 * Dossiq Milestone Controller
 *
 * REST API for milestone progress tracking.
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
 * @spec openspec/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\MilestoneService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for milestone progress tracking.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class MilestoneController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param MilestoneService $milestoneService The milestone service
	 * @param IUserSession $userSession The user session
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed)
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MilestoneService $milestoneService,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Get milestone progress for a case, resolving its type server-side.
	 *
	 * The two-segment form requires the caller to already know the case's type.
	 * A manifest tile builds its URL from the loaded record, and on first render
	 * that record is not there, so the type collapsed to an empty path segment
	 * and the request 404'd before the corrected one followed. The server knows
	 * the case's type; asking the client for it was redundant.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse Milestone progress data
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function caseProgress(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($this->milestoneService->getCaseProgressForCase($caseId));
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end caseProgress()

	/**
	 * Get milestone progress for a case whose type the caller already holds.
	 *
	 * @param string $caseId The case UUID
	 * @param string $caseTypeId The case type UUID
	 *
	 * @return JSONResponse Milestone progress data
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function progress(string $caseId, string $caseTypeId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$progress = $this->milestoneService->getCaseProgress($caseId, $caseTypeId);
			return new JSONResponse($progress);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end progress()

	/**
	 * Mark a milestone as reached.
	 *
	 * @param string $caseId The case UUID
	 * @param string $milestoneId The milestone definition UUID
	 *
	 * @return JSONResponse The created milestone record
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function mark(string $caseId, string $milestoneId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		try {
			$result = $this->milestoneService->markMilestone(
				$caseId,
				$milestoneId,
				$userId,
				'manual',
			);
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}//end mark()

	/**
	 * Reverse a milestone.
	 *
	 * @param string $caseId The case UUID
	 * @param string $milestoneId The milestone definition UUID
	 *
	 * @return JSONResponse Success status
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function reverse(string $caseId, string $milestoneId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$reason = $this->request->getParam('reason', '');
		if (trim($reason) === '') {
			return new JSONResponse(
				['error' => 'Reason is required for milestone reversal'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$userId = $user->getUID();

		try {
			$success = $this->milestoneService->reverseMilestone(
				$caseId,
				$milestoneId,
				$userId,
				$reason,
			);
			return new JSONResponse(['success' => $success]);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}//end reverse()
}//end class
