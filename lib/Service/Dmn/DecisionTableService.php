<?php

/**
 * Dossiq DMN Decision Table Service
 *
 * Loads and persists decisionTable definitions from OpenRegister, and
 * structurally validates them before save. Evaluation itself is delegated
 * to the pure {@see \OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator} — this service never contains
 * evaluation logic, mirroring the `RoutingRuleService` / `RoutingEngine`
 * split already used for KCC routing.
 *
 * DEPRECATED (dossiq-decisions-to-decidiq): decision-table storage and CRUD
 * move to OpenRegister's flow-decision-tables, which is being built in
 * parallel. This service keeps working until that change lands and is retired
 * then; do not add new consumers. LocalDecisionAuthoringTest pins the
 * consumer set closed.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Dmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/dmn-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dmn;

use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator;
use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Persists and validates decision-table definitions.
 *
 * @spec openspec/specs/dmn-decision-tables/spec.md
 */
class DecisionTableService {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service (OR bridge).
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * List all decision tables.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	public function listTables(): array {
		[$objectService, $register, $schema] = $this->resolve();
		$results = $objectService->findAll(['filters' => ['register' => (int)$register, 'schema' => (int)$schema]]);
		return array_map([$this, 'toArray'], $results);
	}//end listTables()

	/**
	 * Create a decision table.
	 *
	 * @param array<string, mixed> $data Raw payload.
	 *
	 * @return array<string, mixed> The saved table.
	 *
	 * @throws OCSBadRequestException When validation fails.
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	public function createTable(array $data): array {
		$payload = $this->validateTable(data: $data);
		[$objectService, $register, $schema] = $this->resolve();
		return $this->toArray(value: $objectService->saveObject(object: $payload, register: $register, schema: $schema));
	}//end createTable()

	/**
	 * Update a decision table.
	 *
	 * @param string $id The table id.
	 * @param array<string, mixed> $data Raw payload.
	 *
	 * @return array<string, mixed> The saved table.
	 *
	 * @throws OCSBadRequestException When validation fails.
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	public function updateTable(string $id, array $data): array {
		$payload = $this->validateTable(data: $data);
		[$objectService, $register, $schema] = $this->resolve();
		return $this->toArray(value: $objectService->saveObject(object: $payload, register: $register, schema: $schema, uuid: $id));
	}//end updateTable()

	/**
	 * Delete a decision table.
	 *
	 * @param string $id The table id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	public function deleteTable(string $id): void {
		[$objectService, $register, $schema] = $this->resolve();
		// Named, like every other call in this class. OpenRegister's signature is
		// deleteObject(uuid, register, schema); passing them positionally in
		// register/schema/id order transposed all three, so this looked up a
		// register whose id was really the schema's and 500'd every time.
		$objectService->deleteObject(uuid: $id, register: $register, schema: $schema);
	}//end deleteTable()

	/**
	 * Load one decision table by id.
	 *
	 * @param string $id The table id.
	 *
	 * @return array<string, mixed>|null Null when not found.
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	public function getTable(string $id): ?array {
		[$objectService, $register, $schema] = $this->resolve();

		try {
			$result = $objectService->find($id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		$table = $this->toArray(value: $result);
		if ($table === []) {
			return null;
		}

		return $table;
	}//end getTable()

	/**
	 * Look up a decision table by its business `key` (used by the workflow
	 * `evaluateDecision` automatic action, which references decisions by
	 * name rather than by OpenRegister uuid).
	 *
	 * @param string $key The decision table's `key`.
	 *
	 * @return array<string, mixed>|null Null when not found or `key` is empty.
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	public function findByKey(string $key): ?array {
		if ($key === '') {
			return null;
		}

		foreach ($this->listTables() as $table) {
			if ((string)($table['key'] ?? '') === $key) {
				return $table;
			}
		}

		return null;
	}//end findByKey()

	/**
	 * Structurally validate + normalise a decision-table payload.
	 *
	 * @param array<string, mixed> $data Raw payload.
	 *
	 * @return array<string, mixed> The validated payload.
	 *
	 * @throws OCSBadRequestException When the shape is invalid.
	 */
	private function validateTable(array $data): array {
		$name = trim((string)($data['name'] ?? ''));
		if ($name === '') {
			throw new OCSBadRequestException('Decision table name is required');
		}

		$key = trim((string)($data['key'] ?? ''));
		if ($key === '') {
			throw new OCSBadRequestException('Decision table key is required');
		}

		$hitPolicy = strtoupper(trim((string)($data['hitPolicy'] ?? 'UNIQUE')));
		if (in_array($hitPolicy, ['UNIQUE', 'FIRST', 'PRIORITY', 'ANY', 'COLLECT'], true) === false) {
			throw new OCSBadRequestException('Invalid hitPolicy: ' . $hitPolicy);
		}

		$inputs = $this->validateFields(raw: ($data['inputs'] ?? []), label: 'inputs');
		$outputs = $this->validateFields(raw: ($data['outputs'] ?? []), label: 'outputs');
		$rules = $this->validateRules(raw: ($data['rules'] ?? []), inputCount: count($inputs), outputCount: count($outputs));

		return [
			'name' => $name,
			'key' => $key,
			'description' => trim((string)($data['description'] ?? '')),
			'hitPolicy' => $hitPolicy,
			'inputs' => $inputs,
			'outputs' => $outputs,
			'rules' => $rules,
			'enabled' => (bool)($data['enabled'] ?? true),
		];
	}//end validateTable()

