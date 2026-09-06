<?php

/**
 * Seed payload / schema conformance sweep.
 *
 * OpenRegister gives a DECLARED property a magic-table column and an undeclared
 * one nothing at all. `MagicMapper::prepareObjectDataForTable()` is a whitelist
 * by omission: it walks the schema's declared properties and copies those out of
 * the payload, so a key the schema does not declare is never read and there is
 * no JSON blob column to fall back on. The save answers 200 and the value is
 * gone. Since openregister#2166 a warning is logged, but a warning in
 * nextcloud.log is not a failing build, and dossiq shipped this defect four
 * times in one day (#1779, #1780, #1782 and the VTH checklist seeds).
 *
 * This sweep is the mechanical floor: every key every shipped seed payload
 * writes must be declared by the schema it is written to.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * Asserts every shipped seed payload only writes properties its schema declares.
 *
 * @covers \OCA\Dossiq\Repair\VthSeedDataRepairStep
 * @covers \OCA\Dossiq\Service\Besluitvorming\TemplateBundleSeeder
 * @covers \OCA\Dossiq\Service\SeedDataService
 *
 * `RegisterFragmentMerger` is declared as USED, not covered: the test reads a
 * merged register to know which properties a schema declares, so it executes
 * the merger without asserting anything about it. Without the tag, PHPUnit's
 * strict-coverage mode marks the test risky, which only shows up under a
 * coverage driver and so is green locally and red in CI.
 *
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class SeedPayloadSchemaConformanceTest extends TestCase {

	/**
	 * Collection key to the schema slug its records are written to.
	 *
	 * These are the fan-out points the seeders share: `TemplateBundleSeeder::
	 * seedChildren()` and `SeedDataService` both take the records under these
	 * keys off the case-type payload and write each one, verbatim plus a
	 * `caseType` back-reference, into the named schema. A key listed here is
	 * therefore BOTH a collection of payloads to check AND a key the parent
	 * payload is allowed to carry, because the parent save never sees it.
	 *
	 * @var array<string, string>
	 */
	private const COLLECTION_SCHEMA = [
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
	 * Keys OpenRegister consumes itself, before the schema whitelist runs.
	 *
	 * `id` addresses the object: `ObjectService::extractUuidAndNormalizeObject()`
	 * reads `@self.id` then `id` and uses it as the target uuid. `slug` is read
	 * by `SaveObject::setSelfMetadata()` and stored on the entity as `@self.slug`,
	 * which is where `VthSeedDataRepairStep::existingSlugs()` reads it back from.
	 * Neither is a schema property and neither is lost.
	 *
	 * `uuid` is deliberately NOT on this list. Nothing reads it: the uuid comes
	 * from the `$uuid` parameter or from `id`, so a payload `uuid` is dropped
	 * like any other undeclared key.
	 *
	 * @var array<int, string>
	 */
	private const OPENREGISTER_METADATA_KEYS = ['id', 'slug'];

	/**
	 * Case-type keys a seeder reads and takes off the payload before saving it.
	 *
	 * These never reach OpenRegister, so they are not drops. The list mirrors
	 * the `unset()` calls exactly and is not a waiver: adding a key here without
	 * a matching `unset()` in the seeder re-opens the defect this sweep exists
	 * to catch.
	 *
	 * - `statusTypes` … `resultTypes`, `workflowTemplate`, `initialStatusName`:
	 *   `TemplateBundleSeeder::splitBundle()`, lib/Service/Besluitvorming/TemplateBundleSeeder.php.
	 * - `caseTypeSlug`: `VthSeedDataRepairStep::seedInspectionChecklists()`
	 *   resolves it to the `caseType` uuid and drops the slug.
	 *
	 * @var array<int, string>
	 */
	private const SEEDER_CONSUMED_KEYS = [
		'workflowTemplate',
		'initialStatusName',
		'caseTypeSlug',
	];

	/**
	 * Seed files whose payloads reach OpenRegister on install or on demand.
	 *
	 * @var array<int, string>
	 */
	private const SEED_FILES = [
		'vth_seed_data.json',
		'case_flow_seed_data.json',
		'bezwaar_seed_data.json',
		'templates/bvw-college-besluit.json',
		'templates/bvw-mandaatbesluit.json',
		'templates/bvw-raadsbesluit.json',
		'templates/omgevingsvergunning.json',
		'templates/woo-verzoek.json',
		'templates/woo_verzoek.json',
		'templates/vth-handhavingszaak.json',
		'templates/vth-omgevingsvergunning.json',
		'templates/vth-toezichtzaak.json',
	];

	/**
	 * The merged register configuration, keyed by schema slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schemas = [];

	/**
	 * Load the same merged configuration the installer imports.
	 *
	 * The base monolith alone is not the authority: `SettingsService::
	 * loadConfiguration()` deep-merges `register.d/*.json` on top of it (ADR-037),
	 * and a fragment may add the very property a seed writes. Reading only the
	 * monolith would fail this sweep on properties that do install.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$settingsDir = __DIR__ . '/../../../lib/Settings';
		$base = json_decode(file_get_contents($settingsDir . '/dossiq_register.json'), true);
		$this->assertIsArray(actual: $base, message: 'dossiq_register.json must parse');

		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $base,
			fragmentDir: $settingsDir . '/register.d'
		);

		$this->schemas = ($merged['components']['schemas'] ?? []);
		$this->assertNotEmpty(actual: $this->schemas, message: 'the merged configuration must carry schemas');

	}//end setUp()

	/**
	 * Every key every shipped seed payload writes is declared by its schema.
	 *
	 * @return void
	 */
	public function testSeedPayloadsOnlyWriteDeclaredProperties(): void {
		$settingsDir = __DIR__ . '/../../../lib/Settings';
		$findings = [];

		foreach (self::SEED_FILES as $relative) {
			$path = $settingsDir . '/' . $relative;
			if (file_exists($path) === false) {
				continue;
			}

			$data = json_decode(file_get_contents($path), true);
			$this->assertIsArray(actual: $data, message: $relative . ' must parse as JSON');

			$this->collectFindings(
				node: $data,
				pointer: $relative,
				findings: $findings
			);
		}//end foreach

		$this->assertSame(
			expected: [],
			actual: $findings,
			message:
			"Seed payloads write properties no schema declares. OpenRegister answers 200\n"
			. "and stores nothing, so each of these values is silently lost on every install.\n"
			. "Either declare the property on the schema, or stop writing it.\n\n"
			. implode("\n", $findings)
		);

	}//end testSeedPayloadsOnlyWriteDeclaredProperties()

	/**
	 * Walk a decoded seed tree and record every undeclared payload key.
	 *
	 * A key named in COLLECTION_SCHEMA carries payloads for the schema it maps
	 * to. `caseType` is handled as its singular form too, because the
	 * besluitvorming templates wrap one case type in that key.
	 *
	 * @param mixed $node The current node in the decoded tree.
	 * @param string $pointer Human-readable path to the node, for the failure message.
	 * @param array<int, string> $findings Accumulated findings, appended in place.
	 *
	 * @return void
	 */
	private function collectFindings(mixed $node, string $pointer, array &$findings): void {
		if (is_array($node) === false) {
			return;
		}

		foreach ($node as $key => $value) {
			$name = (string)$key;
			$childPointer = $pointer . '/' . $name;

			if ($name === 'caseType' && $this->isPayload(node: $value) === true) {
				$this->checkPayload(
					payload: $value,
					schemaSlug: 'caseType',
					pointer: $childPointer,
					findings: $findings
				);
				continue;
			}

			if (isset(self::COLLECTION_SCHEMA[$name]) === true && is_array($value) === true) {
				$slug = self::COLLECTION_SCHEMA[$name];
				foreach ($value as $index => $record) {
					if ($this->isPayload(node: $record) === false) {
						// A record that is not an object is not a payload at
						// all. `VTHTemplateService::seedSubObjects()` and
						// `TemplateBundleSeeder::seedChildren()` both skip a
						// non-array record, so a collection shipped as bare
						// strings seeds NOTHING and still reports success.
						$findings[] = $childPointer . '[' . $index . ']: not an object, so the'
							. ' seeder skips it and no "' . $slug . '" row is written';
						continue;
					}

					$this->checkPayload(
						payload: $record,
						schemaSlug: $slug,
						pointer: $childPointer . '[' . $index . ']',
						findings: $findings
					);
				}

				continue;
			}

			$this->collectFindings(node: $value, pointer: $childPointer, findings: $findings);
		}//end foreach

	}//end collectFindings()

	/**
	 * Check one payload's keys against its schema's declared properties.
	 *
	 * Nested collections are checked in their own right by the walker, so they
	 * are skipped here: the seeders split them off the parent before saving it.
	 *
	 * @param array<string, mixed> $payload The object payload as shipped.
	 * @param string $schemaSlug The schema it is written to.
	 * @param string $pointer Human-readable path to the payload.
	 * @param array<int, string> $findings Accumulated findings, appended in place.
	 *
	 * @return void
	 */
	private function checkPayload(
		array $payload,
		string $schemaSlug,
		string $pointer,
		array &$findings,
	): void {
		$schema = ($this->schemas[$schemaSlug] ?? null);
		if (is_array($schema) === false) {
			$findings[] = $pointer . ': no schema "' . $schemaSlug . '" in the merged configuration';
			return;
		}

		$declared = array_keys(($schema['properties'] ?? []));

		foreach (array_keys($payload) as $key) {
			$name = (string)$key;
			if ($name === '' || $name[0] === '@' || $name[0] === '_') {
				continue;
			}

			if (in_array($name, $declared, true) === true) {
				continue;
			}

			if (in_array($name, self::OPENREGISTER_METADATA_KEYS, true) === true) {
				continue;
			}

			if (isset(self::COLLECTION_SCHEMA[$name]) === true) {
				continue;
			}

			if (in_array($name, self::SEEDER_CONSUMED_KEYS, true) === true) {
				continue;
			}

			$findings[] = $pointer . '/' . $name . ': schema "' . $schemaSlug . '" does not declare it';
		}//end foreach

		$this->checkChildCollections(
			payload: $payload,
			pointer: $pointer,
			findings: $findings
		);

		$this->checkNested(
			payload: $payload,
			properties: ($schema['properties'] ?? []),
			schemaSlug: $schemaSlug,
			pointer: $pointer,
			findings: $findings
		);

		$this->checkRequired(
			payload: $payload,
			schema: $schema,
			schemaSlug: $schemaSlug,
			pointer: $pointer,
			findings: $findings
		);

	}//end checkPayload()

	/**
	 * Check the child collections a payload carries against THEIR schemas.
	 *
	 * 🔴 WITHOUT THIS THE SWEEP ONLY SAW THE TOP LEVEL. `checkPayload()` skips
	 * a `COLLECTION_SCHEMA` key because the parent save never sees it, and the
	 * walker only reaches a collection that sits at the top of a seed file. In
	 * `vth_seed_data.json` the collections are nested INSIDE each case-type
	 * record, so nothing checked them: 26 propertyDefinitions shipped `type`
	 * and `enum`, which the schema spells `propertyType` and `enumValues`, and
	 * the sweep that exists to catch exactly that was green. It only stayed
	 * harmless because nothing wrote those rows at all.
	 *
	 * `checkNested()` cannot cover this: it walks the properties the schema
	 * DECLARES, and a child collection is by definition not one of them.
	 *
	 * @param array<string, mixed> $payload The payload, which may carry collections.
	 * @param string $pointer Human-readable path to the payload.
	 * @param array<int, string> $findings Accumulated findings, appended in place.
	 *
	 * @return void
	 */
	private function checkChildCollections(
		array $payload,
		string $pointer,
		array &$findings,
	): void {
		foreach ($payload as $key => $value) {
			$name = (string)$key;
			if (isset(self::COLLECTION_SCHEMA[$name]) === false || is_array($value) === false) {
				continue;
			}

			foreach ($value as $index => $record) {
				if ($this->isPayload(node: $record) === false) {
					continue;
				}

				$this->checkPayload(
					payload: $record,
					schemaSlug: self::COLLECTION_SCHEMA[$name],
					pointer: $pointer . '/' . $name . '[' . $index . ']',
					findings: $findings
				);
			}
		}//end foreach

	}//end checkChildCollections()

	/**
	 * Check the objects nested inside a payload against their sub-schemas.
	 *
	 * The top-level keys are only half the surface. An `inspectionChecklist
	 * Template` declares `sections`, each section declares `items`, and each
	 * item declares `responseType` — a checklist can name every top-level key
	 * correctly and still write `type` on every question. Those nested keys are
	 * stored as part of the parent column, so a wrong one does not vanish from
	 * the database the way a top-level key does; it lands as data no reader
	 * understands, which is the same defect wearing a different coat.
	 *
	 * @param array<string, mixed> $payload The payload, or a nested object in it.
	 * @param array<string, mixed> $properties The matching schema property map.
	 * @param string $schemaSlug The owning schema slug, for the message.
	 * @param string $pointer Human-readable path to the payload.
	 * @param array<int, string> $findings Accumulated findings, appended in place.
	 *
	 * @return void
	 */
	private function checkNested(
		array $payload,
		array $properties,
		string $schemaSlug,
		string $pointer,
		array &$findings,
	): void {
		foreach ($properties as $name => $definition) {
			if (is_array($definition) === false || array_key_exists($name, $payload) === false) {
				continue;
			}

			$value = $payload[$name];

			if (($definition['type'] ?? '') === 'array') {
				$itemSchema = ($definition['items'] ?? []);
				if (is_array($value) === false || is_array($itemSchema) === false) {
					continue;
				}

				foreach ($value as $index => $entry) {
					if ($this->isPayload(node: $entry) === false) {
						continue;
					}

					$this->checkNestedObject(
						payload: $entry,
						definition: $itemSchema,
						schemaSlug: $schemaSlug,
						pointer: $pointer . '/' . $name . '[' . $index . ']',
						findings: $findings
					);
				}

				continue;
			}

			if ($this->isPayload(node: $value) === true) {
				$this->checkNestedObject(
					payload: $value,
					definition: $definition,
					schemaSlug: $schemaSlug,
					pointer: $pointer . '/' . $name,
					findings: $findings
				);
			}
		}//end foreach

	}//end checkNested()

	/**
	 * Check one nested object against the sub-schema that declares it.
	 *
	 * A sub-schema with no `properties` map declares nothing and constrains
	 * nothing, so a free-form object is left alone rather than reported.
	 *
	 * @param array<string, mixed> $payload The nested object.
	 * @param array<string, mixed> $definition Its sub-schema.
	 * @param string $schemaSlug The owning schema slug, for the message.
	 * @param string $pointer Human-readable path to the object.
	 * @param array<int, string> $findings Accumulated findings, appended in place.
	 *
	 * @return void
	 */
	private function checkNestedObject(
		array $payload,
		array $definition,
		string $schemaSlug,
		string $pointer,
		array &$findings,
	): void {
		$properties = ($definition['properties'] ?? []);
		if (is_array($properties) === false || $properties === []) {
			return;
		}

		foreach (array_keys($payload) as $key) {
			$name = (string)$key;
			if ($name === '' || $name[0] === '@' || $name[0] === '_') {
				continue;
			}

			if (array_key_exists($name, $properties) === false) {
				$findings[] = $pointer . '/' . $name . ': schema "' . $schemaSlug
					. '" does not declare it on this nested object';
			}
		}

		foreach (($definition['required'] ?? []) as $required) {
			$name = (string)$required;
			if ($name !== '' && array_key_exists($name, $payload) === false) {
				$findings[] = $pointer . ': schema "' . $schemaSlug . '" requires "'
					. $name . '" on this nested object, which it does not carry';
			}
		}

		$this->checkNested(
			payload: $payload,
			properties: $properties,
			schemaSlug: $schemaSlug,
			pointer: $pointer,
			findings: $findings
		);

	}//end checkNestedObject()

	/**
	 * Check that a payload carries every property its schema requires.
	 *
	 * A missing required property is the loud half of the same defect: the save
	 * throws instead of dropping, the seeder logs a warning and carries on, and
	 * the collection ends at zero rows while the install reports success. That
	 * is how the three `bouwtoezicht-*` checklist templates never installed.
	 *
	 * @param array<string, mixed> $payload The object payload as shipped.
	 * @param array<string, mixed> $schema The schema definition.
	 * @param string $schemaSlug The schema slug, for the message.
	 * @param string $pointer Human-readable path to the payload.
	 * @param array<int, string> $findings Accumulated findings, appended in place.
	 *
	 * @return void
	 */
	private function checkRequired(
		array $payload,
		array $schema,
		string $schemaSlug,
		string $pointer,
		array &$findings,
	): void {
		foreach (($schema['required'] ?? []) as $required) {
			$name = (string)$required;
			if ($name === '' || array_key_exists($name, $payload) === true) {
				continue;
			}

			// A `$ref` property the seeder resolves and injects itself (the
			// `caseType` back-reference `seedChildren()` sets) is not expected
			// in the shipped file.
			if ($name === 'caseType' && $schemaSlug !== 'case') {
				continue;
			}

			$findings[] = $pointer . ': schema "' . $schemaSlug
				. '" requires "' . $name . '", which the payload does not carry';
		}//end foreach

	}//end checkRequired()

	/**
	 * Whether a node is an object payload rather than a list or a scalar.
	 *
	 * @param mixed $node The node to test.
	 *
	 * @return bool True when the node is a string-keyed map.
	 */
	private function isPayload(mixed $node): bool {
		return (is_array($node) === true && $node !== [] && array_is_list($node) === false);

	}//end isPayload()
}//end class
