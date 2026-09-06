<?php

/**
 * Sweeps every shipped x-openregister-lifecycle block against OpenRegister's contract.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
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

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * An invalid lifecycle block is ignored, and says so only in the log.
 *
 * OpenRegister's `LifecycleAnnotationValidator::validate()` has two modes:
 *
 *  - STATIC, the default. `field`, `initial` and `transitions` are all
 *    required; `field` must be a `type: string` property carrying a non-empty
 *    `enum`; `initial` and every `final` value must be members of that enum.
 *  - GRAPH, entered when a non-empty `graph` block is present. The enum
 *    constraint is relaxed for a `$ref` field, `initial` may take the object
 *    form `{from, field}`, and `graph` must carry `schema`, `parentField`,
 *    `parentFrom`, `orderField`, `finalField` plus an `allowedMoves` of
 *    `forward|adjacent|any`.
 *
 * A block that satisfies neither is REJECTED AND DROPPED: the import logs
 * `x-openregister-lifecycle is missing required key "transitions"` and carries
 * on, so the schema behaves as though it declared nothing. The `case` schema
 * shipped exactly that — object-form `initial` with no `graph` block — and
 * logged it eight times per install while the shipped comment on `case.status`
 * claimed OpenRegister was initialising the field.
 *
 * dossiq#1678 already ruled on which engine owns case status:
 * `StatusTransitionService` does, because OpenRegister cannot express a
 * per-caseType dynamic statusType graph. So the block was removed rather than
 * completed — declaring GRAPH mode would have installed OpenRegister's move
 * enforcement as a second engine over the same field, which is the outcome
 * #1678 refused. The initialisation it claimed to provide is delivered, and
 * validly, by `x-openregister-prefill` on `caseType`.
 *
 * This file sweeps the shipped registers so the next block cannot ship dead.
 *
 * @coversNothing
 */
class ShippedLifecycleAnnotationTest extends TestCase {

	/**
	 * The register configurations that ship lifecycle blocks.
	 *
	 * @var array<int, string>
	 */
	private const REGISTER_FILES = [
		'dossiq_register.json',
		'dossiq_mock_register.json',
	];

	/**
	 * Every shipped lifecycle block, keyed by "file:schema".
	 *
	 * OpenRegister reads the block from a schema's `configuration`; a copy at
	 * the schema's top level is not read at all, so both positions are
	 * collected and the position itself is asserted below.
	 *
	 * @return array<string, array{block: mixed, schema: array<string, mixed>, position: string}>
	 */
	private function shippedLifecycles(): array {
		$found = [];
		foreach (self::REGISTER_FILES as $file) {
			$config = json_decode(
				(string)file_get_contents(__DIR__ . '/../../../lib/Settings/' . $file),
				true
			);
			self::assertIsArray($config, sprintf('%s did not parse.', $file));

			foreach (($config['components']['schemas'] ?? []) as $slug => $schema) {
				if (is_array($schema) === false) {
					continue;
				}

				foreach (['configuration' => ($schema['configuration'] ?? []), 'schema' => $schema] as $position => $holder) {
					if (is_array($holder) === false || isset($holder['x-openregister-lifecycle']) === false) {
						continue;
					}

					$found[$file . ':' . $slug . ':' . $position] = [
						'block' => $holder['x-openregister-lifecycle'],
						'schema' => $schema,
						'position' => $position,
					];
				}
			}
		}

		return $found;
	}//end shippedLifecycles()

	/**
	 * The sweep found blocks to check.
	 *
	 * Without this the assertions below pass on an empty loop, which is exactly
	 * how a shipped-data sweep reads clean while checking nothing.
	 *
	 * @return void
	 */
	public function testTheSweepFoundLifecycleBlocks(): void {
		self::assertGreaterThan(
			15,
			count($this->shippedLifecycles()),
			'No lifecycle blocks were read out of the shipped registers, so nothing below can have been checked.'
		);
	}//end testTheSweepFoundLifecycleBlocks()

	/**
	 * Every shipped block satisfies static mode or graph mode, and nothing between.
	 *
	 * @return void
	 */
	public function testEveryShippedLifecycleSatisfiesOpenRegistersContract(): void {
		$broken = [];
		foreach ($this->shippedLifecycles() as $where => $entry) {
			$errors = $this->validate(annotation: $entry['block'], schema: $entry['schema']);
			if ($errors !== []) {
				$broken[] = $where . ' — ' . implode('; ', $errors);
			}
		}

		self::assertSame(
			[],
			$broken,
			"These shipped x-openregister-lifecycle blocks are rejected by OpenRegister's validator, so the schema "
			. "behaves as though it declared nothing while the import logs the refusal on every install:\n"
			. implode("\n", $broken)
		);
	}//end testEveryShippedLifecycleSatisfiesOpenRegistersContract()

