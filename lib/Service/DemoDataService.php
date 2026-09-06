<?php

/**
 * Installs this app's demo dataset on request (ADR-111).
 *
 * An app installed from the App Store opens on an empty list, and the only
 * question its first reader has is whether they can see it work. Answering it
 * requires data they cannot author, against a schema they do not know yet.
 *
 * This service imports `lib/Settings/dossiq_mock_register.json` — a `type: mock` descriptor
 * whose every object was generated from the schema that validates it —
 * through the same OpenRegister importer the app already uses for its real
 * configuration.
 *
 * 🔴 ON DEMAND ONLY, NEVER ON INSTALL. A mock register has no Repair step and
 * is not imported at boot: demo objects appearing unasked on a production
 * instance are indistinguishable from real records to everyone who did not
 * install it. The operator asks, through the setup walkthrough or `occ`.
 *
 * 🔴 AND `force: true`, DELIBERATELY. OpenRegister's importer version-gates a
 * non-forced import and SKIPS silently when the version has not moved. An
 * operator who clicks "install demo data" and is told it succeeded, on an
 * instance where nothing was written, has been lied to by a version compare.
 * The request is explicit, so the import is unconditional.
 *
 * 🔴 AND OBJECTS ONLY. The descriptor ships the register and the schemas its
 * objects were generated from, and `force: true` makes handing those to the
 * importer destructive rather than merely redundant: it forked the schema set
 * and overwrote the live register's title. The payload is narrowed to its
 * objects before it goes in. See {@see self::objectsOnly()} for the two
 * separate mechanisms and what each one cost.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Imports the generated demo dataset into OpenRegister on request.
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 */
class DemoDataService {
	/**
	 * App-relative path to the generated mock descriptor.
	 *
	 * @var string
	 */
	private const DESCRIPTOR = '/lib/Settings/dossiq_mock_register.json';

	/**
	 * BOOKKEEPING identity for the demo import, and nothing else.
	 *
	 * 🔴 ITS OWN NAMESPACE, not the app id, and that part was always right.
	 * OpenRegister keys a Configuration row and the
	 * `imported_config_<app>_version` / `_hash` pair by this string. Sharing
	 * the app's identity would make the demo import and the real
	 * configuration import share one version gate and one Configuration row,
	 * so installing demo data could mask a pending configuration update, or
	 * be masked by one, and the app's own Configuration row would be
	 * retitled `dossiq demo data`.
	 *
	 * 🔴 WHAT WAS WRONG IS THAT THE SAME STRING ALSO NAMED THE SCHEMA OWNER.
	 * `ImportHandler::importFromJson()` passes its `appId` on to
	 * `SchemaMapper::findByApplicationAndSlug()`, so every schema in the
	 * payload was looked up as `(dossiq.demo, <slug>)`, matched nothing, and
	 * took the create branch. One click on "install demo data" therefore
	 * forked the whole schema set: 139 duplicate schemas under application
	 * `dossiq.demo`, 393 demo objects bound to them and invisible to an app
	 * that resolves its schemas under `dossiq`, and the live register
	 * retitled from "Dossiq Case Management Register" to "Dossiq (demo)"
	 * with its application and version overwritten too. Measured on a clean
	 * rig on 2026-09-04, and reproduced on a second one.
	 *
	 * The bookkeeping namespace is kept. The definitional pass is what goes:
	 * see {@see self::objectsOnly()}. With no registers and no schemas in the
	 * payload there is nothing for this id to own, and it is free to go on
	 * meaning what its name says.
	 *
	 * @var string
	 */
	private const CONFIG_APP_ID = Application::APP_ID . '.demo';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Resolves this app's path and version.
	 * @param ContainerInterface $container  Resolves OpenRegister's importer.
	 * @param LoggerInterface    $logger     Records what was imported.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this app ships a demo dataset at all.
	 *
	 * @return boolean True when the descriptor is present on disk.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	public function isAvailable(): bool {
		return is_file($this->descriptorPath()) === true;
	}//end isAvailable()

	/**
	 * The answer that means "plant nothing".
	 *
	 * 🔴 NOT THE ABSENCE OF AN ANSWER. An operator who declines has FINISHED the
	 * step; a step that can never be marked done reopens the wizard over every
	 * page (nextcloud-vue#806).
	 *
	 * @var string
	 */
	public const NONE_DATASET = 'none';

