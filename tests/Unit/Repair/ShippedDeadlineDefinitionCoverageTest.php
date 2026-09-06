<?php

/**
 * Sweeps the shipped statutory terms against the shipped case types.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use PHPUnit\Framework\TestCase;

/**
 * A case type that declares a processingDeadline must have a term to run.
 *
 * The shipped termijn seed named three case-type slugs — omgevingsvergunning-
 * regulier, wmo-melding, woo-verzoek — that NO shipped seed ever creates. So
 * on a fresh install every definition was an orphan, no case could ever match
 * one, and no FlowTimer armed: zero flow_timers and zero deadlineInstance rows
 * across seven cases. Nothing failed; the clock simply never started.
 *
 * The rule below is mechanical rather than curated. A case type that declares
 * a `processingDeadline` is one where a statutory term runs, so it must have a
 * TermijnDefinitie, and that definition's duration must be the one the case
 * type itself declares. A case type with no processingDeadline (a toezichtzaak
 * has none) is exempt by its own data, not by a list someone maintains.
 *
 * @coversNothing
 */
class ShippedDeadlineDefinitionCoverageTest extends TestCase {

	/**
	 * Seed files that create case types on a fresh install.
	 *
	 * Each is driven by a repair step listed in info.xml's `<install>` block:
	 * CaseFlowSeedDataRepairStep and VthSeedDataRepairStep respectively.
	 *
	 * @var array<int, string>
	 */
	private const CASE_TYPE_SEEDS = [
		'/../../../lib/Settings/case_flow_seed_data.json',
		'/../../../lib/Settings/vth_seed_data.json',
	];

	/**
	 * Read and decode one shipped settings file.
	 *
	 * @param string $relative The path relative to this file.
	 *
	 * @return array<string, mixed> The decoded contents.
	 */
	private function readJson(string $relative): array {
		$decoded = json_decode((string)file_get_contents(__DIR__ . $relative), true);
		self::assertIsArray($decoded, 'The shipped file ' . $relative . ' must parse.');

		return $decoded;
	}//end readJson()

	/**
	 * Every case type a fresh install creates, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>> The case types.
	 */
	private function shippedCaseTypes(): array {
		$caseTypes = [];
		foreach (self::CASE_TYPE_SEEDS as $seed) {
			foreach (($this->readJson($seed)['caseTypes'] ?? []) as $caseType) {
				$slug = trim((string)($caseType['slug'] ?? ($caseType['identifier'] ?? '')));
				if ($slug === '') {
					continue;
				}

				$caseTypes[$slug] = $caseType;
			}
		}

		return $caseTypes;
	}//end shippedCaseTypes()

	/**
	 * The shipped TermijnDefinities, keyed by the case-type slug they bind to.
	 *
	 * @return array<string, array<string, mixed>> The definitions.
	 */
	private function shippedDefinitions(): array {
		$definitions = [];
		foreach (($this->readJson('/../../../lib/Settings/termijnbewaking_seed_data.json')['termijnDefinities'] ?? []) as $definition) {
			$slug = trim((string)($definition['caseType'] ?? ''));
			if ($slug === '') {
				continue;
			}

			$definitions[$slug] = $definition;
		}

		return $definitions;
	}//end shippedDefinitions()

	/**
	 * Whole days in an ISO-8601 `P{n}D` duration, or null.
	 *
	 * @param mixed $duration The declared duration.
	 *
	 * @return int|null The days.
	 */
	private function days(mixed $duration): ?int {
		if (is_string($duration) !== true || preg_match('/^P(\d+)D$/', trim($duration), $matches) !== 1) {
			return null;
		}

		return (int)$matches[1];
	}//end days()

	/**
	 * THE SWEEP: no deadline-bearing case type may ship without a term.
	 *
	 * @return void
	 */
	public function testEveryShippedCaseTypeWithADeadlineHasATermijnDefinitie(): void {
		$caseTypes = $this->shippedCaseTypes();
		$definitions = $this->shippedDefinitions();

		self::assertNotSame([], $caseTypes, 'The shipped seeds must create case types, or this sweep is vacuous.');
		self::assertNotSame([], $definitions, 'The shipped seed must create TermijnDefinities, or this sweep is vacuous.');

		$uncovered = [];
		foreach ($caseTypes as $slug => $caseType) {
			if ($this->days($caseType['processingDeadline'] ?? null) === null) {
				// No statutory beslistermijn declared; a toezicht- or
				// handhavingszaak legitimately runs without one.
				continue;
			}

			if (array_key_exists($slug, $definitions) === true) {
				continue;
			}

			$uncovered[] = $slug;
		}

		self::assertSame(
			[],
			$uncovered,
			"These shipped case types declare a processingDeadline but no shipped TermijnDefinitie binds to them,\n"
			. "so a case of that type starts NO statutory clock on a fresh install and nothing says so.\n"
			. "A definition binds by case-type SLUG (deadlineDefinition.caseType), so add one to\n"
			. "lib/Settings/termijnbewaking_seed_data.json for:\n - "
			. implode("\n - ", $uncovered)
		);
	}//end testEveryShippedCaseTypeWithADeadlineHasATermijnDefinitie()

	/**
	 * A definition's duration must be the one its case type declares.
	 *
	 * Two numbers for the same statutory term is a defect either way round —
	 * the dashboard shows one deadline while the timer fires on the other.
	 *
	 * @return void
	 */
	public function testEachDefinitionMatchesItsCaseTypesDeclaredDeadline(): void {
		$definitions = $this->shippedDefinitions();

		$mismatched = [];
		foreach ($this->shippedCaseTypes() as $slug => $caseType) {
			$declared = $this->days($caseType['processingDeadline'] ?? null);
			$definition = ($definitions[$slug] ?? null);
			if ($declared === null || $definition === null) {
				continue;
			}

			$configured = (int)($definition['standardDurationDays'] ?? 0);
			if ($configured !== $declared) {
				$mismatched[] = $slug . ': case type says ' . $declared . ' days, definition says ' . $configured;
			}

			$extension = $this->days($caseType['extensionDuration'] ?? null);
			if ($extension !== null && (int)($definition['extensionCapacity'] ?? 0) !== $extension) {
				$mismatched[] = $slug . ': case type allows ' . $extension . ' extension days, definition allows '
					. (int)($definition['extensionCapacity'] ?? 0);
			}
		}

		self::assertSame([], $mismatched, "Shipped term durations disagree with the case types they bind to:\n - " . implode("\n - ", $mismatched));
	}//end testEachDefinitionMatchesItsCaseTypesDeclaredDeadline()

	/**
	 * At least one shipped definition binds to a shipped case type.
	 *
	 * The measured failure state was that NONE did, which every other
	 * assertion in this file would have passed vacuously.
	 *
	 * @return void
	 */
	public function testAtLeastOneShippedDefinitionBindsToAShippedCaseType(): void {
		$bound = array_intersect(array_keys($this->shippedDefinitions()), array_keys($this->shippedCaseTypes()));

		self::assertNotSame(
			[],
			$bound,
			'Not one shipped TermijnDefinitie binds to a case type a fresh install creates, so no term can ever start.'
		);
	}//end testAtLeastOneShippedDefinitionBindsToAShippedCaseType()

}//end class
