<?php

/**
 * FakeTermijnStore fixture
 *
 * In-memory ObjectService fake reused across the termijnbewaking +
 * archief-edepot unit tests, pinned to OpenRegister's REAL contract.
 *
 * Loaded via tests/bootstrap.php so individual test files can run
 * standalone — previously the class was declared at the bottom of
 * TermijnServiceTest.php, which meant any --filter run against a single
 * other test file fataled with "Class FakeTermijnStore not found".
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCP\AppFramework\Db\DoesNotExistException;

// Idempotence guard. Checked against FakeStoredObject, NOT FakeTermijnStore:
// FakeTermijnStore has no extends/implements clause, so PHP hoists its
// declaration to compile time and it exists BEFORE this line runs — a guard
// on it would always return early and FakeStoredObject (declared at runtime,
// because it implements JsonSerializable) would never be defined.
if (class_exists(FakeStoredObject::class, false) === true) {
	return;
}

/**
 * Entity-shaped wrapper mirroring what OpenRegister's ObjectService
 * actually returns: an ObjectEntity, NOT a plain array. Consumers must
 * go through `jsonSerialize()` (as SearchesObjects::saveObjectAsArray /
 * findObjectAsArray do) — an `is_array()` check on this return is the
 * exact green-CI/dead-runtime bug this fake used to hide.
 */
class FakeStoredObject implements \JsonSerializable {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data The stored object data.
	 */
	public function __construct(
		private readonly array $data,
	) {
	}

	/**
	 * The stored object as an associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->data;
	}

	/**
	 * The object UUID, mirroring ObjectEntity::getUuid().
	 *
	 * @return string
	 */
	public function getUuid(): string {
		return (string)($this->data['id'] ?? '');
	}
}

/**
 * In-memory ObjectService fake pinned to OpenRegister's REAL contract:
 *
 * - `saveObject()` declares the real signature — `$object` FIRST, then
 *   `$extend`, `$register`, `$schema`, `$uuid`. A caller still using the
 *   retired `($register, $schema, $object)` order fatals here with the
 *   same TypeError it produces against the live service. It returns an
 *   entity-shaped object, never an array.
 * - `find()` declares the real argument order and THROWS
 *   `DoesNotExistException` for an unknown id, exactly like live.
 * - `searchObjects()` / `searchObjectsBySlug()` resolve object ids ONLY
 *   via the `@self` metadata block (`@self.uuid`). A top-level
 *   `'id' => …` / `'uuid' => …` filter silently matches ZERO rows, the
 *   way the live search treats a filter on a schema property no schema
 *   declares. A fake that resolved those keys is a fake that cannot
 *   fail — it hid the dead parafering-raise lookup for months.
 *
 * Tests seed and inspect rows through `seed()` / `get()`; only code
 * under test should call the ObjectService-shaped methods.
 */
class FakeTermijnStore {
	/**
	 * Object store, keyed by schema slug then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Auto-increment id sequence.
	 *
	 * @var int
	 */
	private int $seq = 0;

	/**
	 * Test-side seeding helper: persist a row directly, bypassing the
	 * ObjectService contract (this is the fixture's own API, not OR's).
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $object Object data.
	 *
	 * @return array<string, mixed> The stored row (with generated id).
	 */
	public function seed(string $schema, array $object): array {
		if (empty($object['id']) === true) {
			$this->seq++;
			$object['id'] = $schema . '-' . $this->seq;
		}
		$this->store[$schema][$object['id']] = $object;
		return $object;
	}

	/**
	 * Test-side read helper: fetch a stored row as a plain array.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id Object id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get(string $schema, string $id): ?array {
		return ($this->store[$schema][$id] ?? null);
	}

	/**
	 * Find a single object by id — real ObjectService argument order,
	 * entity-shaped return, DoesNotExistException on a miss.
	 *
	 * @param int|string $id Object id.
	 * @param array|null $_extend Relations to expand (ignored).
	 * @param bool $files Include file metadata (ignored).
	 * @param string|int|null $register Register id or slug (ignored).
	 * @param string|int|null $schema Schema slug; null searches all schemas.
	 *
	 * @return FakeStoredObject The stored object.
	 *
	 * @throws DoesNotExistException When the id is unknown.
	 */
	public function find(
		int|string $id,
		?array $_extend = [],
		bool $files = false,
		string|int|null $register = null,
		string|int|null $schema = null,
	): FakeStoredObject {
		$id = (string)$id;
		$schemas = ($schema === null ? array_keys($this->store) : [(string)$schema]);
		foreach ($schemas as $slug) {
			if (isset($this->store[$slug][$id]) === true) {
				return new FakeStoredObject($this->store[$slug][$id]);
			}
		}

		throw new DoesNotExistException('Object ' . $id . ' does not exist');
	}

