<?php

/**
 * Dossiq RenameDutchDirectionValues Repair Step
 *
 * Rewrites the stored VALUES of the `direction` property from Dutch to English
 * so rows written before the vocabulary change stay readable afterwards.
 *
 *   inkomend  ->  inbound
 *   uitgaand  ->  outbound
 *   intern    ->  internal
 *
 * WHY A VALUE MIGRATION IS NOT A COLUMN MIGRATION. Its sibling step,
 * RenameDutchDeadlineColumns, moves data between COLUMNS, because OpenRegister
 * gives every schema property a real snake_cased column and MagicMapper never
 * renames one. This step changes no schema at all: the column is already
 * `direction`, and only the strings inside it move. There is no DDL here, and
 * nothing to collide.
 *
 * WHY IT IS SCOPED TO THE `direction` COLUMN, NOT TO THE WORD. `intern` is
 * ALSO a value of the statutory ZGW `vertrouwelijkheidaanduiding` enum
 * (openbaar, beperkt_openbaar, intern, zaakvertrouwelijk, vertrouwelijk,
 * confidentieel, geheim, zeer_geheim — see ZgwRulesBase::VERTROUWELIJKHEID_LEVELS).
 * Those are wire values of the Zaakgericht Werken standard that this app both
 * consumes and emits, so they are EXEMPT from the fleet vocabulary rule and
 * must not be touched. Restricting the update to the `direction` column is what
 * keeps the two vocabularies apart: the same word means "internal document
 * flow" in one column and a statutory confidentiality level in another. A
 * word-based rewrite would have corrupted every ZGW confidentiality field in
 * the install.
 *
 * WHY IT MATCHES TWO REGISTERS, NOT ONE. Measured on this install: BOTH
 * `dossiq` (id 17) and `dossiq-default` (id 2424) carry the three schemas
 * that declare a `direction` property — customerContact (106), portaalBericht
 * (651) and supplierMessage (928). Resolving a single exact slug would migrate
 * half the rows and report success, so the register set is resolved by slug
 * PREFIX, exactly as the sibling step does.
 *
 * WHY OTHER APPS' `direction` COLUMNS ARE OUT OF SCOPE. 105 shard tables on
 * this install have a `direction` column, across pipelinq, decidesk, shillinq,
 * scholiq and openconnector registers. Their vocabularies are their own —
 * portaalBericht's own values here are `citizen_to_handler`, not a direction at
 * all. A dossiq repair step that rewrote them would be editing another app's
 * data.
 *
 * SAFETY. Non-destructive and idempotent:
 *   - only the three known Dutch strings are rewritten; every other value,
 *     including NULL and vocabularies this step does not recognise, is left
 *     exactly as it is;
 *   - a re-run updates zero rows, because the sources no longer exist;
 *   - nothing is dropped, and soft-deleted rows are migrated too — a restored
 *     row must not come back with a direction the schema no longer allows.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rewrite dossiq's Dutch `direction` values to their English equivalents.
 *
 * @spec openspec/specs/stuf-zkn-outbound/spec.md
 */
class RenameDutchDirectionValues implements IRepairStep {

	/**
	 * Slug prefix of the registers in scope.
	 *
	 * Matches `dossiq` and `dossiq-default`; both hold live rows.
	 *
	 * @var string
	 */
	// The OpenRegister register SLUG. It MOVES with the app id: the
	// `MigrateRegisterSlug` step renames `procest` -> `dossiq` (and
	// `dossiq-default` -> `dossiq-default`) on the existing register rows and
	// is registered ahead of this step in both info.xml blocks. Renaming a
	// register strands nothing, because objects are bound to it by NUMERIC id:
	// every shard table's `_register` column holds that id, and the tables are
	// named `oc_openregister_table_<registerId>_<schemaId>`. What the ordering
	// buys is that this step still resolves a register at all — run before
	// MigrateRegisterSlug it would match none and migrate nothing, silently.
	private const REGISTER_SLUG_PREFIX = 'dossiq';

	/**
	 * The only column whose values this step touches.
	 *
	 * @var string
	 */
	private const COLUMN = 'direction';

