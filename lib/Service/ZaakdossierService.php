<?php

/**
 * Dossiq Zaakdossier Service
 *
 * Orchestrates the ZGW DRC case dossier: it stores uploaded documents as
 * Nextcloud files (via {@see ZgwDocumentService}) and as `informatieobject`
 * register objects carrying full ZGW metadata, links them to a case through a
 * `zaakinformatieobject` join, enforces the `concept -> definitief ->
 * gearchiveerd` status lifecycle, computes a SHA-256 integrity hash, lists a
 * dossier grouped by informatieobjecttype, and performs bulk status
 * transitions. Confidentiality is enforced by {@see InformatieobjectAccessGuard}
 * at the controller boundary.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DomainException;
use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service orchestrating the ZGW DRC zaakdossier.
 *
 * The per-document status state machine is owned by
 * {@see InformatieobjectStatusLifecycle}; this service orchestrates the
 * dossier around it.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
 */
class ZaakdossierService {
	use SearchesObjects;

	/**
	 * Valid informatieobject statuses.
	 *
	 * Canonically owned by {@see InformatieobjectStatusLifecycle}; aliased here
	 * so existing callers of `ZaakdossierService::VALID_STATUSES` keep working.
	 *
	 * @var string[]
	 */
	public const VALID_STATUSES = InformatieobjectStatusLifecycle::VALID_STATUSES;

	/**
	 * Allowed forward-only status transitions (from => [allowed-to, ...]).
	 *
	 * Canonically owned by {@see InformatieobjectStatusLifecycle}; aliased here
	 * for backwards compatibility.
	 *
	 * @var array<string, string[]>
	 */
	public const STATUS_TRANSITIONS = InformatieobjectStatusLifecycle::STATUS_TRANSITIONS;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (config + ObjectService).
	 * @param ZgwDocumentService $documentService Binary file storage service.
	 * @param InformatieobjectAccessGuard $accessGuard Classification access guard.
	 * @param InformatieobjectStatusLifecycle $statusLifecycle Per-document status state machine.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ZgwDocumentService $documentService,
		private readonly InformatieobjectAccessGuard $accessGuard,
		private readonly InformatieobjectStatusLifecycle $statusLifecycle,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Upload a document, creating an informatieobject and a zaakinformatieobject join.
	 *
	 * @param string $caseId The case (zaak) UUID the document is linked to.
	 * @param string $fileName The original filename.
	 * @param string $content The raw binary file content.
	 * @param array<string, mixed> $metadata Document metadata (titel, informatieobjecttype,
	 *                                       vertrouwelijkheidaanduiding, auteur, beschrijving, …).
	 *
	 * @return array<string, mixed> The created informatieobject summary.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or required fields/config missing.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function uploadDocument(string $caseId, string $fileName, string $content, array $metadata): array {
		[$objectService, $register] = $this->requireRegister();

		if (trim($caseId) === '') {
			throw new RuntimeException('caseId is required');
		}

		if (trim($fileName) === '') {
			throw new RuntimeException('bestandsnaam is required');
		}

		$type = (string)($metadata['informatieobjecttype'] ?? '');
		if ($type === '') {
			throw new RuntimeException('informatieobjecttype is required');
		}

		$defaultClass = $this->resolveDefaultClassification(type: $type);
		$classification = (string)($metadata['vertrouwelijkheidaanduiding'] ?? '');
		if ($classification === '') {
			$classification = $defaultClass;
		} elseif ($this->accessGuard->isClassificationAllowed($defaultClass, $classification) === false) {
			// REQ-ZAK-003d: a user may only override to a MORE restrictive level.
			throw new InvalidArgumentException(
				'Classification may not be less restrictive than the document type default'
			);
		}

		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');

		$now = date('Y-m-d\TH:i:s');
		$hash = hash('sha256', $content);

		$informatieobject = [
			'title' => (string)($metadata['title'] ?? $fileName),
			'fileName' => $fileName,
			'bestandsomvang' => strlen($content),
			'format' => (string)($metadata['format'] ?? 'application/octet-stream'),
			'vertrouwelijkheidaanduiding' => $classification,
			'auteur' => (string)($metadata['auteur'] ?? ''),
			'status' => 'draft',
			'informatieobjecttype' => $type,
			'creatiedatum' => (string)($metadata['creatiedatum'] ?? date('Y-m-d')),
			'bronorganisatie' => (string)($metadata['bronorganisatie'] ?? ''),
			'taal' => (string)($metadata['taal'] ?? 'nld'),
			'description' => (string)($metadata['description'] ?? $metadata['beschrijving'] ?? ''),
			'integrity' => [
				'algorithm' => 'sha256',
				'value' => $hash,
				'date' => $now,
			],
		];

		$saved = $objectService->saveObject(object: $informatieobject, register: $register, schema: $infoSchema);
		$infoId = $this->resolveSavedUuid(saved: $saved);

		// Persist the binary content under the informatieobject UUID folder.
		$this->documentService->storeRaw(uuid: $infoId, fileName: $fileName, content: $content);

		// Create the case <-> document join.
		$this->createJoin(caseId: $caseId, infoObjectId: $infoId);

		$this->logger->info(
			'Dossiq dossier: uploaded informatieobject ' . $infoId . ' for case ' . $caseId,
			['app' => Application::APP_ID],
		);

		return [
			'id' => $infoId,
			'title' => $informatieobject['title'],
			'fileName' => $fileName,
			'status' => 'draft',
			'vertrouwelijkheidaanduiding' => $classification,
			'informatieobjecttype' => $type,
			'integrity' => $informatieobject['integrity'],
		];
	}//end uploadDocument()

