<?php

/**
 * Move this app's `decision` (besluit) rows onto decidiq's `Decision`.
 *
 * `decision` was a global slug two apps declared: decidiq's governance decision
 * (motion, voting, resolution) and this app's ZGW besluit. `SchemaMapper::find()`
 * matches on `LOWER(slug)`, so whichever row it reached first answered for both.
 * decidiq's `Decision` now carries the four BRC fields it lacked (decidiq#1161),
 * which is what lets one schema hold both records.
 *
 * {@see \OCA\Dossiq\Service\SettingsService::getConfigValue()} resolves decidiq's
 * schema LAST — only when this app has no `decision_schema` of its own. That order
 * is deliberate: every existing instance HAS that key and its besluiten live in the
 * schema the key names, so preferring decidiq unconditionally would make the BRC
 * answer 404 for every besluit it holds. The consequence is that an existing
 * instance never moves on its own, and this is the supported way to move it.
 *
 * Deliberately a command and not a repair step. It rewrites records across an app
 * boundary, and an upgrade is not the moment to do that silently: the operator
 * runs the dry run, reads the counts, and then asks for the write.
 *
 * Idempotent through `externalReference`. Each migrated Decision carries
 * `dossiq:<source uuid>`, and a second run skips every source already present. The
 * key is the source UUID rather than a slug or title because a besluit is free to
 * carry neither, and two besluiten may share a title.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-besluit-resolves-to-decidiqs-decision/specs/zgw-brc/spec.md#requirement-besluiten-move-onto-decidiq-only-when-asked-req-brc-021
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Migrates besluiten from this app's `decision` schema onto decidiq's.
 *
 * @spec openspec/changes/the-besluit-resolves-to-decidiqs-decision/specs/zgw-brc/spec.md#requirement-besluiten-move-onto-decidiq-only-when-asked-req-brc-021
 */
class BesluitMigrationService {
	use SearchesObjects;

	/**
	 * This app's register slug.
	 *
	 * @var string
	 */
	private const SOURCE_REGISTER = 'dossiq';

	/**
	 * decidiq's register slug.
	 *
	 * @var string
	 */
	private const TARGET_REGISTER = 'decidiq';

	/**
	 * The shared slug, read on decidiq's side of the (application, slug) pair.
	 *
	 * @var string
	 */
	private const DECISION_SLUG = 'decision';

	/**
	 * The owning application of the target schema.
	 *
	 * @var string
	 */
	private const TARGET_APP = 'decidiq';

	/**
	 * The stored key naming this app's own decision schema.
	 *
	 * @var string
	 */
	private const DECISION_SCHEMA_KEY = 'decision_schema';

	/**
	 * Prefix stamped into `externalReference`, making the source traceable and
	 * the migration idempotent.
	 *
	 * @var string
	 */
	private const PROVENANCE_PREFIX = 'dossiq:';

	/**
	 * Upper bound on rows read per side. Well past any realistic besluit count,
	 * and low enough that a runaway instance fails visibly rather than silently
	 * migrating half of itself.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 5000;

	/**
	 * Source field to target field. Everything not named here is dropped, so a
	 * new source field surfaces as a gap in this list rather than as silent loss.
	 *
	 * `case` is absent on purpose. decidiq's `Decision` has no `case` field and
	 * is not getting one — cases and decisions are already linked — so the
	 * reference travels through the generic subject block instead, which is what
	 * that block is for.
	 *
	 * @var array<string, string>
	 */
	private const FIELD_MAP = [
		'title' => 'title',
		'description' => 'text',
		'decisionType' => 'decisionType',
		'responsibleOrganisation' => 'responsibleOrganisation',
		'decisionDate' => 'decisionDate',
		'effectiveDate' => 'effectiveDate',
		'expiryDate' => 'expiryDate',
		'publicationDate' => 'publicationDate',
		'deliveryDate' => 'deliveryDate',
		'explanation' => 'background',
		'governingBody' => 'governingBody',
	];

