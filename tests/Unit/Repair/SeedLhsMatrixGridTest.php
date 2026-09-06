<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The shipped Landelijke Handhavingsstrategie matrix must be a COMPLETE and
 * REACHABLE grid.
 *
 * LhsRecommendationService indexes cells by "severity:behaviour:actorType" and
 * THROWS when the triple misses, so a cell whose axis value is misspelled does
 * not degrade — it removes that combination from the product. This asserts the
 * seed against its own axes.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use PHPUnit\Framework\TestCase;

/**
 * Grid invariants for the shipped LHS matrix seed.
 */
class SeedLhsMatrixGridTest extends TestCase {

	/**
	 * The seeded matrix, decoded.
	 *
	 * @return array<string, mixed> The matrix.
	 */
	private function matrix(): array {
		$path = (__DIR__ . '/../../../lib/Settings/seed/lhs-matrix-2024.json');
		$this->assertFileExists($path);
		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded);

		return $this->findMatrix($decoded);

	}//end matrix()

	/**
	 * Locate the object carrying the axes, wherever the seed nests it.
	 *
	 * @param array<string, mixed> $node The node.
	 *
	 * @return array<string, mixed> The matrix.
	 */
	private function findMatrix(array $node): array {
		if (array_key_exists('actorTypeAxis', $node) === true) {
			return $node;
		}

		foreach ($node as $value) {
			if (is_array($value) === true) {
				$found = $this->findMatrix($value);
				if ($found !== []) {
					return $found;
				}
			}
		}

		return [];

	}//end findMatrix()

	/**
	 * Every cell names values that exist on their axis.
	 *
	 * 🔴 This is the assertion that was missing. The shipped seed labelled all
	 * twelve government cells `actorType: "government"` while the axis declared
	 * `overheid`, so `recommend()` threw "Geen LHS-cel gevonden" for every
	 * government actor — a quarter of the matrix, unreachable, with the axis
	 * still offering the option.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	public function testEveryCellValueExistsOnItsAxis(): void {
		$matrix = $this->matrix();
		$axes = [
			'severity' => $matrix['severityAxis'],
			'behaviour' => $matrix['behaviourAxis'],
			'actorType' => $matrix['actorTypeAxis'],
		];

		$offenders = [];
		foreach ($matrix['cells'] as $cell) {
			foreach ($axes as $key => $allowed) {
				$value = ($cell[$key] ?? null);
				if (in_array($value, $allowed, true) === false) {
					$offenders[] = ($key . '=' . var_export($value, true));
				}
			}
		}

		$this->assertSame([], array_values(array_unique($offenders)));

	}//end testEveryCellValueExistsOnItsAxis()

	/**
	 * Every axis value is reachable: it has at least one cell.
	 *
	 * The mirror of the test above. A cell pointing at a value that is not on
	 * the axis, and an axis value with no cell, are the same defect seen from
	 * the two ends, and either alone can be satisfied by a half fix.
	 *
	 * @return void
	 */
	public function testEveryAxisValueHasAtLeastOneCell(): void {
		$matrix = $this->matrix();

		foreach (['severity' => 'severityAxis', 'behaviour' => 'behaviourAxis', 'actorType' => 'actorTypeAxis'] as $key => $axisKey) {
			$used = [];
			foreach ($matrix['cells'] as $cell) {
				$used[] = ($cell[$key] ?? null);
			}

			foreach ($matrix[$axisKey] as $value) {
				$this->assertContains(
					$value,
					$used,
					sprintf('axis %s offers "%s" and no cell carries it, so recommend() throws for it', $axisKey, (string)$value)
				);
			}
		}

	}//end testEveryAxisValueHasAtLeastOneCell()

	/**
	 * The grid is complete: one cell per combination.
	 *
	 * @return void
	 */
	public function testTheGridIsComplete(): void {
		$matrix = $this->matrix();
		$expected = (count($matrix['severityAxis']) * count($matrix['behaviourAxis']) * count($matrix['actorTypeAxis']));

		$keys = [];
		foreach ($matrix['cells'] as $cell) {
			$keys[] = (($cell['severity'] ?? '') . ':' . ($cell['behaviour'] ?? '') . ':' . ($cell['actorType'] ?? ''));
		}

		$this->assertCount($expected, $matrix['cells']);
		$this->assertCount($expected, array_unique($keys), 'two cells share a triple, so one of them is unreachable');

	}//end testTheGridIsComplete()

}//end class
