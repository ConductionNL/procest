<?php

/**
 * LHS Axis Vocabulary Tests
 *
 * The Landelijke Handhavingsstrategie matrix is a three-axis lookup, and
 * `LhsRecommendationService::recommend()` keys it as the literal string
 * "severity:behaviour:actorType". That makes the axis values a CONTRACT
 * between three separate files:
 *
 *   - the seed matrix, which declares the axes and the 48 cells;
 *   - the `lhsMatrix` schema, which stores them;
 *   - the `lhsRecommendation` schema, whose enums are what a caller may send.
 *
 * Nothing checked that the three agreed, and they stopped agreeing.
 * `RenameDutchValues` translated ONE member of the actorType axis,
 * `overheid` -> `government`, and could not reach the cells because they live
 * inside a JSON column rather than a column of their own. The recommendation
 * schema then offered a value no cell could match, so a quarter of the
 * national enforcement strategy threw "Geen LHS-cel gevonden" — a message that
 * reads like bad input rather than a broken axis (dossiq#1596).
 *
 * These tests are the check that was missing. They compare the files against
 * each other rather than against a hardcoded list, so they keep holding when
 * the vocabulary legitimately changes and fail the moment one file moves
 * without the others.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The LHS axis vocabulary must agree across every file that declares it.
 *
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
 */
class LhsAxisVocabularyTest extends TestCase {
	/**
	 * Read a JSON file from lib/Settings.
	 *
	 * @param string $relative Path relative to lib/Settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settingsJson(string $relative): array {
		$path = dirname(__DIR__, 3) . '/lib/Settings/' . $relative;
		$this->assertFileExists($path);

		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded, $relative . ' must be valid JSON');

		return $decoded;
	}//end settingsJson()

	/**
	 * The `actorType` enum a register descriptor offers for a recommendation.
	 *
	 * @param string $register The register descriptor filename.
	 *
	 * @return array<int, string>
	 */
	private function recommendationActorTypes(string $register): array {
		$schemas = $this->settingsJson($register)['components']['schemas'];
		$this->assertArrayHasKey('lhsRecommendation', $schemas);

		return $schemas['lhsRecommendation']['properties']['actorType']['enum'];
	}//end recommendationActorTypes()

	/**
	 * 🔴 The regression this file exists for.
	 *
	 * Every actorType a caller may send MUST be a value the matrix axis holds.
	 * A value outside it cannot key a cell, so the recommendation for that
	 * actor is unreachable — not wrong, unreachable, which is why no output
	 * ever looked incorrect.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	public function testEveryRecommendableActorTypeExistsOnTheMatrixAxis(): void {
		$axis = $this->settingsJson('seed/lhs-matrix-2024.json')['actorTypeAxis'];

		foreach (['dossiq_register.json', 'dossiq_mock_register.json'] as $register) {
			foreach ($this->recommendationActorTypes($register) as $actorType) {
				$this->assertContains(
					$actorType,
					$axis,
					$register . ' offers actorType "' . $actorType . '" which the LHS matrix axis '
					. 'does not carry, so no cell can ever match it'
				);
			}
		}
	}//end testEveryRecommendableActorTypeExistsOnTheMatrixAxis()

	/**
	 * And the converse: an axis value nobody may send is a dead row of the
	 * matrix. Without this, deleting a value from the enum would pass the test
	 * above while quietly retiring twelve cells.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	public function testEveryMatrixAxisValueIsRecommendable(): void {
		$axis = $this->settingsJson('seed/lhs-matrix-2024.json')['actorTypeAxis'];
		$enum = $this->recommendationActorTypes('dossiq_register.json');

		foreach ($axis as $actorType) {
			$this->assertContains(
				$actorType,
				$enum,
				'the LHS matrix carries cells for actorType "' . $actorType . '" that no caller '
				. 'can ask for, because the recommendation schema does not offer it'
			);
		}
	}//end testEveryMatrixAxisValueIsRecommendable()

	/**
	 * Every seeded cell keys on values its own axes carry.
	 *
	 * This is the same defect one level down: a cell naming an off-axis value
	 * is as unreachable as an enum naming one, and the decision-table migrator
	 * SKIPS a matrix in that state rather than projecting the defect forward.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	public function testEverySeededCellKeysOnItsOwnAxes(): void {
		$matrix = $this->settingsJson('seed/lhs-matrix-2024.json');

		$axes = [
			'severity' => $matrix['severityAxis'],
			'behaviour' => $matrix['behaviourAxis'],
			'actorType' => $matrix['actorTypeAxis'],
		];

		$this->assertNotEmpty($matrix['cells'], 'the seed must carry cells to check');

		foreach ($matrix['cells'] as $cell) {
			foreach ($axes as $property => $allowed) {
				$this->assertContains(
					$cell[$property],
					$allowed,
					'cell ' . json_encode($cell) . ' names ' . $property . ' "' . $cell[$property]
					. '", which its own axis does not carry'
				);
			}
		}
	}//end testEverySeededCellKeysOnItsOwnAxes()

	/**
	 * The rename map must not translate a member of the actorType axis.
	 *
	 * Pins the fix at its source. Restoring `overheid => government` would
	 * re-split the vocabulary on the next upgrade, and every other test here
	 * reads the POST-rename files, so none of them would notice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	public function testTheRenameMapLeavesTheActorTypeAxisAlone(): void {
		$axis = $this->settingsJson('seed/lhs-matrix-2024.json')['actorTypeAxis'];
		$renames = \OCA\Dossiq\Repair\RenameDutchValueDecisions::VALUE_MAP['actorType'] ?? [];

		foreach (array_keys($renames) as $from) {
			$this->assertNotContains(
				$from,
				$axis,
				'the rename map translates "' . (string)$from . '", which is an LHS actorType axis '
				. 'value — renaming one member of an axis splits it from the cells keyed on it'
			);
		}
	}//end testTheRenameMapLeavesTheActorTypeAxisAlone()

	/**
	 * The DEMO objects use the axis vocabulary too.
	 *
	 * The third declaration site, and the one this file originally missed.
	 * Two were fixed by hand and gate-101 found the third: a seeded
	 * `lhsRecommendation` still carried `government`, so a fresh instance
	 * shipped a demo record the enforcement lookup could never resolve.
	 *
	 * One fix is not the class. This asserts every site rather than the two
	 * that happened to be noticed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	public function testDemoRecommendationsUseTheAxisVocabulary(): void {
		$axis = $this->settingsJson('seed/lhs-matrix-2024.json')['actorTypeAxis'];
		$mock = $this->settingsJson('dossiq_mock_register.json');

		$checked = 0;
		foreach (($mock['components']['objects'] ?? []) as $object) {
			if (is_array($object) === false
				|| (($object['@self']['schema'] ?? null) !== 'lhsRecommendation')
			) {
				continue;
			}

			$checked++;
			$this->assertContains(
				($object['actorType'] ?? null),
				$axis,
				'a demo lhsRecommendation carries an actorType the LHS matrix axis does not, '
				. 'so the enforcement lookup could never resolve it'
			);
		}

		$this->assertGreaterThan(
			0,
			$checked,
			'no demo lhsRecommendation was inspected — a test that checks nothing passes for '
			. 'the wrong reason, so the shape of the mock register has moved'
		);

	}//end testDemoRecommendationsUseTheAxisVocabulary()
}//end class
