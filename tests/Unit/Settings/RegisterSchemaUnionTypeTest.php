<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * No shipped schema property declares a union `type` array.
 *
 * OpenRegister's importer refuses a property whose `type` is an array (for
 * example `["number", "null"]`) and DROPS THE WHOLE SCHEMA, silently: every
 * other property of that schema vanishes with it, and nothing in the import
 * summary says so. The acceptance proof caught `mandateArrangement` missing
 * on every fresh install because of exactly one such property
 * (`mandateGroups.items.properties.to_amount`). Nullability is expressed by
 * omitting the property, never by a union type.
 */
class RegisterSchemaUnionTypeTest extends TestCase {

	/**
	 * Every shipped register file whose schemas are imported.
	 *
	 * @return array<int, string> Absolute file paths.
	 */
	private function shippedRegisterFiles(): array {
		$files = [__DIR__ . '/../../../lib/Settings/dossiq_register.json'];
		foreach ((array)glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') as $file) {
			$files[] = (string)$file;
		}

		return $files;
	}

	/**
	 * Sweep every schema property for a union `type`.
	 *
	 * @return void
	 */
	public function testNoShippedSchemaPropertyDeclaresAUnionType(): void {
		$offenders = [];
		$schemasSeen = 0;

		foreach ($this->shippedRegisterFiles() as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			if (is_array($data) === false) {
				continue;
			}

			$schemas = (array)(((array)($data['components'] ?? []))['schemas'] ?? []);
			foreach ($schemas as $name => $schema) {
				$schemasSeen++;
				$this->collectUnionTypes(
					node: $schema,
					path: basename($file) . ' ' . (string)$name,
					offenders: $offenders,
				);
			}
		}

		$this->assertGreaterThan(0, $schemasSeen, 'The sweep saw no schemas at all — the query is broken, not the data clean');
		$this->assertSame(
			[],
			$offenders,
			"Union `type` arrays make OpenRegister drop the WHOLE schema on import, silently:\n" . implode("\n", $offenders)
		);
	}

	/**
	 * Collect every `type` given as an array.
	 *
	 * @param mixed $node The JSON node.
	 * @param string $path The path walked so far.
	 * @param array<int, string> $offenders Accumulator of findings.
	 *
	 * @return void
	 */
	private function collectUnionTypes(mixed $node, string $path, array &$offenders): void {
		if (is_array($node) === false) {
			return;
		}

		$type = ($node['type'] ?? null);
		if (is_array($type) === true && array_is_list($type) === true) {
			$offenders[] = $path . ' declares type [' . implode(', ', array_map('strval', $type)) . ']';
		}

		foreach ($node as $key => $value) {
			$this->collectUnionTypes(node: $value, path: $path . '/' . (string)$key, offenders: $offenders);
		}
	}
}//end class
