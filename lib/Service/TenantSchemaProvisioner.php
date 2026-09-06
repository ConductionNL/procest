<?php

/**
 * Dossiq Tenant Schema Provisioner
 *
 * Wraps the raw PostgreSQL DDL primitives (CREATE SCHEMA, table cloning,
 * DROP SCHEMA) used by the schema-per-tenant provisioning flow.
 *
 * Schema names are pre-validated by `TenantProvisioningService::buildSchemaName()`
 * (UUID-derived, ≤63 chars, identifier-safe) so the DDL bind cannot inject.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-03-schema-provisioning/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Postgres-native schema-per-tenant primitives.
 *
 * The cloning step copies application table structures (not shared tables —
 * those stay in `public`). Shared tables are the SaaS-control plane:
 * `tenant`, `tenantConfiguration`, `tenantQuota`, `tenantUser`,
 * `tenantMandate`, `tenantBillingEvent`, `tenantOnboardingTask`.
 *
 * @spec openspec/specs/tenant-provisioning/spec.md#requirement-schema-per-tenant-provisioning-req-001-b
 */
class TenantSchemaProvisioner {
	/**
	 * Maximum PostgreSQL identifier length.
	 */
	public const PG_IDENTIFIER_MAX_LENGTH = 63;

	/**
	 * Register slugs whose per-schema shard tables are cloned per tenant.
	 *
	 * MEASURED, not assumed: OpenRegister does NOT name a shard table after the
	 * register slug. Every call site composes `openregister_table_` .
	 * $register->getId() . '_' . $schema->getId() — numeric ids — and
	 * RegisterSchemaLinkageRepairService rejects anything that does not match
	 * `^[A-Za-z0-9]+_openregister_table_[0-9]+_[0-9]+$`. On this install the
	 * tables are `oc_openregister_table_11_34`, `oc_openregister_table_17_*`
	 * and so on; there is no `oc_openregister_table_procest_*`, and there never
	 * was one to freeze.
	 *
	 * The constant this replaces hard-coded that slug-shaped prefix, so
	 * listApplicationTables() matched ZERO tables and every tenant came up with
	 * an empty schema — reported as success. Exactly the failure its own
	 * comment warned a rename would cause, already happening. The slug is now
	 * resolved to its numeric register id at run time, and the match anchors on
	 * the `openregister_table_` MARKER rather than on a computed `oc_` prefix,
	 * which is configurable per install.
	 *
	 * @var array<int, string>
	 */
	private const REGISTER_SLUGS = ['dossiq', 'dossiq-default'];

	/**
	 * The marker every OpenRegister shard-table name carries.
	 *
	 * @var string
	 */
	private const TABLE_MARKER = 'openregister_table_';

	/**
	 * Shared tables that MUST stay in the public schema.
	 *
	 * @var array<int, string>
	 */
	private const SHARED_SCHEMA_SLUGS = [
		'tenant',
		'tenantConfiguration',
		'tenantQuota',
		'tenantUser',
		'tenantMandate',
		'tenantBillingEvent',
		'tenantOnboardingTask',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db DB connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a new schema.
	 *
	 * @param string $name Schema name (already validated).
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the name is invalid.
	 * @throws RuntimeException When the DDL fails.
	 *
	 * @spec openspec/changes/tenant-zaaksysteem-saas-03-schema-provisioning/tasks.md
	 */
	public function createSchema(string $name): void {
		$this->assertSafeIdentifier(name: $name);

		try {
			// The identifier is whitelisted (assertSafeIdentifier); double-quoting
			// it makes Postgres reject any remaining injection attempt.
			$sql = 'CREATE SCHEMA "' . $name . '"';
			$this->db->executeStatement($sql);
		} catch (Throwable $e) {
			throw new RuntimeException('CREATE SCHEMA failed: ' . $e->getMessage(), 0, $e);
		}
	}//end createSchema()

	/**
	 * Clone application table structures from `public` into the tenant schema.
	 *
	 * Uses `CREATE TABLE ... (LIKE source INCLUDING ALL)` so constraints,
	 * defaults, and indexes are preserved. Shared tables are skipped.
	 *
	 * @param string $schemaName Target tenant schema.
	 *
	 * @return array<int, string> Cloned table names.
	 *
	 * @throws RuntimeException On DDL failure.
	 *
	 * @spec openspec/specs/tenant-provisioning/spec.md#requirement-schema-per-tenant-provisioning-req-001-b
	 */
	public function cloneApplicationTables(string $schemaName): array {
		$this->assertSafeIdentifier(name: $schemaName);

		$sourceTables = $this->listApplicationTables();
		$cloned = [];

		foreach ($sourceTables as $sourceTable) {
			if ($this->isSharedTable(tableName: $sourceTable) === true) {
				continue;
			}

			$tableName = $this->extractTableName(fullName: $sourceTable);
			try {
				$sql = sprintf(
					'CREATE TABLE "%s"."%s" (LIKE "%s" INCLUDING ALL)',
					$schemaName,
					$tableName,
					$sourceTable
				);
				$this->db->executeStatement($sql);
				$cloned[] = $tableName;
			} catch (Throwable $e) {
				throw new RuntimeException(
					'Failed to clone table ' . $sourceTable . ': ' . $e->getMessage(),
					0,
					$e
				);
			}
		}//end foreach

		$this->logger->info(
			'Dossiq: cloned application tables into tenant schema',
			['schemaName' => $schemaName, 'count' => count($cloned)]
		);

		return $cloned;
	}//end cloneApplicationTables()

	/**
	 * Drop a tenant schema and all its contents. Used by rollback + termination.
	 *
	 * @param string $name Schema name.
	 *
	 * @return void
	 *
	 * @throws RuntimeException On DDL failure.
	 *
	 * @spec openspec/specs/tenant-provisioning/spec.md#requirement-schema-per-tenant-provisioning-req-001-b
	 */
	public function dropSchema(string $name): void {
		$this->assertSafeIdentifier(name: $name);

		try {
			$sql = 'DROP SCHEMA IF EXISTS "' . $name . '" CASCADE';
			$this->db->executeStatement($sql);
		} catch (Throwable $e) {
			throw new RuntimeException('DROP SCHEMA failed: ' . $e->getMessage(), 0, $e);
		}
	}//end dropSchema()

	/**
	 * Return whether a schema currently exists. Used by tests + idempotency.
	 *
	 * @param string $name Schema name.
	 *
	 * @return bool True when present.
	 *
	 * @spec openspec/specs/tenant-provisioning/spec.md#requirement-schema-per-tenant-provisioning-req-001-b
	 */
	public function schemaExists(string $name): bool {
		$this->assertSafeIdentifier(name: $name);
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('schema_name')
				->from('information_schema.schemata')
				->where($qb->expr()->eq('schema_name', $qb->createNamedParameter($name)));
			$result = $qb->executeQuery();
			$row = $result->fetchOne();
			$result->closeCursor();
			return $row !== false;
		} catch (Throwable $e) {
			$this->logger->info('Dossiq: schemaExists lookup failed', ['name' => $name, 'exception' => $e->getMessage()]);
			return false;
		}
	}//end schemaExists()

