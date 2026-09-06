<?php

/**
 * Sweeps the shipped register configuration for schemas nothing carries.
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

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * A schema the register does not carry is imported and then unreachable.
 *
 * OpenRegister resolves a schema slug WITHIN the register a caller names:
 * `RegisterScopedSchemaResolver::resolveSchemaWithin()` looks the slug up in
 * `Register::getSchemas()` and throws `SchemaNotInRegisterException` otherwise.
 * That list comes from `components.registers.<slug>.schemas` in the imported
 * configuration. So a schema declared under `components.schemas` but left out
 * of the register's own list is created as a row, attached to nothing, and
 * answers every read and write with
 *
 *   Schema slug "x" is not carried by register "dossiq" (id 18)
 *
 * MEASURED, NOT THEORISED. On a fresh rig, `lhsMatrixCell` was in exactly that
 * position: 16 seed rows refused, the register carrying 122 schemas — the
 * declared list, to the row. Three VTH checklist rows failed with the same
 * message naming `lhsMatrixCell`, a slug they never mentioned, because
 * OpenRegister's `setSchema()` leaves the unresolved ref pending for the next
 * caller. `80-stuf-zkn-outbound.json` was worse: it wrote `registers` and
 * `schemas` at the TOP level rather than under `components`, so its three
 * schemas were never created at all, and `stufEndpoint` and `stufMessage`
 * failed 46 times on that same rig.
 *
 * Both are the same defect at different depths, so this file checks the
 * merged, effective configuration the importer actually receives, produced by
 * the app's OWN {@see RegisterFragmentMerger} rather than a re-implementation
 * of it.
 *
 * @coversNothing
 */
class RegisterSchemaCarriageTest extends TestCase {

	/**
	 * The register slug every dossiq schema belongs to.
	 *
	 * @var string
	 */
	private const REGISTER = 'dossiq';

	/**
	 * The effective configuration: the monolith with every fragment merged on.
	 *
	 * @return array<string, mixed> The merged configuration.
	 */
	private function effectiveConfiguration(): array {
		$settings = __DIR__ . '/../../../lib/Settings';
		$base = json_decode((string)file_get_contents($settings . '/dossiq_register.json'), true);
		self::assertIsArray($base, 'dossiq_register.json did not parse.');

		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $settings . '/register.d');

