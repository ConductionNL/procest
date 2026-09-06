<?php

/**
 * Dossiq Zaakdossier Controller
 *
 * Authenticated JSON REST API for the ZGW DRC case dossier: list/upload
 * documents, link/unlink existing informatieobjecten, update metadata and
 * transition status (single + bulk).
 *
 * The binary surface — ZIP export, single-file download and the ZGW DRC
 * streaming endpoint — lives on {@see ZaakdossierDownloadController}. Upload
 * decoding and screening is delegated to {@see DossierUploadHandler} (ADR-022).
 *
 * Every read endpoint enforces {@see InformatieobjectReader}, which wraps
 * InformatieobjectAccessGuard, so confidentiality
 * (`vertrouwelijkheidaanduiding`) is gated server-side and per-object — never
 * relying on the UI alone (OWASP A01:2021, ADR-005 Rule 3).
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
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectReader;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Controller for the ZGW DRC zaakdossier.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 */
class ZaakdossierController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param ZaakdossierService $fileService The dossier orchestrator.
	 * @param InformatieobjectReader $reader The clearance-gated document reader.
	 * @param DossierUploadHandler $uploadHandler The upload decoding/screening collaborator.
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ZaakdossierService $fileService,
		private readonly InformatieobjectReader $reader,
		private readonly DossierUploadHandler $uploadHandler,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List the dossier for a case, grouped by type and filtered by clearance.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 *
	 * @return JSONResponse Grouped dossier or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function listDossier(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$file = $this->fileService->getDossierForCase(caseId: $caseId);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$filtered = $this->reader->filterForUser(
			user: $user,
			informatieobjecten: ($file['informatieobjecten'] ?? []),
		);

		$regrouped = $this->fileService->groupByType(documents: $filtered);

		return new JSONResponse($regrouped);
	}//end listDossier()

	/**
	 * Upload one or more documents to a case dossier.
	 *
	 * Accepts multipart files plus a shared `metadata` JSON body. Returns a
	 * per-file result list so a single failure does not block the rest.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 *
	 * @return JSONResponse Per-file upload results.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function uploadDocument(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Its sibling `linkExisting()` below already guards, via
		// `InformatieobjectReader::guardReadable()` — but that guard is about
		// the DOCUMENT, and upload has no existing document to check. The
		// missing half is the CASE, so this endpoint wrote attachments into
		// any case on the instance.
		if ($this->uploadHandler->hasCaseUploadAccess(user: $user, caseId: $caseId) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		$metadata = $this->uploadHandler->decodeMetadata(raw: $this->request->getParam('metadata', '{}'));
		if (($metadata['auteur'] ?? '') === '') {
			$metadata['auteur'] = $user->getDisplayName();
		}

		$files = $this->uploadHandler->normaliseUploadedFiles(uploaded: $this->request->getUploadedFile('files'));
		if (empty($files) === true) {
			return new JSONResponse(['error' => 'No files uploaded'], Http::STATUS_BAD_REQUEST);
		}

		$results = [];
		foreach ($files as $file) {
			$results[] = $this->uploadHandler->uploadOne(
				caseId: $caseId,
				file: $file,
				metadata: $metadata,
			);
		}

		return new JSONResponse(['results' => $results], Http::STATUS_CREATED);
	}//end uploadDocument()

	/**
	 * Link an existing informatieobject to a case.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return JSONResponse The join result.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function linkExisting(string $caseId, string $infoObjectId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$authError = $this->reader->guardReadable(user: $user, infoObjectId: $infoObjectId);
		if ($authError !== null) {
			return $authError;
		}

		try {
			$result = $this->fileService->linkExistingInformatieobject(caseId: $caseId, infoObjectId: $infoObjectId);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end linkExisting()

	/**
	 * Unlink an informatieobject from a case (preserves the document).
	 *
	 * @param string $caseId The case UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return JSONResponse The unlink result.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function unlinkDocument(string $caseId, string $infoObjectId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$authError = $this->reader->guardReadable(user: $user, infoObjectId: $infoObjectId);
		if ($authError !== null) {
			return $authError;
		}

		try {
			$removed = $this->fileService->unlinkInformatieobject(caseId: $caseId, infoObjectId: $infoObjectId);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(['unlinked' => $removed]);
	}//end unlinkDocument()

	/**
	 * Update editable metadata on an informatieobject.
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return JSONResponse The updated metadata or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function updateMetadata(string $infoObjectId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$authError = $this->reader->guardReadable(user: $user, infoObjectId: $infoObjectId);
		if ($authError !== null) {
			return $authError;
		}

		$metadata = [
			'title' => $this->request->getParam('title'),
			// Key = schema property (renamed). The request param keeps both
			// spellings: it is a published contract and an un-updated client
			// must not break.
			'description' => $this->request->getParam('description') ?? $this->request->getParam('beschrijving'),
			'informatieobjecttype' => $this->request->getParam('informatieobjecttype'),
			'vertrouwelijkheidaanduiding' => $this->request->getParam('vertrouwelijkheidaanduiding'),
		];
		$metadata = array_filter($metadata, static fn ($value) => $value !== null);

		try {
			$result = $this->fileService->updateMetadata(infoObjectId: $infoObjectId, metadata: $metadata);
		} catch (\DomainException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($result);
	}//end updateMetadata()

	/**
	 * Transition a single informatieobject's status.
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return JSONResponse The transition result. HTTP 400 on an invalid transition.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function transitionStatus(string $infoObjectId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$authError = $this->reader->guardReadable(user: $user, infoObjectId: $infoObjectId);
		if ($authError !== null) {
			return $authError;
		}

		$newStatus = (string)$this->request->getParam('status', '');

		try {
			$result = $this->fileService->transitionStatus(infoObjectId: $infoObjectId, newStatus: $newStatus);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($result);
	}//end transitionStatus()

	/**
	 * Apply a bulk status transition over multiple informatieobjecten.
	 *
	 * @return JSONResponse Per-id success/failure list.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function bulkTransitionStatus(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$ids = (array)$this->request->getParam('ids', []);
		$newStatus = (string)$this->request->getParam('status', '');

		// Per-object clearance gate before any mutation.
		if ($this->allReadable(user: $user, ids: $ids) === false) {
			return new JSONResponse(
				['error' => 'Insufficient clearance for one or more selected documents'],
				Http::STATUS_FORBIDDEN,
			);
		}

		$results = $this->fileService->bulkTransitionStatus(infoObjectIds: $ids, newStatus: $newStatus);

		return new JSONResponse(['results' => $results]);
	}//end bulkTransitionStatus()

	/**
	 * Apply a bulk metadata update over multiple informatieobjecten.
	 *
	 * @return JSONResponse Per-id success/failure list.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function bulkUpdateMetadata(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$ids = (array)$this->request->getParam('ids', []);
		$metadata = (array)$this->request->getParam('metadata', []);

		$results = [];
		foreach ($ids as $id) {
			$results[] = $this->updateOneMetadata(user: $user, id: (string)$id, metadata: $metadata);
		}

		return new JSONResponse(['results' => $results]);
	}//end bulkUpdateMetadata()

	/**
	 * Update one informatieobject's metadata inside a bulk run.
	 *
	 * @param IUser $user The requesting user.
	 * @param string $id The informatieobject UUID.
	 * @param array<string, mixed> $metadata The metadata to apply.
	 *
	 * @return array<string, mixed> The per-id result entry.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	private function updateOneMetadata(IUser $user, string $id, array $metadata): array {
		if ($this->reader->guardReadable(user: $user, infoObjectId: $id) !== null) {
			return ['id' => $id, 'success' => false, 'error' => 'Insufficient clearance'];
		}

		try {
			$this->fileService->updateMetadata(infoObjectId: $id, metadata: $metadata);
			return ['id' => $id, 'success' => true];
		} catch (\Throwable $e) {
			return ['id' => $id, 'success' => false, 'error' => $e->getMessage()];
		}
	}//end updateOneMetadata()

	/**
	 * Whether every listed informatieobject is readable by the user.
	 *
	 * @param IUser $user The requesting user.
	 * @param array<int,mixed> $ids The informatieobject UUIDs.
	 *
	 * @return bool True when all ids pass the clearance gate.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	private function allReadable(IUser $user, array $ids): bool {
		foreach ($ids as $id) {
			if ($this->reader->guardReadable(user: $user, infoObjectId: (string)$id) !== null) {
				return false;
			}
		}

		return true;
	}//end allReadable()
}//end class