	/**
	 * Validate that the identifier is safe to embed in DDL.
	 *
	 * @param string $name Identifier.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When invalid.
	 *
	 * @spec openspec/specs/tenant-provisioning/spec.md#requirement-schema-per-tenant-provisioning-req-001-b
	 */
	public function assertSafeIdentifier(string $name): void {
		if ($name === '' || strlen($name) > self::PG_IDENTIFIER_MAX_LENGTH) {
			throw new InvalidArgumentException('Invalid PostgreSQL identifier length: ' . $name);
		}

		if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
			throw new InvalidArgumentException('Invalid PostgreSQL identifier shape: ' . $name);
		}
	}//end assertSafeIdentifier()

	/**
	 * List application tables in the public schema that match one of the prefixes.
	 *
	 * @return array<int, string>
	 */
	private function listApplicationTables(): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('table_name')
				->from('information_schema.tables')
				->where($qb->expr()->eq('table_schema', $qb->createNamedParameter('public')));
			$result = $qb->executeQuery();
			$rows = $result->fetchAll(\PDO::FETCH_ASSOC);
			$result->closeCursor();

			$markers = $this->shardTableMarkers();
			if ($markers === []) {
				$this->logger->warning(
					'Dossiq: no register resolved for tenant provisioning; cloning no application tables.',
					['slugs' => self::REGISTER_SLUGS]
				);
				return [];
			}

			$tables = [];
			foreach ($rows as $row) {
				$name = (string)($row['table_name'] ?? '');
				foreach ($markers as $marker) {
					if (str_contains($name, $marker) === true) {
						$tables[] = $name;
						break;
					}
				}
			}

			return $tables;
		} catch (Throwable $e) {
			$this->logger->info('Dossiq: listApplicationTables failed', ['exception' => $e->getMessage()]);
			return [];
		}//end try
	}//end listApplicationTables()

	/**
	 * Resolve this app's registers to the shard-table markers they own.
	 *
	 * A register's shard tables are named
	 * `<prefix>openregister_table_<registerId>_<schemaId>`, so the marker is
	 * `openregister_table_<registerId>_`. Deriving it from the slug at run time
	 * is what keeps this correct across a slug rename: the numeric id does not
	 * move, and an empty result is logged rather than silently cloning nothing.
	 *
	 * @return array<int, string> Markers; empty when no register resolves.
	 */
	private function shardTableMarkers(): array {
		$placeholders = implode(', ', array_fill(0, count(self::REGISTER_SLUGS), '?'));

		try {
			$rows = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug IN (' . $placeholders . ')',
				self::REGISTER_SLUGS
			)->fetchAll();
		} catch (Throwable $e) {
			$this->logger->info('Dossiq: could not resolve register ids', ['exception' => $e->getMessage()]);
			return [];
		}

		$markers = [];
		foreach ($rows as $row) {
			$id = (int)($row['id'] ?? 0);
			if ($id > 0) {
				$markers[] = self::TABLE_MARKER . $id . '_';
			}
		}

		return $markers;
	}//end shardTableMarkers()

	/**
	 * Detect shared tables — they remain in the public schema.
	 *
	 * @param string $tableName Table name.
	 *
	 * @return bool True when shared.
	 */
	private function isSharedTable(string $tableName): bool {
		$lower = strtolower($tableName);
		foreach (self::SHARED_SCHEMA_SLUGS as $slug) {
			if (str_contains($lower, '_' . strtolower($slug) . '_') === true || str_ends_with($lower, '_' . strtolower($slug)) === true) {
				return true;
			}
		}

		return false;
	}//end isSharedTable()

	/**
	 * Extract the bare table name (no schema qualifier).
	 *
	 * @param string $fullName Source table name.
	 *
	 * @return string Bare name.
	 */
	private function extractTableName(string $fullName): string {
		$parts = explode('.', $fullName);
		return end($parts);
	}//end extractTableName()
}//end class
