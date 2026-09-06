<?php

/**
 * Dossiq VTH catalogue files.
 *
 * The bundled VTH workflow-template catalogue lives as JSON files on disk, and
 * this class is the only thing that reads them. Split out of
 * {@see \OCA\Dossiq\Repair\SeedVthWorkflowTemplates} so that step reads as
 * orchestration: resolve a case type, build the graph, publish, report. Finding
 * the files, decoding them, refusing the unusable ones and answering questions
 * about one entry are a different job, and they are this one.
 *
 * Nothing here throws. A file that cannot be read or parsed answers null, and
 * the caller reports it as a failed entry rather than losing the rest of the
 * catalogue to it.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Vth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Vth;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use Psr\Log\LoggerInterface;

/**
 * Reads the bundled VTH workflow-template catalogue off disk.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */
class VthCatalogueFiles {

	/**
	 * Catalogue directory, relative to lib/.
	 */
	private const CATALOG_DIR = __DIR__ . '/../../Settings/seed/vth-workflow-templates';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The directory the catalogue is bundled in.
	 *
	 * @return string The absolute path.
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function directory(): string {
		return self::CATALOG_DIR;
	}//end directory()

	/**
	 * Whether the bundled catalogue directory is present at all. A build that
	 * dropped it is a condition the seed reports rather than throws on.
	 *
	 * @return bool True when the directory exists.
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function exists(): bool {
		return is_dir(self::CATALOG_DIR);
	}//end exists()

	/**
	 * Every catalogue file, in a stable order.
	 *
	 * @return array<int, string> Absolute paths, or an empty list.
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function paths(): array {
		$files = glob(self::CATALOG_DIR . '/*.json');
		if ($files === false) {
			return [];
		}

		return $files;
	}//end paths()

	/**
	 * Read and validate one catalogue file.
	 *
	 * Answers null on any condition that makes the file unusable: unreadable,
	 * invalid JSON, or missing its slug or title. The caller reports those as
	 * failed entries.
	 *
	 * @param string $file Absolute path to the JSON catalogue file.
	 *
	 * @return array<string, mixed>|null The decoded entry, or null when unusable.
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function load(string $file): ?array {
		$raw = file_get_contents($file);
		if ($raw === false) {
			$this->refuse(file: $file, reason: 'unable to read catalog file');
			return null;
		}

		$data = json_decode($raw, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
			$this->refuse(file: $file, reason: 'invalid JSON in catalog file');
			return null;
		}

		if ((string)($data['slug'] ?? '') === '' || (string)($data['title'] ?? '') === '') {
			$this->refuse(file: $file, reason: 'missing slug or title');
			return null;
		}

		return $data;
	}//end load()

	/**
	 * The route a catalogue entry declares.
	 *
	 * An entry naming no route is on the route `standaard`, same as every
	 * definition created before routes existed. The normalising rule is
	 * WorkflowLifecycleGuard's, and this reads it from there rather than
	 * keeping a second copy that can drift.
	 *
	 * @param array<string, mixed> $data The decoded catalogue entry.
	 *
	 * @return string The route slug, never empty.
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 */
	public function variantOf(array $data): string {
		$variant = trim((string)($data['variant'] ?? ''));
		if ($variant === '') {
			return WorkflowLifecycleGuard::VARIANT_DEFAULT;
		}

		return $variant;
	}//end variantOf()

	/**
	 * Log one refusal, naming the file rather than its path.
	 *
	 * @param string $file The catalogue file.
	 * @param string $reason Why it is unusable.
	 *
	 * @return void
	 */
	private function refuse(string $file, string $reason): void {
		$this->logger->warning(
			'Dossiq: VTH workflow template catalogue entry refused, ' . $reason,
			['app' => Application::APP_ID, 'file' => basename($file)]
		);
	}//end refuse()
}//end class
