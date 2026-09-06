<?php

/**
 * Shipped Enum Value Conformance Sweep
 *
 * Two sweeps over the same question, asked at the two moments a value can
 * become one its schema refuses: when it is SHIPPED, and when a repair step
 * REWRITES it.
 *
 * OpenRegister validates a value against the `enum` its property declares and
 * REFUSES the row when it does not match, while the import still reports
 * success. Gate-108 and `SeedPayloadSchemaConformanceTest` both check the KEYS
 * a payload writes; neither looks at the VALUES, so an out-of-enum value is
 * invisible to every mechanical check the app runs.
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

use OCA\Dossiq\Repair\RenameDutchValueDecisions;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * Every value this app ships, or migrates a stored value to, is one its schema
 * declares.
 *
 * @covers \OCA\Dossiq\Repair\RenameDutchValueDecisions
 *
 * `RegisterFragmentMerger` is declared as USED, not covered: the sweep reads a
 * merged register to learn which values a schema allows, so it executes the
 * merger without asserting anything about it. Without the tag, PHPUnit's
 * strict-coverage mode marks the test risky, which only appears under a
 * coverage driver and so is green locally and red in CI.
 *
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
final class ShippedEnumValueConformanceTest extends TestCase {

	/**
	 * Collection key to the schema slug its records are written to.
	 *
	 * 🔴 A slug that names no schema makes this sweep check NOTHING for that
	 * collection and still pass, so {@see testTheCollectionMapNamesRealSchemas}
	 * asserts every slug here resolves. That test exists because the first
	 * draft of this file mapped `tasks` to `caseTask`, which no schema is
	 * called: 32 task payloads were skipped in silence.
	 *
	 * @var array<string, string>
	 */
	private const COLLECTION_SCHEMA = [
		'cases' => 'case',
		'tasks' => 'caseTask',
		'caseTypes' => 'caseType',
		'statusTypes' => 'statusType',
		'roleTypes' => 'roleType',
		'resultTypes' => 'resultType',
		'documentTypes' => 'documentType',
		'decisionTypes' => 'decisionType',
		'propertyDefinitions' => 'propertyDefinition',
		'inspectionChecklists' => 'inspectionChecklistTemplate',
	];

	/**
	 * The merged register's schemas, keyed by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schemas = [];

	/**
	 * Load the configuration an install actually imports.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$base = json_decode((string)file_get_contents($this->settingsDir() . '/dossiq_register.json'), true);
		self::assertIsArray($base, 'dossiq_register.json must parse');

		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $base,
			fragmentDir: $this->settingsDir() . '/register.d'
		);

		$this->schemas = (array)($merged['components']['schemas'] ?? []);
		self::assertNotEmpty($this->schemas, 'the merged configuration must carry schemas');

	}//end setUp()

	/**
	 * Every slug the collection map names is a schema this app ships.
	 *
	 * @return void
	 */
	public function testTheCollectionMapNamesRealSchemas(): void {
		$missing = [];
		foreach (self::COLLECTION_SCHEMA as $key => $slug) {
			if (isset($this->schemas[$slug]) === false) {
				$missing[] = $key . ' => ' . $slug;
			}
		}

		self::assertSame(
			[],
			$missing,
			"A collection mapped to a schema that does not exist is skipped in SILENCE,\n"
			. "so the sweep below reports clean without having looked:\n" . implode("\n", $missing)
		);

	}//end testTheCollectionMapNamesRealSchemas()

	/**
	 * Every value a shipped seed payload writes is one its enum allows.
	 *
	 * @return void
	 */
	public function testEverySeededValueSatisfiesItsDeclaredEnum(): void {
		$findings = [];
		$payloadsSeen = 0;

		foreach ($this->seedFiles() as $path) {
			$data = json_decode((string)file_get_contents($path), true);
			if (is_array($data) === false) {
				continue;
			}

			$this->walk(
				node: $data,
				pointer: basename($path),
				findings: $findings,
				payloadsSeen: $payloadsSeen
			);
		}

		self::assertGreaterThan(
			0,
			$payloadsSeen,
			'The sweep saw no seed payloads at all — the walk is broken, not the data clean'
		);
		self::assertSame(
			[],
			$findings,
			"OpenRegister REFUSES a row whose value is outside the enum its property declares,\n"
			. "and the import still reports success, so the row is simply missing:\n"
			. implode("\n", $findings)
		);

	}//end testEverySeededValueSatisfiesItsDeclaredEnum()

	/**
	 * Every value the vocabulary migration rewrites TO is one its enum allows.
	 *
	 * 🔴 THIS IS THE SWEEP THE SEED SWEEP CANNOT DO. The shipped data is Dutch
	 * and clean; `RenameDutchValues` then runs on every upgrade and rewrites it
	 * in place, by column, without consulting a schema. A mapping whose
	 * replacement no schema declares therefore turns valid stored rows into
	 * rows the app refuses to save again — the data on disk stays green and the
	 * live instance does not.
	 *
	 * A mapping is only judged where it can bite: a schema that declares an
	 * enum for the property AND lists the value being rewritten FROM.
	 *
	 * @return void
	 */
	public function testEveryMigratedValueSatisfiesItsDeclaredEnum(): void {
		$considered = 0;
		$findings = $this->migrationViolations(
			valueMap: RenameDutchValueDecisions::VALUE_MAP,
			considered: $considered
		);

		// 🔴 COUNT THE PAIRS LOOKED AT, NOT THE VIOLATIONS FOUND. An earlier
		// draft counted only mappings whose source value a schema declared,
		// which was 17 before the fix and ZERO after it: every remaining
		// mapping names a Dutch value the schemas have already left behind. The
		// guard would have started failing precisely because the defect was
		// gone. What must be non-zero is the JOIN — that mapped properties
		// still resolve to schemas with enums at all.
		self::assertGreaterThan(
			0,
			$considered,
			'The sweep resolved no mapped property to any schema enum — the lookup is broken, '
			. 'not the map clean'
		);
		self::assertSame(
			[],
			$findings,
			"Each of these rewrites a stored value the schema DECLARES into one it REFUSES.\n"
			. "Either the schema never moved with the vocabulary, or the mapping should not\n"
			. "exist. Removing the mapping is the fix when the vocabulary is a standard's:\n"
			. implode("\n", $findings)
		);

	}//end testEveryMigratedValueSatisfiesItsDeclaredEnum()

	/**
	 * The migration sweep reports a violation that is really there.
	 *
	 * The sweep above passes, so on its own it cannot tell "no violations" from
	 * "no longer looking". This plants one against a real schema enum and
	 * requires the sweep to name it.
	 *
	 * @return void
	 */
	public function testTheMigrationSweepCatchesAPlantedViolation(): void {
		$enum = ($this->schemasDeclaringEnumFor(property: 'confidentiality')['case'] ?? []);
		self::assertContains(
			'openbaar',
			$enum,
			'the control needs case.confidentiality to declare the statutory vocabulary'
		);

		$considered = 0;
		$findings = $this->migrationViolations(
			valueMap: ['confidentiality' => ['openbaar' => 'public']],
			considered: $considered
		);

		self::assertNotSame([], $findings, 'the sweep must report a planted out-of-enum rewrite');
		self::assertStringContainsString('case.confidentiality', implode("\n", $findings));

	}//end testTheMigrationSweepCatchesAPlantedViolation()

	/**
	 * Every rewrite in a value map that lands outside its schema's enum.
	 *
	 * A mapping is only judged where it can bite: a schema that declares an
	 * enum for the property AND lists the value being rewritten FROM.
	 *
	 * @param array<string, array<string, string>> $valueMap Property => old => new.
	 * @param integer $considered Property/schema pairs examined, incremented in place.
	 *
	 * @return array<int, string> One line per violation.
	 */
	private function migrationViolations(array $valueMap, int &$considered): array {
		$findings = [];

		foreach ($valueMap as $property => $values) {
			foreach ($this->schemasDeclaringEnumFor(property: (string)$property) as $slug => $enum) {
				$considered++;

				foreach ($values as $old => $new) {
					if (in_array((string)$old, $enum, true) === false
						|| in_array((string)$new, $enum, true) === true
					) {
						continue;
					}

					$findings[] = sprintf(
						'%s.%s: the migration rewrites "%s" to "%s", which the schema does not allow (%s)',
						$slug,
						(string)$property,
						(string)$old,
						(string)$new,
						implode(', ', $enum)
					);
				}
			}
		}

		return $findings;
	}//end migrationViolations()

	/**
	 * The directory holding the shipped configuration.
	 *
	 * @return string Absolute path to lib/Settings.
	 */
	private function settingsDir(): string {
		return __DIR__ . '/../../../lib/Settings';
	}//end settingsDir()

	/**
	 * Every shipped seed file whose payloads reach OpenRegister.
	 *
	 * Discovered rather than listed: a seed file added next week is swept
	 * without anyone remembering to name it here, which is the failure mode a
	 * hand-kept list has.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function seedFiles(): array {
		$paths = array_merge(
			(array)glob($this->settingsDir() . '/*.json'),
			(array)glob($this->settingsDir() . '/templates/*.json'),
			(array)glob($this->settingsDir() . '/vth-templates/*.json'),
			(array)glob($this->settingsDir() . '/seed/*.json'),
			(array)glob($this->settingsDir() . '/register.d/*.json')
		);

		return array_values(
			array_filter(
				$paths,
				static fn (string $path): bool => in_array(
					basename($path),
					['dossiq_register.json', 'dossiq_mock_register.json'],
					true
				) === false
			)
		);
	}//end seedFiles()

	/**
	 * Schemas declaring an enum for one property, keyed by slug.
	 *
	 * @param string $property The property name.
	 *
	 * @return array<string, array<int, string>> Enum values by schema slug.
	 */
	private function schemasDeclaringEnumFor(string $property): array {
		$found = [];
		foreach ($this->schemas as $slug => $schema) {
			$definition = ((array)($schema['properties'] ?? []))[$property] ?? null;
			if (is_array($definition) === false || is_array($definition['enum'] ?? null) === false) {
				continue;
			}

			$found[(string)$slug] = array_map('strval', (array)$definition['enum']);
		}

		return $found;
	}//end schemasDeclaringEnumFor()

	/**
	 * Walk a decoded seed tree, checking every payload it can place.
	 *
	 * @param mixed $node The current node.
	 * @param string $pointer Human-readable path to the node.
	 * @param array<int, string> $findings Accumulated findings, appended in place.
	 * @param integer $payloadsSeen Count of payloads checked, incremented in place.
	 *
	 * @return void
	 */
	private function walk(mixed $node, string $pointer, array &$findings, int &$payloadsSeen): void {
		if (is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $value) {
			$name = (string)$key;
			$childPointer = $pointer . '/' . $name;

			if ($name === 'caseType' && $this->isPayload(node: $value) === true) {
				$this->checkPayload(
					payload: (array)$value,
					slug: 'caseType',
					pointer: $childPointer,
					findings: $findings,
					payloadsSeen: $payloadsSeen
				);
				continue;
			}

			if (isset(self::COLLECTION_SCHEMA[$name]) === true && is_array($value) === true) {
				foreach ($value as $index => $record) {
					if ($this->isPayload(node: $record) === false) {
						continue;
					}

					$this->checkPayload(
						payload: (array)$record,
						slug: self::COLLECTION_SCHEMA[$name],
						pointer: $childPointer . '[' . (string)$index . ']',
						findings: $findings,
						payloadsSeen: $payloadsSeen
					);
				}

				continue;
			}

			if ($this->isPayload(node: $value) === true
				&& is_array(($value['@self'] ?? null)) === true
				&& is_string(($value['@self']['schema'] ?? null)) === true
			) {
				$this->checkPayload(
					payload: (array)$value,
					slug: (string)$value['@self']['schema'],
					pointer: $childPointer,
					findings: $findings,
					payloadsSeen: $payloadsSeen
				);
				continue;
			}

			$this->walk(node: $value, pointer: $childPointer, findings: $findings, payloadsSeen: $payloadsSeen);
		}//end foreach

	}//end walk()

	/**
	 * Check one payload's scalar values against the enums its schema declares.
	 *
	 * Nested collections are recursed into so a `caseType` carrying its status
	 * types is checked whole.
	 *
	 * @param array<string, mixed> $payload The payload as shipped.
	 * @param string $slug The schema it is written to.
	 * @param string $pointer Human-readable path to the payload.
	 * @param array<int, string> $findings Accumulated findings, appended in place.
	 * @param integer $payloadsSeen Count of payloads checked, incremented in place.
	 *
	 * @return void
	 */
	private function checkPayload(
		array $payload,
		string $slug,
		string $pointer,
		array &$findings,
		int &$payloadsSeen,
	): void {
		$schema = ($this->schemas[$slug] ?? null);
		if (is_array($schema) === false) {
			return;
		}

		$payloadsSeen++;
		$properties = (array)($schema['properties'] ?? []);

		foreach ($payload as $key => $value) {
			$name = (string)$key;

			if (isset(self::COLLECTION_SCHEMA[$name]) === true && is_array($value) === true) {
				foreach ($value as $index => $record) {
					if ($this->isPayload(node: $record) === false) {
						continue;
					}

					$this->checkPayload(
						payload: (array)$record,
						slug: self::COLLECTION_SCHEMA[$name],
						pointer: $pointer . '/' . $name . '[' . (string)$index . ']',
						findings: $findings,
						payloadsSeen: $payloadsSeen
					);
				}

				continue;
			}

			$definition = ($properties[$name] ?? null);
			if (is_array($definition) === false || is_array($definition['enum'] ?? null) === false) {
				continue;
			}

			if (is_scalar($value) === false || is_bool($value) === true) {
				continue;
			}

			$allowed = array_map('strval', (array)$definition['enum']);
			if (in_array((string)$value, $allowed, true) === true) {
				continue;
			}

			$findings[] = sprintf(
				'%s/%s ships "%s", which schema "%s" does not allow (%s)',
				$pointer,
				$name,
				(string)$value,
				$slug,
				implode(', ', $allowed)
			);
		}//end foreach

	}//end checkPayload()

	/**
	 * Whether a node is an object payload rather than a list or a scalar.
	 *
	 * @param mixed $node The node to judge.
	 *
	 * @return boolean True when the node is a keyed object.
	 */
	private function isPayload(mixed $node): bool {
		return (is_array($node) === true && $node !== [] && array_is_list($node) === false);
	}//end isPayload()
}//end class
