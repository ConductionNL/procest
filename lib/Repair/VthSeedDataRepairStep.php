<?php

/**
 * Dossiq VTH Seed Data Repair Step.
 *
 * Idempotent loader for `lib/Settings/vth_seed_data.json`. Seeds the
 * VTH (Vergunningen, Toezicht, Handhaving) case-type catalogue, each case
 * type's statusTypes, roleTypes, documentTypes and propertyDefinitions, and
 * the 3 baseline inspection-checklist templates referenced by the VTH module
 * specs. Re-runs are safe: an existing case type is left alone, and its
 * children are matched by name so none is written twice.
 *
 * The children are written by
 * {@see \OCA\Dossiq\Repair\Vth\VthCaseTypeChildSeeder} into the schemas
 * that declare them, because the link lives on the child: `caseType` has no
 * `statusTypes` property, every `statusType` carries a `caseType`
 * back-reference instead.
 *
 * Listed in `appinfo/info.xml` between `SeedVthWorkflowTemplates` and
 * `SeedDeadlineMonitoringData`; runs as `post-migration` after
 * `InitializeSettings` has populated the `case_type_schema` /
 * `status_type_schema` / `role_type_schema` / `document_type_schema` /
 * `property_definition_schema` / `inspection_checklist_template_schema`
 * config keys.
 *
 * The class is additive: it does NOT touch the workflow templates shipped by
 * `SeedVthWorkflowTemplates` — which run AFTER this step and read the
 * statusTypes it writes — nor the LHS matrix shipped by `SeedVthMatrixCells`. The `lhsMatrix`
 * fragment in `vth_seed_data.json` is descriptive (level glossary)
 * rather than canonical seed rows — it documents the matrix the
 * dedicated repair step seeds.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 *
 * @spec openspec/changes/vth-workflow-configuration-01-config-foundation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Vth\VthCaseTypeChildSeeder;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step that seeds VTH case types and inspection-checklist templates
 * into OpenRegister.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — needs OpenRegister + settings.
 *
 * @spec openspec/changes/vth-workflow-configuration-01-config-foundation/tasks.md
 */
class VthSeedDataRepairStep implements IRepairStep {

	use SearchesObjects;

	/**
	 * Location of the VTH seed catalogue, relative to this file.
	 */
	private const SEED_PATH = __DIR__ . '/../Settings/vth_seed_data.json';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings bridge.
	 * @param VthCaseTypeChildSeeder $children Writes each case type's statusTypes,
	 *                                         roleTypes, documentTypes and
	 *                                         propertyDefinitions.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly VthCaseTypeChildSeeder $children,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair-step display name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/vth-workflow-configuration-01-config-foundation/tasks.md
	 */
	public function getName(): string {
		return 'Seed VTH case types and inspection-checklist templates for Dossiq';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-workflow-configuration-01-config-foundation/tasks.md
	 */
	public function run(IOutput $output): void {
		$output->info('Seeding VTH case types + inspection checklists...');

		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not available. Skipping VTH seed.');
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->warning('ObjectService unavailable. Skipping VTH seed.');
			return;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		if ($register === '') {
			$output->warning('Register not configured. Skipping VTH seed.');
			return;
		}

		$caseTypeSchema = (string)$this->settingsService->getConfigValue('case_type_schema');
		if ($caseTypeSchema === '') {
			$output->warning('case_type_schema not configured. Skipping VTH seed.');
			return;
		}

		$data = $this->loadSeed(output: $output);
		if ($data === null) {
			return;
		}

		// Repair steps run without a Nextcloud user session — anonymous
		// callers are fail-closed by OpenRegister RBAC (#1955) on every
		// boot, so the idempotency reads + writes below run inside
		// runAsSystem().
		[$caseSummary, $checklistSummary] = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use ($objectService, $register, $caseTypeSchema, $data, $output): array {
				$caseSummary = $this->seedCaseTypes(
					objectService: $objectService,
					register: $register,
					caseTypeSchema: $caseTypeSchema,
					data: $data,
					output: $output
				);

				// The checklists bind to their case type by uuid, so they reuse
				// the map the case-type pass just built and refreshed rather
				// than reading the whole catalogue back a second time.
				$checklistSummary = $this->seedInspectionChecklists(
					objectService: $objectService,
					register: $register,
					data: $data,
					caseTypeIds: $caseSummary['ids'],
					output: $output
				);

				return [$caseSummary, $checklistSummary];
			}
		);

		$output->info(
			sprintf(
				'VTH seed complete: %d case-types (%d already present), %d child records, '
				. '%d checklists (%d skipped).',
				$caseSummary['seeded'],
				$caseSummary['skipped'],
				$caseSummary['children'],
				$checklistSummary['seeded'],
				$checklistSummary['skipped']
			)
		);
	}//end run()

