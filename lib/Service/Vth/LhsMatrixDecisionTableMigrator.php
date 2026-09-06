<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Dossiq\Service\Vth
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Vth;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Project each LHS matrix onto a decision table.
 *
 * The Landelijke Handhavingsstrategie matrix is a three-axis lookup: severity
 * by behaviour by actor type, yielding an intervention. That is a decision
 * table, and openregister#3186 gave the fleet one evaluator for those, whose
 * own suite proves this exact shape evaluates
 * (`testTheLhsMatrixShapeEvaluates`).
 *
 * dossiq meanwhile indexes the cells by hand into a
 * "severity:behaviour:actorType" dictionary and throws when the triple misses.
 * That hand-rolled lookup is what let the shipped matrix label all twelve of
 * its government cells `government` while the axis said `overheid`, leaving a
 * quarter of the strategy unreachable and nothing to notice (dossiq#1596). A
 * decision table cannot hide that the same way: its inputs are declared, and
 * the evaluator refuses a table it cannot resolve rather than silently missing.
 *
 * 🔴 THE PROJECTION NOW ARRIVES ENABLED, which is what phase 2 means and where
 * this parts company with its two siblings. `LhsRecommendationService` asks the
 * table first and falls back to the matrix only where no projection exists, so
 * a disabled table would not be cautious: it would make the migration a no-op
 * that reports success.
 *
 * A re-run RESOLVES the existing table by marker and updates it, rather than
 * writing a second one. If its rules have been edited since projection the run
 * REFUSES rather than overwriting: the projection is one-way, and the matrix no
 * longer has a settings page, so an overwrite would replace an administrator's
 * work with a source they cannot even read.
 *
 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
 */
class LhsMatrixDecisionTableMigrator {

	use SearchesObjects;

	/**
	 * Provenance marker written into the projected table's description.
	 *
	 * Resolved by marker rather than by name: a name is editable, and a re-run
	 * matching on one would mint a second table the moment somebody renamed
	 * the first.
	 *
	 * @var string
	 */
	public const MARKER_PREFIX = 'dossiq:lhsMatrix:';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register/schema configuration.
	 * @param LoggerInterface $logger          The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Project every stored matrix onto a decision table.
	 *
	 * @param IUser   $user   The owner the tables are written as.
	 * @param boolean $dryRun Report without writing.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	public function migrate(IUser $user, bool $dryRun): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return $this->emptySummary(note: 'OpenRegister is not available.');
		}

		// The whole migration runs AS the given user: a written table inherits
		// its owner and organisation from whoever wrote it, permanently.
		if (method_exists($objectService, 'runAs') === false) {
			return $this->emptySummary(note: 'OpenRegister exposes no runAs(); the migration needs an owner for the tables it writes.');
		}

		return $objectService->runAs(
			$user,
			fn (): array => $this->migrateAll(objectService: $objectService, dryRun: $dryRun)
		);

	}//end migrate()

	/**
	 * An empty summary carrying the reason nothing happened.
	 *
	 * @param string $note Why the run did nothing.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function emptySummary(string $note): array {
		return ['note' => $note, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'rows' => []];

	}//end emptySummary()

	/**
	 * Project every matrix, returning the summary.
	 *
	 * @param object  $objectService OpenRegister's object service.
	 * @param boolean $dryRun        Report only.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function migrateAll(object $objectService, bool $dryRun): array {
		$register = (string)$this->settingsService->getConfigValue('register');
		$tableSchema = (string)$this->settingsService->getConfigValue('decision_table_schema');
		$matrices = $this->fetchMatrices(objectService: $objectService, register: $register);

		$counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
		$rows = [];

		foreach ($matrices as $matrix) {
			$row = $this->migrateOne(
				matrix: $matrix,
				objectService: $objectService,
				register: $register,
				tableSchema: $tableSchema,
				dryRun: $dryRun,
			);

			if (array_key_exists($row['outcome'], $counts) === true) {
				$counts[$row['outcome']] = ($counts[$row['outcome']] + 1);
			}

			$rows[] = $row;
		}

		return ($counts + ['total' => count($matrices), 'rows' => $rows]);

	}//end migrateAll()

	/**
	 * Project one matrix.
	 *
	 * @param array<string, mixed> $matrix        The stored matrix.
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param string               $tableSchema   The decision-table schema.
	 * @param boolean              $dryRun        Report only.
	 *
	 * @return array{outcome: string, marker: string, detail: string} The outcome row.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function migrateOne(array $matrix, object $objectService, string $register, string $tableSchema, bool $dryRun): array {
		$id = (string)($matrix['id'] ?? ($matrix['@self']['id'] ?? ''));
		$marker = (self::MARKER_PREFIX . $id);

		if ($id === '' || $tableSchema === '') {
			return ['outcome' => 'failed', 'marker' => $marker, 'detail' => 'no matrix id, or no decision-table schema configured'];
		}

		$table = $this->tableFor(matrix: $matrix, marker: $marker);
		if ($table === null) {
			// A matrix whose cells name values absent from its own axes cannot
			// be projected honestly: the rule would be unreachable in the table
			// exactly as the cell is unreachable in the matrix, and projecting
			// it would carry the defect across while looking like a migration
			// that worked. dossiq#1596 is that defect.
			return ['outcome' => 'skipped', 'marker' => $marker, 'detail' => 'a cell names a value that is not on its axis'];
		}

		$existing = $this->existingTable(
			objectService: $objectService,
			register: $register,
			tableSchema: $tableSchema,
			marker: $marker
		);

		$outcome = 'created';
		if ($existing !== null) {
			// 🔴 AN EDITED TABLE IS NOT OVERWRITTEN.
			//
			// The projection is ONE-WAY. Once the table is the surface an
			// administrator authors enforcement through, a re-run that rewrote
			// its rules would silently discard their work and replace it with
			// whatever the legacy matrix still says — and the matrix no longer
			// has a settings page, so they could not even see what it said.
			//
			// Rules that differ from the projection mean somebody changed them.
			// Refuse, name it, and let a human decide.
			if ($this->rulesDiffer(existing: $existing, projected: $table) === true) {
				return [
					'outcome' => 'skipped',
					'marker' => $marker,
					'detail' => 'the projected table has been edited since it was created; '
						. 'refusing to overwrite it',
				];
			}

			$outcome = 'updated';
			$table['id'] = (string)($existing['id'] ?? '');
		}

		if ($dryRun === true) {
			return ['outcome' => $outcome, 'marker' => $marker, 'detail' => sprintf('%d rule(s)', count($table['rules']))];
		}

		try {
			$objectService->saveObject(object: $table, register: $register, schema: $tableSchema);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not project an LHS matrix onto a decision table',
				['app' => Application::APP_ID, 'marker' => $marker, 'exception' => $e->getMessage()]
			);

			return ['outcome' => 'failed', 'marker' => $marker, 'detail' => $e->getMessage()];
		}

		return ['outcome' => $outcome, 'marker' => $marker, 'detail' => sprintf('%d rule(s)', count($table['rules']))];

	}//end migrateOne()

	/**
	 * Find the table already projected from this matrix, if any.
	 *
	 * Resolved by the provenance MARKER, never by name or key: both are
	 * editable in the decision-table editor, and matching on one would mint a
	 * second table the moment somebody renamed the first.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param string $tableSchema   The decision-table schema.
	 * @param string $marker        The provenance marker.
	 *
	 * @return array<string, mixed>|null The existing table.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function existingTable(object $objectService, string $register, string $tableSchema, string $marker): ?array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $tableSchema,
				filters: ['_limit' => 200],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not read existing decision tables',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);

			return null;
		}

		foreach ($rows as $row) {
			if (str_contains((string)($row['description'] ?? ''), $marker) === true) {
				return $row;
			}
		}

		return null;

	}//end existingTable()

	/**
	 * Whether an existing table's rules differ from the projection.
	 *
	 * Compares the DECISION each rule encodes: its id, its inputs and its
	 * outputs. Not the annotation, which is a note rather than a decision, and
	 * not the surrounding table, because a renamed table or a reworded
	 * description is an administrator's business.
	 *
	 * 🔴 IT CANNOT BE A BYTE COMPARISON, and the first version was.
	 * OpenRegister DROPS an empty-string property on save, so a rule projected
	 * with `annotation: ""` comes back with no `annotation` key at all. A
	 * json_encode comparison therefore called every table edited the moment it
	 * had been stored once — measured against a running instance, all 48 rules
	 * "differed" immediately after the run that wrote them. A guard that
	 * refuses every re-run is not cautious, it is broken, and it would have
	 * read to an administrator as the migration being permanently stuck.
	 *
	 * Keyed by rule id and sorted, so a reordering that preserves every
	 * decision is not an edit either.
	 *
	 * @param array<string, mixed> $existing  The stored table.
	 * @param array<string, mixed> $projected The freshly projected table.
	 *
	 * @return boolean True when the stored rules decide differently.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function rulesDiffer(array $existing, array $projected): bool {
		$stored = $this->comparableRules(rules: $this->decode(value: ($existing['rules'] ?? null)));
		$fresh = $this->comparableRules(rules: $projected['rules']);

		return $stored !== $fresh;

	}//end rulesDiffer()

	/**
	 * Reduce rules to the decisions they encode, keyed by id and sorted.
	 *
	 * @param array<int, mixed> $rules The rules.
	 *
	 * @return array<string, array<int, array<int, string>>> The comparable form.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function comparableRules(array $rules): array {
		$comparable = [];

		foreach ($rules as $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			$comparable[(string)($rule['id'] ?? '')] = [
				array_map(strval(...), (array)($rule['inputEntries'] ?? [])),
				array_map(strval(...), (array)($rule['outputEntries'] ?? [])),
			];
		}

		ksort($comparable);

		return $comparable;

	}//end comparableRules()

	/**
	 * Build the decision table for one matrix, or null when it is inconsistent.
	 *
	 * @param array<string, mixed> $matrix The stored matrix.
	 * @param string               $marker The provenance marker.
	 *
	 * @return array<string, mixed>|null The table.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function tableFor(array $matrix, string $marker): ?array {
		$axes = [
			'severity' => $this->decode(value: ($matrix['severityAxis'] ?? null)),
			'behaviour' => $this->decode(value: ($matrix['behaviourAxis'] ?? null)),
			'actorType' => $this->decode(value: ($matrix['actorTypeAxis'] ?? null)),
		];
		$cells = $this->decode(value: ($matrix['cells'] ?? null));
		if ($cells === [] || in_array([], $axes, true) === true) {
			return null;
		}

		$rules = [];
		foreach ($cells as $cell) {
			foreach ($axes as $key => $allowed) {
				if (in_array(($cell[$key] ?? null), $allowed, true) === false) {
					return null;
				}
			}

			$rules[] = [
				'id' => sprintf('%s:%s:%s', $cell['severity'], $cell['behaviour'], $cell['actorType']),
				'annotation' => (string)($cell['note'] ?? ''),
				'inputEntries' => [
					(string)$cell['severity'],
					(string)$cell['behaviour'],
					(string)$cell['actorType'],
				],
				'outputEntries' => [(string)($cell['intervention'] ?? '')],
			];
		}//end foreach

		$version = (string)($matrix['version'] ?? '1');

		return [
			'name' => (string)($matrix['name'] ?? 'LHS'),
			'key' => ('lhs-matrix-' . $version),
			'description' => sprintf(
				'Projected from the LHS matrix "%s" (version %s). %s This table IS the enforcement '
				. 'lookup: LhsRecommendationService evaluates it and only falls back to the matrix '
				. 'where no projection exists. Edit it here; a re-run of the projection refuses to '
				. 'overwrite changed rules rather than discarding them.',
				(string)($matrix['name'] ?? ''),
				$version,
				$marker
			),
			// UNIQUE: the matrix is a grid, so exactly one cell answers a
			// triple. Declaring UNIQUE means an overlapping pair is REFUSED
			// rather than silently resolved by declaration order, which is the
			// property a hand-indexed dictionary quietly gave up.
			'hitPolicy' => 'UNIQUE',
			'inputs' => [
				['name' => 'severity', 'type' => 'string'],
				['name' => 'behaviour', 'type' => 'string'],
				['name' => 'actorType', 'type' => 'string'],
			],
			'outputs' => [['name' => 'intervention', 'type' => 'string']],
			'rules' => $rules,
			// ENABLED. Phase 1 shipped these disabled because the matrix was
			// still the lookup and a second answering table would have been a
			// second source of truth. That is no longer the shape: the
			// evaluator IS the lookup, and a disabled table means the matrix
			// answers instead. Shipping it disabled would make the projection
			// a no-op that reports success.
			'enabled' => true,
		];

	}//end tableFor()

	/**
	 * Decode a field the schema may store as a JSON string.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, mixed> The list.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function decode(mixed $value): array {
		if (is_string($value) === true) {
			$decoded = json_decode($value, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}

			return [];
		}

		if (is_array($value) === true) {
			return $value;
		}

		return [];

	}//end decode()

	/**
	 * Read the stored matrices.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 *
	 * @return array<int, array<string, mixed>> The matrices.
	 *
	 * @spec openspec/changes/lhs-matrix-is-a-decision-table/specs/lhs-decision-table/spec.md
	 */
	private function fetchMatrices(object $objectService, string $register): array {
		$schema = (string)$this->settingsService->getConfigValue('lhs_matrix_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: []
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not list LHS matrices',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);

			return [];
		}//end try

	}//end fetchMatrices()

}//end class
