<?php

/**
 * Dossiq Seed VTH Workflow Templates Repair Step
 *
 * Repair step that seeds six canonical VTH (Vergunningen, Toezicht &
 * Handhaving) workflow templates as published `workflowTemplate` v1 objects
 * via `WorkflowDefinitionService::createDraft()` + `publish()`. Idempotent
 * on re-run.
 *
 * The repair step routes every mutation of a `workflowTemplate` through
 * `WorkflowDefinitionService` to respect the immutability invariant of
 * published rows established by `workflow-definition-model`. It NEVER
 * writes `workflowTemplate` rows directly through `ObjectService`.
 *
 * Nothing in the catalogue is a hard dependency. An entry whose case type is
 * absent, whose case type carries no statuses, or that names a status the case
 * type does not have is skipped, and the rest of the catalogue continues. Every
 * skip is named with its reason in the summary the step prints, because a count
 * of skipped entries is what hid `toezichtbezoek` for the life of the
 * catalogue.
 *
 * A template this step created but could not publish is published on the next
 * run: an existing DRAFT is a repair, an existing published row is a no-op, and
 * an existing DEPRECATED row is reported and left alone. Two catalogue entries
 * may share a case type when they declare different routes (see
 * openspec/specs/workflow-variants/spec.md); publishing one of them leaves the
 * others backing new cases.
 *
 * This class is orchestration only. The OpenRegister reads live in
 * {@see \OCA\Dossiq\Repair\Vth\VthSeedLookup} and the steps/transitions
 * translation in {@see \OCA\Dossiq\Repair\Vth\VthWorkflowGraphResolver}.
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
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Repair\Vth\VthCatalogueFiles;
use OCA\Dossiq\Repair\Vth\VthCatalogueReport;
use OCA\Dossiq\Repair\Vth\VthSeedLookup;
use OCA\Dossiq\Repair\Vth\VthWorkflowGraphResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WorkflowDefinitionService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds six canonical VTH workflow templates.
 *
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */
class SeedVthWorkflowTemplates implements IRepairStep {

