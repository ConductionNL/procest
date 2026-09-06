<?php

/**
 * LhsDecisionTableLookup Tests
 *
 * The lookup sits in the enforcement path: what it answers becomes the
 * intervention recorded against a citizen or a business. So the questions worth
 * asking are not "does it read a table" but "when does it decline to answer",
 * because every decline hands the question back to the matrix and a decline it
 * should not have made is invisible in the result.
 *
 * The evaluator here is OpenRegister's own, a verbatim copy kept under
 * tests/Stubs. That matters: a hand-written fake evaluator would let these
 * tests agree with whatever this class happens to send it, which is the shape
 * of a fake that cannot fail.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Vth
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

namespace OCA\Dossiq\Tests\Unit\Service\Vth;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Vth\LhsDecisionTableLookup;
use OCA\Dossiq\Service\Vth\LhsMatrixDecisionTableMigrator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The projected decision table, and when it declines to answer.
 *
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
 */
class LhsDecisionTableLookupTest extends TestCase {
	/**
	 * Decision tables the fake register holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $tables = [];

	/**
	 * Whether the container can resolve the evaluator.
	 *
	 * @var bool
	 */
	private bool $evaluatorAvailable = true;

	/**
	 * A table projected from matrix `m-1`, carrying one rule.
	 *
	 * @param bool $enabled Whether the table is enabled.
	 *
	 * @return array<string, mixed> The table.
	 */
	private function table(bool $enabled = true): array {
		return [
			'id' => 'table-1',
			'name' => 'LHS 2024',
			'description' => 'Projected. ' . LhsMatrixDecisionTableMigrator::MARKER_PREFIX . 'm-1',
			'hitPolicy' => 'UNIQUE',
			'enabled' => $enabled,
			'inputs' => [
				['name' => 'severity', 'type' => 'string'],
				['name' => 'behaviour', 'type' => 'string'],
				['name' => 'actorType', 'type' => 'string'],
			],
			'outputs' => [['name' => 'intervention', 'type' => 'string']],
			'rules' => [
				[
					'id' => 'gering:goedwillend:overheid',
					// EXACTLY what LhsMatrixDecisionTableMigrator writes: bare
					// values, not quoted FEEL literals. A fixture that quoted
					// them would prove the evaluator works on a shape the
					// projection never produces.
					'inputEntries' => ['gering', 'goedwillend', 'overheid'],
					'outputEntries' => ['warning'],
				],
			],
		];
	}//end table()

	/**
	 * Build the lookup over a fake register and the real evaluator.
	 *
	 * @return LhsDecisionTableLookup The lookup.
	 */
	private function lookup(): LhsDecisionTableLookup {
		$tables = &$this->tables;

		$objectService = new class($tables) {
			/**
			 * @param array<int, array<string, mixed>> $tables Stored tables.
			 */
			public function __construct(private array &$tables) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				return $this->tables;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = ['register' => 'dossiq', 'decision_table_schema' => 'decisionTable'];

				return ($map[$key] ?? $default);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		if ($this->evaluatorAvailable === true) {
			// OpenRegister's OWN evaluator, kept verbatim under tests/Stubs. A
			// hand-written fake would agree with whatever this class sends it.
			$evaluatorClass = '\\OCA\\OpenRegister\\Service\\Dmn\\DecisionTableEvaluator';
			$container->method('get')->willReturn(new $evaluatorClass());
		} else {
			$container->method('get')->willThrowException(new RuntimeException('not registered'));
		}

		return new LhsDecisionTableLookup(
			$settings,
			$container,
			$this->createMock(LoggerInterface::class)
		);
	}//end lookup()

	/**
	 * An enabled projected table answers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md#requirement-req-ldt-005-the-evaluator-is-the-lookup
	 */
	public function testAnEnabledProjectionAnswers(): void {
		$this->tables = [$this->table()];

		$this->assertSame(
			'warning',
			$this->lookup()->intervention('m-1', 'gering', 'goedwillend', 'overheid')
		);
	}//end testAnEnabledProjectionAnswers()

	/**
	 * 🔴 A DISABLED table is not consulted.
	 *
	 * Reading it anyway would make the enforcement answer depend on a table an
	 * administrator deliberately switched off.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md#requirement-req-ldt-005-the-evaluator-is-the-lookup
	 */
	public function testADisabledProjectionIsNotConsulted(): void {
		$this->tables = [$this->table(enabled: false)];

		$this->assertNull(
			$this->lookup()->intervention('m-1', 'gering', 'goedwillend', 'overheid')
		);
	}//end testADisabledProjectionIsNotConsulted()

	/**
	 * No projection at all declines, so the matrix answers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md#requirement-req-ldt-005-the-evaluator-is-the-lookup
	 */
	public function testNoProjectionDeclines(): void {
		$this->tables = [];

		$this->assertNull(
			$this->lookup()->intervention('m-1', 'gering', 'goedwillend', 'overheid')
		);
	}//end testNoProjectionDeclines()

	/**
	 * A table belonging to ANOTHER matrix is not used.
	 *
	 * The marker carries the matrix id, and taking any table that happened to
	 * be there would answer one matrix's question from another's rules.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md#requirement-req-ldt-005-the-evaluator-is-the-lookup
	 */
	public function testAnotherMatrixsTableIsNotUsed(): void {
		$this->tables = [$this->table()];

		$this->assertNull(
			$this->lookup()->intervention('m-2', 'gering', 'goedwillend', 'overheid')
		);
	}//end testAnotherMatrixsTableIsNotUsed()

	/**
	 * The table is resolved by MARKER, not by name.
	 *
	 * A name is editable in the decision-table editor, so matching on one would
	 * detach the table from its matrix the moment somebody renamed it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md#requirement-req-ldt-005-the-evaluator-is-the-lookup
	 */
	public function testARenamedTableStillResolves(): void {
		$table = $this->table();
		$table['name'] = 'Renamed by an administrator';
		$this->tables = [$table];

		$this->assertSame(
			'warning',
			$this->lookup()->intervention('m-1', 'gering', 'goedwillend', 'overheid')
		);
	}//end testARenamedTableStillResolves()

	/**
	 * A triple the table cannot resolve declines rather than inventing one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md#requirement-req-ldt-005-the-evaluator-is-the-lookup
	 */
	public function testAnUnmatchedTripleDeclines(): void {
		$this->tables = [$this->table()];

		$this->assertNull(
			$this->lookup()->intervention('m-1', 'ernstig', 'crimineel', 'burger')
		);
	}//end testAnUnmatchedTripleDeclines()

	/**
	 * With no evaluator resolvable, the lookup declines instead of failing.
	 *
	 * An OpenRegister release that has not shipped the evaluator must degrade
	 * to the matrix, not take enforcement recommendations down with it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md#requirement-req-ldt-005-the-evaluator-is-the-lookup
	 */
	public function testAnUnresolvableEvaluatorDeclines(): void {
		$this->tables = [$this->table()];
		$this->evaluatorAvailable = false;

		$this->assertNull(
			$this->lookup()->intervention('m-1', 'gering', 'goedwillend', 'overheid')
		);
	}//end testAnUnresolvableEvaluatorDeclines()

	/**
	 * An unconfigured instance declines without reaching the register.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md#requirement-req-ldt-005-the-evaluator-is-the-lookup
	 */
	public function testAnUnconfiguredInstanceDeclines(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('');

		$lookup = new LhsDecisionTableLookup(
			$settings,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertNull($lookup->intervention('m-1', 'gering', 'goedwillend', 'overheid'));
	}//end testAnUnconfiguredInstanceDeclines()
}//end class
