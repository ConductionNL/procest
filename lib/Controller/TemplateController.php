<?php

/**
 * Dossiq Template Controller
 *
 * REST API for managing zaaktype templates.
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
 * @spec openspec/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\TemplateLibraryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for zaaktype template management.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class TemplateController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param TemplateLibraryService $templateService The template service
	 * @param IUserSession $userSession The user session
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TemplateLibraryService $templateService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List all available templates.
	 *
	 * @return JSONResponse List of templates
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$templates = $this->templateService->listTemplates();
		return new JSONResponse(['results' => $templates]);
	}//end index()

	/**
	 * Get a single template by ID.
	 *
	 * @param string $id The template ID
	 *
	 * @return JSONResponse The template data or 404
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Ships with the app; not user data. loadTemplate()
	 * globs the fixed, app-owned directory lib/Settings/templates/*.json and
	 * returns the file whose `id` FIELD equals $id — the id is never used to
	 * build a path, so there is no traversal, and the content is the same
	 * read-only zaaktype library for every tenant and every user. index() on
	 * this same controller already returns that whole library to any
	 * authenticated caller, so a per-object guard here would protect nothing
	 * that is not already listable. No OpenRegister object is touched.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function show(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$template = $this->templateService->loadTemplate($id);
		if ($template === null) {
			return new JSONResponse(['error' => 'Template not found'], 404);
		}

		return new JSONResponse($template);
	}//end show()

	/**
	 * Activate a template (create all objects from it).
	 *
	 * @param string $id The template ID
	 *
	 * @return JSONResponse Result with created object IDs
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function activate(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->templateService->activateTemplate($id);
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			return new JSONResponse(
				['error' => $e->getMessage()],
				400,
			);
		}
	}//end activate()
}//end class
