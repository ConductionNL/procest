<?php

/**
 * Repair step — translate stored Dutch enum VALUES to English.
 *
 * The sibling steps RenameDutchColumns and RenameDutchDirectionValues move the
 * COLUMNS and the single `direction` column's values. This one moves what is
 * IN them. Both are needed and neither substitutes for the other: after the
 * schema starts declaring `ACTIVE`, a row still holding `ACTIEF` does not error
 * — a filter on the new value simply returns null, and the caller reads null as
 * "nothing to do". That silence is the whole reason this step exists.
 *
 * All logic lives in RenameDutchValueDecisions and all storage behind
 * ValueMigrationPort, so this class is wiring that can be tested with a fake.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Rewrites stored Dutch enum values to their English replacements.
 *
 * @spec exclude Data migration for the Dutch-to-English vocabulary change.
 */
class RenameDutchValues implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param ValueMigrationPort $gateway Storage seam.
	 * @param RenameDutchValueDecisions $decisions Pure predicates.
	 */
	public function __construct(
		private readonly ValueMigrationPort $gateway,
		private readonly RenameDutchValueDecisions $decisions,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec exclude Data migration for the Dutch-to-English vocabulary change.
	 */
	public function getName(): string {
		return 'Translate stored dossiq Dutch enum values to English';
	}//end getName()

	/**
	 * Rewrite the stored values, one column at a time.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude Data migration for the Dutch-to-English vocabulary change.
	 */
	public function run(IOutput $output): void {
		$tables = $this->gateway->shardTables();
		if ($tables === []) {
			$output->info($this->decisions->nothingToDoMessage());
			return;
		}

		$updated = 0;
		foreach ($tables as $table) {
			$columns = $this->gateway->columnsOf($table);
			if ($columns === []) {
				continue;
			}

			$planned = $this->decisions->plannedRewrites(
				valueMap: RenameDutchValueDecisions::VALUE_MAP,
				columns: $columns
			);

			foreach ($planned as $rewrite) {
				$updated += $this->gateway->rewrite(
					table: $table,
					column: $rewrite['column'],
					old: $rewrite['old'],
					new: $rewrite['new']
				);
			}
		}

		$output->info($this->decisions->summaryMessage(updated: $updated));

	}//end run()
}//end class