	/**
	 * Memoised template slug → caseType UUID map, built once per run.
	 *
	 * A `spawnCase` action names its target by TEMPLATE slug, while the engine's
	 * `createSubCase` needs a caseType UUID — which is instance-specific and so
	 * cannot live in the catalog JSON. This map is the bridge.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $spawnTargets = null;

	/**
	 * Constructor for SeedVthWorkflowTemplates.
	 *
	 * @param SettingsService $settingsService Settings service for OR access
	 * @param WorkflowDefinitionService $definitionService Workflow lifecycle service
	 * @param VthCatalogueFiles $files The bundled catalogue on disk
	 * @param VthSeedLookup $lookup OpenRegister lookups for the seed
	 * @param VthWorkflowGraphResolver $graphResolver Steps/transitions resolver
	 * @param VthCatalogueReport $report Per-entry outcomes and the summary they print
	 * @param LoggerInterface $logger Logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WorkflowDefinitionService $definitionService,
		private readonly VthCatalogueFiles $files,
		private readonly VthSeedLookup $lookup,
		private readonly VthWorkflowGraphResolver $graphResolver,
		private readonly VthCatalogueReport $report,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function getName(): string {
		return 'Seed VTH (Vergunningen, Toezicht, Handhaving) workflow templates for Dossiq';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function run(IOutput $output): void {
		$output->info('Seeding VTH workflow templates...');

		$files = $this->catalogFiles(output: $output);
		if ($files === []) {
			return;
		}

		$this->report->reset();

		$this->lookup->runElevated(
			operation: function () use ($files): void {
				foreach ($files as $file) {
					$this->processCatalogFileSafely(file: $file);
				}
			}
		);

		$this->report->write(output: $output);
	}//end run()

	/**
	 * Resolve the catalog files to seed, reporting every precondition that makes
	 * the seed a no-op.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return array<int, string> Absolute catalog file paths, or an empty list.
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	private function catalogFiles(IOutput $output): array {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning(
				'OpenRegister is not available. Skipping VTH workflow templates seed.'
			);
			return [];
		}

		if ($this->files->exists() === false) {
			$output->warning(
				'VTH workflow templates catalog directory not found at ' . $this->files->directory()
			);
			return [];
		}

		$files = $this->files->paths();
		if ($files === []) {
			$output->warning('No VTH workflow template catalog files found.');
			return [];
		}

		return $files;
	}//end catalogFiles()

	/**
	 * Build (once per run) the template slug → caseType UUID map for spawnCase.
	 *
	 * Every catalog entry is read, including cross-link ones: a cross-link entry
	 * seeds no workflow of its own but its caseType is still a legitimate spawn
	 * target. A slug whose caseType does not resolve is simply absent from the
	 * map, and the resolver drops the action rather than storing a dead one.
	 *
	 * @return array<string, string> Template slug → caseType UUID
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	private function spawnTargets(): array {
		if ($this->spawnTargets !== null) {
			return $this->spawnTargets;
		}

		$map = [];
		foreach ($this->files->paths() as $file) {
			$data = $this->files->load(file: $file);
			if ($data === null) {
				continue;
			}

			$caseTypeSlug = (string)($data['caseTypeSlug'] ?? '');
			if ($caseTypeSlug === '') {
				continue;
			}

			$caseTypeId = $this->lookup->resolveCaseTypeId(slug: $caseTypeSlug);
			if ($caseTypeId === '') {
				continue;
			}

			$map[(string)$data['slug']] = $caseTypeId;
		}

		$this->spawnTargets = $map;

		return $map;
	}//end spawnTargets()

	/**
	 * Process one catalog file, converting any throw into a `failed` tally.
	 *
	 * One unusable catalog file must never abort the rest of the catalog.
	 *
	 * @param string $file Absolute path to the JSON catalog file.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	private function processCatalogFileSafely(string $file): void {
		try {
			$this->processCatalogFile(file: $file);
			return;
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to process VTH workflow template catalog file',
				[
					'app' => Application::APP_ID,
					'file' => basename($file),
					'exception' => $e->getMessage(),
				]
			);
			$this->outcome(
				entry: basename($file),
				outcome: 'failed',
				reason: 'failed while being processed, see the log for the error.',
			);
		}//end try
	}//end processCatalogFileSafely()

	/**
	 * Record one entry's result for the summary.
	 *
	 * @param string $entry The catalogue entry, by slug or file name.
	 * @param string $outcome One of seeded|published|present|deprecated|skipped|crossLink|failed.
	 * @param string $reason What happened to it, in one sentence.
	 *
	 * @return void
	 */
	private function outcome(string $entry, string $outcome, string $reason): void {
		$this->report->record(entry: $entry, outcome: $outcome, reason: $reason);
	}//end outcome()

	/**
	 * Process a single catalog file.
	 *
	 * @param string $file Absolute path to the JSON catalog file
	 *
	 * @return void
	 */
	private function processCatalogFile(string $file): void {
		$data = $this->files->load(file: $file);
		if ($data === null) {
			$this->outcome(
				entry: basename($file),
				outcome: 'failed',
				reason: 'unreadable, invalid JSON, or missing its slug or title.',
			);
			return;
		}

		$slug = (string)($data['slug'] ?? '');
		$title = (string)($data['title'] ?? '');

		// Cross-link entries (e.g. bezwaar) do not create a new
		// workflowTemplate; they only document VTH-specific guards that
		// a downstream change should attach to the canonical workflow.
		if ((bool)($data['crossLink'] ?? false) === true) {
			$this->reportCrossLink(data: $data, slug: $slug);
			$this->outcome(
				entry: $slug,
				outcome: 'crossLink',
				reason: 'cross-link entry, it documents guards on "'
					. (string)($data['targetWorkflowIdentifier'] ?? '') . '" and creates no workflow.',
			);
			return;
		}

		// Resolve caseType slug → UUID and the statusType map (soft-fail).
		$context = $this->resolveTemplateContext(data: $data, slug: $slug, title: $title);
		if (isset($context['present']) === true) {
			$this->outcome(entry: $slug, outcome: 'present', reason: (string)$context['present']);
			return;
		}

		if (isset($context['deprecated']) === true) {
			$this->outcome(entry: $slug, outcome: 'deprecated', reason: (string)$context['deprecated']);
			return;
		}

		if (isset($context['skip']) === true) {
			$this->outcome(entry: $slug, outcome: 'skipped', reason: (string)$context['skip']);
			return;
		}

		if (isset($context['republish']) === true) {
			$this->publishExistingDraft(
				slug: $slug,
				title: $title,
				draftId: (string)$context['republish'],
			);
			return;
		}

		// Resolve steps and transitions. On any unresolved status the whole
		// template is skipped, so nothing is ever half seeded.
		$graph = $this->graphResolver->resolve(
			data: $data,
			slug: $slug,
			statusMap: (array)$context['statusMap'],
			spawnTargets: $this->spawnTargets(),
		);
		if ($graph['unresolved'] !== []) {
			$this->outcome(
				entry: $slug,
				outcome: 'skipped',
				reason: 'skipped, case type "' . (string)($data['caseTypeSlug'] ?? '') . '" has no status named '
					. $this->report->quotedList(values: $graph['unresolved']) . '. It has '
					. $this->report->quotedList(values: array_keys((array)$context['statusMap'])) . '.',
			);
			return;
		}

		$this->createAndPublishTemplate(
			data: $data,
			slug: $slug,
			title: $title,
			caseTypeId: (string)$context['caseTypeId'],
			graph: $graph,
		);
	}//end processCatalogFile()

