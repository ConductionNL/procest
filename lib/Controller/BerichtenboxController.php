<?php

/**
 * Dossiq Berichtenbox Controller.
 *
 * REST endpoints for sending, listing and polling Mijn Overheid Berichtenbox
 * messages linked to cases.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\BerichtenboxService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller exposing Berichtenbox send/list/poll endpoints.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class BerichtenboxController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param BerichtenboxService $berichtenboxService The Berichtenbox service.
	 * @param IUserSession $userSession The user session.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed).
	 */
	public function __construct(
		IRequest $request,
		private BerichtenboxService $berichtenboxService,
		private IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Send a Berichtenbox message for a case.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function send(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId');
		$bsn = $this->request->getParam('bsn', '');
		$subject = $this->request->getParam('subject', '');
		$body = $this->request->getParam('body', '');
		$typeCode = $this->request->getParam('berichtTypeCode', '');
		$attachmentFileId = $this->request->getParam('attachmentFileId');

		if (empty($caseId) === true) {
			return new JSONResponse(['success' => false, 'error' => 'caseId is required'], 400);
		}

		// Dispatches an official government message, with attachment, into a
		// citizen's statutory message box. Externally visible and not
		// undoable, so this is the strictest guard in the file.
		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: (string)$caseId, user: $user) === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'Not authorized'],
				Http::STATUS_FORBIDDEN
			);
		}

		$result = $this->berichtenboxService->sendMessage(
			$caseId,
			$bsn,
			$subject,
			$body,
			$typeCode,
			$attachmentFileId
		);

		if (isset($result['error']) === true) {
			return new JSONResponse(['success' => false, 'error' => $result['error']], 400);
		}

		return new JSONResponse(['success' => true, 'message' => $result]);
	}//end send()

	/**
	 * List Berichtenbox messages linked to a case.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function messages(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = (string)$this->request->getParam('caseId', '');
		if ($caseId === '') {
			return new JSONResponse(['success' => false, 'error' => 'caseId is required'], Http::STATUS_BAD_REQUEST);
		}

		// Official correspondence with a citizen about this case.
		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'Not authorized'],
				Http::STATUS_FORBIDDEN
			);
		}

		$messages = $this->berichtenboxService->getMessagesForCase($caseId);
		return new JSONResponse(['success' => true, 'messages' => $messages]);
	}//end messages()

	/**
	 * Poll read-status for a sent Berichtenbox message.
	 *
	 * @param string $messageId The external message identifier.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function poll(string $messageId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// The route carries only a message id, so the owning case is resolved
		// first and the same per-case guard applied. An unresolvable message
		// denies, so this is not an existence oracle.
		$caseId = $this->berichtenboxService->getCaseIdForMessage($messageId);
		if ($caseId === null
			|| $this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false
		) {
			return new JSONResponse(
				['success' => false, 'error' => 'Not authorized'],
				Http::STATUS_FORBIDDEN
			);
		}

		$result = $this->berichtenboxService->pollReadStatus($messageId);
		return new JSONResponse(['success' => true, 'message' => $result]);
	}//end poll()
}//end class
