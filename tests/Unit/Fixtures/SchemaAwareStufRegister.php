<?php

/**
 * A StufRegisterAccess double that behaves like the live object store.
 *
 * The point of this class is the two behaviours a hand-written mock does NOT
 * reproduce, and whose absence let a whole family of dropped writes ship green:
 *
 * 1. A SAVE silently drops every property the schema does not declare.
 *    OpenRegister builds one column per declared property; a key with no column
 *    is not an error, it is simply not stored. The save answers 200 and returns
 *    the object, minus the field.
 * 2. A FILTER on an undeclared property matches ZERO rows. It is not ignored:
 *    MagicSearchHandler::applyObjectFilters() emits `1 = 0` for a filter key it
 *    cannot resolve to a column, so the query returns an empty set whatever is
 *    stored.
 *
 * The declared-property sets are read from the SHIPPED schema fragment rather
 * than restated here, so this double cannot agree with a caller that has drifted
 * away from the contract the app installs.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Fixtures
 *
 * @author    Conduction <info@conduction.nl>
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

namespace OCA\Dossiq\Tests\Unit\Fixtures;

use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use RuntimeException;

/**
 * Schema-aware in-memory stand-in for StufRegisterAccess.
 *
 * @psalm-suppress MissingConstructor The parent's promoted properties are never
 *                 read: every method that would touch them is overridden here.
 */
class SchemaAwareStufRegister extends StufRegisterAccess {
	/**
	 * The register fragment this app installs.
	 *
	 * @var string
	 */
	public const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/80-stuf-zkn-outbound.json';

	/**
	 * Declared property names per schema slug.
	 *
	 * @var array<string, list<string>>
	 */
	private array $declared = [];

	/**
	 * Stored rows, keyed by schema slug then row uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Every property key handed to saveObject(), keyed by schema slug.
	 *
	 * @var array<string, list<string>>
	 */
	public array $writtenKeys = [];

	/**
	 * Every filter key handed to findOne()/findAll(), keyed by schema slug.
	 *
	 * @var array<string, list<string>>
	 */
	public array $filterKeys = [];

	/**
	 * Constructor. Deliberately does NOT call the parent's.
	 *
	 * @return void
	 */
	public function __construct() {
		$fragment = json_decode((string)file_get_contents(self::FRAGMENT), true);
		if (is_array($fragment) === false) {
			throw new RuntimeException('The StUF register fragment does not parse.');
		}

		foreach (($fragment['components']['schemas'] ?? []) as $slug => $schema) {
			$this->declared[(string)$slug] = array_map(
				'strval',
				array_keys((array)($schema['properties'] ?? []))
			);
		}
	}//end __construct()

	/**
	 * The property names the shipped schema declares.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return list<string> The declared property names.
	 */
	public function declaredProperties(string $schema): array {
		return ($this->declared[$schema] ?? []);
	}//end declaredProperties()

	/**
	 * Save an object, dropping whatever the schema does not declare.
	 *
	 * @param string $schema The schema slug.
	 * @param array  $data   The object payload.
	 *
	 * @return array The stored row.
	 */
	public function saveObject(string $schema, array $data): array {
		foreach (array_keys($data) as $key) {
			$this->writtenKeys[$schema][] = (string)$key;
		}

		$uuid = (string)($data['id'] ?? '');
		if ($uuid === '') {
			$uuid = 'row-' . count(($this->store[$schema] ?? []));
		}

		// THE DROP. Live gives a declared property a column and an undeclared
		// one nothing at all, so the write succeeds and the value is gone.
		$row = array_intersect_key($data, array_flip($this->declaredProperties($schema)));
		$row['id'] = $uuid;

		$this->store[$schema][$uuid] = $row;
		return $row;
	}//end saveObject()

	/**
	 * Find one row by filter.
	 *
	 * @param string $schema  The schema slug.
	 * @param array  $filters The filter map.
	 *
	 * @return array|null The row, or null.
	 */
	public function findOne(string $schema, array $filters): ?array {
		$rows = $this->findAll(schema: $schema, filters: $filters, limit: 1);
		return ($rows[0] ?? null);
	}//end findOne()

	/**
	 * Find rows by filter, clamping to zero on an undeclared filter key.
	 *
	 * @param string $schema  The schema slug.
	 * @param array  $filters The filter map.
	 * @param int    $limit   The page size.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 */
	public function findAll(string $schema, array $filters = [], int $limit = 100): array {
		$declared = $this->declaredProperties(schema: $schema);
		foreach (array_keys($filters) as $key) {
			$this->filterKeys[$schema][] = (string)$key;
		}

		foreach (array_keys($filters) as $key) {
			// THE CLAMP. applyObjectFilters() emits `1 = 0` for a filter key it
			// cannot resolve to a column, so the query answers with no rows at
			// all rather than ignoring the clause.
			if (in_array((string)$key, $declared, true) === false) {
				return [];
			}
		}

		$matches = [];
		foreach (($this->store[$schema] ?? []) as $row) {
			foreach ($filters as $field => $value) {
				if (($row[$field] ?? null) !== $value) {
					continue 2;
				}
			}

			$matches[] = $row;
			if (count($matches) >= $limit) {
				break;
			}
		}

		return $matches;
	}//end findAll()

	/**
	 * Resolve a row by its identifier.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The row id.
	 *
	 * @return array|null The row, or null.
	 */
	public function findById(string $schema, string $id): ?array {
		return ($this->store[$schema][$id] ?? null);
	}//end findById()

	/**
	 * Seed a row directly, bypassing the drop.
	 *
	 * @param string $schema The schema slug.
	 * @param array  $row    The row.
	 *
	 * @return void
	 */
	public function seed(string $schema, array $row): void {
		$this->store[$schema][(string)($row['id'] ?? 'seed')] = $row;
	}//end seed()
}//end class