	/**
	 * Publish a draft an earlier run created but could not publish.
	 *
	 * @param string $slug The template slug.
	 * @param string $title The template title.
	 * @param string $draftId The existing draft's UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	private function publishExistingDraft(string $slug, string $title, string $draftId): void {
		$published = $this->definitionService->publish(id: $draftId);
		if ($published === null) {
			$this->logger->error(
				'Dossiq: VTH workflow template could not be published from its existing draft',
				['app' => Application::APP_ID, 'slug' => $slug, 'draftId' => $draftId]
			);
			$this->outcome(
				entry: $slug,
				outcome: 'failed',
				reason: 'is present as a draft and could not be published, see the log for the reason.',
			);
			return;
		}

		$this->outcome(
			entry: $slug,
			outcome: 'published',
			reason: 'was left as a draft by an earlier run and is now published as "' . $title . '".',
		);
	}//end publishExistingDraft()

	/**
	 * Report a cross-link catalog entry: documented, never seeded.
	 *
	 * @param array<string, mixed> $data The decoded catalog entry.
	 * @param string $slug The template slug.
	 *
	 * @return void
	 */
	private function reportCrossLink(array $data, string $slug): void {
		$this->logger->info(
			'Dossiq: VTH workflow template cross-link entry, no new workflow created',
			[
				'app' => Application::APP_ID,
				'slug' => $slug,
				'targetWorkflowIdentifier' => (string)($data['targetWorkflowIdentifier'] ?? ''),
			]
		);
	}//end reportCrossLink()

