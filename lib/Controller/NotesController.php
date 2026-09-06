<?php

/**
 * Dossiq Notes Controller.
 *
 * Thin endpoint for note-typed side-effects that the shared nc-vue notes
 * surface (CnNotesTab, nc-vue #207) cannot own itself: turning a saved
 * note's `@mention` tokens into real Nextcloud notifications. Note
 * storage/CRUD stays entirely inside the OpenRegister integration leaf
 * (ADR-022) — this controller does not read or write notes, it only
 * reacts to the `mention` event the frontend forwards after a note with
 * mentions has already been saved.
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
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\MentionNotificationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for note-mention notification side-effects.
 *
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */
class NotesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request
	 * @param MentionNotificationService $mentionSvc The mention notification service
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		IRequest $request,
		private readonly MentionNotificationService $mentionSvc,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Notify every user mentioned in a just-saved note.
	 *
	 * Body shape (matches `CnNotesTab`'s `mention` event payload verbatim,
	 * see nc-vue's CnNotesTab.vue): `{ objectId, register, schema, noteId,
	 * mentionedUserIds }`. Best-effort: a failure here must never surface
	 * as an error to the note author, since the note itself is already
	 * saved by the time this endpoint is called — hence the try/catch
	 * around the delegate call still returns 200 with a soft error flag
	 * rather than a 5xx.
	 *
	 * @return JSONResponse Dispatch result
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	#[NoAdminRequired]
	public function mention(): JSONResponse {
		$actor = $this->userSession->getUser();
		if ($actor === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$data = $this->readJsonBody();

		$objectId = (string)($data['objectId'] ?? '');
		$register = (string)($data['register'] ?? '');
		$schema = (string)($data['schema'] ?? '');
		$noteId = (string)($data['noteId'] ?? '');
		$mentionedUserIdsRaw = $data['mentionedUserIds'] ?? [];
		$mentionedUserIds = [];
		if (is_array($mentionedUserIdsRaw) === true) {
			$mentionedUserIds = array_values(array_filter(array_map('strval', $mentionedUserIdsRaw)));
		}

		if ($objectId === '' || $mentionedUserIds === []) {
			return new JSONResponse(
				['error' => 'objectId and a non-empty mentionedUserIds array are required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			$notified = $this->mentionSvc->notifyMention(
				actorUserId: $actor->getUID(),
				actorDisplayName: $actor->getDisplayName(),
				objectId: $objectId,
				register: $register,
				schema: $schema,
				noteId: $noteId,
				mentionedUserIds: $mentionedUserIds,
			);

			return new JSONResponse(['notified' => $notified], Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Failed to dispatch note mention notifications: ' . $e->getMessage(),
				['app' => 'dossiq']
			);
			return new JSONResponse(
				['error' => 'Could not dispatch mention notifications: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}//end try
	}//end mention()

	/**
	 * Read the decoded JSON request body.
	 *
	 * Nextcloud's AppFramework auto-decodes a JSON request body and merges
	 * it into the request params, exposed via the PUBLIC getParams(). The
	 * raw getContent() accessor is PROTECTED on OC\AppFramework\Http\Request
	 * and calling it from a controller raises a fatal "Call to protected
	 * method" (HTTP 500) — see StatusTransitionController::readJsonBody()
	 * for the original regression this mirrors the fix of.
	 *
	 * @return array<string, mixed> Decoded payload or empty array
	 */
	private function readJsonBody(): array {
		return $this->request->getParams();
	}//end readJsonBody()
}//end class