	/**
	 * Link an existing informatieobject to a case without duplicating the document.
	 *
	 * Deduplicates: when the join already exists no second join is created.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return array<string, mixed> The join summary.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function linkExistingInformatieobject(string $caseId, string $infoObjectId): array {
		[$objectService, $register] = $this->requireRegister();
		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');

		$existing = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $joinSchema,
			filters: ['case' => $caseId, 'informatieobject' => $infoObjectId, '_limit' => 1],
		);

		if (empty($existing) === false) {
			return [
				'id' => ($existing[0]['id'] ?? ''),
				'case' => $caseId,
				'duplicated' => false,
			];
		}

		return $this->createJoin(caseId: $caseId, infoObjectId: $infoObjectId);
	}//end linkExistingInformatieobject()

	/**
	 * Unlink an informatieobject from a case, preserving the document itself.
	 *
	 * Only the `zaakinformatieobject` join records are deleted; the
	 * informatieobject record and the Nextcloud file remain intact.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return bool True when at least one join was removed.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function unlinkInformatieobject(string $caseId, string $infoObjectId): bool {
		[$objectService, $register] = $this->requireRegister();
		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');

		$joins = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $joinSchema,
			filters: ['case' => $caseId, 'informatieobject' => $infoObjectId, '_limit' => 100],
		);

		$removed = false;
		foreach ($joins as $join) {
			$joinId = (string)($join['id'] ?? ($join['uuid'] ?? ''));
			if ($joinId === '') {
				continue;
			}

			$objectService->deleteObject(uuid: $joinId, register: $register, schema: $joinSchema);
			$removed = true;
		}

		return $removed;
	}//end unlinkInformatieobject()

	/**
	 * Validate and apply a status transition, locking the document on definitief.
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 * @param string $newStatus The requested status.
	 *
	 * @return array<string, mixed> The updated informatieobject summary.
	 *
	 * @throws \RuntimeException When OpenRegister/config is unavailable or status invalid.
	 * @throws \InvalidArgumentException When the transition is not permitted (caller maps to HTTP 400).
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function transitionStatus(string $infoObjectId, string $newStatus): array {
		return $this->statusLifecycle->transition(infoObjectId: $infoObjectId, newStatus: $newStatus);
	}//end transitionStatus()

	/**
	 * Determine whether a status transition is permitted (forward-only).
	 *
	 * @param string $from The current status.
	 * @param string $to The requested status.
	 *
	 * @return bool True when allowed.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function isTransitionAllowed(string $from, string $to): bool {
		return $this->statusLifecycle->isTransitionAllowed(from: $from, to: $to);
	}//end isTransitionAllowed()

	/**
	 * List the dossier for a case, grouped by informatieobjecttype with counts.
	 *
	 * The returned `informatieobjecten` list is NOT clearance-filtered here;
	 * the controller applies {@see InformatieobjectAccessGuard::filterDossierForUser()}
	 * so the service stays testable without a user context.
	 *
	 * @param string $caseId The case (zaak) UUID.
	 *
	 * @return array<string, mixed> Structure with `total`, `groups` and `informatieobjecten`.
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or config missing.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function getDossierForCase(string $caseId): array {
		[$objectService, $register] = $this->requireRegister();
		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');

		$joins = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $joinSchema,
			filters: ['case' => $caseId, '_limit' => 500],
		);

		$documents = [];
		foreach ($joins as $join) {
			$infoId = (string)($join['informatieobject'] ?? '');
			if ($infoId === '') {
				continue;
			}

			$doc = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $infoSchema,
				id: $infoId,
			);

			if ($doc !== null) {
				$documents[] = $doc;
			}
		}

		return $this->groupByType(documents: $documents);
	}//end getDossierForCase()

	/**
	 * Group a list of informatieobjecten by informatieobjecttype with counts.
	 *
	 * @param array<int, array<string, mixed>> $documents The documents to group.
	 *
	 * @return array<string, mixed> Grouped structure.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function groupByType(array $documents): array {
		$groups = [];
		foreach ($documents as $doc) {
			$type = (string)($doc['informatieobjecttype'] ?? 'unknown');
			if (isset($groups[$type]) === false) {
				$groups[$type] = [];
			}

			$groups[$type][] = $doc;
		}

		$result = [];
		foreach ($groups as $type => $docs) {
			$result[] = [
				'informatieobjecttype' => $type,
				'count' => count($docs),
				'documents' => $docs,
			];
		}

		return [
			'total' => count($documents),
			'groups' => $result,
			'informatieobjecten' => $documents,
		];
	}//end groupByType()

	/**
	 * Apply a bulk status transition, returning a per-id success/failure list.
	 *
	 * @param string[] $infoObjectIds The informatieobject UUIDs.
	 * @param string $newStatus The requested status.
	 *
	 * @return array<int, array<string, mixed>> Per-id results with `id`, `success`, optional `error`.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function bulkTransitionStatus(array $infoObjectIds, string $newStatus): array {
		return $this->statusLifecycle->transitionMany(infoObjectIds: $infoObjectIds, newStatus: $newStatus);
	}//end bulkTransitionStatus()

	/**
	 * Update editable metadata on an informatieobject (titel, beschrijving, type).
	 *
	 * Rejects mutation of a definitief document (caller maps to HTTP 409).
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 * @param array<string, mixed> $metadata Editable fields.
	 *
	 * @return array<string, mixed> The updated summary.
	 *
	 * @throws \RuntimeException When OpenRegister/config unavailable or document missing.
	 * @throws \DomainException When the document is definitief and therefore immutable.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function updateMetadata(string $infoObjectId, array $metadata): array {
		[$objectService, $register] = $this->requireRegister();
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');

		$current = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $infoSchema,
			id: $infoObjectId,
		);

		if ($current === null) {
			throw new RuntimeException('Informatieobject not found: ' . $infoObjectId);
		}

		if ((string)($current['status'] ?? '') === 'final') {
			throw new DomainException('Definitieve documenten kunnen niet worden gewijzigd');
		}

		$allowed = ['title', 'description', 'informatieobjecttype', 'vertrouwelijkheidaanduiding'];
		$updateData = [];
		foreach ($allowed as $field) {
			if (array_key_exists($field, $metadata) === true) {
				$updateData[$field] = $metadata[$field];
			}
		}

		if (empty($updateData) === true) {
			return ['id' => $infoObjectId, 'updated' => false];
		}

		$objectService->saveObject(object: $updateData, register: $register, schema: $infoSchema, uuid: $infoObjectId);

		return array_merge(['id' => $infoObjectId, 'updated' => true], $updateData);
	}//end updateMetadata()

	/**
	 * Fetch a single informatieobject as an array.
	 *
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return array<string, mixed>|null The document or null when not found.
	 *
	 * @throws \RuntimeException When OpenRegister/config unavailable.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function getInformatieobject(string $infoObjectId): ?array {
		[$objectService, $register] = $this->requireRegister();
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');

		return $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $infoSchema,
			id: $infoObjectId,
		);
	}//end getInformatieobject()

	/**
	 * Resolve the default classification for an informatieobjecttype.
	 *
	 * @param string $type The informatieobjecttype UUID/slug.
	 *
	 * @return string The default vertrouwelijkheidaanduiding ('intern' fallback).
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
	 */
	public function resolveDefaultClassification(string $type): string {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$typeSchema = $this->settingsService->getConfigValue('dossier_informatieobjecttype_schema');

		if ($objectService === null || $register === '' || $typeSchema === '') {
			return 'intern';
		}

		$record = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $typeSchema,
			id: $type,
		);

