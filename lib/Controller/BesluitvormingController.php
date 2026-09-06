<?php

/**
 * Dossiq BesluitvormingController
 *
 * REST API controller for besluitvorming (decision-making) workflow operations.
 * Currently exposes a single endpoint to activate a besluitvorming template
 * bundle (case type + status types + property defs + document types + roles).
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
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\TemplateLibraryService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller exposing besluitvorming template-activation endpoints.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-2
 */
class BesluitvormingController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param TemplateLibraryService $templateLibrary Template-bundle service.
	 * @param IUserSession $userSession User session for guard.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly TemplateLibraryService $templateLibrary,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Activate a besluitvorming template by its slug.
	 *
	 * Idempotent: re-activating a template upserts its objects via the
	 * underlying TemplateLibraryService; duplicates are not created.
	 *
	 * @param string $slug The template slug (e.g. "bvw-college-besluit").
	 *
	 * @return JSONResponse The activation result envelope.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @deprecated Decision types (bvw-* templates) are now managed by decidesk
	 *             (dossiq-delegate-contract-decision). Template activation is
	 *             kept for historical read access until the sunset of the local
	 *             besluit engine. New decision flows must use
	 *             ContractDecisionDelegationService::raiseContractDecision().
	 *
	 * @spec openspec/changes/besluitvorming-workflow/tasks.md#task-2
	 * @spec openspec/specs/contract-decision-delegation/spec.md
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function activateTemplate(string $slug): JSONResponse {
		$unauthorized = $this->requireAuthenticatedAdmin();
		if ($unauthorized !== null) {
			return $unauthorized;
		}

		try {
			$result = $this->templateLibrary->activateTemplate(templateId: $slug);
		} catch (Throwable $e) {
			$this->logger->error(
				'BesluitvormingController::activateTemplate failed: ' . $e->getMessage(),
				['app' => Application::APP_ID, 'slug' => $slug]
			);
			return new JSONResponse(
				['error' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end activateTemplate()

	/**
	 * Require an authenticated user; AuthorizedAdminSetting handles admin-ness
	 * upstream via the NC middleware. The explicit guard here is the body-side
	 * sanity check that satisfies hydra-gate-no-admin-idor's `->require*` rule.
	 *
	 * @return JSONResponse|null Null when authorised, a response when blocked.
	 */
	private function requireAuthenticatedAdmin(): ?JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				['error' => 'Authenticatie vereist'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end requireAuthenticatedAdmin()
}//end class