	/**
	 * Every shipped block sits where OpenRegister reads it.
	 *
	 * A block at the schema's top level rather than under `configuration` is
	 * never read — the same silent no-op with none of the logging, because the
	 * validator never sees it either. dossiq#1678 fixed one of those on the
	 * complaint schema.
	 *
	 * @return void
	 */
	public function testEveryShippedLifecycleSitsUnderConfiguration(): void {
		$misplaced = [];
		foreach ($this->shippedLifecycles() as $where => $entry) {
			if ($entry['position'] !== 'configuration') {
				$misplaced[] = $where;
			}
		}

		self::assertSame(
			[],
			$misplaced,
			sprintf(
				'These lifecycle blocks sit at the schema top level, where OpenRegister does not read them: %s',
				implode(', ', $misplaced)
			)
		);
	}//end testEveryShippedLifecycleSitsUnderConfiguration()

	/**
	 * Re-implements OpenRegister's LifecycleAnnotationValidator contract.
	 *
	 * Deliberately a re-implementation and not a call: OpenRegister is an
	 * optional runtime dependency and its classes are not on this suite's
	 * autoloader. The contract is transcribed from
	 * `openregister/lib/Service/Lifecycle/LifecycleAnnotationValidator.php`,
	 * which is the only place it is defined, and the mode split is its own.
	 *
	 * @param mixed $annotation The declared block.
	 * @param array<string, mixed> $schema The schema carrying it.
	 *
	 * @return array<int, string> Contract violations, empty when valid.
	 */
	private function validate(mixed $annotation, array $schema): array {
		if (is_array($annotation) === false) {
			return ['the block is not an object'];
		}

		// `property` is an accepted alias for `field`.
		if (isset($annotation['field']) === false && isset($annotation['property']) === true) {
			$annotation['field'] = $annotation['property'];
		}

		if (isset($annotation['graph']) === true && is_array($annotation['graph']) === true && $annotation['graph'] !== []) {
			return $this->validateGraphMode(annotation: $annotation, schema: $schema);
		}

		$errors = [];
		foreach (['field', 'initial', 'transitions'] as $required) {
			if (isset($annotation[$required]) === false) {
				$errors[] = sprintf('missing required key "%s" (static mode)', $required);
			}
		}

		if ($errors !== []) {
			return $errors;
		}

		$field = (string)$annotation['field'];
		$properties = ($schema['properties'] ?? []);
		if (isset($properties[$field]) === false) {
			return [sprintf('field "%s" is not declared in `properties`', $field)];
		}

		$definition = $properties[$field];
		if (($definition['type'] ?? null) !== 'string') {
			$errors[] = sprintf('field "%s" must be type "string"', $field);
		}

		$enum = ($definition['enum'] ?? null);
		if (is_array($enum) === false || $enum === []) {
			return array_merge($errors, [sprintf('field "%s" must declare a non-empty `enum`', $field)]);
		}

		if (is_string($annotation['initial']) === false) {
			return array_merge($errors, ['static mode requires a literal string `initial`; the {from, field} form is graph mode only']);
		}

		if (in_array($annotation['initial'], $enum, true) === false) {
			$errors[] = sprintf('initial "%s" is not in the field\'s enum', $annotation['initial']);
		}

		foreach ((array)($annotation['final'] ?? []) as $final) {
			if (in_array((string)$final, $enum, true) === false) {
				$errors[] = sprintf('final "%s" is not in the field\'s enum', (string)$final);
			}
		}

		return $errors;
	}//end validate()

	/**
	 * The graph-mode half of the contract.
	 *
	 * @param array<string, mixed> $annotation The declared block.
	 * @param array<string, mixed> $schema The schema carrying it.
	 *
	 * @return array<int, string> Contract violations, empty when valid.
	 */
	private function validateGraphMode(array $annotation, array $schema): array {
		$errors = [];

		$field = ($annotation['field'] ?? null);
		if (is_string($field) === false || $field === '') {
			$errors[] = 'graph mode still requires a non-empty `field`';
		}

		if (is_string($field) === true && isset(($schema['properties'] ?? [])[$field]) === false) {
			$errors[] = sprintf('field "%s" is not declared in `properties`', $field);
		}

		if (isset($annotation['initial']) === true && is_string($annotation['initial']) === false) {
			$initial = $annotation['initial'];
			$fromOk = (is_array($initial) === true && is_string($initial['from'] ?? null) === true && ($initial['from'] ?? '') !== '');
			$fieldOk = (is_array($initial) === true && is_string($initial['field'] ?? null) === true && ($initial['field'] ?? '') !== '');
			if ($fromOk === false || $fieldOk === false) {
				$errors[] = '`initial` must be a string or an object carrying non-empty `from` and `field`';
			}
		}

		$graph = $annotation['graph'];
		foreach (['schema', 'parentField', 'parentFrom', 'orderField', 'finalField'] as $key) {
			$value = ($graph[$key] ?? null);
			if (is_string($value) === false || $value === '') {
				$errors[] = sprintf('graph is missing required string key "%s"', $key);
			}
		}

		if (in_array(($graph['allowedMoves'] ?? null), ['forward', 'adjacent', 'any'], true) === false) {
			$errors[] = 'graph.allowedMoves must be one of forward|adjacent|any';
		}

		return $errors;
	}//end validateGraphMode()
}//end class