	/**
	 * Target fields declaring `format: date-time` where this app declares `date`.
	 *
	 * Found by running the migration against a loaded instance, not by reading
	 * the two schemas side by side: OpenRegister validates on write, so a bare
	 * `2026-09-05` into `decisionDate` is rejected outright and the besluit does
	 * not move. Widening to midnight UTC is the only conversion that adds no
	 * information the source never had.
	 *
	 * @var array<int, string>
	 */
	private const WIDEN_TO_DATE_TIME = ['decisionDate'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Raw app config; the stored schema key is read
	 *                              directly rather than through SettingsService,
	 *                              whose getter would answer with decidiq's id
	 *                              once the local key is gone.
	 * @param IAppManager $appManager Used to check decidiq is installed.
	 * @param ContainerInterface $container Loose lookup of OpenRegister services.
	 * @param SettingsService $settingsService Supplies the ObjectService bridge.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Migrate, or report what a migration would do.
	 *
	 * @param bool $commit False to read and report only, true to write.
	 *
	 * @return array{status:string, total:int, migrated:int, skipped:int, failed:int, detached:bool, message:string}
	 *         A summary. `status` is 'ok' when the run completed and 'blocked'
	 *         when a precondition stopped it before any read.
	 *
	 * @spec openspec/changes/the-besluit-resolves-to-decidiqs-decision/specs/zgw-brc/spec.md#requirement-besluiten-move-onto-decidiq-only-when-asked-req-brc-021
	 */
	public function migrate(bool $commit): array {
		$summary = [
			'status' => 'blocked',
			'total' => 0,
			'migrated' => 0,
			'skipped' => 0,
			'failed' => 0,
			'detached' => false,
			'message' => '',
		];

		$blocked = $this->precondition();
		if ($blocked !== null) {
			$summary['message'] = $blocked;
			return $summary;
		}

		$sourceSchema = $this->localSchemaId();
		$targetSchema = $this->targetSchemaId();
		$objectService = $this->settingsService->getObjectService();

		if ($objectService === null || $sourceSchema === '' || $targetSchema === '') {
			$summary['message'] = 'OpenRegister is unavailable, or one of the two decision schemas could not be resolved.';
			return $summary;
		}

		try {
			$sources = $this->rows(objectService: $objectService, register: self::SOURCE_REGISTER, schema: $sourceSchema);
			$alreadyThere = $this->migratedSourceIds(objectService: $objectService, schema: $targetSchema);
		} catch (Throwable $e) {
			$summary['message'] = 'Could not read the decision rows: ' . $e->getMessage();
			return $summary;
		}

		$summary['status'] = 'ok';
		$summary['total'] = count($sources);
		$summary = array_merge(
			$summary,
			$this->migrateRows(
				objectService: $objectService,
				targetSchema: $targetSchema,
				sources: $sources,
				alreadyThere: $alreadyThere,
				commit: $commit,
			)
		);

		if ($commit === true) {
			$summary['detached'] = $this->detachLocalSchema(summary: $summary);
		}

		$this->logger->info('Dossiq: besluit migration finished', $summary);

		return $summary;
	}//end migrate()

	/**
	 * Walk the source rows, counting and (when committing) writing each.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $targetSchema The target schema id.
	 * @param array<int, array<string, mixed>> $sources The source rows.
	 * @param array<string, true> $alreadyThere Source ids already migrated.
	 * @param bool $commit False to count only, true to write.
	 *
	 * @return array{migrated:int, skipped:int, failed:int} The counts.
	 */
	private function migrateRows(
		object $objectService,
		string $targetSchema,
		array $sources,
		array $alreadyThere,
		bool $commit,
	): array {
		$counts = [
			'migrated' => 0,
			'skipped' => 0,
			'failed' => 0,
		];

		foreach ($sources as $row) {
			$sourceId = trim((string)($row['id'] ?? ($row['uuid'] ?? '')));
			if ($sourceId === '') {
				// No stable identity means no idempotency key, so a re-run would
				// duplicate it. Reported rather than guessed at.
				$counts['failed']++;
				continue;
			}

			if (isset($alreadyThere[$sourceId]) === true) {
				$counts['skipped']++;
				continue;
			}

			$written = ($commit === false);
			if ($commit === true) {
				$written = $this->write(
					objectService: $objectService,
					schema: $targetSchema,
					row: $row,
					sourceId: $sourceId,
				);
			}

			if ($written === false) {
				$counts['failed']++;
				continue;
			}

			$counts['migrated']++;
		}//end foreach

		return $counts;
	}//end migrateRows()

	/**
	 * Reasons the migration cannot run at all.
	 *
	 * @return string|null The reason, or null when the run may proceed.
	 */
	private function precondition(): ?string {
		if ($this->appManager->isInstalled(self::TARGET_APP) === false) {
			return 'decidiq is not installed, so there is nothing to migrate onto.';
		}

		if ($this->localSchemaId() === '') {
			return 'This instance has no `' . self::DECISION_SCHEMA_KEY
				. '` of its own, so its besluiten already resolve to decidiq. Nothing to do.';
		}

		return null;
	}//end precondition()

	/**
	 * The stored id of this app's own decision schema.
	 *
	 * Read straight from app config. Going through SettingsService would answer
	 * with decidiq's id the moment the local key is gone, which is exactly the
	 * value this method must not return.
	 *
	 * @return string The stored id, or '' when the key is unset.
	 */
	private function localSchemaId(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, self::DECISION_SCHEMA_KEY, ''));
	}//end localSchemaId()

	/**
	 * The id of decidiq's `decision` schema.
	 *
	 * Looked up on the (slug, application) PAIR. Slug alone is what caused the
	 * collision in the first place and would as readily return this app's row.
	 *
	 * @return string The schema id, or '' when it cannot be resolved.
	 */
	private function targetSchemaId(): string {
		try {
			$schemaMapper = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper');
			$schema = $schemaMapper->findByApplicationAndSlug(self::DECISION_SLUG, self::TARGET_APP);
			if ($schema === null) {
				return '';
			}

			return (string)$schema->getId();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not resolve decidiq\'s decision schema',
				['exception' => $e->getMessage()]
			);
			return '';
		}
	}//end targetSchemaId()

	/**
	 * Resolve a register slug to its numeric id.
	 *
	 * The numeric path is the one that must be taken here. The slug path
	 * resolves the SCHEMA with `findBySlugInIds()`, which matches a slug and not
	 * an id — and both schema ids in this migration arrive as ids, one from app
	 * config and one from the mapper. Handing those to the slug path would look
	 * like an empty register rather than like a mistake.
	 *
	 * @param string $slug The register slug.
	 *
	 * @return string The numeric id, or '' when the register cannot be resolved.
	 */
	private function registerId(string $slug): string {
		try {
			$registerMapper = $this->container->get('OCA\\OpenRegister\\Db\\RegisterMapper');
			$register = $registerMapper->find($slug, _rbac: false, _multitenancy: false);

			return (string)$register->getId();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not resolve the `' . $slug . '` register',
				['exception' => $e->getMessage()]
			);
			return '';
		}
	}//end registerId()

	/**
	 * Read a schema's rows.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register slug.
	 * @param string $schema The schema id.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(object $objectService, string $register, string $schema): array {
		$registerId = $this->registerId(slug: $register);
		if ($registerId === '') {
			return [];
		}

		$read = fn (): array => $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: (int)$registerId,
			schema: (int)$schema,
			// NO `_rbac`/`_multitenancy` here. On `ObjectService::searchObjects()`
			// those are PARAMETERS, not query keys, and this bridge passes the
			// array through as the query — so a key named `_rbac` reads as a
			// filter on a field no object has, and the search returns nothing
			// while looking like an empty register. The anonymous-under-occ
			// problem is what `runAsSystemIfAvailable()` below is for.
			filters: ['_limit' => self::READ_LIMIT],
		);

		$rows = $this->runAsSystemIfAvailable(objectService: $objectService, operation: $read);

		if (is_array($rows) === false) {
			return [];
		}

		return $rows;
	}//end rows()

	/**
	 * Source ids already carried by a Decision in the target schema.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $schema The target schema id.
	 *
	 * @return array<string, true> Set of source uuids, keyed for O(1) lookup.
	 */
	private function migratedSourceIds(object $objectService, string $schema): array {
		$seen = [];
		foreach ($this->rows(objectService: $objectService, register: self::TARGET_REGISTER, schema: $schema) as $row) {
			$reference = (string)($row['externalReference'] ?? '');
			if (str_starts_with($reference, self::PROVENANCE_PREFIX) === false) {
				continue;
			}

			$seen[substr($reference, strlen(self::PROVENANCE_PREFIX))] = true;
		}

		return $seen;
	}//end migratedSourceIds()

	/**
	 * Write one besluit into decidiq's schema.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $schema The target schema id.
	 * @param array<string, mixed> $row The source besluit.
	 * @param string $sourceId The source uuid.
	 *
	 * @return bool True when written.
	 */
	private function write(object $objectService, string $schema, array $row, string $sourceId): bool {
		$targetRegisterId = $this->registerId(slug: self::TARGET_REGISTER);
		if ($targetRegisterId === '') {
			return false;
		}

		$save = fn (): mixed => $this->saveObjectAsArray(
			objectService: $objectService,
			register: (int)$targetRegisterId,
			schema: (int)$schema,
			object: $this->project(row: $row, sourceId: $sourceId),
		);

		try {
			$this->runAsSystemIfAvailable(objectService: $objectService, operation: $save);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not migrate a besluit onto decidiq',
				['source' => $sourceId, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end write()

	/**
	 * Project a besluit onto decidiq's Decision shape.
	 *
	 * @param array<string, mixed> $row The source besluit.
	 * @param string $sourceId The source uuid.
	 *
	 * @return array<string, mixed> The target object.
	 */
	private function project(array $row, string $sourceId): array {
		$target = ['externalReference' => self::PROVENANCE_PREFIX . $sourceId];

		foreach (self::FIELD_MAP as $from => $to) {
			$value = ($row[$from] ?? null);
			if ($value === null || $value === '') {
				continue;
			}

			$target[$to] = $value;
			if (in_array($to, self::WIDEN_TO_DATE_TIME, true) === true) {
				$target[$to] = $this->asDateTime(value: $value);
			}
		}

		// The case reference travels through the generic subject block. decidiq's
		// Decision has no `case` field and is not getting one; this block is how
		// it points at a record in another app.
		$case = trim((string)($row['case'] ?? ''));
		if ($case !== '') {
			$target['sourceApp'] = self::SOURCE_REGISTER;
			$target['subjectRegister'] = self::SOURCE_REGISTER;
			$target['subjectSchema'] = 'case';
			$target['subjectId'] = $case;
		}

		return $target;
	}//end project()

	/**
	 * Widen a `date` to a `date-time`, leaving anything else untouched.
	 *
	 * Midnight UTC, because the source carries a day and nothing finer. Guessing
	 * a local time would invent precision the besluit never had, and OpenRegister
	 * would accept it just as readily.
	 *
	 * @param mixed $value The source value.
	 *
	 * @return mixed The widened value, or the input when it is not a bare date.
	 */
	private function asDateTime(mixed $value): mixed {
		if (is_string($value) === false) {
			return $value;
		}

		if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value) !== 1) {
			return $value;
		}

		return $value . 'T00:00:00+00:00';
	}//end asDateTime()

	/**
	 * Drop the local `decision_schema` key so the fallback takes over.
	 *
	 * Only once every row is accounted for. Detaching while a besluit is still
	 * behind would point the BRC at decidiq's schema for a record that never
	 * arrived, and it would answer 404 with nothing saying why.
	 *
	 * The schema and its rows are left in place. Nothing reads them once the key
	 * is gone, and keeping them means this is reversible by restoring the key.
	 *
	 * @param array{total:int, migrated:int, skipped:int, failed:int} $summary The run so far.
	 *
	 * @return bool True when the key was removed.
	 */
	private function detachLocalSchema(array $summary): bool {
		if ($summary['failed'] > 0) {
			return false;
		}

		if (($summary['migrated'] + $summary['skipped']) !== $summary['total']) {
			return false;
		}

		$this->appConfig->deleteKey(app: Application::APP_ID, key: self::DECISION_SCHEMA_KEY);

		return true;
	}//end detachLocalSchema()
}//end class
