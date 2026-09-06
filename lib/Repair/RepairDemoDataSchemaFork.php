<?php

/**
 * Removes the schema fork a defective demo import left behind.
 *
 * `DemoDataService` used to hand OpenRegister's importer the whole mock
 * descriptor under the configuration id `dossiq.demo`. Two things followed,
 * and neither of them said anything at the time:
 *
 *   SCHEMAS FORKED. A schema is resolved by the PAIR (application, slug). Under
 *   `dossiq.demo` every slug matched nothing, and the importer's not-found
 *   branch is the CREATE branch, so one click built a second schema set: 139
 *   duplicate rows on the reference rig, with 393 demo objects bound to them.
 *   The app resolves its schemas under `dossiq`, so it never showed one of
 *   those objects. `dossiq.demo/case` held three cases the case list could not
 *   see.
 *
 *   THE REGISTER LOST ITS IDENTITY. A register is resolved by SLUG alone, so
 *   the demo descriptor's own register entry matched the live row and
 *   overwrote it: "Dossiq Case Management Register" became "Dossiq (demo)",
 *   application became `dossiq.demo`, version 1.1.0 went back to 1.0.0.
 *
 * The import is fixed at source, so a fresh instance cannot acquire this. This
 * step is for the instances that already ran it, and it is what makes the fix
 * usable there: with two rows sharing a slug, the corrected object-only import
 * resolves the schema ambiguously and refuses every object.
 *
 * 🔴 WHAT IT DELETES, AND WHAT IT REFUSES TO. Deleting is the one thing here
 * that cannot be undone, so each deletion has to be provable rather than
 * likely. A schema is removed only when ALL THREE hold:
 *
 *   1. its `application` is exactly `dossiq.demo` — a string nothing but the
 *      defective import has ever written;
 *   2. exactly one schema with the same slug exists under `dossiq`, so the
 *      real home of that data demonstrably exists;
 *   3. no register lists its id, so nothing on the instance can reach it.
 *
 * Anything failing any of the three is left alone and NAMED in the output. A
 * slug with no twin under `dossiq` may be the only copy of something; a slug
 * with several twins is a question about data, not a repair; a schema a
 * register still links is reachable, and reachable is not unambiguously the
 * fork. The step reports those rather than guessing, and an operator can act
 * on the report.
 *
 * Removal goes through OpenRegister's own `SchemaDeletionService`, which
 * snapshots every object into the hash-chained audit trail before deleting the
 * row and only then drops the magic table. So even the deletions this step
 * does make leave a record.
 *
 * Idempotent: a repaired instance has no `dossiq.demo` schemas and no register
 * on that application id, so the second run does nothing and says so. It never
 * throws — it runs during an upgrade, where an escaping exception costs more
 * than the fork does.
 *
 * @category  Repair
 * @package   OCA\Dossiq\Repair
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Retires the `dossiq.demo` schema fork and restores the register's identity.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Needs OpenRegister's schema, register and deletion services.
 *
 * @spec exclude No canonical spec covers repairing the damage of a defective
 *  demo import. Pointing this at the first-time-setup spec would report
 *  conformance to a requirement that says nothing about the fork.
 */
class RepairDemoDataSchemaFork implements IRepairStep {

	use SearchesObjects;

	/**
	 * The application id the defective demo import wrote.
	 *
	 * 🔴 A HISTORICAL LITERAL, NOT A REFERENCE TO THE CURRENT CONSTANT. What
	 * this step has to find is the value that was WRITTEN, which cannot change
	 * retroactively. Deriving it from `DemoDataService` would make a later
	 * rename of that constant silently stop repairing the instances that need
	 * it, and the step would go on reporting "nothing to do".
	 *
	 * @var string
	 */
	private const FORKED_APPLICATION = 'dossiq.demo';