	/**
	 * Equality-filter object search over stored rows.
	 *
	 * OpenRegister's real search treats `_limit`/`_offset` as pagination
	 * keys (stripped here) and resolves object identity ONLY via the
	 * `@self` metadata block. A top-level `id`/`uuid` filter addresses a
	 * schema property no schema declares, so — like live — it matches
	 * ZERO rows instead of resolving the object.
	 *
	 * @param string $register Register (ignored; single-register fake).
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $filters Object-field filters.
	 * @param string|null $uuid Metadata uuid filter (from `@self.uuid`).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findObjects(string $register, string $schema, array $filters = [], ?string $uuid = null): array {
		$rows = array_values($this->store[$schema] ?? []);

		unset($filters['_limit'], $filters['_offset']);

		if (array_key_exists('id', $filters) === true || array_key_exists('uuid', $filters) === true) {
			// Live behaviour: a filter on an undeclared schema property
			// silently returns zero rows. Do NOT resolve it here.
			return [];
		}

		if ($uuid !== null) {
			$rows = array_values(array_filter(
				$rows,
				static fn (array $row): bool => (string)($row['id'] ?? '') === $uuid,
			));
		}

		if (count($filters) === 0) {
			return $rows;
		}

		return array_values(array_filter(
			$rows,
			static function (array $row) use ($filters): bool {
				foreach ($filters as $key => $value) {
					if (($row[$key] ?? null) !== $value) {
						return false;
					}
				}
				return true;
			},
		));
	}

	/**
	 * Slug-aware search bridge mirroring OpenRegister
	 * ObjectService::searchObjectsBySlug(). A `@self` block inside the
	 * filter map carries metadata filters (uuid); direct keys stay
	 * object-field filters.
	 *
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 * @param array<string, mixed> $filters Object-field filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
		$self = ($filters['@self'] ?? []);
		unset($filters['@self']);
		$uuid = (isset($self['uuid']) === true ? (string)$self['uuid'] : null);

		return $this->findObjects($registerSlug, $schemaSlug, $filters, $uuid);
	}

	/**
	 * Numeric-ID search bridge mirroring OpenRegister
	 * ObjectService::searchObjects(). The SearchesObjects trait packs
	 * register/schema into a `@self` block and keeps object-field filters
	 * at the top level; `@self.uuid` is the ONLY way to address an object
	 * by id through search.
	 *
	 * @param array<string, mixed> $query Query with `@self` register/schema plus field filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjects(array $query = []): array {
		$self = ($query['@self'] ?? []);
		$schema = (string)($self['schema'] ?? '');
		$uuid = (isset($self['uuid']) === true ? (string)$self['uuid'] : null);
		unset($query['@self']);

		return $this->findObjects('', $schema, $query, $uuid);
	}

	/**
	 * Persist (insert or update) an object — REAL ObjectService signature.
	 *
	 * `$object` comes FIRST. A caller still passing the retired
	 * `($register, $schema, $object)` order fatals here on the `array`
	 * type of `$object`, exactly as it does against the live service.
	 *
	 * @param array<string, mixed> $object The object to store.
	 * @param array|null $extend Relations to expand on the result (ignored).
	 * @param string|int|null $register Register id or slug (ignored).
	 * @param string|int|null $schema Schema slug.
	 * @param string|null $uuid UUID of the object to update, null to create.
	 * @param bool $_rbac Apply register RBAC (ignored).
	 * @param bool $_multitenancy Apply organisation scoping (ignored).
	 * @param bool $silent Suppress events (ignored).
	 * @param bool $_validation Validate against the schema (ignored).
	 *
	 * @return FakeStoredObject The stored object, entity-shaped like live.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
	): FakeStoredObject {
		$schema = (string)$schema;
		if ($uuid !== null && $uuid !== '') {
			$object['id'] = $uuid;
		}

		if (empty($object['id']) === true) {
			$this->seq++;
			$object['id'] = $schema . '-' . $this->seq;
		}

		$this->store[$schema][$object['id']] = $object;
		return new FakeStoredObject($object);
	}
}