	/**
	 * Old value => new value.
	 *
	 * @var array<string, string>
	 */
	private const VALUE_MAP = [
		'inkomend' => 'inbound',
		'uitgaand' => 'outbound',
		'intern' => 'internal',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md
	 */
	public function getName(): string {
		return 'Dossiq: rewrite Dutch direction values (inkomend/uitgaand/intern) to English';
	}//end getName()

	/**
	 * Rewrite the direction values across every dossiq shard table.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md
	 */
	public function run(IOutput $output): void {
		$tables = $this->shardTables();
		if ($tables === []) {
			$output->info('RenameDutchDirectionValues: no dossiq shard tables on this install; nothing to do.');
			return;
		}

		$updated = 0;
		$touched = 0;

		foreach ($tables as $table) {
			if ($this->hasDirectionColumn(table: $table) === false) {
				continue;
			}

			$touched++;
			$qTable = $this->quote(identifier: $table);
			$qColumn = $this->quote(identifier: self::COLUMN);

			foreach (self::VALUE_MAP as $old => $new) {
				try {
					$rows = $this->db->executeStatement(
						sprintf('UPDATE %s SET %s = ? WHERE %s = ?', $qTable, $qColumn, $qColumn),
						[$new, $old]
					);
				} catch (Exception $e) {
					// One unreadable table must not abort the rest.
					$this->logger->warning(
						'RenameDutchDirectionValues: update failed on a shard table.',
						['table' => $table, 'from' => $old, 'to' => $new, 'exception' => $e->getMessage()]
					);
					continue;
				}

				if ($rows > 0) {
					$updated += $rows;
					$output->info(sprintf('  %s: %s -> %s (%d row(s))', $table, $old, $new, $rows));
				}
			}//end foreach
		}//end foreach

		// The count is reported even when it is zero. A step that prints nothing
		// on a clean install is indistinguishable from a step that never ran.
		$output->info(
			sprintf(
				'RenameDutchDirectionValues: inspected %d shard table(s) with a `direction` column, updated %d row(s).',
				$touched,
				$updated
			)
		);

	}//end run()

	/**
	 * Whether a shard table carries a `direction` column.
	 *
	 * @param string $table The table name.
	 *
	 * @return bool
	 */
	private function hasDirectionColumn(string $table): bool {
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns '
				. 'WHERE table_name = :table AND column_name = :column'
			);
			$stmt->bindValue('table', $table);
			$stmt->bindValue('column', self::COLUMN);
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchDirectionValues: could not inspect columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
	}//end hasDirectionColumn()

	/**
	 * Resolve the shard tables of every register whose slug starts with the prefix.
	 *
	 * @return array<int, string>
	 */
	private function shardTables(): array {
		try {
			$ids = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?',
				[self::REGISTER_SLUG_PREFIX . '%']
			)->fetchAll(\PDO::FETCH_COLUMN);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchDirectionValues: could not resolve the dossiq registers; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		if ($ids === []) {
			return [];
		}

		// Table discovery goes through information_schema, NOT IDBConnection —
		// the reasoning is documented at length in RenameDutchDeadlineColumns:
		// OCP\IDBConnection exposes neither getSchema() nor getPrefix(), and
		// getQueryBuilder()->getTableName('') yields the literal `*PREFIX*`
		// placeholder, which a raw information_schema string never resolves. A
		// LIKE built from it matches zero tables and reports every register
		// empty — a silent no-op that reads as success.
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchDirectionValues: could not list tables; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$markers = [];
		foreach ($ids as $id) {
			$markers[] = 'openregister_table_' . ((int)$id) . '_';
		}

		$tables = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['table_name'] ?? '');
			if ($this->isShardOf(table: $name, markers: $markers) === true) {
				$tables[] = $name;
			}
		}

		return $tables;
	}//end shardTables()

	/**
	 * Whether a table name belongs to one of the in-scope registers.
	 *
	 * Matched on the `openregister_table_<id>_` MARKER rather than a computed
	 * prefix, so an instance-specific table prefix cannot make the match fail.
	 *
	 * @param string $table The table name.
	 * @param array<int, string> $markers The in-scope markers.
	 *
	 * @return bool
	 */
	private function isShardOf(string $table, array $markers): bool {
		foreach ($markers as $marker) {
			if (str_contains($table, $marker) === true) {
				return true;
			}
		}

		return false;
	}//end isShardOf()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * The table names come from information_schema on this same connection and
	 * the column is a class constant, so neither is caller-supplied; quoting is
	 * for identifiers that need it, not for untrusted input.
	 *
	 * @param string $identifier The identifier.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
	}//end quote()

}//end class
