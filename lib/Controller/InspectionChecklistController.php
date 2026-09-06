<?php

/**
 * Dossiq Inspection Checklist Controller
 *
 * REST endpoints for admin CRUD on inspection checklists and per-case
 * inspection result submission.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\InspectionChecklistService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Controller for inspection checklist CRUD and inspection result submission.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/vth-module/tasks.md#task-4
 */
class InspectionChecklistController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param InspectionChecklistService $checklistService Checklist service
	 * @param IUserSession $userSession User session
	 * @param IGroupManager $groupManager Group manager
	 * @param LoggerInterface $logger Logger
	 * @param CaseAccessGuard $caseAccessGuard Per-case read-access guard
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly InspectionChecklistService $checklistService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List all inspection checklists.
	 *
	 * @return JSONResponse List of inspectionChecklist objects
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function index(): JSONResponse {
		$caseTypeRef = $this->request->getParam(key: 'caseTypeRef');
		$checklists = $this->checklistService->listChecklists(caseTypeRef: $caseTypeRef);
		return new JSONResponse(data: $checklists, statusCode: Http::STATUS_OK);
	}//end index()

	/**
	 * Create a new inspection checklist.
	 *
	 * @return JSONResponse Created inspectionChecklist object
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function create(): JSONResponse {
		$data = $this->request->getParams();
		// Identity stripped on a CREATE too. `saveObject()` resolves its target
		// from the payload (`@self.id` first, then `id`), so a create carrying
		// either would replace an existing checklist rather than add one.
		unset($data['_route'], $data['id'], $data['uuid'], $data['@self']);

		try {
			$result = $this->checklistService->createChecklist(data: $data);
			return new JSONResponse(data: $result, statusCode: Http::STATUS_CREATED);
		} catch (Throwable $e) {
			$this->logger->error(
				'Failed to create inspection checklist: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => 'Failed to create checklist: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end create()

	/**
	 * Update an existing inspection checklist.
	 *
	 * @param string $id UUID of the checklist to update
	 *
	 * @return JSONResponse Updated inspectionChecklist object
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function update(string $id): JSONResponse {
		$data = $this->request->getParams();
		unset($data['_route'], $data['id'], $data['uuid'], $data['@self']);

		try {
			$result = $this->checklistService->updateChecklist(id: $id, data: $data);
			return new JSONResponse(data: $result, statusCode: Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->error(
				'Failed to update inspection checklist ' . $id . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => 'Failed to update checklist: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end update()

	/**
	 * Delete an inspection checklist.
	 *
	 * @param string $id UUID of the checklist to delete
	 *
	 * @return JSONResponse Success or error
	 *
	 * @AuthorizedAdminSetting(settings=OCA\Dossiq\Settings\AdminSettings::class)
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function destroy(string $id): JSONResponse {
		$success = $this->checklistService->deleteChecklist(id: $id);
		if ($success === true) {
			return new JSONResponse(data: ['message' => 'Deleted'], statusCode: Http::STATUS_OK);
		}

		return new JSONResponse(
			['message' => 'Failed to delete checklist'],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end destroy()

	/**
	 * Submit an inspection result for a case.
	 *
	 * @param string $id UUID of the case
	 *
	 * @return JSONResponse Saved inspectionResult object
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	#[NoAdminRequired]
	public function submitResult(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Not authenticated');
		}

		$params = $this->request->getParams();
		$checklistId = $params['checklistId'] ?? '';
		if ($checklistId === '') {
			return new JSONResponse(
				['message' => 'checklistId is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// Per-object authorization: only the assigned inspector or admin may submit.
		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			$assignedUid = $params['assignedInspector'] ?? '';
			if ($assignedUid !== '' && $assignedUid !== $user->getUID()) {
				throw new OCSForbiddenException('Not authorized to submit this inspection result');
			}
		}

		try {
			$result = $this->checklistService->submitResult(
				caseId: $id,
				checklistId: $checklistId,
				resultData: $params,
				completedBy: $user->getUID()
			);
			return new JSONResponse(data: $result, statusCode: Http::STATUS_CREATED);
		} catch (RuntimeException $e) {
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Failed to submit inspection result for case ' . $id . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['message' => 'Submission failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end submitResult()

	/**
	 * Get all inspection results for a case.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseReadAccess()`.
	 *
	 * `GET /api/vth/cases/{id}/inspection-results` is a per-case sub-resource
	 * and `$id` is the CASE uuid, so the case-membership predicate applies
	 * directly. Inspection results carry enforcement findings against a named
	 * address, so this is a per-case read of supervision data and not a
	 * catalogue lookup like the checklist DEFINITIONS above (which are admin
	 * CRUD and guarded as such).
	 *
	 * @param string $id UUID of the case
	 *
	 * @return JSONResponse List of inspectionResult objects
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	#[NoAdminRequired]
	public function getResults(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Not authenticated');
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return new JSONResponse(data: ['error' => 'Not authorized'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$results = $this->checklistService->getResultsForCase(caseId: $id);
		return new JSONResponse(data: $results, statusCode: Http::STATUS_OK);
	}//end getResults()
}//end class
