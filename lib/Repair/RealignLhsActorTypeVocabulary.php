<?php

/**
 * Dossiq Realign LHS actorType Vocabulary Repair Step
 *
 * Rewrites stored `lhsRecommendation.actorType` values of `government` back to
 * `overheid` (dossiq#1596).
 *
 * WHAT WENT WRONG. `actorType` is not free vocabulary: it is one AXIS of the
 * Landelijke Handhavingsstrategie matrix, whose 48 cells are keyed
 * `severity:behaviour:actorType`. `RenameDutchValueDecisions` translated one
 * member of that axis, `overheid` -> `government`, and did not translate the
 * cells, which are stored as JSON inside the matrix's `cells` column and so
 * were never in reach of a column-level rename.
 *
 * The result was a vocabulary split down the middle. The recommendation schema
 * offered `government`; every matrix cell said `overheid`; and
 * `LhsRecommendationService::recommend()` throws when the triple misses. So a
 * quarter of the national enforcement strategy was unreachable, and it
 * presented as "Geen LHS-cel gevonden voor combinatie ..." — a message that
 * reads like bad input rather than a broken axis.
 *
 * The tell that this was a slip and not a decision: the other three axis
 * values (`burger`, `bedrijf`, `recidivist`) were never translated. A set is
 * translated whole or not at all.
 *
 * The rename entry is removed at source, so a fresh instance cannot acquire
 * this. This step exists for the instances that already ran it.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
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
 * Repair step restoring the LHS actorType axis vocabulary.
 *
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
 */
class RealignLhsActorTypeVocabulary implements IRepairStep {
	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * The dossiq register slug.
	 */
	private const REGISTER_SLUG = 'dossiq';

	/**
	 * The recommendation schema slug.
	 */
	private const SCHEMA_SLUG = 'lhsRecommendation';

	/**
	 * The value the bad rename produced.
	 */
	private const WRONG = 'government';

	/**
	 * The axis value every matrix cell actually uses.
	 */
	private const RIGHT = 'overheid';

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
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	public function getName(): string {
		return 'Realign Dossiq LHS actorType vocabulary (government -> overheid)';
	}//end getName()

	/**
	 * Rewrite every mis-translated recommendation.
	 *
	 * NEVER throws: an upgrade that dies here leaves the instance worse off
	 * than an unrepaired recommendation does.
	 *
	 * @param IOutput $output Output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('Dossiq: LHS actorType realignment skipped, OpenRegister unavailable.');
			return;
		}

		$repaired = 0;

		try {
			$this->withSystemIdentity(
				objectService: $objectService,
				work: function () use ($objectService, &$repaired): void {
					$repaired = $this->rewriteRows(objectService: $objectService);
				}
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: LHS actorType realignment failed',
				['exception' => $e->getMessage()]
			);
			$output->warning('Dossiq: LHS actorType realignment could not run: ' . $e->getMessage());
			return;
		}

		if ($repaired === 0) {
			$output->info('Dossiq: no LHS recommendations needed the actorType realignment.');
			return;
		}

		$output->info(sprintf('Dossiq: realigned actorType on %d LHS recommendation(s).', $repaired));
	}//end run()

	/**
	 * Find and rewrite the affected rows.
	 *
	 * Filters on the WRONG value rather than reading every recommendation: the
	 * step is idempotent because a repaired row no longer matches.
	 *
	 * @param object $objectService OpenRegister's object service.
	 *
	 * @return integer The number of rows rewritten.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function rewriteRows(object $objectService): int {
		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG,
			filters: ['actorType' => self::WRONG, '_limit' => 5000],
		);

		$repaired = 0;
		foreach ($rows as $row) {
			// Defensive: a filter the backend cannot express server-side would
			// return everything, and rewriting a `burger` row to `overheid`
			// would be a far worse bug than the one being fixed.
			if (($row['actorType'] ?? null) !== self::WRONG) {
				continue;
			}

			$row['actorType'] = self::RIGHT;

			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: self::REGISTER_SLUG,
				schema: self::SCHEMA_SLUG,
				object: $row,
			);

			if ($saved !== null) {
				$repaired++;
			}
		}

		return $repaired;
	}//end rewriteRows()
}//end class
