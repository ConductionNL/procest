<?php

/**
 * Dossiq Zip Manifest Builder
 *
 * Builds a ZIP export of a case dossier: documents are organised into
 * per-informatieobjecttype sub-folders and accompanied by a `manifest.csv`
 * describing every included document. Documents above the caller's clearance
 * are excluded. Entries are added one at a time (each file's content is read,
 * written and released before the next), so a large dossier never loads all
 * file contents into memory simultaneously — mirroring the streaming intent of
 * OpenRegister's `FilePublishingHandler::createObjectFilesZip()` while reusing
 * the app's established `\ZipArchive` convention.
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
 * @spec openspec/changes/document-zaakdossier/tasks.md#T04
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\IUser;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Builds a manifest-bearing, type-foldered ZIP export of a dossier.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T04
 */
class ZipManifestBuilder {
	/**
	 * Manifest.csv column order.
	 */
	public const MANIFEST_COLUMNS = [
		'fileName',
		'title',
		'informatieobjecttype',
		'status',
		'vertrouwelijkheidaanduiding',
		'creatiedatum',
		'auteur',
	];

	/**
	 * Archive layout: one sub-folder per informatieobjecttype.
	 */
	public const LAYOUT_PER_TYPE = 'per-type';

	/**
	 * Archive layout: every document at the archive root.
	 */
	public const LAYOUT_FLAT = 'flat';

	/**
	 * Constructor.
	 *
	 * @param ZgwDocumentService $documentService Binary file storage service.
	 * @param InformatieobjectAccessGuard $accessGuard Confidentiality guard.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ZgwDocumentService $documentService,
		private readonly InformatieobjectAccessGuard $accessGuard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build a CSV manifest string for a list of informatieobjecten.
	 *
	 * @param array<int, array<string, mixed>> $documents The documents to describe.
	 *
	 * @return string The manifest.csv content.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T04
	 */
	public function buildManifest(array $documents): string {
		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			return '';
		}

		fputcsv($handle, self::MANIFEST_COLUMNS);
		foreach ($documents as $doc) {
			$row = [];
			foreach (self::MANIFEST_COLUMNS as $column) {
				$row[] = (string)($doc[$column] ?? '');
			}

			fputcsv($handle, $row);
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end buildManifest()

	/**
	 * Filter a document list to those the user is cleared to read.
	 *
	 * @param IUser|null $user The caller, or null (treated as no extra filtering).
	 * @param array<int, array<string, mixed>> $documents Candidate documents.
	 *
	 * @return array<int, array<string, mixed>> The clearance-filtered list.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T04
	 */
	public function filterByClearance(?IUser $user, array $documents): array {
		if ($user === null) {
			return array_values($documents);
		}

		return array_values($this->accessGuard->filterDossierForUser(user: $user, informatieobjecten: $documents));
	}//end filterByClearance()

	/**
	 * Build a ZIP archive at the given path for the supplied documents.
	 *
	 * Documents above the caller's clearance are excluded before any file is
	 * read. Under self::LAYOUT_PER_TYPE the archive contains one sub-folder per
	 * informatieobjecttype; under self::LAYOUT_FLAT every document sits at the
	 * root. A `manifest.csv` is always written at the root.
	 *
	 * @param string $targetPath Filesystem path to write the ZIP to.
	 * @param IUser|null $user The caller (for clearance filtering).
	 * @param array<int, array<string, mixed>> $documents Candidate documents.
	 * @param string $layout self::LAYOUT_PER_TYPE or self::LAYOUT_FLAT.
	 *
	 * @return array<string, mixed> Result with `path`, `included` count and `excluded` count.
	 *
	 * @throws \RuntimeException When the ZIP archive cannot be created.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T04
	 */
	public function buildZip(string $targetPath, ?IUser $user, array $documents, string $layout = self::LAYOUT_PER_TYPE): array {
		$candidateCount = count($documents);
		$included = $this->filterByClearance(user: $user, documents: $documents);
		$excluded = ($candidateCount - count($included));

		$zip = new ZipArchive();
		if ($zip->open($targetPath, (ZipArchive::CREATE | ZipArchive::OVERWRITE)) !== true) {
			throw new RuntimeException('Could not create ZIP archive at ' . $targetPath);
		}

		// Manifest.csv at the archive root.
		$zip->addFromString('manifest.csv', $this->buildManifest(documents: $included));

		$usedNames = [];
		foreach ($included as $doc) {
			$infoId = (string)($doc['id'] ?? ($doc['uuid'] ?? ''));
			$fileName = (string)($doc['fileName'] ?? '');
			if ($infoId === '' || $fileName === '') {
				continue;
			}

			$entryName = $this->buildEntryName(
				doc: $doc,
				fileName: $fileName,
				layout: $layout,
				usedNames: $usedNames,
			);

			try {
				// Read one file at a time; content is released before the next iteration.
				$content = $this->documentService->getContent(uuid: $infoId, fileName: $fileName);
				$zip->addFromString($entryName, $content);
				unset($content);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Dossiq dossier ZIP: skipped unreadable file ' . $fileName . ' (' . $e->getMessage() . ')'
				);
			}
		}//end foreach

		$zip->close();

		return [
			'path' => $targetPath,
			'included' => count($included),
			'excluded' => $excluded,
		];
	}//end buildZip()