		$level = (string)($record['vertrouwelijkheidaanduiding'] ?? '');
		if (in_array($level, InformatieobjectAccessGuard::HIERARCHY, true) === true) {
			return $level;
		}

		return 'intern';
	}//end resolveDefaultClassification()

	/**
	 * Create a zaakinformatieobject join object.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $infoObjectId The informatieobject UUID.
	 *
	 * @return array<string, mixed> The join summary.
	 */
	private function createJoin(string $caseId, string $infoObjectId): array {
		[$objectService, $register] = $this->requireRegister();
		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');

		$join = [
			'case' => $caseId,
			'informatieobject' => $infoObjectId,
			'natureRelationshipDisplay' => 'Hoort at omgekeerd',
			'registrationDate' => date('Y-m-d\TH:i:s\Z'),
		];

		$saved = $objectService->saveObject(object: $join, register: $register, schema: $joinSchema);
		$joinId = $this->resolveSavedUuid(saved: $saved);

		return [
			'id' => $joinId,
			'case' => $caseId,
			'informatieobject' => $infoObjectId,
			'duplicated' => true,
		];
	}//end createJoin()

	/**
	 * Resolve the ObjectService and register, throwing when unavailable.
	 *
	 * @return array{0: object, 1: string} The object service and register slug.
	 *
	 * @throws \RuntimeException When OpenRegister or the register config is unavailable.
	 */
	private function requireRegister(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
	}//end requireRegister()

	/**
	 * Read the UUID out of whatever `ObjectService::saveObject()` returned.
	 *
	 * OpenRegister returns an ObjectEntity when the register is live and a
	 * plain array in the array-mode/test paths, so both shapes are handled.
	 * The UUID MUST come from the SAVED result — the input payload never
	 * carries an `id`, so reading it back from the payload always yielded ''.
	 *
	 * `is_callable()` rather than `method_exists()`: ObjectEntity declares
	 * `uuid` as a protected property and exposes `getUuid()` only through
	 * `OCP\AppFramework\Db\Entity::__call()`. `method_exists()` does not see
	 * magic methods and would report false for every live object, silently
	 * dropping this to the array branch and returning ''. `is_callable()`
	 * accounts for `__call()`, and `call_user_func()` keeps the invocation
	 * resolvable for static analysis.
	 *
	 * @param mixed $saved The saveObject() return value.
	 *
	 * @return string The saved object UUID, or '' when it cannot be resolved.
	 */
	private function resolveSavedUuid(mixed $saved): string {
		if (is_object($saved) === true && is_callable([$saved, 'getUuid']) === true) {
			return (string)call_user_func([$saved, 'getUuid']);
		}

		$row = (array)$saved;
		$self = (array)($row['@self'] ?? []);
		return (string)($row['id'] ?? ($self['id'] ?? ''));
	}//end resolveSavedUuid()
}//end class
