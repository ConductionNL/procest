<?php

/**
 * Dossiq LHS Decision Table Lookup
 *
 * Answers "which intervention does the enforcement strategy prescribe for this
 * severity, behaviour and actor type" by evaluating the projected decision
 * table through OpenRegister, instead of by hand-indexing the matrix cells.
 *
 * WHY THIS EXISTS. The LHS matrix is a three-axis lookup yielding one value,
 * which is a decision table and nothing more exotic. OpenRegister already
 * carries one evaluator for those, whose own suite proves this exact shape
 * evaluates. dossiq indexed the cells into a "severity:behaviour:actorType"
 * dictionary and threw when the triple missed.
 *
 * That hand-rolled index was not merely duplicate work. It is what let the
 * shipped vocabulary split in half without anything noticing: the
 * recommendation schema offered an actorType no cell carried, and the
 * dictionary answered a miss the same way it answers genuinely bad input
 * (dossiq#1596). A declared table cannot hide that: its inputs are NAMED, so
 * the evaluator refuses what it cannot resolve rather than quietly missing.
 *
 * SOFT DEPENDENCY. The evaluator is resolved lazily through the container and
 * guarded by class_exists. An OpenRegister release that has not shipped it, or
 * an instance where the projection was never run, must degrade to the matrix
 * rather than take enforcement recommendations down with it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Vth
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

namespace OCA\Dossiq\Service\Vth;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Evaluates the projected LHS decision table.
 *
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
 */
class LhsDecisionTableLookup {
	use SearchesObjects;

	/**
	 * OpenRegister's decision-table evaluator.
	 */
	private const EVALUATOR = '\\OCA\\OpenRegister\\Service\\Dmn\\DecisionTableEvaluator';

	/**
	 * Provenance marker prefix the migrator writes into a projected table.
	 */
	private const MARKER_PREFIX = LhsMatrixDecisionTableMigrator::MARKER_PREFIX;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the register, schema and object service.
	 * @param ContainerInterface $container Resolves the OpenRegister evaluator lazily.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Look up the prescribed intervention for one triple.
	 *
	 * Returns null when this instance has no projected table for the matrix,
	 * which is the caller's signal to fall back rather than to fail. A table
	 * that EXISTS and refuses the triple is a different answer: that refusal is
	 * propagated as a miss, because a declared input the table cannot resolve
	 * is exactly the defect this lookup exists to surface.
	 *
	 * @param string $matrixId The stored matrix's own id.
	 * @param string $severity Severity axis value.
	 * @param string $behaviour Behaviour axis value.
	 * @param string $actorType Actor-type axis value.
	 *
	 * @return string|null The intervention, or null when no table answers here.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	public function intervention(
		string $matrixId,
		string $severity,
		string $behaviour,
		string $actorType,
	): ?string {
		$table = $this->tableFor(matrixId: $matrixId);
		if ($table === null) {
			return null;
		}

		$evaluator = $this->evaluator();
		if ($evaluator === null) {
			return null;
		}

		try {
			$result = $evaluator->evaluate(
				$table,
				[
					'severity' => $severity,
					'behaviour' => $behaviour,
					'actorType' => $actorType,
				]
			);
		} catch (Throwable $e) {
			// A refusal is information, not noise: it means the table could not
			// resolve a declared input, which is the class of defect the
			// dictionary used to swallow.
			$this->logger->warning(
				'Dossiq: the LHS decision table refused a triple',
				[
					'matrix' => $matrixId,
					'triple' => $severity . ':' . $behaviour . ':' . $actorType,
					'exception' => $e->getMessage(),
				]
			);

			return null;
		}

		$intervention = ($result['outputs']['intervention'] ?? null);
		if (is_string($intervention) === true && $intervention !== '') {
			return $intervention;
		}

		return null;
	}//end intervention()

	/**
	 * Find the enabled decision table projected from this matrix.
	 *
	 * Resolved by the provenance MARKER rather than by name or key: both are
	 * editable in the decision-table editor, and matching on one would make an
	 * ordinary rename silently detach the table from its matrix.
	 *
	 * @param string $matrixId The stored matrix's own id.
	 *
	 * @return array<string, mixed>|null The table, or null when absent or disabled.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function tableFor(string $matrixId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('decision_table_schema');

		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		$marker = (self::MARKER_PREFIX . $matrixId);

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => 200],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not read decision tables for the LHS lookup',
				['exception' => $e->getMessage()]
			);

			return null;
		}

		foreach ($rows as $row) {
			if (str_contains((string)($row['description'] ?? ''), $marker) === false) {
				continue;
			}

			// A DISABLED table is a projection somebody has not adopted yet.
			// Reading it anyway would make the enforcement answer depend on a
			// table an administrator deliberately left switched off.
			if (($row['enabled'] ?? false) !== true) {
				return null;
			}

			return $row;
		}

		return null;
	}//end tableFor()

	/**
	 * Resolve OpenRegister's evaluator, or null when it is not there.
	 *
	 * LAZY, and behind class_exists. Constructor-injecting a class from another
	 * app makes every consumer of this service fatal when that app is absent or
	 * mid-upgrade, which is how a deleted OpenRegister class once 403'd every
	 * object write across the fleet.
	 *
	 * @return object|null The evaluator.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function evaluator(): ?object {
		if (class_exists(self::EVALUATOR) === false) {
			return null;
		}

		try {
			$evaluator = $this->container->get(self::EVALUATOR);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: OpenRegister decision-table evaluator could not be resolved',
				['exception' => $e->getMessage()]
			);

			return null;
		}

		if (is_object($evaluator) === true && method_exists($evaluator, 'evaluate') === true) {
			return $evaluator;
		}

		return null;
	}//end evaluator()
}//end class