		return $merged;
	}//end effectiveConfiguration()

	/**
	 * Every schema the app defines is carried by the register it belongs to.
	 *
	 * @return void
	 */
	public function testEveryDefinedSchemaIsCarriedByTheRegister(): void {
		$config = $this->effectiveConfiguration();

		$defined = array_keys($config['components']['schemas'] ?? []);
		$carried = ($config['components']['registers'][self::REGISTER]['schemas'] ?? []);

		// The positive control: an empty read of either side would make the
		// diff below trivially empty, so both must be substantial first.
		self::assertGreaterThan(100, count($defined), 'The sweep read almost no schemas; it cannot have checked any.');
		self::assertGreaterThan(100, count($carried), 'The register carries almost no schemas; the list was not read.');

		$orphans = array_values(array_diff($defined, $carried));
		self::assertSame(
			[],
			$orphans,
			sprintf(
				'These schemas are defined in the shipped configuration but are not listed in the "%s" register, so '
				. 'OpenRegister refuses every read and write against them with "is not carried by register": %s',
				self::REGISTER,
				implode(', ', $orphans)
			)
		);
	}//end testEveryDefinedSchemaIsCarriedByTheRegister()

	/**
	 * The register lists no slug the configuration never defines.
	 *
	 * The mirror of the assertion above, and it is not redundant: a register
	 * entry for a schema nothing declares binds the register to whatever
	 * another app happens to own under that slug, which is how a cross-app
	 * collision starts.
	 *
	 * @return void
	 */
	public function testTheRegisterCarriesNoUndefinedSlug(): void {
		$config = $this->effectiveConfiguration();

		$defined = array_keys($config['components']['schemas'] ?? []);
		$carried = ($config['components']['registers'][self::REGISTER]['schemas'] ?? []);

		$unknown = array_values(array_diff($carried, $defined));
		self::assertSame(
			[],
			$unknown,
			sprintf(
				'The "%s" register lists schema slugs the shipped configuration never defines: %s',
				self::REGISTER,
				implode(', ', $unknown)
			)
		);
	}//end testTheRegisterCarriesNoUndefinedSlug()

	/**
	 * No fragment declares registers or schemas outside `components`.
	 *
	 * The merger merges onto `components`, and the importer reads
	 * `components.registers` / `components.schemas`. A top-level `registers` or
	 * `schemas` key parses, merges and imports nothing — silently. Nothing else
	 * in the pipeline notices, which is why this is asserted on the FILES
	 * rather than on the merged result: the merged result is where the evidence
	 * has already been lost.
	 *
	 * @return void
	 */
	public function testNoFragmentDeclaresRegistersOrSchemasOutsideComponents(): void {
		$files = glob(__DIR__ . '/../../../lib/Settings/register.d/*.json');
		self::assertIsArray($files);
		self::assertGreaterThan(15, count($files), 'The fragment sweep found almost no fragments.');

		$misplaced = [];
		foreach ($files as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			self::assertIsArray($fragment, sprintf('%s did not parse.', basename($file)));

			foreach (['registers', 'schemas', 'endpoints'] as $key) {
				if (array_key_exists($key, $fragment) === true) {
					$misplaced[] = basename($file) . ':' . $key;
				}
			}
		}

		self::assertSame(
			[],
			$misplaced,
			sprintf(
				'These fragments declare importer keys at the top level instead of under `components`, where nothing '
				. 'reads them: %s',
				implode(', ', $misplaced)
			)
		);
	}//end testNoFragmentDeclaresRegistersOrSchemasOutsideComponents()

	/**
	 * Every schema slug named in PHP resolves inside the register.
	 *
	 * The assertion above proves the shipped DATA is self-consistent. This one
	 * proves the CODE agrees with it: a literal slug in a `saveObject()` or
	 * search call, and every slug in `SchemaSlugMap`, must be a schema the
	 * register carries. `lhsMatrixCell` was a literal in three call sites while
	 * the register did not carry it.
	 *
	 * @return void
	 */
	public function testEverySchemaSlugNamedInCodeIsCarried(): void {
		$config = $this->effectiveConfiguration();
		$carried = ($config['components']['registers'][self::REGISTER]['schemas'] ?? []);

		$named = array_merge($this->literalSchemaSlugs(), $this->mappedSchemaSlugs());
		$defined = array_keys($config['components']['schemas'] ?? []);

		self::assertGreaterThan(100, count($named), 'No schema slugs were read out of the source; nothing was checked.');

		// A slug the configuration does not define at all is a different
		// defect (a code reference to a retired schema) and is not this
		// assertion's business — it is the sibling test below.
		$unreachable = array_values(array_diff(array_intersect($named, $defined), $carried));

		self::assertSame(
			[],
			$unreachable,
			sprintf(
				'These schema slugs are named in lib/ and defined in the configuration, but the "%s" register does not '
				. 'carry them, so every call naming one is refused: %s',
				self::REGISTER,
				implode(', ', $unreachable)
			)
		);
	}//end testEverySchemaSlugNamedInCodeIsCarried()

	/**
	 * Schema slugs written as literals in `lib/`.
	 *
	 * @return array<int, string> Slugs.
	 */
	private function literalSchemaSlugs(): array {
		$slugs = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(__DIR__ . '/../../../lib', \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			foreach (["/schema:\s*'([A-Za-z][A-Za-z0-9]*)'/", "/\\\$schema\s*=\s*'([A-Za-z][A-Za-z0-9]*)'/"] as $pattern) {
				preg_match_all($pattern, $source, $matches);
				$slugs = array_merge($slugs, ($matches[1] ?? []));
			}
		}

		return array_values(array_unique($slugs));
	}//end literalSchemaSlugs()

	/**
	 * Schema slugs the app maps to a config key.
	 *
	 * @return array<int, string> Slugs.
	 */
	private function mappedSchemaSlugs(): array {
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/Service/Settings/SchemaSlugMap.php');
		preg_match_all("/'([A-Za-z][A-Za-z0-9]*)' => '[a-z0-9_]+_schema'/", $source, $matches);

		return array_values(array_unique($matches[1] ?? []));
	}//end mappedSchemaSlugs()
}//end class
