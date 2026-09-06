<?php

/**
 * Dossiq VTH catalogue report.
 *
 * Collects what happened to every entry in the VTH workflow-template catalogue
 * and writes it out as the summary an administrator reads.
 *
 * 🔴 A COUNT IS NOT A REPORT, AND THAT IS WHY THIS CLASS EXISTS. The seed step
 * used to print "0 seeded, 5 skipped (already present or unresolved)" and name
 * one of the five. An entry that never landed read exactly like one that landed
 * last time, so `toezichtbezoek` was dropped on every install for the life of
 * the catalogue and no line ever said so.
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

use OCP\Migration\IOutput;

/**
 * What happened to each VTH catalogue entry, and how it is reported.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */
class VthCatalogueReport {

	/**
	 * The outcomes recorded so far, in catalogue order.
	 *
	 * @var array<int, array{entry: string, outcome: string, reason: string}>
	 */
	private array $outcomes = [];

	/**
	 * Forget every recorded outcome.
	 *
	 * The container hands out one instance, so a step that runs twice in one
	 * process would otherwise report the first run's entries again.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function reset(): void {
		$this->outcomes = [];
	}//end reset()

	/**
	 * Record one entry's result.
	 *
	 * @param string $entry The catalogue entry, by slug or file name.
	 * @param string $outcome One of seeded|published|present|deprecated|skipped|crossLink|failed.
	 * @param string $reason What happened to it, in one sentence.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function record(string $entry, string $outcome, string $reason): void {
		$this->outcomes[] = ['entry' => $entry, 'outcome' => $outcome, 'reason' => $reason];
	}//end record()

	/**
	 * Write the summary, one line per catalogue entry.
	 *
	 * A run that left something undone closes with the command that finishes it.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function write(IOutput $output): void {
		$counts = [
			'seeded' => 0,
			'published' => 0,
			'present' => 0,
			'deprecated' => 0,
			'skipped' => 0,
			'crossLink' => 0,
			'failed' => 0,
		];
		foreach ($this->outcomes as $outcome) {
			$counts[$outcome['outcome']] = ($counts[$outcome['outcome']] ?? 0) + 1;
		}

		$output->info(
			'VTH workflow templates: ' . $counts['seeded'] . ' seeded, '
			. $counts['published'] . ' published from an earlier draft, '
			. $counts['present'] . ' already present, '
			. $counts['deprecated'] . ' present but retired, '
			. $counts['skipped'] . ' skipped, '
			. $counts['crossLink'] . ' cross-link, '
			. $counts['failed'] . ' failed. ' . count($this->outcomes) . ' catalogue entries in total.'
		);

		foreach ($this->outcomes as $outcome) {
			$output->info('  ' . $outcome['entry'] . ': ' . $outcome['reason']);
		}

		if (($counts['skipped'] + $counts['failed']) > 0) {
			$output->info(
				'Fix what the skipped and failed lines above name, then run `occ maintenance:repair` again.'
			);
		}

		if ($counts['deprecated'] > 0) {
			$output->info(
				'A retired entry above stays retired. Somebody turned it off, and this step will not turn it back on.'
			);
		}
	}//end write()

	/**
	 * The title of the definition a publish displaced, or an empty string.
	 *
	 * 🔴 THIS USED TO REPORT A DEPRECATION THAT SHOULD NEVER HAVE HAPPENED.
	 * The catalogue ships two entries against `handhavingszaak`, and under one
	 * published definition per CASE TYPE whichever the glob reached last retired
	 * the other. They are now two ROUTES through that case type, each with its
	 * own active definition, so a second route displaces nothing.
	 *
	 * What is left for this method to report is the real case: publishing a new
	 * version of a route that already had one. The caller reads that route's
	 * active definition BEFORE publishing and hands it here, so the summary can
	 * name what it replaced.
	 *
	 * @param array<string, mixed>|null $displaced The definition that was active on the same route before the publish.
	 * @param string $publishedId The uuid that was just published.
	 *
	 * @return string The displaced title, or '' when this publish displaced nothing.
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function displacedTitle(?array $displaced, string $publishedId): string {
		$title = (string)(($displaced['title'] ?? ''));
		if ($title === '' || (string)(($displaced['id'] ?? '')) === $publishedId) {
			return '';
		}

		return $title;
	}//end displacedTitle()

	/**
	 * The summary line for a template this run seeded and published.
	 *
	 * @param string $title The template's title.
	 * @param int $version The version that was published.
	 * @param string $variant The route it landed on.
	 * @param string $displacedTitle The previous version of that route, or ''.
	 * @param bool $isDefaultRoute Whether new cases on this case type follow it.
	 *
	 * @return string The sentence the administrator reads.
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function seededReason(
		string $title,
		int $version,
		string $variant,
		string $displacedTitle,
		bool $isDefaultRoute,
	): string {
		$reason = 'seeded and published as "' . $title . '" version ' . $version
			. ', on the "' . $variant . '" route.';

		if ($isDefaultRoute === true) {
			$reason .= ' New cases on this case type follow this route.';
		}

		if ($displacedTitle !== '') {
			$reason .= ' This replaced "' . $displacedTitle . '", the previous published version of that route.';
		}

		return $reason;
	}//end seededReason()

	/**
	 * The summary line for a catalogue entry that is present but retired.
	 *
	 * 🔑 THE SEEDER DOES NOT UNDO A DEPRECATION, AND SAYS SO HERE. A row reads
	 * `deprecated` whether the old one-per-case-type rule retired it or an
	 * administrator did, and the stored data cannot tell those apart.
	 * Republishing on sight would bring back a route somebody turned off, on an
	 * upgrade they did not ask for it on. So this names the way back instead,
	 * and leaves the decision with the person entitled to take it.
	 *
	 * @param string $title The template's title.
	 * @param string $variant The route it is on.
	 *
	 * @return string The sentence the administrator reads.
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function deprecatedReason(string $title, string $variant): string {
		return 'is present as "' . $title . '" on the "' . $variant
			. '" route, and it is retired. This step will not republish it, because it cannot tell'
			. ' a retirement somebody chose from one an older rule caused. To bring the route back,'
			. ' clone this definition on the case type\'s workflow tab and publish the copy.';
	}//end deprecatedReason()

	/**
	 * Render a list of names as a readable, quoted enumeration.
	 *
	 * @param array<int, string> $values The names.
	 *
	 * @return string The quoted list, or "none" when there are no names.
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function quotedList(array $values): string {
		if ($values === []) {
			return 'none';
		}

		return '"' . implode('", "', $values) . '"';
	}//end quotedList()
}//end class
