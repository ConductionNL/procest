<?php

/**
 * Shipped Object Property Type Unit Tests
 *
 * Sweeps every object shipped in the register configuration against the
 * schema that object declares, checking that each value's JSON type is the
 * type its property declares.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * A shipped object whose value does not match its declared property type is
 * REFUSED by the register import, and the import still reports success.
 *
 * Measured on 2026-09-05: nine sociaal-domein `zaak` objects never landed on
 * any install. Their `gdprClassification` was an object, and the property was
 * declared as `{"$ref": "#/components/schemas/gdprClassification"}` — a
 * JSON-Pointer reference OpenRegister does not resolve at import time, so the
 * property carried no `type` at all and the column fell back to string. The
 * data was right and the declaration was wrong, but the symptom was only a
 * lower object count in a summary that said "success".
 *
 * Three sweeps, because the defect had three ways of hiding:
 *
 * 1. Every top-level property declares a `type`. The column type is decided
 *    there, so an absent one is the failure above. The sweep is deliberately
 *    top-level: a nested node inside a JSON column may be untyped on purpose
 *    (`caseModel.planItems[].entryCriteria[].ifPart.value` compares against
 *    any scalar), and pruning those would change behaviour, not fix it.
 * 2. No property refers to another schema by JSON Pointer. OpenRegister reads
 *    a `$ref` as a bare relation slug; `#/components/schemas/x` matches no
 *    slug, so the reference resolves to nothing and takes the type with it.
 * 3. Every shipped value matches the type — and the enum — its property
 *    declares. An enum violation is refused exactly like a type violation,
 *    and three participatiewet objects carried `financieel` against an enum
 *    that had been anglicised to `financial`.
 *
 * @covers \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class ShippedObjectPropertyTypeTest extends TestCase {

	/**
	 * Every configuration whose objects an install imports, by name.
	 *
	 * Two of them, because the demo objects arrive by two routes and the same
	 * mistake fits both: the effective register configuration (the monolith
	 * plus its ADR-037 fragments), and the ADR-111 mock descriptor
	 * DemoDataService imports on request. The mock ships a generated copy of
	 * the same schemas, so a shape fixed in one and not the other is a fix
	 * that only half landed.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $configurations;

	/**
	 * The key of the effective register configuration in {@see $configurations}.
	 *
	 * @var string
	 */
	private const EFFECTIVE = 'dossiq_register.json + register.d';

	/**
	 * Load both shipped configurations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$settings = __DIR__ . '/../../../lib/Settings';

		$base = json_decode((string)file_get_contents($settings . '/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $base,
			fragmentDir: $settings . '/register.d'
		);

		$this->configurations = [
			self::EFFECTIVE => (array)$merged,
			'dossiq_mock_register.json' => (array)json_decode(
				(string)file_get_contents($settings . '/dossiq_mock_register.json'),
				true
			),
		];
	}//end setUp()

	/**
	 * Every top-level schema property declares a `type`.
	 *
	 * @return void
	 */
	public function testEveryShippedPropertyDeclaresAType(): void {
		$offenders = [];
		$propertiesSeen = 0;

		foreach ($this->configurations as $where => $configuration) {
			foreach ($this->schemasOf(configuration: $configuration) as $schemaName => $schema) {
				foreach ((array)($schema['properties'] ?? []) as $propertyName => $definition) {
					if (is_array($definition) === false) {
						continue;
					}

					$propertiesSeen++;
					if (array_key_exists('type', $definition) === false) {
						$offenders[] = $where . ' ' . $schemaName . '.' . (string)$propertyName
							. ' declares no type: ' . (string)json_encode($definition);
					}
				}
			}
		}

		$this->assertGreaterThan(
			0,
			$propertiesSeen,
			'The sweep saw no properties at all — the query is broken, not the data clean'
		);
		$this->assertSame(
			[],
			$offenders,
			"A property with no `type` imports as a string column, so an object or an array\n"
			. "written into it is REFUSED and the whole row is skipped:\n" . implode("\n", $offenders)
		);
	}//end testEveryShippedPropertyDeclaresAType()

	/**
	 * No property points at another schema with a JSON Pointer.
	 *
	 * @return void
	 */
	public function testNoPropertyReferencesASchemaByJsonPointer(): void {
		$offenders = [];

		foreach ($this->configurations as $where => $configuration) {
			foreach ($this->schemasOf(configuration: $configuration) as $schemaName => $schema) {
				$this->collectJsonPointerRefs(
					node: $schema,
					path: $where . ' ' . (string)$schemaName,
					offenders: $offenders
				);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"OpenRegister reads a `\$ref` as a bare relation slug, so a JSON Pointer resolves\n"
			. "to nothing and the property is left untyped. Declare the shape inline:\n"
			. implode("\n", $offenders)
		);
	}//end testNoPropertyReferencesASchemaByJsonPointer()

	/**
	 * Every shipped object's values match the types their properties declare.
	 *
	 * @return void
	 */
	public function testEveryShippedObjectMatchesItsDeclaredTypes(): void {
		$offenders = [];
		$objectsSeen = 0;

		// An object is validated against the schema the INSTALL will use, which
		// is the effective register's. The mock descriptor carries its own
		// snapshot of the schemas and DemoDataService strips it before import
		// (see DemoDataService::objectsOnly), so the snapshot is a fallback
		// here, never the authority.
		$effective = $this->schemasOf(configuration: $this->configurations[self::EFFECTIVE]);

		foreach ($this->configurations as $where => $configuration) {
			$snapshot = $this->schemasOf(configuration: $configuration);
			foreach ((array)($configuration['components']['objects'] ?? []) as $object) {
				if (is_array($object) === false) {
					continue;
				}

				$schemaName = (string)($object['@self']['schema'] ?? '');
				$slug = (string)($object['@self']['slug'] ?? '(no slug)');
				$schema = ($effective[$schemaName] ?? ($snapshot[$schemaName] ?? null));
				if (is_array($schema) === false) {
					$offenders[] = $where . ' ' . $slug . ' names schema "' . $schemaName
						. '", which this app ships nowhere';
					continue;
				}

				$objectsSeen++;
				$properties = (array)($schema['properties'] ?? []);
				foreach ($object as $key => $value) {
					if ($key === '@self' || isset($properties[$key]) === false) {
						continue;
					}

					$this->collectValueMismatches(
						definition: (array)$properties[$key],
						value: $value,
						path: $where . ' ' . $schemaName . '/' . $slug . '.' . (string)$key,
						offenders: $offenders
					);
				}
			}
		}

		$this->assertGreaterThan(
			0,
			$objectsSeen,
			'The sweep saw no objects at all — the query is broken, not the data clean'
		);
		$this->assertSame(
			[],
			$offenders,
			"The register import REFUSES a row whose value does not match its declared\n"
			. "property, and still reports the import as a success:\n" . implode("\n", $offenders)
		);
	}//end testEveryShippedObjectMatchesItsDeclaredTypes()

	/**
	 * One configuration's schema map.
	 *
	 * @param array<string, mixed> $configuration The decoded configuration.
	 *
	 * @return array<string, array<string, mixed>> Schemas by name.
	 */
	private function schemasOf(array $configuration): array {
		return (array)($configuration['components']['schemas'] ?? []);
	}//end schemasOf()

	/**
	 * Collect every `$ref` written as a JSON Pointer, at any depth.
	 *
	 * @param mixed $node The JSON node.
	 * @param string $path The path walked so far.
	 * @param array<int, string> $offenders Accumulator of findings.
	 *
	 * @return void
	 */
	private function collectJsonPointerRefs(mixed $node, string $path, array &$offenders): void {
		if (is_array($node) === false) {
			return;
		}

		$reference = ($node['$ref'] ?? null);
		if (is_string($reference) === true && str_starts_with($reference, '#/') === true) {
			$offenders[] = $path . ' refers to ' . $reference;
		}

		foreach ($node as $key => $value) {
			$this->collectJsonPointerRefs(node: $value, path: $path . '/' . (string)$key, offenders: $offenders);
		}
	}//end collectJsonPointerRefs()

	/**
	 * Compare one shipped value against its declared property, recursing into
	 * declared object properties and array items.
	 *
	 * @param array<string, mixed> $definition The property definition.
	 * @param mixed $value The shipped value.
	 * @param string $path The path walked so far, for the failure message.
	 * @param array<int, string> $offenders Accumulator of findings.
	 *
	 * @return void
	 */
	private function collectValueMismatches(array $definition, mixed $value, string $path, array &$offenders): void {
		if ($value === null) {
			return;
		}

		$declared = ($definition['type'] ?? null);
		if (is_string($declared) === false) {
			return;
		}

		$actual = $this->jsonType(value: $value);
		if ($value === [] && in_array($declared, ['object', 'array'], true) === true) {
			// An empty JSON array and an empty JSON object decode identically.
			return;
		}

		if ($this->typeAccepts(declared: $declared, actual: $actual) === false) {
			$offenders[] = $path . ' is declared ' . $declared . ' but ships ' . $actual;
			return;
		}

		$enum = ($definition['enum'] ?? null);
		if (is_array($enum) === true && is_scalar($value) === true && in_array($value, $enum, true) === false) {
			$offenders[] = $path . ' ships "' . (string)$value . '", which its enum does not allow ('
				. implode(', ', array_map('strval', $enum)) . ')';
		}

		if ($declared === 'array' && is_array($definition['items'] ?? null) === true) {
			foreach ((array)$value as $index => $element) {
				$this->collectValueMismatches(
					definition: (array)$definition['items'],
					value: $element,
					path: $path . '[' . (string)$index . ']',
					offenders: $offenders
				);
			}
		}

		if ($declared === 'object' && is_array($definition['properties'] ?? null) === true) {
			$properties = (array)$definition['properties'];
			foreach ((array)$value as $key => $nested) {
				if (isset($properties[$key]) === false) {
					continue;
				}

				$this->collectValueMismatches(
					definition: (array)$properties[$key],
					value: $nested,
					path: $path . '.' . (string)$key,
					offenders: $offenders
				);
			}
		}
	}//end collectValueMismatches()

	/**
	 * The JSON type of a decoded PHP value.
	 *
	 * A decoded JSON object and a decoded JSON array are both PHP arrays, so
	 * the two are told apart by whether the keys form a list.
	 *
	 * @param mixed $value The decoded value.
	 *
	 * @return string One of: boolean, integer, number, string, array, object.
	 */
	private function jsonType(mixed $value): string {
		if (is_bool($value) === true) {
			return 'boolean';
		}

		if (is_int($value) === true) {
			return 'integer';
		}

		if (is_float($value) === true) {
			return 'number';
		}

		if (is_string($value) === true) {
			return 'string';
		}

		if (is_array($value) === true) {
			if ($value === [] || array_is_list($value) === true) {
				return 'array';
			}

			return 'object';
		}

		return 'unknown';
	}//end jsonType()

	/**
	 * Whether a declared JSON Schema type accepts an observed JSON type.
	 *
	 * @param string $declared The declared type.
	 * @param string $actual The observed type.
	 *
	 * @return bool Whether the value is acceptable.
	 */
	private function typeAccepts(string $declared, string $actual): bool {
		if ($declared === $actual) {
			return true;
		}

		// Every JSON integer is a valid JSON number.
		return ($declared === 'number' && $actual === 'integer');
	}//end typeAccepts()
}//end class
