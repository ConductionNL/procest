<?php

/**
 * Database adapter for the Dutch-to-English value migration.
 *
 * Table discovery goes through information_schema rather than IDBConnection,
 * for the reason RenameDutchColumns records: OCP\IDBConnection exposes neither
 * getSchema() nor getPrefix(), and `getTableName('')` yields the literal
 * `*PREFIX*` placeholder, which a raw information_schema string never resolves.
 * Matching therefore anchors on the `openregister_table_` marker.
 *
 * Every failure is logged and swallowed. A repair step runs during upgrade; an
 * exception here aborts the upgrade, which is a far worse outcome than a value
 * left in Dutch.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The real storage implementation behind ValueMigrationPort.
 *
 * @spec exclude Database adapter for the Dutch-to-English vocabulary migration.
 */
class DbValueMigrationPort implements ValueMigrationPort {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 * @param RenameDutchValueDecisions $decisions Pure predicates.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly RenameDutchValueDecisions $decisions,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 *
	 * @spec exclude Database adapter for the Dutch-to-English vocabulary migration.
	 */
	public function shardTables(): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
			$rows = $stmt->fetchAll();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'DbValueMigrationPort: could not list tables.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return $this->decisions->column(rows: $rows, key: 'table_name');
	}//end shardTables()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 *
	 * @spec exclude Database adapter for the Dutch-to-English vocabulary migration.
	 */
	public function columnsOf(string $table): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
			$rows = $stmt->fetchAll();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'DbValueMigrationPort: could not read columns.',
				[
					'table' => $table,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}

		return $this->decisions->column(rows: $rows, key: 'column_name');
	}//end columnsOf()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $table Table name.
	 * @param string $column Column name.
	 * @param string $old Stored Dutch value.
	 * @param string $new English replacement.
	 *
	 * @return int
	 *
	 * @spec exclude Database adapter for the Dutch-to-English vocabulary migration.
	 */
	public function rewrite(string $table, string $column, string $old, string $new): int {
		$quote = fn (string $identifier): string => $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
		$sql = 'UPDATE ' . $quote($table) . ' SET ' . $quote($column) . ' = ? WHERE ' . $quote($column) . ' = ?';

		try {
			return $this->db->executeStatement($sql, [$new, $old]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'DbValueMigrationPort: statement failed; leaving the value as it was.',
				[
					'sql' => $sql,
					'exception' => $e->getMessage(),
				]
			);
			return 0;
		}

	}//end rewrite()
}//end class
