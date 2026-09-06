<?php

/**
 * Dossiq Advice Controller.
 *
 * Workflow endpoints for advice requests (adviesAanvraag). CRUD is delegated
 * to the manifest renderer (OpenRegister); this controller exposes only the
 * domain operations that need server-side side-effects: status transitions
 * (which trigger notifications) and manual reminder dispatch.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/advice-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\AdviceService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for advice request workflow operations.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class AdviceController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The HTTP request
	 * @param AdviceService $adviceService The advice service
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed)
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AdviceService $adviceService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Transition the status of an advice request.
	 *
	 * Drives workflow side-effects (notifications to adviseur / requester)
	 * that the manifest CRUD path cannot perform. The CRUD itself (writing
	 * the object) is still performed by this method via the service so the
	 * notification + persistence remain transactional from the caller's
	 * point of view.
	 *
	 * Accepted payloads:
	 *   { "to": "requested" }                  — fire "advies_aangevraagd"
	 *   { "to": "received", "adviceDocument": "<fileId>" } — mark received
	 *   { "to": "expired" }                     — mark expired
	 *
	 * @param string $id The advice UUID
	 *
	 * @return JSONResponse Updated record
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function transitionStatus(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$data = $this->readJsonBody();
		$to = (string)($data['to'] ?? '');

		if ($to === '') {
			return new JSONResponse(
				['error' => 'to status is required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			$advice = $this->adviceService->transitionStatus($id, $to, $data);
			return new JSONResponse($advice);
		} catch (\RuntimeException $e) {
			return new JSONResponse(
				['error' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST,
			);
		} catch (\Throwable $e) {
			$this->logger->error('Dossiq: advice transition failed: ' . $e->getMessage());
			return new JSONResponse(
				['error' => 'Could not transition advice request'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}//end transitionStatus()

	/**
	 * Dispatch a manual reminder notification to the adviseur.
	 *
	 * @param string $id The advice UUID
	 *
	 * @return JSONResponse Success response
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function dispatchReminder(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			// Authorized seam. The unguarded `dispatchReminder()` behind it is
			// the cron's, and is not reachable from HTTP.
			$this->adviceService->dispatchReminderAsUser($id);
			return new JSONResponse(['status' => 'reminded']);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			$this->logger->error('Dossiq: advice dispatchReminder failed: ' . $e->getMessage());
			return new JSONResponse(
				['error' => 'Could not send reminder'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}//end dispatchReminder()

	/**
	 * Create an advice request for a specific case.
	 *
	 * @param string $id UUID of the case
	 *
	 * @return JSONResponse Created advice request or error
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-6
	 */
	#[NoAdminRequired]
	public function createForCase(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Not authenticated');
		}

		$data = $this->readJsonBody();
		$data['caseRef'] = $id;
		$data['requestedBy'] = $user->getUID();
		$data['status'] = 'open';

		try {
			$advice = $this->adviceService->requestAdvice(caseId: $id, data: $data, requestedBy: $user->getUID());
			return new JSONResponse(data: $advice, statusCode: Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Failed to create advice request for case ' . $id . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new JSONResponse(
				['error' => 'Could not create advice request: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}
	}//end createForCase()

	/**
	 * Get all advice requests for a specific case.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseReadAccess()`.
	 *
	 * `GET /api/vth/cases/{id}/advice-requests` is a per-case sub-resource and
	 * `$id` is the CASE uuid, so the case-membership predicate applies directly
	 * — no `OwningCaseResolver` hop is needed. This is the same relationship
	 * `AdviceAuthorizationGuard::isHandlerOfLinkedCase()` already tests on the
	 * transition and reminder paths (assignee of the linked case, admin
	 * bypass); the case-level LIST simply never had it.
	 *
	 * @param string $id UUID of the case
	 *
	 * @return JSONResponse List of advice requests
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-7
	 */
	#[NoAdminRequired]
	public function getForCase(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Not authenticated');
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return new JSONResponse(data: ['error' => 'Not authorized'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$advice = $this->adviceService->getAdviceForCase(caseId: $id);
		return new JSONResponse(data: $advice, statusCode: Http::STATUS_OK);
	}//end getForCase()

	/**
	 * Decode a JSON request body safely.
	 *
	 * @return array<string, mixed> Decoded payload or empty array
	 */
	private function readJsonBody(): array {
		// Prefer the request object's getContent() when reachable — test
		// stubs expose a public getContent(); the concrete OC request hides
		// it, so we fall through to php://input there.
		$content = '';
		if (method_exists($this->request, 'getContent') === true) {
			try {
				$raw = $this->request->getContent();
				if (is_string($raw) === true) {
					$content = $raw;
				}
			} catch (\Throwable $e) {
				$content = '';
			}
		}

		if ($content === '') {
			$content = (string)file_get_contents('php://input');
		}

		if ($content === '') {
			return [];
		}

		$decoded = json_decode($content, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end readJsonBody()
}//end class
