<?php

/**
 * Dossiq Realign Statutory Vocabulary Repair Step
 *
 * Moves the values `RenameDutchValues` anglicised out of the ZGW and StUF
 * vocabularies back to the spelling their schema declares (dossiq#1841).
 *
 * WHAT WENT WRONG. `RenameDutchValueDecisions::VALUE_MAP` is keyed by property
 * and applied by COLUMN, against no schema at all, to every OpenRegister shard
 * table on the instance. Seventeen of its entries reached four properties whose
 * vocabulary is a standard's and is therefore Dutch by statute:
 * `confidentiality`, `vertrouwelijkheidaanduiding`, `stufMessage.status` and
 * `zaaksysteemMapping.synchronisationStatus`. The map's own docblock had always
 * excluded exactly these, and the map contradicted it.
 *
 * The tell that this was a slip and not a decision: the confidentiality map
 * covered five of the eight statutory levels, leaving `zaakvertrouwelijk`,
 * `vertrouwelijk` and `confidentieel` in Dutch. A set is translated whole or
 * not at all.
 *
 * WHY IT MATTERED. Every rewritten value is one its own schema refuses, so the
 * row could no longer be saved — a case handler opening a demo case and
 * changing anything got a validation refusal on a field they had not touched.
 * Worse for the confidentiality pair: `InformatieobjectAccessGuard` ranks the
 * Dutch eight and fails CLOSED on a value it cannot rank, so `openbaar` became
 * `public` and every public document was treated as the most secret one this
 * app holds, while `ZgwAuthMiddleware::isConfidentialityAllowed()` answered
 * false for every ZGW consumer.
 *
 * The map entries are removed at source, so a fresh instance cannot acquire
 * this. This step exists for the instances that already ran them.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step restoring the statutory ZGW and StUF vocabularies.
 *
 * @spec exclude Corrective data migration for the Dutch-to-English vocabulary change.
 */
class RealignStatutoryVocabulary implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * The dossiq register slug.
	 */
	private const REGISTER_SLUG = 'dossiq';

	/**
	 * Schema slug => property => the wrong English value => the declared Dutch one.
	 *
	 * 🔴 SCHEMA-SCOPED, UNLIKE THE MIGRATION THAT CAUSED THIS. Reversing by
	 * column would repeat the original mistake in the other direction: `status`
	 * is a column on many schemas and `sent` is a perfectly good value on some
	 * of them, so a blanket `sent` -> `verzonden` would corrupt rows this step
	 * exists to protect. Every pair below is addressed through its own schema.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private const REALIGNMENTS = [
		'case' => ['confidentiality' => self::CONFIDENTIALITY],
		'caseType' => ['confidentiality' => self::CONFIDENTIALITY],
		'document' => ['confidentiality' => self::CONFIDENTIALITY],
		'documentType' => ['confidentiality' => self::CONFIDENTIALITY],
		'informatieobject' => ['vertrouwelijkheidaanduiding' => self::CONFIDENTIALITY],
		'informatieobjecttype' => ['vertrouwelijkheidaanduiding' => self::CONFIDENTIALITY],
		'stufMessage' => [
			'status' => [
				'sent' => 'verzonden',
				'confirmed' => 'bevestigd',
				'error' => 'fout',
				'awaiting_retry' => 'wacht_op_retry',
			],
		],
		'zaaksysteemMapping' => [
			'synchronisationStatus' => [
				'error' => 'fout',
				'cancelled' => 'geannuleerd',
				'waiting' => 'wacht',
			],
		],
	];

	/**
	 * The ZGW Vertrouwelijkheidaanduiding, restored to the statutory spelling.
	 *
	 * Only the five the migration touched appear here. The other three were
	 * never translated, which is how the slip was spotted.
	 *
	 * @var array<string, string>
	 */
	private const CONFIDENTIALITY = [
		'public' => 'openbaar',
		'restricted_public' => 'beperkt_openbaar',
		'internal' => 'intern',
		'secret' => 'geheim',
		'top_secret' => 'zeer_geheim',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves OpenRegister's ObjectService.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settingsService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec exclude Corrective data migration for the Dutch-to-English vocabulary change.
	 */
	public function getName(): string {
		return 'Realign Dossiq statutory ZGW and StUF vocabularies';
	}//end getName()

	/**
	 * Move every mis-translated value back.
	 *
	 * NEVER throws: an upgrade that dies here leaves the instance worse off
	 * than a value left in English does.
	 *
	 * @param IOutput $output Output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec exclude Corrective data migration for the Dutch-to-English vocabulary change.
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('Dossiq: statutory vocabulary realignment skipped, OpenRegister unavailable.');
			return;
		}

		$repaired = 0;

		try {
			$this->withSystemIdentity(
				objectService: $objectService,
				work: function () use ($objectService, &$repaired): void {
					$repaired = $this->realignAll(objectService: $objectService);
				}
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: statutory vocabulary realignment failed',
				['exception' => $e->getMessage()]
			);
			$output->warning('Dossiq: statutory vocabulary realignment could not run: ' . $e->getMessage());
			return;
		}

		if ($repaired === 0) {
			$output->info('Dossiq: no rows needed the statutory vocabulary realignment.');
			return;
		}

		$output->info(sprintf('Dossiq: realigned the statutory vocabulary on %d row(s).', $repaired));
	}//end run()

	/**
	 * Walk every schema, property and value pair.
	 *
	 * @param object $objectService OpenRegister's object service.
	 *
	 * @return integer The number of rows rewritten.
	 */
	private function realignAll(object $objectService): int {
		$repaired = 0;

		foreach (self::REALIGNMENTS as $schema => $properties) {
			foreach ($properties as $property => $values) {
				foreach ($values as $wrong => $right) {
					$repaired += $this->realign(
						objectService: $objectService,
						schema: (string)$schema,
						property: (string)$property,
						wrong: (string)$wrong,
						right: $right
					);
				}
			}
		}

		return $repaired;
	}//end realignAll()

	/**
	 * Rewrite one wrong value on one schema's property.
	 *
	 * Filters on the WRONG value rather than reading every row, so the step is
	 * idempotent: a repaired row no longer matches.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $schema The schema slug.
	 * @param string $property The property to rewrite.
	 * @param string $wrong The value the bad rename produced.
	 * @param string $right The value the schema declares.
	 *
	 * @return integer The number of rows rewritten.
	 */
	private function realign(
		object $objectService,
		string $schema,
		string $property,
		string $wrong,
		string $right,
	): int {
		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: self::REGISTER_SLUG,
			schema: $schema,
			filters: [
				$property => $wrong,
				'_limit' => 5000,
			],
		);

		$repaired = 0;
		foreach ($rows as $row) {
			// Defensive: a filter the backend cannot express server-side would
			// return everything, and rewriting a `zaakvertrouwelijk` case to
			// `openbaar` would be a far worse bug than the one being fixed.
			if (($row[$property] ?? null) !== $wrong) {
				continue;
			}

			$row[$property] = $right;

			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: self::REGISTER_SLUG,
				schema: $schema,
				object: $row,
			);

			if ($saved !== null) {
				$repaired++;
			}
		}

		return $repaired;
	}//end realign()
}//end class
