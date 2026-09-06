<?php

/**
 * The SHIPPED register schema, readable from unit tests.
 *
 * Loads `lib/Settings/dossiq_register.json` and deep-merges the ADR-037
 * fragments from `lib/Settings/register.d/` on top — the exact configuration
 * SettingsService imports — so a test fake can serve only the properties a
 * schema actually declares, and a lifecycle assertion can read the edges that
 * actually ship.
 *
 * This exists because of the fake-agrees-with-caller class: a fake object
 * that hands back a `caseType` no schema declares keeps a dead read green for
 * months, and a writer whose status transition the shipped lifecycle refuses
 * looks fine to every test that never opened the register JSON. Building the
 * fakes FROM the shipped JSON makes schema drift red the test instead.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Fixtures
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Fixtures;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use RuntimeException;

/**
 * Reads schema facts out of the shipped register configuration.
 */
final class ShippedRegisterSchema {

	/**
	 * The merged (base + fragments) register configuration, cached per run.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $merged = null;

	/**
	 * The merged register configuration, exactly as the import sees it.
	 *
	 * @return array<string, mixed> The configuration.
	 */
	public static function merged(): array {
		if (self::$merged !== null) {
			return self::$merged;
		}

		$settingsDir = __DIR__ . '/../../../lib/Settings';
		$raw = file_get_contents($settingsDir . '/dossiq_register.json');
		if ($raw === false) {
			throw new RuntimeException('dossiq_register.json is not readable from the test');
		}

		$base = json_decode($raw, true);
		if (is_array($base) === false) {
			throw new RuntimeException('dossiq_register.json does not parse');
		}

		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $base,
			fragmentDir: $settingsDir . '/register.d'
		);
		self::$merged = $merged;

		return $merged;
	}//end merged()

	/**
	 * One schema out of the merged configuration.
	 *
	 * @param string $slug The schema key under components.schemas.
	 *
	 * @return array<string, mixed> The schema.
	 */
	public static function schema(string $slug): array {
		$schema = (self::merged()['components']['schemas'][$slug] ?? null);
		if (is_array($schema) === false) {
			throw new RuntimeException('The shipped register declares no schema ' . $slug);
		}

		return $schema;
	}//end schema()

	/**
	 * The property names a schema declares.
	 *
	 * @param string $slug The schema key.
	 *
	 * @return array<int, string> The declared property names.
	 */
	public static function declaredPropertyNames(string $slug): array {
		return array_map('strval', array_keys((array)(self::schema($slug)['properties'] ?? [])));
	}//end declaredPropertyNames()

	/**
	 * Strip a row to what OpenRegister would actually return.
	 *
	 * Keeps declared properties plus the `id`/`uuid`/`@self` metadata; every
	 * undeclared property is dropped, exactly as the live store drops them.
	 *
	 * @param array<string, mixed> $row The row a test wants to serve.
	 * @param string $slug The schema key the row claims to belong to.
	 *
	 * @return array<string, mixed> The row as the store would return it.
	 */
	public static function asStored(array $row, string $slug): array {
		$keep = array_merge(self::declaredPropertyNames($slug), ['id', 'uuid', '@self']);

		return array_intersect_key($row, array_flip($keep));
	}//end asStored()

	/**
	 * The lifecycle edges a schema declares, as "from>to" strings.
	 *
	 * @param string $slug The schema key.
	 *
	 * @return array<int, string> Every declared edge.
	 */
	public static function lifecycleEdges(string $slug): array {
		$lifecycle = (array)(self::schema($slug)['configuration']['x-openregister-lifecycle'] ?? []);
		$edges = [];
		foreach ((array)($lifecycle['transitions'] ?? []) as $transition) {
			if (is_array($transition) === false) {
				continue;
			}

			$to = (string)($transition['to'] ?? '');
			foreach ((array)($transition['from'] ?? []) as $from) {
				$edges[] = ((string)$from) . '>' . $to;
			}
		}

		return $edges;
	}//end lifecycleEdges()

	/**
	 * The values a schema's enum property allows.
	 *
	 * @param string $slug The schema key.
	 * @param string $property The property name.
	 *
	 * @return array<int, string> The allowed values.
	 */
	public static function enumValues(string $slug, string $property): array {
		return array_map(
			'strval',
			(array)(self::schema($slug)['properties'][$property]['enum'] ?? [])
		);
	}//end enumValues()

}//end class
