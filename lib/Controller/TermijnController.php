<?php

/**
 * Dossiq TermijnController.
 *
 * REST surface for TermijnInstance lifecycle (create, get, pauze,
 * hervat, verleng, voltooi). Defers all business logic to
 * {@see TermijnService}, {@see DeadlinePauseService} and
 * {@see DeadlineExtensionService} (ADR-022).
 *
 * Auth: @NoAdminRequired — handler / caseworker calls only. Per-object
 * IDOR guard is enforced by re-fetching the instance and verifying the
 * caller's case-access through the existing CaseSharingService is
 * outside the chain scope; for now we rely on NC SecurityMiddleware's
 * authenticated-user default + the case-bound zaakId on the row.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\TermijnService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * REST surface for TermijnInstance lifecycle.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/termijn-binding/spec.md
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
class TermijnController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request Request.
	 * @param TermijnService $term Termijn service.
	 * @param DeadlinePauseService $pause Pause service.
	 * @param DeadlineExtensionService $extension Extension service.
	 * @param CaseTypeSlugResolver $caseTypeSlugs Case-type uuid-to-slug resolver.
	 * @param IUserSession $userSession User session.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TermijnService $term,
		private readonly DeadlinePauseService $pause,
		private readonly DeadlineExtensionService $extension,
		private readonly CaseTypeSlugResolver $caseTypeSlugs,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Per-object authorization guard.
	 *
	 * Returns a Http::STATUS_FORBIDDEN response when no user is logged in.
	 * Per-object IDOR enforcement (the user must have access to the
	 * specific zaak) is delegated to {@see TermijnService} which only
	 * returns instances bound to a zaak the caller can see — the NC
	 * SecurityMiddleware enforces base auth + we re-check the session
	 * here so the controller cannot be reached anonymously even if
	 * route attributes are misconfigured.
	 *
	 * @return JSONResponse|null
	 */
	private function ensureAuthenticated(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end ensureAuthenticated()

	/**
	 * Create a TermijnInstance for a zaak.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function create(): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$caseId = (string)($body['caseId'] ?? '');
		$caseTypeRef = (string)($body['caseType'] ?? '');
		if ($caseId === '' || $caseTypeRef === '') {
			return $this->badRequest(msg: 'zaakId and zaaktype are required');
		}

		// A caller holding a case reads its `caseType` as a uuid, while a
		// deadlineDefinition binds by slug. Both are accepted here and resolve
		// to the slug, so this endpoint and the created-case listener agree.
		$caseType = $this->caseTypeSlugs->toSlug(reference: $caseTypeRef);
		if ($caseType === '') {
			return $this->badRequest(msg: 'zaaktype could not be resolved');
		}

		try {
			$row = $this->term->createTermijnInstance($caseId, $caseType);
			return new JSONResponse($row, Http::STATUS_CREATED);
		} catch (Throwable $e) {
			return $this->error(e: $e, log: 'Termijn create failed');
		}
	}//end create()

	/**
	 * Get a TermijnInstance by id.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function show(string $id): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$row = $this->term->getTermijnInstance($id);
		if ($row === null) {
			return $this->notFound(msg: 'TermijnInstance not found: ' . $id);
		}

		return new JSONResponse($row);
	}//end show()

	/**
	 * Pause a TermijnInstance.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function pauze(string $id): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$durationDays = (int)($body['duurDagen'] ?? 0);
		$rationale = (string)($body['rationale'] ?? '');
		$documentLink = (string)($body['documentLink'] ?? '');

		try {
			$row = $this->pause->registerPauze($id, $durationDays, $rationale, $documentLink);
			return new JSONResponse($row);
		} catch (Throwable $e) {
			return $this->error(e: $e, log: 'Pauze failed');
		}
	}//end pauze()

	/**
	 * Resume after pauze.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function hervat(string $id): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$when = (string)($body['aanvullingDatum'] ?? '');
		$resumeAt = null;
		if ($when !== '') {
			$resumeAt = new DateTimeImmutable($when);
		}

		try {
			$row = $this->pause->resumeAfterPauze($id, $resumeAt);
			return new JSONResponse($row);
		} catch (Throwable $e) {
			return $this->error(e: $e, log: 'Hervat failed');
		}
	}//end hervat()

	/**
	 * Request a verlenging.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function verleng(string $id): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$rationale = (string)($body['rationale'] ?? '');
		$newEndDate = (string)($body['newEinddatum'] ?? '');
		$documentLink = (string)($body['documentLink'] ?? '');
		$isSupervisor = (bool)($body['supervisorOverride'] ?? false);

		try {
			if ($isSupervisor === true) {
				$row = $this->extension->requestSupervisorExtension(
					$id,
					$rationale,
					$newEndDate,
					$documentLink
				);
				return new JSONResponse($row);
			}

			$row = $this->extension->requestExtension($id, $rationale, $newEndDate, $documentLink);
			return new JSONResponse($row);
		} catch (Throwable $e) {
			return $this->error(e: $e, log: 'Verleng failed');
		}
	}//end verleng()

	/**
	 * Mark a TermijnInstance as voltooid.
	 *
	 * @param string $id Instance id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function voltooi(string $id): JSONResponse {
		$denied = $this->ensureAuthenticated();
		if ($denied !== null) {
			return $denied;
		}

		$body = $this->jsonBody();
		$when = (string)($body['voltooiDatum'] ?? '');
		$documentLink = (string)($body['documentLink'] ?? '');
		$completedAt = null;
		if ($when !== '') {
			$completedAt = new DateTimeImmutable($when);
		}

		try {
			$row = $this->term->markTermijnCompleted(
				$id,
				$completedAt,
				$documentLink
			);
			if ($row === null) {
				return $this->notFound(msg: 'TermijnInstance not found: ' . $id);
			}

			return new JSONResponse($row);
		} catch (Throwable $e) {
			return $this->error(e: $e, log: 'Voltooi failed');
		}
	}//end voltooi()

	/**
	 * Decode the JSON request body into an array.
	 *
	 * @return array<string, mixed>
	 */
	private function jsonBody(): array {
		// OCP\IRequest::getContent() is protected on the concrete OC
		// request; read raw payload from php://input instead.
		$raw = (string)file_get_contents('php://input');
		$body = json_decode($raw, true);
		if (is_array($body) === true) {
			return $body;
		}

		return [];
	}//end jsonBody()

	/**
	 * Build a 400 Bad Request response.
	 *
	 * @param string $msg Message.
	 *
	 * @return JSONResponse
	 */
	private function badRequest(string $msg): JSONResponse {
		return new JSONResponse(['message' => $msg], Http::STATUS_BAD_REQUEST);
	}//end badRequest()

	/**
	 * Build a 404 Not Found response.
	 *
	 * @param string $msg Message.
	 *
	 * @return JSONResponse
	 */
	private function notFound(string $msg): JSONResponse {
		return new JSONResponse(['message' => $msg], Http::STATUS_NOT_FOUND);
	}//end notFound()

	/**
	 * Build a 400 response from a caught exception.
	 *
	 * @param Throwable $e Exception.
	 * @param string $log Log prefix.
	 *
	 * @return JSONResponse
	 */
	private function error(Throwable $e, string $log): JSONResponse {
		$this->logger->info($log . ': ' . $e->getMessage());
		return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
	}//end error()
}//end class
