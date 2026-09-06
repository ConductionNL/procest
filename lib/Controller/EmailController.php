<?php

/**
 * Dossiq Email Controller
 *
 * REST API for case email integration.
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseEmailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for case email operations.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class EmailController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param CaseEmailService $emailService The email service
	 * @param IUserSession $userSession The user session
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseEmailService $emailService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Send an email from case context.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse Send result
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function send(string $caseId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$data = $this->readJsonBody();

			$result = $this->emailService->sendEmail(
				$caseId,
				$data['to'] ?? '',
				$data['subject'] ?? '',
				$data['body'] ?? '',
				$data['attachments'] ?? [],
			);
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			// M4: Never expose internal error details (SMTP host/credentials) to callers.
			// 'email_send_failed' is the sentinel thrown by CaseEmailService when the
			// transport layer itself fails; surface a safe generic message for that case.
			if ($e->getMessage() === 'email_send_failed') {
				return new JSONResponse(['error' => 'email_send_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}//end try
	}//end send()

	/**
	 * Send email using a template.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse Send result
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function sendFromTemplate(string $caseId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$data = $this->readJsonBody();

			$result = $this->emailService->sendFromTemplate(
				$caseId,
				$data['templateId'] ?? '',
				$data['to'] ?? '',
			);
			return new JSONResponse($result);
		} catch (\RuntimeException $e) {
			// M4: Surface generic error for transport failures.
			if ($e->getMessage() === 'email_send_failed') {
				return new JSONResponse(['error' => 'email_send_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}//end try
	}//end sendFromTemplate()

	/**
	 * Preview a template with case data.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse Resolved template preview
	 *
	 * @NoAdminRequired
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $caseId is a URL segment of the
	 * route `/api/email/{caseId}/preview` and is bound positionally by the dispatcher.
	 * It is not read yet because the case-data lookup is still a stub (see the
	 * `// Would load from case.` marker below); the parameter cannot be dropped
	 * without changing the route.
	 *
	 * @no-admin-idor-exempt Reads NO case. The method renders the caller's OWN
	 * posted body against an EMPTY variable map — `$caseData = []` two lines
	 * down, and `$caseId` is never passed to anything — so the response is a
	 * pure function of the request payload and cannot disclose another user's
	 * data for any value of the id. The docblock above already records that the
	 * case-data lookup is still a stub. THIS EXEMPTION EXPIRES THE MOMENT THAT
	 * STUB IS FILLED IN: loading the case here makes it a per-object read and
	 * it must then take the same CaseAccessGuard check as its siblings.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function preview(string $caseId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$data = $this->readJsonBody();
		$template = $data['body'] ?? '';
		$caseData = [];
		// Would load from case.
		$resolved = $this->emailService->resolveVariables($template, $caseData);
		$unresolved = $this->emailService->findUnresolvedVariables($template, $caseData);

		return new JSONResponse(
			[
				'resolved' => $resolved,
				'unresolved' => $unresolved,
			]
		);
	}//end preview()

	/**
	 * Get email templates for a case type.
	 *
	 * @param string $caseTypeId The case type UUID
	 *
	 * @return JSONResponse List of templates
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function templates(string $caseTypeId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$templates = $this->emailService->getTemplatesForCaseType($caseTypeId);
		return new JSONResponse(['results' => $templates]);
	}//end templates()

	/**
	 * Read and decode the JSON request body.
	 *
	 * OCP\IRequest::getContent() is protected on the concrete OC request, so
	 * the raw payload is read from php://input instead.
	 *
	 * @return array<string, mixed> The decoded body, or an empty array when absent/invalid
	 */
	private function readJsonBody(): array {
		$content = (string)file_get_contents('php://input');
		if ($content === '') {
			return [];
		}

		$decoded = json_decode($content, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end readJsonBody()
}//end class
