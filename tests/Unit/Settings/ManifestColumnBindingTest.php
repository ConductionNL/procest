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
 * Every manifest column and include key names a property the schema actually has.
 *
 * A column key that matches no property renders an em-dash in every row and
 * says nothing else. There is no console warning, no failed request and no
 * empty state: the list looks populated, and one column is simply blank
 * forever. The acceptance proof reported it as "the Voorstellen Subject column
 * shows a dash", and the cause was that the column asked for `onderwerp` while
 * the property had been renamed to `subject`.
 *
 * That rename is where the whole class comes from. `lib/Repair/RenameDutchColumns.php`
 * carries the Dutch to English map and moves the stored DATA; the manifest is
 * a separate file it never touches, so every key the rename moved had to be
 * followed by hand and six were missed across five pages. A seventh mismatch,
 * `updatedAt` on WorkflowDefinitions, is older: the timestamp lives on
 * `@self.updated` and never was a schema property.
 *
 * The sweep counts what it inspected before it asserts anything, so a manifest
 * that stopped parsing, or a schema map that came back empty, fails loudly
 * rather than reading as clean.
 */
class ManifestColumnBindingTest extends TestCase {

	/**
	 * Repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * Keys that are legitimately not schema properties.
	 *
	 * `@self.*` is OpenRegister's metadata envelope (created, updated, owner);
	 * `id` is the object id. Both resolve at render time and neither appears
	 * in a schema's `properties` map.
	 *
	 * @param string $key A column or include key.
	 *
	 * @return boolean True when the key resolves outside the schema.
	 */
	private function isEnvelopeKey(string $key): bool {
		return str_starts_with($key, '@') === true
			|| str_starts_with($key, '_') === true
			|| $key === 'id';
	}

	/**
	 * Every property name declared by every shipped schema, keyed by slug.
	 *
	 * The base register plus the `register.d/` fragments, merged the way the
	 * importer merges them: the base wins, a fragment only adds.
	 *
	 * @return array<string, array<int, string>> Schema slug => property names.
	 */
	private function shippedSchemaProperties(): array {
		$files = [self::ROOT . '/lib/Settings/dossiq_register.json'];
		foreach ((array)glob(self::ROOT . '/lib/Settings/register.d/*.json') as $file) {
			$files[] = (string)$file;
		}

		$schemas = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents((string)$file), true);
			if (is_array($data) === false) {
				continue;
			}

			$declared = (array)(((array)($data['components'] ?? []))['schemas'] ?? []);
			foreach ($declared as $slug => $schema) {
				if (isset($schemas[(string)$slug]) === true) {
					continue;
				}

				$schemas[(string)$slug] = array_keys((array)(((array)$schema)['properties'] ?? []));
			}
		}

		return $schemas;
	}

	/**
	 * Every (where, schema, key) triple the manifest binds a column to.
	 *
	 * Covers an index page's `config.columns`, a widget's `content.columns`
	 * and a data widget's `content.include` — the three places a manifest
	 * names a property to render.
	 *
	 * @return array<int, array{where: string, schema: string, key: string}> The bindings.
	 */
	private function manifestBindings(): array {
		$manifest = json_decode((string)file_get_contents(self::ROOT . '/src/manifest.json'), true);
		$this->assertIsArray($manifest, 'src/manifest.json did not parse');

		$bindings = [];
		foreach ((array)($manifest['pages'] ?? []) as $index => $page) {
			$config = (array)(((array)$page)['config'] ?? []);
			$pageSchema = (string)($config['schema'] ?? '');
			$label = 'pages[' . $index . '] ' . (string)(((array)$page)['id'] ?? '?');

			$this->collect(
				bindings: $bindings,
				where: $label . '.config.columns',
				schema: $pageSchema,
				keys: (array)($config['columns'] ?? []),
			);

			foreach ((array)($config['widgets'] ?? []) as $widget) {
				$content = (array)(((array)$widget)['content'] ?? []);
				$source = (array)($content['dataSource'] ?? []);
				$schema = (string)($content['schema'] ?? ($source['schema'] ?? $pageSchema));
				$widgetLabel = $label . '/' . (string)(((array)$widget)['id'] ?? '?');

				$this->collect(
					bindings: $bindings,
					where: $widgetLabel . '.content.columns',
					schema: $schema,
					keys: (array)($content['columns'] ?? []),
				);
				$this->collect(
					bindings: $bindings,
					where: $widgetLabel . '.content.include',
					schema: $schema,
					keys: (array)($content['include'] ?? []),
				);
			}
		}

		return $bindings;
	}

	/**
	 * Normalise one `columns` / `include` array into binding triples.
	 *
	 * An entry is either a bare string key or an object carrying `key`.
	 *
	 * @param array<int, array{where: string, schema: string, key: string}> $bindings Accumulator, by reference.
	 * @param string                                                       $where    Human-readable manifest path.
	 * @param string                                                       $schema   Schema slug the keys resolve against.
	 * @param array<int|string, mixed>                                     $keys     The raw entries.
	 *
	 * @return void
	 */
	private function collect(array &$bindings, string $where, string $schema, array $keys): void {
		if ($schema === '') {
			return;
		}

		foreach ($keys as $entry) {
			$key = $entry;
			if (is_array($entry) === true) {
				$key = ($entry['key'] ?? null);
			}

			if (is_string($key) === false || $key === '') {
				continue;
			}

			$bindings[] = ['where' => $where, 'schema' => $schema, 'key' => $key];
		}
	}

	/**
	 * No manifest column or include key is unresolvable against its schema.
	 *
	 * @return void
	 */
	public function testEveryManifestColumnKeyResolvesToASchemaProperty(): void {
		$schemas = $this->shippedSchemaProperties();
		$bindings = $this->manifestBindings();

		$this->assertGreaterThan(
			0,
			count($schemas),
			'The sweep loaded no schemas at all, so it could not have found a mismatch'
		);
		$this->assertGreaterThan(
			0,
			count($bindings),
			'The sweep found no column bindings at all, so it could not have found a mismatch'
		);

		$offenders = [];
		$checked = 0;
		foreach ($bindings as $binding) {
			if ($this->isEnvelopeKey($binding['key']) === true) {
				continue;
			}

			$properties = ($schemas[$binding['schema']] ?? null);
			if ($properties === null) {
				// A page over another app's schema. Not this repo's contract.
				continue;
			}

			$checked++;
			$root = explode('.', $binding['key'])[0];
			if (in_array($root, $properties, true) === true) {
				continue;
			}

			$offenders[] = sprintf(
				'%s asks for "%s", which schema "%s" does not declare',
				$binding['where'],
				$binding['key'],
				$binding['schema']
			);
		}

		$this->assertGreaterThan(
			0,
			$checked,
			'Every binding was skipped, so an all-clear says nothing about the manifest'
		);
		$this->assertSame(
			[],
			$offenders,
			"A column bound to a property the schema does not declare renders a dash in every row, silently:\n"
			. implode("\n", $offenders)
		);
	}
}