	/**
	 * Resolve the caseType UUID and statusType map a template needs, applying
	 * the idempotency check.
	 *
	 * Answers one of five things:
	 *   `present`     this entry is already seeded and published, nothing to do.
	 *   `deprecated`  this entry is present and retired, which is reported and never undone.
	 *   `skip`        the reason this entry cannot be seeded, for the summary.
	 *   `republish`   the uuid of a draft an earlier run failed to publish.
	 *   otherwise     {caseTypeId, statusMap} to build the template from.
	 *
	 * @param array<string, mixed> $data The decoded catalog entry
	 * @param string $slug The template slug
	 * @param string $title The template title
	 *
	 * @return array<string, mixed> One of the three answers above
	 */
	private function resolveTemplateContext(array $data, string $slug, string $title): array {
		$caseTypeSlug = (string)($data['caseTypeSlug'] ?? '');
		$caseTypeId = $this->resolveCaseType(data: $data, slug: $slug);
		if ($caseTypeId === '') {
			if ($caseTypeSlug === '') {
				return ['skip' => 'skipped, the catalogue entry names no case type.'];
			}

			return [
				'skip' => 'skipped, this instance has no case type "' . $caseTypeSlug
					. '". Create it, then run `occ maintenance:repair` again.',
			];
		}

		// Idempotency, and the repair that goes with it: an existing published
		// template is left alone, an existing DRAFT is one a failed publish
		// stranded and gets published on this run.
		$existing = $this->lookup->findSeeded(caseTypeId: $caseTypeId, title: $title);
		if ($existing !== null) {
			$this->logger->info(
				'Dossiq: VTH workflow template already present',
				[
					'app' => Application::APP_ID,
					'slug' => $slug,
					'caseType' => $caseTypeId,
					'lifecycleStatus' => (string)($existing['lifecycleStatus'] ?? ''),
				]
			);

			$lifecycleStatus = (string)($existing['lifecycleStatus'] ?? '');
			if ($lifecycleStatus === WorkflowDefinitionService::STATUS_DRAFT) {
				return ['republish' => (string)($existing['id'] ?? ($existing['uuid'] ?? ''))];
			}

			// A retired entry is reported, never republished. See
			// VthCatalogueReport::deprecatedReason() for why the seeder must not
			// decide this on the administrator's behalf.
			if ($lifecycleStatus === WorkflowDefinitionService::STATUS_DEPRECATED) {
				return [
					'deprecated' => $this->report->deprecatedReason(
						title: $title,
						variant: $this->files->variantOf(data: $data),
					),
				];
			}

			$named = $lifecycleStatus;
			if ($named === '') {
				$named = 'unknown';
			}

			return ['present' => 'already present as ' . $named . ', nothing to do.'];
		}

		// Build the name → UUID map for statusTypes belonging to this caseType.
		// 🔑 THIS STEP READS THEM; IT HAS NEVER WRITTEN THEM. For a long time
		// nothing did: VthSeedDataRepairStep stripped a case type's statusTypes
		// before saving it, on the stated grounds that THIS step owned them, so
		// a VTH case type arrived with none, every template was skipped here,
		// and the run reported "0 seeded" with no error anywhere. Measured on a
		// clean rig on 2026-09-04: six VTH case types, 46 statusTypes on the
		// instance and not one of them attached to a VTH case type.
		//
		// VthCaseTypeChildSeeder now writes them, and info.xml runs
		// VthSeedDataRepairStep before this step in both blocks. An empty map
		// here therefore means a case type genuinely carries no statuses, which
		// the message says rather than guessing at an owner.
		$statusMap = $this->lookup->buildStatusMap(caseTypeId: $caseTypeId);
		if ($statusMap === []) {
			$this->logger->warning(
				'Dossiq: VTH workflow template found no statusTypes for its caseType',
				[
					'app' => Application::APP_ID,
					'slug' => $slug,
					'caseType' => $caseTypeId,
					'caseTypeSlug' => $caseTypeSlug,
				]
			);
			return [
				'skip' => 'skipped, case type "' . $caseTypeSlug . '" carries no statuses. '
					. 'VthSeedDataRepairStep seeds them and runs first, so read its output above.',
			];
		}

		return [
			'caseTypeId' => $caseTypeId,
			'statusMap' => $statusMap,
		];
	}//end resolveTemplateContext()

	/**
	 * Resolve the catalog entry's caseType slug to its UUID.
	 *
	 * @param array<string, mixed> $data The decoded catalog entry.
	 * @param string $slug The template slug.
	 *
	 * @return string The caseType UUID, or the empty string when unresolved.
	 */
	private function resolveCaseType(array $data, string $slug): string {
		$caseTypeSlug = (string)($data['caseTypeSlug'] ?? '');
		if ($caseTypeSlug === '') {
			$this->logger->warning(
				'Dossiq: VTH workflow template names no caseTypeSlug',
				['app' => Application::APP_ID, 'slug' => $slug]
			);
			return '';
		}

		$caseTypeId = $this->lookup->resolveCaseTypeId(slug: $caseTypeSlug);
		if ($caseTypeId === '') {
			// 🔴 THE OLD MESSAGE NAMED A STEP THAT DOES NOT EXIST, AND THAT IS
			// WHY THIS TOOK SO LONG TO FIND. It read "run base-register-seed-data
			// first", which is not a repair step, not an occ command and not a
			// thing an operator can run. It was also the ONLY diagnosis on
			// offer, so two real defects hid behind it: the step was registered
			// ahead of VthSeedDataRepairStep, which provisions the case types
			// it resolves, and the lookup could not read a case type whose slug
			// is metadata rather than a property. Both are fixed. What is left
			// is a genuine gap in the catalogue, and the caller names it in the
			// per-entry summary rather than inventing a command.
			$this->logger->warning(
				'Dossiq: VTH workflow template skipped, its caseType is not on this instance',
				[
					'app' => Application::APP_ID,
					'slug' => $slug,
					'caseTypeSlug' => $caseTypeSlug,
				]
			);
		}

		return $caseTypeId;
	}//end resolveCaseType()