	/**
	 * Validate an `inputs`/`outputs` array.
	 *
	 * @param mixed $raw Raw value from the payload.
	 * @param string $label `inputs` or `outputs` (for error messages).
	 *
	 * @return array<int, array<string, mixed>> The validated fields.
	 *
	 * @throws OCSBadRequestException When malformed.
	 */
	private function validateFields(mixed $raw, string $label): array {
		if (is_array($raw) === false) {
			throw new OCSBadRequestException($label . ' must be an array');
		}

		$fields = [];
		foreach ($raw as $field) {
			if (is_array($field) === false) {
				throw new OCSBadRequestException('Each ' . $label . ' entry must be an object');
			}

			$name = trim((string)($field['name'] ?? ''));
			if ($name === '') {
				throw new OCSBadRequestException('Each ' . $label . ' entry requires a name');
			}

			$type = (string)($field['type'] ?? 'string');
			if (in_array($type, UnaryTestEvaluator::VALID_TYPES, true) === false) {
				throw new OCSBadRequestException('Invalid type for ' . $label . ' entry "' . $name . '": ' . $type);
			}

			$fields[] = [
				'name' => $name,
				'label' => trim((string)($field['label'] ?? $name)),
				'type' => $type,
			];
		}//end foreach

		return $fields;
	}//end validateFields()

	/**
	 * Validate the `rules` array against the declared input/output counts.
	 *
	 * @param mixed $raw Raw value from the payload.
	 * @param int $inputCount Number of declared inputs.
	 * @param int $outputCount Number of declared outputs.
	 *
	 * @return array<int, array<string, mixed>> The validated rules.
	 *
	 * @throws OCSBadRequestException When a rule's entry counts don't align with inputs/outputs.
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	private function validateRules(mixed $raw, int $inputCount, int $outputCount): array {
		if (is_array($raw) === false) {
			throw new OCSBadRequestException('rules must be an array');
		}

		$rules = [];
		$index = -1;
		foreach ($raw as $rule) {
			$index++;
			if (is_array($rule) === false) {
				throw new OCSBadRequestException('Each rule must be an object');
			}

			$inputEntries = [];
			if (is_array($rule['inputEntries'] ?? null) === true) {
				$inputEntries = array_values($rule['inputEntries']);
			}

			if (count($inputEntries) !== $inputCount) {
				$got = count($inputEntries);
				throw new OCSBadRequestException('Rule ' . $index . ' inputEntries count (' . $got . ') must match inputs count (' . $inputCount . ')');
			}

			$outputEntries = [];
			if (is_array($rule['outputEntries'] ?? null) === true) {
				$outputEntries = array_values($rule['outputEntries']);
			}

			if (count($outputEntries) !== $outputCount) {
				$got = count($outputEntries);
				throw new OCSBadRequestException('Rule ' . $index . ' outputEntries count (' . $got . ') must match outputs count (' . $outputCount . ')');
			}

			$built = [
				'id' => trim((string)($rule['id'] ?? ('r' . ($index + 1)))),
				'annotation' => trim((string)($rule['annotation'] ?? '')),
				'inputEntries' => array_map(static fn (mixed $entry): string => (string)$entry, $inputEntries),
				'outputEntries' => $outputEntries,
			];

			// PRIORITY ranks by this, and it is only carried when the author
			// supplied it: writing a default 0 onto every rule of every table
			// would put a meaningless field on the tables that do not use it.
			if (array_key_exists('priority', $rule) === true) {
				$built['priority'] = (int)$rule['priority'];
			}

			$rules[] = $built;
		}//end foreach

		return $rules;
	}//end validateRules()

	/**
	 * Resolve the ObjectService and register/schema identifiers.
	 *
	 * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable or unconfigured.
	 */
	private function resolve(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new OCSBadRequestException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('decision_table_schema');

		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException('Decision table schema is not configured');
		}

		return [$objectService, $register, $schema];
	}//end resolve()

	/**
	 * Normalise an ObjectService result to a plain array.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return array<string, mixed> The normalised array.
	 */
	private function toArray(mixed $value): array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}

			return [];
		}

		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end toArray()
}//end class