	/**
	 * The register title the demo descriptor overwrote the live one with.
	 *
	 * Restoring the title is gated on this exact string so an operator who
	 * renamed the register themselves keeps their name.
	 *
	 * @var string
	 */
	private const DEFACED_TITLE = 'Dossiq (demo)';

	/**
	 * Path to the shipped register definition, relative to this file.
	 *
	 * The identity is restored FROM THE SHIPPED FILE rather than from a
	 * literal here, so there is one place that says what the register is
	 * called.
	 *
	 * @var string
	 */
	private const REGISTER_JSON = __DIR__ . '/../Settings/dossiq_register.json';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Tells whether OpenRegister is installed.
	 * @param ContainerInterface $container  Resolves OpenRegister's mappers and services.
	 * @param SettingsService    $settings   Resolves the ObjectService for the system elevation.
	 * @param LoggerInterface    $logger     Records what was retired and what was spared.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string The name.
	 *
	 * @spec exclude No canonical spec covers repairing the damage of a
	 *  defective demo import; see the class docblock.
	 */
	public function getName(): string {
		return 'Retire the dossiq.demo schema fork and restore the Dossiq register title';
	}//end getName()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers repairing the damage of a
	 *  defective demo import; see the class docblock.
	 */
	public function run(IOutput $output): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			$output->info('Demo-fork repair: OpenRegister is not installed; nothing to repair.');
			return;
		}

		try {
			$objectService = $this->settings->getObjectService();
		} catch (Throwable $e) {
			$objectService = null;
		}

		// The prune runs whether or not anything was retired THIS time. An
		// instance repaired before the pruning existed still carries the
		// dangling ids, and hanging it off the retirement would mean the one
		// run that could clean them up had already happened.
		$operation = function () use ($output): void {
			$this->restoreRegisterIdentity(output: $output);
			$this->retireForkedSchemas(output: $output);
			$this->pruneDemoConfiguration(output: $output);
		};

		try {
			if ($objectService === null) {
				$operation();
				return;
			}

			$this->runAsSystemIfAvailable(objectService: $objectService, operation: $operation);
		} catch (Throwable $e) {
			// A repair that cannot run must not fail an upgrade. The fork is a
			// mess, not an outage.
			$output->warning('Demo-fork repair could not complete: ' . $e->getMessage());
			$this->logger->warning(
				'Dossiq: demo-fork repair failed',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
		}//end try
	}//end run()

	/**
	 * Give the register its own title, description, version and app id back.
	 *
	 * Gated on `application = dossiq.demo`, which only the defective import
	 * wrote. The title is restored only when it still reads exactly what that
	 * import made it read, so a rename an operator chose survives the repair.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	private function restoreRegisterIdentity(IOutput $output): void {
		$registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');

		try {
			$register = $registerMapper->find(
				id: Application::APP_ID,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$output->info('Demo-fork repair: no `dossiq` register on this instance.');
			return;
		}

		if ($register->getApplication() !== self::FORKED_APPLICATION) {
			$output->info('Demo-fork repair: the register still carries its own application id; left alone.');
			return;
		}

		$register->setApplication(Application::APP_ID);

		$shipped = $this->shippedIdentity();
		$restoredTitle = false;
		if (($shipped['title'] ?? '') !== '' && $register->getTitle() === self::DEFACED_TITLE) {
			$register->setTitle((string)$shipped['title']);
			if (($shipped['description'] ?? '') !== '') {
				$register->setDescription((string)$shipped['description']);
			}

			if (($shipped['version'] ?? '') !== '') {
				$register->setVersion((string)$shipped['version']);
			}

			$restoredTitle = true;
		}

		$registerMapper->update($register);

		$note = 'Demo-fork repair: register `dossiq` moved back to application `dossiq`.';
		if ($restoredTitle === true) {
			$note = 'Demo-fork repair: register `dossiq` moved back to application `dossiq` and retitled "'
				. $register->getTitle() . '".';
		}

		$output->info($note);
		$this->logger->warning(
			'Dossiq: restored the register identity a demo import overwrote',
			[
				'app' => Application::APP_ID,
				'title' => $register->getTitle(),
				'titleRestored' => $restoredTitle,
			]
		);
	}//end restoreRegisterIdentity()

	/**
	 * Retire every schema that is provably part of the fork.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	private function retireForkedSchemas(IOutput $output): void {
		$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');

		$forked = $schemaMapper->findAll(
			filters: ['application' => self::FORKED_APPLICATION],
			_rbac: false,
			_multitenancy: false
		);

		if ($forked === []) {
			$output->info('Demo-fork repair: no `dossiq.demo` schemas; nothing to retire.');
			return;
		}

		$linked = $this->schemaIdsAnyRegisterLinks();
		$deletion = $this->container->get('OCA\OpenRegister\Service\SchemaDeletionService');

		$retired = 0;
		$objects = 0;
		$spared = [];

		foreach ($forked as $schema) {
			$slug = (string)$schema->getSlug();
			$reason = $this->reasonToSpare(
				schemaMapper: $schemaMapper,
				slug: $slug,
				schemaId: (int)$schema->getId(),
				linked: $linked
			);

			if ($reason !== '') {
				$spared[$slug] = $reason;
				continue;
			}

			try {
				$result = $deletion->cascadeDeleteSchema(schema: $schema);
				$retired++;
				$objects += (int)($result['deletedCount'] ?? 0);
			} catch (Throwable $e) {
				$spared[$slug] = 'deletion failed: ' . $e->getMessage();
			}
		}//end foreach

		$output->info(
			sprintf(
				'Demo-fork repair: retired %d forked schema(s) and the %d demo object(s) bound to them, '
				. 'spared %d.',
				$retired,
				$objects,
				count($spared)
			)
		);

		foreach ($spared as $slug => $reason) {
			$output->warning('Demo-fork repair: kept `dossiq.demo/' . $slug . '`, ' . $reason . '.');
		}

		$this->logger->warning(
			'Dossiq: retired the dossiq.demo schema fork',
			[
				'app' => Application::APP_ID,
				'retired' => $retired,
				'objects' => $objects,
				'spared' => $spared,
			]
		);

	}//end retireForkedSchemas()

	/**
	 * Drop the deleted schema ids from the demo import's bookkeeping row.
	 *
	 * The Configuration row for `dossiq.demo` records what that import
	 * touched, and after the retirement most of its `schemas` list points at
	 * rows that no longer exist. Nothing breaks on a dangling id, which is
	 * exactly why it would sit there: OpenRegister's configuration view
	 * renders the list, and an entry that resolves to nothing reads as a
	 * schema that failed to load rather than as one that was deliberately
	 * removed.
	 *
	 * The row itself is KEPT. It is the record that this instance once ran
	 * the demo import, and deleting it would erase that.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	private function pruneDemoConfiguration(IOutput $output): void {
		$configurationMapper = $this->container->get('OCA\OpenRegister\Db\ConfigurationMapper');
		$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');

		try {
			$configurations = $configurationMapper->findByApp(self::FORKED_APPLICATION, systemLookup: true);
		} catch (Throwable $e) {
			return;
		}

		$pruned = 0;
		foreach ($configurations as $configuration) {
			$kept = [];
			foreach (($configuration->getSchemas() ?? []) as $id) {
				try {
					$schemaMapper->find($id, _rbac: false, _multitenancy: false);
					$kept[] = $id;
				} catch (Throwable $e) {
					$pruned++;
				}
			}

			$configuration->setSchemas($kept);
			$configurationMapper->update($configuration);
		}

		if ($pruned > 0) {
			$output->info(
				'Demo-fork repair: dropped ' . $pruned . ' dangling schema id(s) from the demo '
				. 'configuration record.'
			);
		}
	}//end pruneDemoConfiguration()

	/**
	 * Say why this forked schema must be kept, or '' when it may go.
	 *
	 * Three separate questions, each with its own answer, because merging them
	 * into one boolean is what makes a report unactionable: an operator needs
	 * to know WHICH of the three held.
	 *
	 * @param object            $schemaMapper OpenRegister's schema mapper.
	 * @param string            $slug         The forked schema's slug.
	 * @param integer           $schemaId     The forked schema's id.
	 * @param array<int, int>   $linked       Schema ids some register links.
	 *
	 * @return string The reason to spare it, or '' when every condition is met.
	 */
	private function reasonToSpare(object $schemaMapper, string $slug, int $schemaId, array $linked): string {
		if ($slug === '') {
			return 'it has no slug, so its twin cannot be identified';
		}

		if (in_array($schemaId, $linked, true) === true) {
			return 'a register still links it, so it is reachable';
		}

		$twins = $schemaMapper->findAll(
			filters: [
				'application' => Application::APP_ID,
				'slug' => $slug,
			],
			_rbac: false,
			_multitenancy: false
		);

		if (count($twins) === 0) {
			return 'no schema of that slug exists under `dossiq`, so this may be the only copy';
		}

		if (count($twins) > 1) {
			return 'more than one schema of that slug exists under `dossiq`, so the real home is ambiguous';
		}

		return '';
	}//end reasonToSpare()

	/**
	 * Every schema id that some register lists.
	 *
	 * @return array<int, int> The linked schema ids.
	 */
	private function schemaIdsAnyRegisterLinks(): array {
		$registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');

		try {
			$registers = $registerMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// Unreadable registers must not read as "no register links
			// anything": that would let every forked schema through the guard
			// that is meant to catch the reachable ones. Refuse instead, by
			// claiming every id is linked.
			$this->logger->warning(
				'Dossiq: demo-fork repair could not read the registers; sparing every schema',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return [-1];
		}

		$ids = [];
		foreach ($registers as $register) {
			foreach (($register->getSchemas() ?? []) as $id) {
				if (is_numeric($id) === true) {
					$ids[] = (int)$id;
				}
			}
		}

		return array_values(array_unique($ids));
	}//end schemaIdsAnyRegisterLinks()

	/**
	 * What the register is called when nothing has overwritten it.
	 *
	 * 🔴 THE TITLE COMES FROM `info`, NOT FROM THE REGISTER BLOCK, AND THAT IS
	 * MEASURED RATHER THAN ASSUMED. Both places carry a title, and they
	 * disagree: `info.title` says "Dossiq Case Management Register" and
	 * `components.registers.dossiq.title` says "Dossiq". A clean install
	 * shows the first, because OpenRegister provisions the register for a
	 * `type: application` configuration from `info`. Restoring from the
	 * register block would have handed the operator a name they had never
	 * seen and called it a repair.
	 *
	 * The VERSION does come from the register block: that is the only place
	 * that carries one, and 1.1.0 is what the row holds on a clean install.
	 *
	 * @return array{title: string, description: string, version: string} The shipped identity, empty strings when unreadable.
	 */
	private function shippedIdentity(): array {
		$identity = [
			'title' => '',
			'description' => '',
			'version' => '',
		];

		if (is_readable(self::REGISTER_JSON) === false) {
			return $identity;
		}

		$raw = file_get_contents(self::REGISTER_JSON);
		if ($raw === false) {
			return $identity;
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			return $identity;
		}

		$info = ($data['info'] ?? []);
		if (is_array($info) === true) {
			$identity['title'] = (string)($info['title'] ?? '');
			$identity['description'] = (string)($info['description'] ?? '');
		}

		$register = ($data['components']['registers'][Application::APP_ID] ?? []);
		if (is_array($register) === true) {
			$identity['version'] = (string)($register['version'] ?? '');
		}

		return $identity;
	}//end shippedIdentity()
}//end class