	/**
	 * Compute the unique in-archive entry name for a document.
	 *
	 * @param array<string, mixed> $doc The document record.
	 * @param string $fileName The base filename.
	 * @param string $layout self::LAYOUT_PER_TYPE or self::LAYOUT_FLAT.
	 * @param array<string, int> $usedNames Reference of already-used names for de-duplication.
	 *
	 * @return string The unique entry name.
	 */
	private function buildEntryName(array $doc, string $fileName, string $layout, array &$usedNames): string {
		$prefix = '';
		if ($layout === self::LAYOUT_PER_TYPE) {
			$type = (string)($doc['informatieobjecttype'] ?? 'unknown');
			$prefix = $this->sanitizeFolderName(name: $type) . '/';
		}

		$entry = $prefix . $this->sanitizeFileName(name: $fileName);

		if (isset($usedNames[$entry]) === false) {
			$usedNames[$entry] = 0;
			return $entry;
		}

		$usedNames[$entry]++;

		$base = $fileName;
		$extension = '';
		$dot = strrpos($fileName, '.');
		if ($dot !== false) {
			$base = substr($fileName, 0, $dot);
			$extension = substr($fileName, $dot);
		}

		return $prefix . $this->sanitizeFileName(name: $base) . '_' . $usedNames[$entry] . $extension;
	}//end buildEntryName()

	/**
	 * Sanitise a folder segment for safe inclusion in a ZIP entry name.
	 *
	 * A folder segment carries no extension, so dots are flattened before the
	 * shared filename rules are applied — that also collapses `.` and `..`
	 * traversal segments into harmless underscores.
	 *
	 * @param string $name The raw folder name.
	 *
	 * @return string The sanitised folder name.
	 */
	private function sanitizeFolderName(string $name): string {
		return $this->sanitizeFileName(name: str_replace('.', '_', $name));
	}//end sanitizeFolderName()

	/**
	 * Sanitise a filename for safe inclusion in a ZIP entry name.
	 *
	 * Dots are preserved so the extension survives; separators and NUL bytes
	 * are flattened so the entry can never escape the archive root.
	 *
	 * @param string $name The raw filename.
	 *
	 * @return string The sanitised filename.
	 */
	private function sanitizeFileName(string $name): string {
		$clean = trim(str_replace(['/', '\\', "\0"], '_', $name));
		if ($clean === '' || $clean === '.' || $clean === '..') {
			return 'unknown';
		}

		return $clean;
	}//end sanitizeFileName()
}//end class