	/**
	 * Create the draft via the lifecycle service and publish it.
	 *
	 * @param array<string, mixed> $data The decoded catalog entry
	 * @param string $slug The template slug
	 * @param string $title The template title
	 * @param string $caseTypeId The resolved caseType UUID
	 * @param array<string, mixed> $graph The resolved {steps, transitions}
	 *
	 * @return void
	 */
	private function createAndPublishTemplate(
		array $data,
		string $slug,
		string $title,
		string $caseTypeId,
		array $graph,
	): void {
		$version = (int)($data['version'] ?? 1);
		$variant = $this->files->variantOf(data: $data);

		// Create draft via the lifecycle service.
		$draft = $this->definitionService->createDraft(
			payload: [
				'title' => $title,
				'description' => (string)($data['description'] ?? ''),
				'caseType' => $caseTypeId,
				'variant' => $variant,
				'version' => $version,
				'steps' => $graph['steps'],
				'transitions' => $graph['transitions'],
			]
		);

		if ($draft === null || isset($draft['id']) === false) {
			$this->logger->error(
				'Dossiq: VTH workflow template could not be created as a draft',
				['app' => Application::APP_ID, 'slug' => $slug]
			);
			$this->outcome(
				entry: $slug,
				outcome: 'failed',
				reason: 'could not be created as a draft, see the log for the reason.',
			);
			return;
		}

		// Read what is active ON THIS ROUTE before publishing: a publish
		// deprecates the previous version of its own route, and this is the only
		// moment that version can still be named. Asking without the route would
		// name the OTHER route's definition, which this publish leaves alone.
		// See openspec/specs/workflow-variants/spec.md.
		$displaced = $this->definitionService->getActiveDefinitionFor(
			caseTypeId: $caseTypeId,
			variant: $variant,
		);

		// Publish, which flips the row to lifecycleStatus=published and
		// isActive=true, and pins caseType.workflowDefinition when no previous
		// definition was pinned (handled inside publish()).
		$published = $this->definitionService->publish(id: (string)$draft['id']);
		if ($published === null) {
			$this->logger->error(
				'Dossiq: VTH workflow template was created but could not be published',
				['app' => Application::APP_ID, 'slug' => $slug, 'draftId' => (string)$draft['id']]
			);
			$this->outcome(
				entry: $slug,
				outcome: 'failed',
				reason: 'was created as a draft but could not be published, see the log for the reason.',
			);
			return;
		}

		// The catalogue says which route is the default, so the answer does not
		// depend on the order glob() handed out the files. Ordering dependencies
		// in this step are exactly what #1819 was about.
		$isDefaultRoute = (bool)($data['isDefaultVariant'] ?? false);
		if ($isDefaultRoute === true) {
			$this->definitionService->setDefaultDefinition(id: (string)$draft['id']);
		}

		// The summary is the reporting channel for this step, deliberately: a
		// count is not a report, and a second warning in the log is a second
		// place to look. The line below names the route where the administrator
		// is already reading.
		$this->outcome(
			entry: $slug,
			outcome: 'seeded',
			reason: $this->report->seededReason(
				title: $title,
				version: $version,
				variant: $variant,
				displacedTitle: $this->report->displacedTitle(
					displaced: $displaced,
					publishedId: (string)$draft['id'],
				),
				isDefaultRoute: $isDefaultRoute,
			),
		);
	}//end createAndPublishTemplate()
}//end class
