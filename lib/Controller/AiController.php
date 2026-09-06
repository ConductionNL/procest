<?php

/**
 * Dossiq AI Controller
 *
 * Controller for AI-assisted case processing endpoints.
 * Provides document classification, data extraction, knowledge base Q&A,
 * summarization, routing suggestions, and audit trail access.
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
 * @spec openspec/specs/ai-assistance/spec.md
 * @spec openspec/specs/ai-assistance/spec.md
 * @spec openspec/specs/ai-assistance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Ai\AiAuditService;
use OCA\Dossiq\Service\AiService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * AI-assisted processing API controller.
 *
 * All endpoints require authenticated Nextcloud user.
 * AI features must be enabled in settings. The admin-gated configuration and
 * health endpoints live on {@see AiSettingsController}.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class AiController extends Controller {
	/**
	 * Constructor for AiController.
	 *
	 * @param string $appName The application name
	 * @param IRequest $request The request object
	 * @param AiService $aiService The AI service
	 * @param AiAuditService $auditService The AI oversight audit service
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger interface
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed)
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private AiService $aiService,
		private AiAuditService $auditService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Classify a document using AI.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function classify(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');
		$documentId = $this->request->getParam('documentId', '');

		if (empty($caseId) === true || empty($documentId) === true) {
			return new JSONResponse(
				['error' => 'caseId and documentId are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$userId = $user->getUID();
		$result = $this->aiService->classifyDocument($caseId, $documentId, $userId);

		return new JSONResponse($result);
	}//end classify()

	/**
	 * Extract structured data from case documents.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function extract(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');
		$documentId = $this->request->getParam('documentId');

		if (empty($caseId) === true) {
			return new JSONResponse(
				['error' => 'caseId is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$userId = $user->getUID();
		$result = $this->aiService->extractData($caseId, $documentId, $userId);

		return new JSONResponse($result);
	}//end extract()

	/**
	 * Ask a knowledge base question in case context.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function ask(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');
		$question = $this->request->getParam('question', '');

		if (empty($caseId) === true || empty($question) === true) {
			return new JSONResponse(
				['error' => 'caseId and question are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$userId = $user->getUID();
		$result = $this->aiService->askQuestion($caseId, $question, $userId);

		return new JSONResponse($result);
	}//end ask()

	/**
	 * Generate a summary for a case, document, or timeline.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function summarize(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');
		$type = $this->request->getParam('type', 'case');
		$documentId = $this->request->getParam('documentId');

		if (empty($caseId) === true) {
			return new JSONResponse(
				['error' => 'caseId is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$validTypes = ['case', 'document', 'timeline'];
		if (in_array($type, $validTypes, true) === false) {
			return new JSONResponse(
				['error' => 'type must be one of: ' . implode(', ', $validTypes)],
				Http::STATUS_BAD_REQUEST
			);
		}

		$userId = $user->getUID();
		$result = $this->aiService->summarize($caseId, $type, $documentId, $userId);

		return new JSONResponse($result);
	}//end summarize()

	/**
	 * Get case routing suggestions.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function suggestRouting(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');

		if (empty($caseId) === true) {
			return new JSONResponse(
				['error' => 'caseId is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$userId = $user->getUID();
		$result = $this->aiService->suggestRouting($caseId, $userId);

		return new JSONResponse($result);
	}//end suggestRouting()

	/**
	 * Get next-step suggestions for a case.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function suggestNext(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');

		if (empty($caseId) === true) {
			return new JSONResponse(
				['error' => 'caseId is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$userId = $user->getUID();
		$result = $this->aiService->suggestNextStep($caseId, $userId);

		return new JSONResponse($result);
	}//end suggestNext()

	/**
	 * Record a user action on an AI suggestion.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function recordAction(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId', '');
		$type = $this->request->getParam('type', '');
		$userAction = $this->request->getParam('userAction', '');
		$suggestion = $this->request->getParam('suggestion', []);
		$actual = $this->request->getParam('actualValue');
		$reason = $this->request->getParam('reason');

		if (empty($caseId) === true || empty($type) === true || empty($userAction) === true) {
			return new JSONResponse(
				['error' => 'caseId, type, and userAction are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$userId = $user->getUID();
		$result = $this->auditService->recordUserAction(
			$caseId,
			$type,
			$userAction,
			$suggestion,
			$actual,
			$reason,
			$userId,
		);

		return new JSONResponse($result);
	}//end recordAction()

	/**
	 * Get AI audit trail entries.
	 *
	 * Queries the recorded `aiAuditEntry` objects from OpenRegister via
	 * {@see AiAuditService::listAuditEntries()} — filterable by `caseId`/`type`,
	 * paged via `limit`/`offset`, newest first.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/ai-oversight-log/tasks.md#1.2
	 */
	public function auditIndex(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = (string)$this->request->getParam('caseId', '');
		$type = $this->request->getParam('type');
		$limit = (int)$this->request->getParam('limit', '50');
		$offset = (int)$this->request->getParam('offset', '0');

		// `caseId` used to be optional and the filter was built with
		// `array_filter()`, so omitting it dropped the key entirely and the
		// response was every AI decision record on the instance. It is now
		// mandatory, and the caller must work on that case.
		if ($caseId === '') {
			return new JSONResponse(['error' => 'caseId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$result = $this->auditService->listAuditEntries(
				filters: array_filter(['caseId' => $caseId, 'type' => $type]),
				limit: $limit,
				offset: $offset,
			);

			return new JSONResponse(
				[
					'success' => true,
					'entries' => $result['entries'],
					'total' => $result['total'],
					'limit' => $result['limit'],
					'offset' => $result['offset'],
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				'AI audit trail query failed',
				['error' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'AI audit trail query failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end auditIndex()
}//end class
