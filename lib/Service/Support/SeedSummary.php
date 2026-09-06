<?php

/**
 * The tally a seed run reports back to its caller.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
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

namespace OCA\Dossiq\Service\Support;

/**
 * Counts what a seed run created, skipped and could not write.
 *
 * It exists because the counting is the part that used to lie. Every refused
 * write was logged and then dropped, so a run that wrote nothing returned
 * `success: true, caseTypes: 0` — the same line an already-seeded instance
 * produces, which is why an empty register looked like an idempotent no-op
 * for as long as it did. Holding the tally in one object keeps "how many
 * were refused" and "was this a success" impossible to answer separately.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class SeedSummary {
	/**
	 * Objects created, keyed by kind.
	 *
	 * @var array<string, int>
	 */
	private array $created = [
		'caseTypes'   => 0,
		'statusTypes' => 0,
		'roleTypes'   => 0,
		'workflows'   => 0,
		'skipped'     => 0,
	];

	/**
	 * Writes the run could not perform.
	 *
	 * @var int
	 */
	private int $failed = 0;

	/**
	 * Fold one case type's result into the tally.
	 *
	 * @param array<string, int> $result The per-case-type counts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function addCaseTypeResult(array $result): void {
		foreach (array_keys($this->created) as $kind) {
			$this->created[$kind] += ($result[$kind] ?? 0);
		}
	}//end addCaseTypeResult()

	/**
	 * Record one refused write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function recordFailure(): void {
		$this->failed++;
	}//end recordFailure()

	/**
	 * Whether every write the run attempted landed.
	 *
	 * @return bool True when nothing was refused.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function isClean(): bool {
		return $this->failed === 0;
	}//end isClean()

	/**
	 * The tally in the shape callers already consume.
	 *
	 * A refused write makes the run unsuccessful, so a caller cannot read
	 * `caseTypes: 0` as "nothing left to do".
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function toArray(): array {
		$summary = ['success' => $this->isClean()] + $this->created + ['failed' => $this->failed];

		if ($this->isClean() === false) {
			$summary['message'] = $this->failed . ' object write(s) refused; see the log for each refusal';
		}

		return $summary;
	}//end toArray()
}//end class
