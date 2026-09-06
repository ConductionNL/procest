<?php

/**
 * Dossiq RenameCollidingSchemaSlugs repair step.
 *
 * Moves this app's share of the cross-app slug collisions onto namespaced slugs IN
 * PLACE, before the register import.
 *
 * A schema slug is global per organisation and `SchemaMapper::find()` matches
 * `LOWER(slug)`, so when two apps declare one slug the lookup answers with whichever
 * row it reached first.
 *
 * `supplierInvoice` is this app's case-side view of a supplier invoice: the document
 * as it arrives in a dossier. shillinq's is the accounts-payable record, more than
 * twice the size, and it owns the invoice. The two share only `invoiceDate` and
 * `dueDate` — no invoice number, nothing that identifies the document — so they are
 * renamed apart rather than folded.
 *
 * Why a repair step and why before the import. OpenRegister matches an existing schema
 * by (application, slug): `ImportHandler` calls `findByApplicationAndSlug()` and
 * creates a NEW schema when that misses. A slug rename in the shipped fragment
 * therefore does not rename anything, it CREATES a second schema and silently orphans
 * the first together with every object already written against it. The old schema keeps
 * its shard table and its rows; the app resolves the new id and reads an empty
 * collection. Nothing errors.
 *
 * Separate from {@see RenameDutchSchemaSlugs}, which is the Dutch-to-English vocabulary
 * pass. Folding this into that map would say they are the same migration, and the next
 * person reading it would have no way to tell why an English slug sits in a list of
 * Dutch translations.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://dossiq.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames this app's colliding schema slugs in place, ahead of the register import.
 *
 * @spec exclude No canonical spec covers the cross-app slug namespacing pass. Pointing
 *  this at an existing spec would report conformance to a requirement that says nothing
 *  about it.
 */
class RenameCollidingSchemaSlugs implements IRepairStep {
	/**
	 * Old slug => new slug, with the app it collided with.
	 *
	 * @var array<string, array{to: string, with: string}>
	 */
	private const RENAMES = [
		'supplierInvoice' => ['to' => 'caseSupplierInvoice', 'with' => 'shillinq'],
		// The case task. `task` was claimed by three apps and they share only
		// `description`, `priority` and `status`. planninq's project task is the
		// largest and keeps the bare slug; pipelinq took crmTask.
		'task' => ['to' => 'caseTask', 'with' => 'planninq, pipelinq'],
	];

	/**
	 * The owning application, as stored on the schema row.
	 *
	 * @var string
	 */
	private const APPLICATION = 'dossiq';

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
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
	 */
	public function getName(): string {
		return 'Namespace the shillinq schema slugs that collided with another app';
	}//end getName()

	/**
	 * Rename each slug, unless doing so would be ambiguous.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
	 */
	public function run(IOutput $output): void {
		foreach (self::RENAMES as $from => $rename) {
			$this->renameOne(output: $output, from: $from, to: $rename['to'], with: $rename['with']);
		}
	}//end run()

	/**
	 * Rename one slug.
	 *
	 * @param IOutput $output Repair output.
	 * @param string $from The slug being moved away from.
	 * @param string $to The namespaced slug.
	 * @param string $with The app this slug collided with.
	 *
	 * @return void
	 */
	private function renameOne(IOutput $output, string $from, string $to, string $with): void {
		$old = $this->schemaIds(slug: $from);
		$new = $this->schemaIds(slug: $to);

		if ($old === null || $new === null) {
			$output->info('RenameCollidingSchemaSlugs: schema table unreadable; leaving `' . $from . '` alone.');
			return;
		}

		if ($old === []) {
			$output->info('RenameCollidingSchemaSlugs: no dossiq-owned `' . $from . '`; nothing to do.');
			return;
		}

		if ($new !== []) {
			// Both slugs present: each may own objects, and renaming would collide
			// with the new row. Abandoning either set is not a call a repair step
			// gets to make without being asked.
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: both slugs exist; refusing to merge them.',
				['from' => $from, 'to' => $to, 'old' => $old, 'new' => $new]
			);
			$output->warning(
				'RenameCollidingSchemaSlugs: both `' . $from . '` and `' . $to
				. '` exist; refusing to merge them. Resolve by hand.'
			);
			return;
		}

		if (count($old) > 1) {
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: duplicate slugs; refusing to guess.',
				['from' => $from, 'ids' => $old]
			);
			$output->warning('RenameCollidingSchemaSlugs: duplicate `' . $from . '` schemas; refusing to guess.');
			return;
		}

		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?',
				[$to, $old[0]]
			);
		} catch (Exception $e) {
			// Safe to fail: the import then creates a new schema rather than
			// updating this one, which is the pre-existing behaviour. Loud,
			// because the objects on the old schema stop being reachable.
			$this->logger->error(
				'RenameCollidingSchemaSlugs: slug rename failed; the import will create a second schema.',
				['from' => $from, 'id' => $old[0], 'exception' => $e->getMessage()]
			);
			$output->warning('RenameCollidingSchemaSlugs: renaming `' . $from . '` failed; see the log.');
			return;
		}

		$output->info(
			'RenameCollidingSchemaSlugs: schema ' . $old[0] . ' renamed `' . $from . '` -> `' . $to
			. '` (collided with ' . $with . '); its objects stay attached.'
		);
	}//end renameOne()

	/**
	 * Ids of this application's schemas carrying the given slug.
	 *
	 * Scoped to THIS app's rows. Without the application filter the lookup would
	 * find shillinq's `SupplierInvoice` too, and renaming that is precisely the
	 * damage this step exists to avoid.
	 *
	 * @param string $slug The schema slug to look for.
	 *
	 * @return array<int, mixed>|null The ids, or null when the table cannot be read.
	 */
	private function schemaIds(string $slug): ?array {
		try {
			$rows = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_schemas` WHERE slug = ? AND application = ?',
				[$slug, self::APPLICATION]
			)->fetchAll(\PDO::FETCH_COLUMN);

			return array_values((array)$rows);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: could not read the schema table; skipping.',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end schemaIds()
}//end class
