<?php

/**
 * Dossiq Tenant Onboarding Controller
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\TenantOnboardingService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Onboarding REST controller.
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md
 */
class TenantOnboardingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param TenantOnboardingService $onboarding Onboarding service.
	 * @param IUserSession $userSession User session.
	 */
	public function __construct(
		IRequest $request,
		private readonly TenantOnboardingService $onboarding,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/saas/tenants/{tenantId}/onboarding/progress
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function progress(string $tenantId): JSONResponse {
		return new JSONResponse(
			[
				'success' => true,
				'progress' => $this->onboarding->getProgress($tenantId),
			]
		);
	}//end progress()

	/**
	 * POST /api/saas/tenants/{tenantId}/onboarding/{step}/complete
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $step Step name.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function complete(string $tenantId, string $step): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$task = $this->onboarding->markStepComplete(
				tenantId: $tenantId,
				step: $step,
				completedBy: $user->getUID()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		if ($task === null) {
			return new JSONResponse(['success' => false, 'error' => 'Step not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['success' => true, 'task' => $task]);
	}//end complete()

	/**
	 * POST /api/saas/tenants/{tenantId}/onboarding/activate
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function activate(string $tenantId): JSONResponse {
		$result = $this->onboarding->activate($tenantId);
		$code = Http::STATUS_CONFLICT;
		if ($result['activated'] === true) {
			$code = Http::STATUS_OK;
		}

		return new JSONResponse(['success' => $result['activated'], 'result' => $result], $code);
	}//end activate()

	/**
	 * POST /api/saas/tenants/{tenantId}/onboarding/initialise
	 *
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-07-onboarding-workflow/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function initialise(string $tenantId): JSONResponse {
		$rows = $this->onboarding->createOnboarding($tenantId);
		return new JSONResponse(['success' => true, 'tasks' => $rows]);
	}//end initialise()
}//end class
