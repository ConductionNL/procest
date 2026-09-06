<?php

/**
 * Dossiq Backfill Informatieobject Metadata Repair Step
 *
 * Idempotent migration that converts existing dossier files into ZGW DRC
 * `informatieobject` register objects. It iterates the document storage
 * folders, and for any file that does not yet have an informatieobject it
 * creates one with sensible defaults (`status` = concept,
 * `vertrouwelijkheidaanduiding` = intern, `auteur` = file owner display name,
 * `integriteit.waarde` = SHA-256 of the file content) plus a
 * `zaakinformatieobject` join when a linking case can be determined. Files
 * that already have an informatieobject (matched by `bestandsnaam`) are
 * skipped, so a re-run is a no-op.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T09
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that back-fills informatieobject metadata for existing files.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T09
 */
class BackfillInformatieobjectMetadata implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * Document storage base path (mirrors ZgwDocumentService::STORAGE_BASE).
	 */
	private const STORAGE_BASE = 'dossiq/documenten';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service (config + ObjectService).
	 * @param IRootFolder $rootFolder Nextcloud root folder.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair-step display name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T09
	 */
	public function getName(): string {
		return 'Back-fill ZGW informatieobject metadata for existing Dossiq dossier files';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T09
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('Dossiq backfill: OpenRegister unavailable; skipping.');
			return;
		}

		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses `create` for 'Anonymous'. Without it this backfill writes
		// nothing and says so only in a warning, which does not fail an upgrade.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->runInner(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * The backfill itself.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T09
	 */
	private function runInner(object $objectService, IOutput $output): void {

		$register = $this->settingsService->getConfigValue('register');
		$infoSchema = $this->settingsService->getConfigValue('dossier_informatieobject_schema');
		if ($register === '' || $infoSchema === '') {
			$output->info('Dossiq backfill: dossier schemas not configured; skipping.');
			return;
		}

		$folder = $this->resolveStorageFolder();
		if ($folder === null) {
			$output->info('Dossiq backfill: storage folder absent; nothing to back-fill.');
			return;
		}

		$existing = $this->existingFilenames(objectService: $objectService, register: $register, schema: $infoSchema);

		$created = 0;
		$skipped = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder === false) {
				continue;
			}

			$result = $this->backfillFolderNode(
				objectService: $objectService,
				register: $register,
				schema: $infoSchema,
				node: $node,
				existing: $existing,
			);
			$created += $result['created'];
			$skipped += $result['skipped'];
			$existing = $result['existing'];
		}//end foreach

		$output->info('Dossiq backfill: created ' . $created . ' informatieobject(en), skipped ' . $skipped . ' existing.');
	}//end runInner()

	/**
	 * Back-fill every not-yet-registered file inside one case folder.
	 *
	 * Files whose name is already registered are counted as skipped; `_part_` upload fragments are
	 * ignored entirely. A per-file failure is logged and does not abort the folder.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register slug.
	 * @param string $schema The informatieobject schema slug.
	 * @param Folder $node The case folder to walk.
	 * @param array<int, string> $existing Filenames already registered.
	 *
	 * @return array{created: int, skipped: int, existing: array<int, string>} Counts plus the grown filename list.
	 */
	private function backfillFolderNode(object $objectService, string $register, string $schema, Folder $node, array $existing): array {
		$created = 0;
		$skipped = 0;

		foreach ($node->getDirectoryListing() as $fileNode) {
			if ($fileNode instanceof File === false) {
				continue;
			}

			$fileName = $fileNode->getName();
			if (str_starts_with($fileName, '_part_') === true) {
				continue;
			}

			if (in_array($fileName, $existing, true) === true) {
				$skipped++;
				continue;
			}

			try {
				$this->backfillFile(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					folderUuid: $node->getName(),
					file: $fileNode,
				);
				$existing[] = $fileName;
				$created++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Dossiq backfill: failed for ' . $fileName . ': ' . $e->getMessage(),
					['app' => Application::APP_ID],
				);
			}
		}//end foreach

		return ['created' => $created, 'skipped' => $skipped, 'existing' => $existing];
	}//end backfillFolderNode()

	/**
	 * Create an informatieobject (+ join when possible) for one existing file.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register slug.
	 * @param string $schema The informatieobject schema slug.
	 * @param string $folderUuid The storing folder UUID (used as the link key).
	 * @param File $file The Nextcloud file node.
	 *
	 * @return void
	 */
	private function backfillFile(object $objectService, string $register, string $schema, string $folderUuid, File $file): void {
		$content = (string)$file->getContent();
		$owner = $file->getOwner();
		$author = '';
		if ($owner !== null) {
			$author = $owner->getDisplayName();
		}

		$informatieobject = [
			'title' => $file->getName(),
			'fileName' => $file->getName(),
			'bestandsomvang' => $file->getSize(),
			'format' => $file->getMimeType(),
			'vertrouwelijkheidaanduiding' => 'intern',
			'auteur' => $author,
			'status' => 'draft',
			'informatieobjecttype' => '',
			'creatiedatum' => date('Y-m-d', $file->getMTime()),
			'taal' => 'nld',
			'fileId' => $file->getId(),
			'integrity' => [
				'algorithm' => 'sha256',
				'value' => hash('sha256', $content),
				'date' => date('Y-m-d\TH:i:s'),
			],
		];

		$saved = $objectService->saveObject(object: $informatieobject, register: $register, schema: $schema);
		$infoId = '';
		if (is_object($saved) === true) {
			$infoId = $saved->getUuid();
		}

		$joinSchema = $this->settingsService->getConfigValue('dossier_zaakinformatieobject_schema');
		if ($joinSchema !== '' && $infoId !== '') {
			$objectService->saveObject(
				object: [
					'case' => $folderUuid,
					'informatieobject' => $infoId,
					'natureRelationshipDisplay' => 'Hoort at omgekeerd',
					'registrationDate' => date('Y-m-d\TH:i:s\Z'),
				],
				register: $register,
				schema: $joinSchema,
			);
		}
	}//end backfillFile()

	/**
	 * Collect the bestandsnaam of every existing informatieobject for idempotency.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register slug.
	 * @param string $schema The informatieobject schema slug.
	 *
	 * @return string[] Filenames already represented by an informatieobject.
	 */
	private function existingFilenames(object $objectService, string $register, string $schema): array {
		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['_limit' => 10000],
		);

		$names = [];
		foreach ($rows as $row) {
			$name = (string)($row['fileName'] ?? '');
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end existingFilenames()

	/**
	 * Resolve the document storage folder, or null when it does not exist.
	 *
	 * @return Folder|null
	 */
	private function resolveStorageFolder(): ?Folder {
		try {
			$userFolder = $this->rootFolder->getUserFolder(userId: 'admin');
			if ($userFolder->nodeExists(path: self::STORAGE_BASE) === false) {
				return null;
			}

			$node = $userFolder->get(path: self::STORAGE_BASE);
			if ($node instanceof Folder === true) {
				return $node;
			}
		} catch (NotFoundException $e) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('Dossiq backfill: cannot resolve storage folder: ' . $e->getMessage());
			return null;
		}

		return null;
	}//end resolveStorageFolder()
}//end class