	/**
	 * The id of the dataset this app ships.
	 *
	 * @var string
	 */
	public const DEMO_DATASET = 'demo';

	/**
	 * Every answer the wizard's choice step may offer, declining included.
	 *
	 * 🔴 THE SERVER OWNS THIS LIST, AND THAT IS THE POINT. The step declares
	 * `optionsSource: datasets` and no options of its own, so the label, the
	 * description and the object count come from the descriptor that will
	 * actually be imported. A manifest that restated them could disagree with
	 * what lands, and nothing would notice.
	 *
	 * @return array<int, array{id: string, label: string, description: string, objectCount: integer, icon: string}> The answers.
	 *
	 * @spec exclude Demo-data choice list; ADR-111 rule 1 has no per-app behavioural spec.
	 */
	public function listChoices(): array {
		$choices = [
			[
				'id'          => self::NONE_DATASET,
				'label'       => 'None, I will set this up myself',
				'description' => 'Nothing is imported. You start with an empty app and add your own data.',
				'objectCount' => 0,
				'icon'        => 'CloseCircleOutline',
			],
		];

		$objects = $this->shippedObjectCount();
		if ($objects !== null) {
			$choices[] = [
				'id'    => self::DEMO_DATASET,
				'label' => 'Example data',
				// 🔴 NO NUMBER IN THIS SENTENCE. The wizard runs a card's
				// description through the app's translation function, which is a
				// literal lookup, so an interpolated count would make the string
				// untranslatable and leave a Dutch operator reading English. The
				// count travels as `objectCount` and the card renders it as a
				// stat, with a label the library translates.
				'description' => (
					'Sample values for every schema this app supplies, generated from the schemas '
					. 'themselves. It shows the lists, detail pages and dashboards working rather '
					. 'than telling a story. Safe to run more than once, and you can delete it '
					. 'afterwards.'
				),
				'objectCount' => $objects,
				'icon'        => 'DatabaseOutline',
			];
		}

		return $choices;

	}//end listChoices()

	/**
	 * How many objects the shipped descriptor carries, or null when it ships none.
	 *
	 * Counted from the FILE, so the card promises the number that will actually
	 * be imported. A missing or malformed descriptor returns null and the app
	 * then offers only "None" — honest, rather than an import that cannot run.
	 *
	 * @return integer|null The object count, or null when there is no usable descriptor.
	 */
	private function shippedObjectCount(): ?int {
		$path = $this->descriptorPath();
		if (is_file($path) === false) {
			return null;
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			return null;
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			return null;
		}

		$components = ($data['components'] ?? []);
		if (is_array($components) === false || is_array(($components['objects'] ?? null)) === false) {
			return 0;
		}

		return count($components['objects']);

	}//end shippedObjectCount()