	/**
	 * Load and decode the seed catalogue.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadSeed(IOutput $output): ?array {
		if (file_exists(self::SEED_PATH) === false) {
			$output->warning('VTH seed file not found: ' . self::SEED_PATH);
			return null;
		}

		$raw = (string)file_get_contents(self::SEED_PATH);
		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			$output->warning('VTH seed file is not a JSON object.');
			return null;
		}

		return $data;
	}//end loadSeed()

	/**
	 * Seed the case-type catalogue and every case type's children.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug.
	 * @param string $caseTypeSchema Case-type schema slug.
	 * @param array<string, mixed> $data Decoded seed data.
	 * @param IOutput $output Output.
	 *
	 * @return array{seeded: int, skipped: int, children: int, ids: array<string, string>}
	 *
	 * @spec openspec/specs/vth-case-type-seed/spec.md
	 */
	private function seedCaseTypes(
		object $objectService,
		string $register,
		string $caseTypeSchema,
		array $data,
		IOutput $output,
	): array {
		$summary = ['seeded' => 0, 'skipped' => 0, 'children' => 0, 'ids' => []];

		$caseTypes = $data['caseTypes'] ?? [];
		if (is_array($caseTypes) === false || $caseTypes === []) {
			return $summary;
		}

		$summary['ids'] = $this->caseTypeIdsBySlug(
			objectService: $objectService,
			register: $register
		);

		foreach ($caseTypes as $caseType) {
			if (is_array($caseType) === false) {
				continue;
			}

			$slug = (string)($caseType['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			$caseTypeId = $this->resolveOrCreate(
				objectService: $objectService,
				register: $register,
				caseTypeSchema: $caseTypeSchema,
				caseType: $caseType,
				slug: $slug,
				summary: $summary,
				output: $output
			);

			if ($caseTypeId === '') {
				continue;
			}

			// 🔑 SEEDED WHETHER OR NOT THE CASE TYPE IS NEW. The six VTH case
			// types are already installed everywhere, so a fix that only ran
			// on creation would be a no-op on every instance that has the
			// defect. The child seeder is idempotent per child, so a case type
			// that already has its statuses gains nothing here.
			$children = $this->children->seed(
				objectService: $objectService,
				register: $register,
				caseTypeId: $caseTypeId,
				caseType: $caseType,
				output: $output
			);

			$summary['children'] += $children['created'];
		}//end foreach

		return $summary;
	}//end seedCaseTypes()

	/**
	 * The uuid of one seeded case type: the one already on the instance, or a
	 * freshly created one.
	 *
	 * A save that lands but whose uuid cannot be read leaves the children for
	 * the next run rather than writing them against an empty `caseType`, which
	 * is a reference no reader can follow and no later run can repair.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug.
	 * @param string $caseTypeSchema Case-type schema slug.
	 * @param array<string, mixed> $caseType The case type as shipped.
	 * @param string $slug Its slug.
	 * @param array{seeded: int, skipped: int, children: int, ids: array<string, string>} $summary Tallies, updated in place.
	 * @param IOutput $output Output.
	 *
	 * @return string The uuid, or the empty string when there is none to use.
	 *
	 * @spec openspec/specs/vth-case-type-seed/spec.md
	 */
	private function resolveOrCreate(
		object $objectService,
		string $register,
		string $caseTypeSchema,
		array $caseType,
		string $slug,
		array &$summary,
		IOutput $output,
	): string {
		$existingId = (string)($summary['ids'][$slug] ?? '');
		if ($existingId !== '') {
			$summary['skipped']++;
			return $existingId;
		}

		try {
			// Only top-level case-type fields are persisted here; the four
			// child collections are stripped first.
			//
			// 🔴 THE COMMENT HERE USED TO SAY SeedVthWorkflowTemplates OWNS
			// THEM. IT DOES NOT — it only READS statusTypes, through
			// VthSeedLookup::buildStatusMap(), and skips the whole template
			// when it finds none. So nothing wrote them, every VTH template
			// was skipped, and the run reported "0 seeded" as a success.
			// Measured on a clean rig on 2026-09-04: six VTH case types, zero
			// statusTypes attached to any of them.
			//
			// The strip itself stays, for the reason its author did not have:
			// `caseType` DECLARES no `statusTypes`, `roleTypes`,
			// `documentTypes` or `propertyDefinitions` property, and
			// OpenRegister's magic mapper is a whitelist by omission, so
			// leaving them on this payload writes four keys the save answers
			// 200 to and stores nowhere. The missing half was never the strip:
			// it was writing them where the schema can hold them, which is
			// what VthCaseTypeChildSeeder now does.
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $caseTypeSchema,
				object: $this->stripChildren(caseType: $caseType)
			);
		} catch (Throwable $e) {
			$output->warning('VTH case-type seed failed for ' . $slug . ': ' . $e->getMessage());
			$this->logger->warning(
				'Dossiq VTH case-type seed failed',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
			return '';
		}//end try

		$summary['seeded']++;

		$id = $this->idOf(row: $saved);
		if ($id === '') {
			$output->warning(
				'VTH seed: case type "' . $slug . '" was created but its id could not be read, '
				. 'so its children wait for the next `occ maintenance:repair`.'
			);
			return '';
		}

		$summary['ids'][$slug] = $id;

		return $id;
	}//end resolveOrCreate()

	/**
	 * The uuid of a saved row, wherever the store put it.
	 *
	 * `@self.id` is where a read carries it — the same place `existingSlugs()`
	 * reads the slug from — and `id` / `uuid` are the shapes a save can answer
	 * with.
	 *
	 * @param array<string, mixed>|null $row The saved row.
	 *
	 * @return string The uuid, or the empty string.
	 */
	private function idOf(?array $row): string {
		if ($row === null) {
			return '';
		}

		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		return (string)($self['id'] ?? $row['id'] ?? $row['uuid'] ?? '');
	}//end idOf()

	/**
	 * Seed the inspection-checklist templates.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug.
	 * @param array<string, mixed> $data Decoded seed data.
	 * @param array<string, string> $caseTypeIds Case-type uuid keyed by slug.
	 * @param IOutput $output Output.
	 *
	 * @return array{seeded: int, skipped: int}
	 */
	private function seedInspectionChecklists(
		object $objectService,
		string $register,
		array $data,
		array $caseTypeIds,
		IOutput $output,
	): array {
		$checklists = $data['inspectionChecklists'] ?? [];
		if (is_array($checklists) === false || $checklists === []) {
			return ['seeded' => 0, 'skipped' => 0];
		}

		// Prefer the configured schema slug; fall back to the canonical name.
		$schema = (string)$this->settingsService->getConfigValue('inspection_checklist_template_schema');
		if ($schema === '') {
			$schema = 'inspectionChecklistTemplate';
		}

		$existing = $this->existingSlugs(
			objectService: $objectService,
			register: $register,
			schema: $schema
		);

		$seeded = 0;
		$skipped = 0;
		foreach ($checklists as $checklist) {
			if (is_array($checklist) === false) {
				continue;
			}

			$slug = (string)($checklist['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			if (in_array($slug, $existing, true) === true) {
				$skipped++;
				continue;
			}

			try {
				$objectService->saveObject(
					register: $register,
					schema: $schema,
					object: $this->bindCaseType(checklist: $checklist, caseTypeIds: $caseTypeIds)
				);
				$seeded++;
			} catch (Throwable $e) {
				$output->warning('VTH checklist seed failed for ' . $slug . ': ' . $e->getMessage());
				$this->logger->warning(
					'Dossiq VTH checklist seed failed',
					['slug' => $slug, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		return ['seeded' => $seeded, 'skipped' => $skipped];
	}//end seedInspectionChecklists()

	/**
	 * Strip the four child collections from a case-type payload.
	 *
	 * NOT to avoid a double write — nothing else writes them. They come off
	 * because `caseType` declares none of these four as properties, so leaving
	 * them on would write keys OpenRegister answers 200 to and stores nowhere.
	 * {@see \OCA\Dossiq\Repair\Vth\VthCaseTypeChildSeeder} writes them into
	 * the schemas that do declare them, once the case type has an id to
	 * point at.
	 *
	 * @param array<string, mixed> $caseType Raw case-type row.
	 *
	 * @return array<string, mixed>
	 */
	private function stripChildren(array $caseType): array {
		unset(
			$caseType['statusTypes'],
			$caseType['roleTypes'],
			$caseType['documentTypes'],
			$caseType['propertyDefinitions']
		);
		return $caseType;
	}//end stripChildren()

	/**
	 * Bind a checklist template to its case type, by slug.
	 *
	 * The seed names its case type by slug because that is the only stable
	 * identifier a shipped file can carry: the uuid is minted at install. The
	 * schema declares `caseType` (a uuid `$ref`) and declares no `caseTypeSlug`,
	 * so shipping the slug straight through wrote a key OpenRegister answers 200
	 * to and stores nowhere, and every checklist installed unbound.
	 *
	 * An unresolvable slug drops the binding rather than the template: a
	 * checklist with no case type is still usable, `caseType` is optional
	 * ("null means any case type"), and a `caseTypeSlug` left in the payload
	 * would only be discarded again.
	 *
	 * @param array<string, mixed> $checklist The shipped checklist payload.
	 * @param array<string, string> $caseTypeIds Case-type uuid keyed by slug.
	 *
	 * @return array<string, mixed> The payload as OpenRegister should receive it.
	 */
	private function bindCaseType(array $checklist, array $caseTypeIds): array {
		$slug = (string)($checklist['caseTypeSlug'] ?? '');
		unset($checklist['caseTypeSlug']);

		$caseTypeId = (string)($caseTypeIds[$slug] ?? '');
		if ($slug !== '' && $caseTypeId === '') {
			$this->logger->warning(
				'Dossiq VTH checklist seed could not resolve its case type',
				['checklist' => ($checklist['slug'] ?? ''), 'caseTypeSlug' => $slug]
			);
			return $checklist;
		}

		if ($caseTypeId !== '') {
			$checklist['caseType'] = $caseTypeId;
		}

		return $checklist;
	}//end bindCaseType()

	/**
	 * Map every seeded case type's slug to its OpenRegister uuid.
	 *
	 * The slug lives in `@self`, the same place `existingSlugs()` reads it from.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug.
	 *
	 * @return array<string, string> Case-type uuid keyed by slug.
	 */
	private function caseTypeIdsBySlug(object $objectService, string $register): array {
		$schema = (string)$this->settingsService->getConfigValue('case_type_schema');
		if ($schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema
			);
		} catch (Throwable) {
			return [];
		}

		$ids = [];
		foreach ($rows as $row) {
			$self = ($row['@self'] ?? []);
			$slug = (string)($self['slug'] ?? $row['slug'] ?? '');
			$id = (string)($self['id'] ?? $row['id'] ?? '');
			if ($slug !== '' && $id !== '') {
				$ids[$slug] = $id;
			}
		}

		return $ids;
	}//end caseTypeIdsBySlug()

	/**
	 * Read existing slugs for idempotency.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 *
	 * @return array<int, string>
	 */
	private function existingSlugs(
		object $objectService,
		string $register,
		string $schema,
	): array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema
			);
		} catch (Throwable) {
			return [];
		}

		$slugs = [];
		foreach ($rows as $row) {
			// THE SLUG LIVES IN `@self`, NOT IN THE OBJECT BODY.
			//
			// A seeded `slug:` is an import-time identifier OpenRegister keeps
			// as metadata; it is NOT a stored property. Reading `$row['slug']`
			// therefore returned '' for every row, so this list came back empty,
			// so the idempotency check below matched nothing, so every upgrade
			// re-seeded the whole set. Measured on a live instance: nine
			// consecutive upgrades left 9 copies each of Omgevingsvergunning
			// Bouwactiviteit, Sloopmelding, Toezichtzaak Bouw, Toezichtzaak
			// Milieu, Handhavingszaak and Invorderingszaak — and every run
			// reported success.
			//
			// The body form is kept as a fallback rather than dropped: an
			// object created by some other path may legitimately carry it.
			$self = $row['@self'] ?? [];
			$slug = (string)($self['slug'] ?? $row['slug'] ?? '');
			if ($slug !== '') {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}//end existingSlugs()
}//end class