	/**
	 * Import the demo dataset.
	 *''
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. Every caller reports the
	 * outcome to an operator who just asked for this, so "nothing happened"
	 * must not be presentable as success.
	 *
	 * 🔴 AND THE COUNT IS WHAT LANDED, NOT WHAT WAS ASKED FOR. This method used
	 * to count `components.objects` in the shipped file and report that as the
	 * result, with a comment saying the number reported is "the number ASKED
	 * FOR". The ask is not an outcome: a descriptor of 456 objects reported
	 * "456 objects" whether the importer stored 456, three or none, so the ten
	 * demo keys no schema declared (#1782) were stripped on the way in under a
	 * green message that could not have said otherwise. `importFromJson()`
	 * answers with `objects` — the entities it created or updated — and
	 * `skipped.objects` — the ones it refused. Both are read here, and both are
	 * returned, so a caller can print the landing next to the ask.
	 *
	 * 🔴 `registers` AND `schemas` ARE EXPECTED TO BE ZERO, AND THAT IS THE
	 * FIX, NOT A REGRESSION. They count what the import DEFINED, and a demo
	 * set defines neither: it binds its objects to the register and schemas
	 * the app already owns. A non-zero count here means the payload carried a
	 * definitional block that {@see self::objectsOnly()} failed to strip.
	 *
	 * @return array{objects: integer, requested: integer, refused: integer, unchanged: integer,
	 *     registers: integer, schemas: integer} What was asked for and what landed.
	 *
	 * @throws RuntimeException When the descriptor is missing or unreadable, OpenRegister is
	 *     absent, or nothing was stored and nothing was already present.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	public function install(): array {
		$path = $this->descriptorPath();
		if (is_file($path) === false) {
			throw new RuntimeException('No demo dataset ships with this app (' . self::DESCRIPTOR . ' not found).');
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new RuntimeException('The demo dataset could not be read: ' . $path);
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			throw new RuntimeException('The demo dataset is not valid JSON: ' . $path);
		}

		// The ASK: how many objects the shipped descriptor carries. Kept, but
		// as one half of a comparison rather than as the answer.
		$requested = 0;
		$components = ($data['components'] ?? []);
		if (is_array($components) === true && is_array(($components['objects'] ?? null)) === true) {
			$requested = count($components['objects']);
		}

		$result = $this->configurationService()->importFromApp(
			appId: self::CONFIG_APP_ID,
			data: $this->objectsOnly(data: $data),
			version: $this->appManager->getAppVersion(Application::APP_ID),
			force: true
		);

		$imported = $this->readLanding(result: $result, requested: $requested);

		// 🔴 AN IMPORT THAT STORED NOTHING IS NOT A SUCCESS, and this is the
		// only place that can tell. Same shape as the seed steps of #1767 and
		// #1769, which reported `success: true` with every counter at zero and
		// recorded themselves as done. A descriptor that ships no objects at
		// all is a different condition and stays a success: registers and
		// schemas are a legitimate thing to ship on their own.
		// STORING NOTHING IS NOT THE SAME AS FAILING. This read `objects === 0`
		// alone, which refuses an import whose objects are already there, and
		// that is the normal case on a second run. The step's own body promises
		// it is "safe to run more than once", and an idempotent import
		// necessarily stores zero the second time. Measured on CI, dossiq
		// development, every run since 2026-09-03: 444 requested, 0 stored,
		// reported as a hard failure on an install with nothing left to do.
		//
		// So the question is whether anything SURVIVED, not whether anything
		// moved.
		if ($requested > 0 && $imported['objects'] === 0 && $imported['unchanged'] === 0) {
			throw new RuntimeException(
				'The demo import stored 0 of ' . $requested . ' object(s) ('
				. $imported['refused'] . ' refused by OpenRegister) and none was already present. '
				. 'Nothing was written, so this is not an install. The demo set carries objects only '
				. 'and binds them to the register and schemas this app already owns, so check first '
				. 'that the register import has run: `occ maintenance:repair` provisions it. A schema '
				. 'slug that resolves to more than one row is refused as ambiguous, which is what an '
				. 'instance still carrying the old `dossiq.demo` schema fork looks like.'
			);
		}

		$this->reportLanding(imported: $imported, requested: $requested);

		return $imported;
	}//end install()

	/**
	 * Read what the importer actually did, as counts.
	 *
	 * An importer reply with no `objects` key has said nothing about objects,
	 * and nothing is zero, never "as many as we asked for".
	 *
	 * `unchanged` is REPORTED BY THE IMPORTER (openregister#3410), not inferred
	 * here. Deriving it as `requested - stored - refused` looks equivalent and
	 * is not: it silently reclassifies an object the importer dropped WITHOUT
	 * saying so as "already present", which is the exact failure install()'s
	 * guard exists to catch.
	 *
	 * @param array<string, mixed> $result    The importer's reply.
	 * @param integer              $requested How many objects the descriptor carries.
	 *
	 * @return array{objects: integer, requested: integer, refused: integer, unchanged: integer,
	 *     registers: integer, schemas: integer} The landing, next to the ask.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	private function readLanding(array $result, int $requested): array {
		$skipped   = (array)($result['skipped'] ?? []);
		$unchanged = (array)($result['unchanged'] ?? []);

		return [
			'objects'   => count((array)($result['objects'] ?? [])),
			'requested' => $requested,
			'refused'   => (int)($skipped['objects'] ?? 0),
			'unchanged' => (int)($unchanged['objects'] ?? 0),
			'registers' => count((array)($result['registers'] ?? [])),
			'schemas'   => count((array)($result['schemas'] ?? [])),
		];
	}//end readLanding()

	/**
	 * Record what landed, and say so louder when some of it did not.
	 *
	 * @param array<string, mixed> $imported  The landing from {@see self::readLanding()}.
	 * @param integer              $requested How many objects the descriptor carries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	private function reportLanding(array $imported, int $requested): void {
		$this->logger->info(
			'[DemoDataService] imported demo data: '
			. $imported['objects'] . ' of ' . $requested . ' object(s) stored, '
			. $imported['refused'] . ' refused, '
			. $imported['registers'] . ' register(s), '
			. $imported['schemas'] . ' schema(s).',
			['app' => Application::APP_ID]
		);

		if ($imported['objects'] >= $requested) {
			return;
		}

		// Partial. Louder than info on purpose: some of what the app ships did
		// not survive the import, and the message above is the only place the
		// difference is visible.
		$this->logger->warning(
			'[DemoDataService] the demo import lost ' . ($requested - $imported['objects'])
			. ' of ' . $requested . ' object(s) — ' . $imported['refused'] . ' refused, the rest were '
			. 'left unchanged because an object of the same version already exists.',
			['app' => Application::APP_ID]
		);
	}//end reportLanding()

	/**
	 * Strip the definitional blocks, leaving the objects.
	 *
	 * 🔴 THE DEMO SET IS DATA, NOT A CONFIGURATION. It ships a `registers` and
	 * a `schemas` block because it is generated from the app's own register
	 * (`hydra-gates/scripts/lib/generate_mock_register.py` reads the schemas
	 * to generate objects that satisfy them), and handing those blocks to the
	 * importer is what did the damage. Two separate ways, and neither of them
	 * announced itself:
	 *
	 *   SCHEMAS. Resolved by the pair (application, slug). Under any
	 *   application id but the app's own they match nothing, and the
	 *   importer's not-found branch is the CREATE branch, so the set forks.
	 *   Handing them the app's own id instead is worse, not better: the mock
	 *   file is a snapshot, its `case` says version 1.9.0 and carries 50
	 *   properties where the shipped schema says 1.13.0 and carries 56, and
	 *   `force: true` makes the importer write the older one over the live
	 *   one. Installing demo data must not be able to downgrade a schema.
	 *
	 *   REGISTERS. Resolved by slug ALONE, so the demo's register entry
	 *   matched the live row every time and `updateFromArray()` overwrote it:
	 *   "Dossiq Case Management Register" became "Dossiq (demo)", version
	 *   1.1.0 became 1.0.0. A register is an identity an operator sees; a
	 *   demo data set has no business rewriting it.
	 *
	 * Objects do not need either block. `ImportHandler` resolves a seed
	 * object's `@self.register` / `@self.schema` slugs against the in-flight
	 * import maps FIRST and falls back to a direct mapper lookup, which finds
	 * the rows the app's own configuration import already created. So the
	 * objects land where the app reads them, and the only thing this payload
	 * can now change is object rows.
	 *
	 * Both spellings are stripped: the importer reads seed objects from
	 * `components.*` and from the top-level keys.
	 *
	 * @param array<string, mixed> $data The decoded descriptor.
	 *
	 * @return array<string, mixed> The same descriptor with no register or schema definitions.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	private function objectsOnly(array $data): array {
		unset($data['registers'], $data['schemas']);

		if (is_array(($data['components'] ?? null)) === true) {
			unset($data['components']['registers'], $data['components']['schemas']);
		}

		return $data;
	}//end objectsOnly()

	/**
	 * Absolute path to the shipped descriptor.
	 *
	 * @return string The path.
	 */
	private function descriptorPath(): string {
		return $this->appManager->getAppPath(Application::APP_ID) . self::DESCRIPTOR;
	}//end descriptorPath()

	/**
	 * OpenRegister's configuration importer.
	 *
	 * 🔴 A CROSS-APP CLASS IS A RUNTIME LOOKUP. OpenRegister may not be
	 * installed, and asking the container for a class from a missing app
	 * raises something the caller cannot act on. Check first and say which app
	 * is missing.
	 *
	 * 🔴 THE RETURN TYPE IS `object`, NOT THE CLASS, AND THAT IS THE POINT.
	 * Naming a class from an OPTIONAL app in a native return type makes PHP
	 * resolve it whenever this method returns — so on an instance without
	 * OpenRegister the failure is a TypeError about a class nobody mentioned,
	 * instead of the RuntimeException above that names the missing app. It
	 * also makes the method impossible to exercise in a unit test, which is
	 * how this was found. The docblock keeps psalm and phpstan informed.
	 *
	 * @return object The importer — an OCA\OpenRegister\Service\ConfigurationService.
	 *
	 * @psalm-return \OCA\OpenRegister\Service\ConfigurationService
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function configurationService(): object {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new RuntimeException('Demo data needs OpenRegister, which is not installed.');
		}

		return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
	}//end configurationService()
}//end class
