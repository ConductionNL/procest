<?php

/**
 * Dossiq Workflow Definition Service
 *
 * Lifecycle and consumer service for workflowTemplate objects (aka
 * "workflow definitions"). Pure CRUD over workflowTemplate is delegated to
 * the manifest renderer / OpenRegister auto-routing; this service owns the
 * domain logic that CRUD cannot express:
 *
 *   - createDraft()          — create a new draft definition from a
 *                              fully-resolved payload (used by seed-data
 *                              and catalog-import flows; mutations to
 *                              workflowTemplate MUST go through this
 *                              service to respect the immutability
 *                              invariant of published rows).
 *   - publish()              — flip a draft to published, deprecate the
 *                              previously active version OF THE SAME ROUTE,
 *                              and take the caseType's default route when
 *                              this publish is entitled to it.
 *   - setDefaultDefinition() — record which route new cases take.
 *   - deprecate()            — flip a published version to deprecated and
 *                              clear isActive (refuses if the caseType has
 *                              no other published version while open cases
 *                              remain).
 *   - cloneDefinition()      — produce a new draft from an existing
 *                              published or deprecated version with
 *                              version + 1.
 *   - getActiveDefinitionFor — read-only consumer entrypoint used by
 *                              status-transition-engine and
 *                              role-based-step-routing. Answers for one route,
 *                              or for the caseType's default route.
 *   - listActiveDefinitionsFor — every active definition, one per route.
 *   - getDefinition          — read-only by UUID.
 *   - getDefinitionForCase   — resolves through case.workflowTemplate +
 *                              case.workflowVersion.
 *   - listVersions           — admin UI listing.
 *
 * Three collaborators carry the concerns that are not lifecycle transitions:
 * {@see Workflow\WorkflowDefinitionRepository} owns every OpenRegister
 * read/write, {@see Workflow\WorkflowLifecycleGuard} owns the preconditions a
 * publish or deprecate must satisfy, and
 * {@see Workflow\TransitionAuthorizationStamper} owns the publish-time
 * freezing of role routing into literal NC group ids. A fourth,
 * {@see Workflow\WorkflowJsonProperty}, owns the coercion in and out of the
 * JSON-string properties the schema stores.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Workflow\TransitionAuthorizationStamper;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowJsonProperty;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle + consumer service for workflowTemplate objects.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
class WorkflowDefinitionService {

	/**
	 * Lifecycle states. Mirrors the enum on the workflowTemplate schema.
	 *
	 * Re-exported from WorkflowLifecycleGuard, which owns the lifecycle
	 * semantics, so existing `WorkflowDefinitionService::STATUS_*` callers
	 * keep reading the single source of truth.
	 */
	public const STATUS_DRAFT = WorkflowLifecycleGuard::STATUS_DRAFT;
	public const STATUS_PUBLISHED = WorkflowLifecycleGuard::STATUS_PUBLISHED;
	public const STATUS_DEPRECATED = WorkflowLifecycleGuard::STATUS_DEPRECATED;

	/**
	 * The route a definition is on when it names none.
	 *
	 * Re-exported from WorkflowLifecycleGuard for the same reason as the
	 * STATUS_* constants: callers read one source of truth.
	 */
	public const VARIANT_DEFAULT = WorkflowLifecycleGuard::VARIANT_DEFAULT;

	/**
	 * Constructor.
	 *
	 * @param WorkflowDefinitionRepository $repository The OpenRegister persistence layer
	 * @param WorkflowLifecycleGuard $guard Publish/deprecate preconditions
	 * @param TransitionAuthorizationStamper $stamper Publish-time role → group
	 *                                                freezing
	 * @param WorkflowJsonProperty $json The JSON-string property codec
	 * @param LoggerInterface $logger The logger
	 */
	public function __construct(
		private readonly WorkflowDefinitionRepository $repository,
		private readonly WorkflowLifecycleGuard $guard,
		private readonly TransitionAuthorizationStamper $stamper,
		private readonly WorkflowJsonProperty $json,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the active definition for a caseType, or null when none exists.
	 * Read-only consumer entrypoint used by status-transition-engine and
	 * role-based-step-routing.
	 *
	 * A case type may carry several routes, with one active definition each.
	 * Naming a route answers for that route. Naming none answers with the case
	 * type's DEFAULT route, which is what every caller written before routes
	 * existed means by "the active definition".
	 *
	 * @param string $caseTypeId The caseType UUID
	 * @param string|null $variant The route to answer for, or null for the default route
	 *
	 * @return array<string, mixed>|null The definition or null
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 */
	public function getActiveDefinitionFor(string $caseTypeId, ?string $variant = null): ?array {
		$active = $this->listActiveDefinitionsFor(caseTypeId: $caseTypeId);
		if ($active === []) {
			return null;
		}

		if ($variant !== null) {
			$wanted = $this->guard->variantOf(row: ['variant' => $variant]);
			foreach ($active as $candidate) {
				if ($this->guard->variantOf(row: $candidate) === $wanted) {
					return $candidate;
				}
			}

			return null;
		}

		return $this->guard->defaultAmong(active: $active, caseTypeId: $caseTypeId);
	}//end getActiveDefinitionFor()

	/**
	 * Every published+active definition of a caseType, at most one per route.
	 *
	 * @param string $caseTypeId The caseType UUID
	 *
	 * @return array<int, array<string, mixed>> The active definitions
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 */
	public function listActiveDefinitionsFor(string $caseTypeId): array {
		if ($caseTypeId === '') {
			return [];
		}

		$active = [];
		foreach ($this->repository->listVersionsForCaseType(caseTypeId: $caseTypeId) as $candidate) {
			if ($this->guard->statusOf(row: $candidate) !== self::STATUS_PUBLISHED) {
				continue;
			}

			if ((bool)($candidate['isActive'] ?? false) === true) {
				$active[] = $candidate;
			}
		}

		return $active;
	}//end listActiveDefinitionsFor()

	/**
	 * Make a published definition the default route of its caseType.
	 *
	 * The default route is a decision somebody takes, not the order `glob()`
	 * happened to hand a seeder its files. This is how that decision is
	 * recorded.
	 *
	 * @param string $id The definition UUID
	 *
	 * @return bool True when the default was set
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 */
	public function setDefaultDefinition(string $id): bool {
		$row = $this->repository->findById(id: $id);
		if ($row === null) {
			return false;
		}

		$caseTypeId = (string)($row['caseType'] ?? '');
		if ($this->guard->statusOf(row: $row) !== self::STATUS_PUBLISHED || $caseTypeId === '') {
			$this->logger->warning(
				'Dossiq: setDefaultDefinition() refused, the definition is not a published definition of a case type',
				['app' => Application::APP_ID, 'id' => $id, 'caseType' => $caseTypeId]
			);
			return false;
		}

		$this->repository->pinWorkflowDefinition(caseTypeId: $caseTypeId, definitionId: $id);

		return true;
	}//end setDefaultDefinition()

	/**
	 * Read a single definition by UUID. Returns null when not found.
	 *
	 * @param string $id The definition UUID
	 *
	 * @return array<string, mixed>|null The definition or null
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function getDefinition(string $id): ?array {
		return $this->repository->findById(id: $id);
	}//end getDefinition()

	/**
	 * Resolve the definition pinned to a case via case.workflowTemplate +
	 * case.workflowVersion. Falls back to the active definition for the
	 * case's caseType when no pin is set.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array<string, mixed>|null The definition or null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getDefinitionForCase(string $caseId): ?array {
		$case = $this->repository->findCase(caseId: $caseId);
		if ($case === null) {
			return null;
		}

		$templateId = (string)($case['workflowTemplate'] ?? '');
		if ($templateId !== '') {
			return $this->repository->findById(id: $templateId);
		}

		$caseTypeId = (string)($case['caseType'] ?? '');
		if ($caseTypeId === '') {
			return null;
		}

		return $this->getActiveDefinitionFor(caseTypeId: $caseTypeId);
	}//end getDefinitionForCase()

	/**
	 * List every version of the definition for a given caseType, ordered
	 * by version descending. Used by the admin UI.
	 *
	 * @param string $caseTypeId The caseType UUID
	 *
	 * @return array<int, array<string, mixed>> The versions
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function listVersions(string $caseTypeId): array {
		return $this->repository->listVersionsForCaseType(caseTypeId: $caseTypeId);
	}//end listVersions()

	/**
	 * Publish a draft definition. Atomically:
	 *   - sets target lifecycleStatus=published, isActive=true, isDraft=false
	 *   - moves any previously active version of the same caseType to
	 *     lifecycleStatus=deprecated, isActive=false
	 *   - updates caseType.workflowDefinition to point at the new active id
	 *
	 * Returns the updated definition or null on error (errors logged).
	 *
	 * @param string $id The definition UUID to publish
	 *
	 * @return array<string, mixed>|null Updated definition or null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function publish(string $id): ?array {
		$current = $this->repository->findById(id: $id);
		if ($current === null) {
			$this->logger->warning(
				'Dossiq: publish() — definition not found',
				['app' => Application::APP_ID, 'id' => $id]
			);
			return null;
		}

		$transitions = $this->json->decodeList(raw: ($current['transitions'] ?? ''));
		if ($this->guard->isPublishableDraft(current: $current, transitions: $transitions, id: $id) === false) {
			return null;
		}

		// Both the definition schema and the caseType schema must be
		// configured before anything is written: publishing without the
		// caseType schema would deprecate the predecessor and leave the
		// caseType pointing at a version it can no longer pin.
		$configured = ($this->repository->isConfiguredFor(schemaKey: WorkflowDefinitionRepository::SCHEMA_DEFINITION) === true
			&& $this->repository->isConfiguredFor(schemaKey: WorkflowDefinitionRepository::SCHEMA_CASE_TYPE) === true);
		if ($configured === false) {
			return null;
		}

		$caseTypeId = (string)($current['caseType'] ?? '');
		$variant = $this->guard->variantOf(row: $current);

		// Resolve each transition's assignee role to its NC group id(s) and
		// freeze the result into the transition `authorization` list (OR PR
		// #153 declarative gate, ADR-022).
		$authoredTransitions = $this->stamper->stamp(transitions: $transitions);
		if ($this->deprecatePreviousActive(caseTypeId: $caseTypeId, variant: $variant, id: $id) === false) {
			return null;
		}

		// Flip target to published+active, writing back the authorization-
		// enriched transitions (JSON-encoded STRING per the workflowTemplate
		// schema) when any were resolved.
		$updated = $this->repository->save(
			payload: $this->buildPublishPayload(authoredTransitions: $authoredTransitions),
			uuid: $id,
		);
		if ($updated === null) {
			return null;
		}

		// Take the case type's default route, but only when this publish is
		// entitled to it. See takeDefaultWhenEntitled().
		$this->takeDefaultWhenEntitled(caseTypeId: $caseTypeId, id: $id, variant: $variant);

		return $updated;
	}//end publish()

	/**
	 * Deprecate a published definition. Refuses (returns null + logs) if
	 * doing so would leave the caseType with no published definition while
	 * open cases remain pinned to it.
	 *
	 * @param string $id The definition UUID to deprecate
	 *
	 * @return array<string, mixed>|null Updated definition or null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function deprecate(string $id): ?array {
		$current = $this->repository->findById(id: $id);
		if ($current === null) {
			return null;
		}

		if ($this->guard->isDeprecatable(current: $current, id: $id) === false) {
			return null;
		}

		return $this->repository->save(
			payload: [
				'lifecycleStatus' => self::STATUS_DEPRECATED,
				'isActive' => false,
			],
			uuid: $id,
		);
	}//end deprecate()

	/**
	 * Clone an existing published or deprecated definition into a new
	 * draft with version + 1.
	 *
	 * @param string $id The source definition UUID
	 *
	 * @return array<string, mixed>|null New draft definition or null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function cloneDefinition(string $id): ?array {
		$source = $this->repository->findById(id: $id);
		if ($source === null) {
			return null;
		}

		$caseTypeId = (string)($source['caseType'] ?? '');
		$nextVersion = $this->repository->nextVersionFor(caseTypeId: $caseTypeId);

		$draft = [
			'title' => $this->cloneTitle(base: (string)($source['title'] ?? 'Workflow')),
			'description' => (string)($source['description'] ?? ''),
			'caseType' => $caseTypeId,
			// A clone stays on its source's route. Cloning the spoedeisende
			// route to get a draft of the ordinary one is not a thing anyone
			// means, and it is what dropping this line would produce.
			'variant' => $this->guard->variantOf(row: $source),
			'version' => $nextVersion,
			'isActive' => false,
			'isDraft' => true,
			'lifecycleStatus' => self::STATUS_DRAFT,
			'steps' => (string)($source['steps'] ?? ''),
			'transitions' => (string)($source['transitions'] ?? ''),
			'nodePositions' => (string)($source['nodePositions'] ?? ''),
		];

		return $this->repository->save(payload: $draft);
	}//end cloneDefinition()

	/**
	 * Create a brand-new draft definition from a fully-resolved payload.
	 * Used by seed-data / catalog import flows where the caller has already
	 * resolved caseType slug → UUID and statusType names → UUIDs.
	 *
	 * The payload SHALL provide:
	 *   - title (string, required)
	 *   - description (string, optional)
	 *   - caseType (UUID string, required)
	 *   - version (int, optional — defaults to next version for caseType)
	 *   - variant (string, optional — the route this definition describes;
	 *     absent or empty means the route `standaard`)
	 *   - steps (array of step rows, will be JSON-encoded if not already a string)
	 *   - transitions (array of transition rows, will be JSON-encoded if not already a string)
	 *
	 * The method enforces lifecycleStatus=draft, isDraft=true, isActive=false.
	 * Returns the created definition (with id) or null on failure.
	 *
	 * @param array<string, mixed> $payload The fully-resolved draft payload
	 *
	 * @return array<string, mixed>|null The created draft or null on failure
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function createDraft(array $payload): ?array {
		$caseTypeId = (string)($payload['caseType'] ?? '');
		if ($caseTypeId === '' || (string)($payload['title'] ?? '') === '') {
			$this->logger->warning(
				'Dossiq: createDraft() — missing caseType or title',
				['app' => Application::APP_ID]
			);
			return null;
		}

		$version = (int)($payload['version'] ?? 0);
		if ($version <= 0) {
			$version = $this->repository->nextVersionFor(caseTypeId: $caseTypeId);
		}

		$stepsValue = $this->json->encode(value: ($payload['steps'] ?? []));
		$transitionsValue = $this->json->encode(value: ($payload['transitions'] ?? []));

		$draft = [
			'title' => (string)$payload['title'],
			'description' => (string)($payload['description'] ?? ''),
			'caseType' => $caseTypeId,
			'variant' => $this->guard->variantOf(row: $payload),
			'version' => $version,
			'isActive' => false,
			'isDraft' => true,
			'lifecycleStatus' => self::STATUS_DRAFT,
			'steps' => $stepsValue,
			'transitions' => $transitionsValue,
			'nodePositions' => (string)($payload['nodePositions'] ?? ''),
		];

		return $this->repository->save(payload: $draft);
	}//end createDraft()

	// -----------------------------------------------------------------
	// Internal helpers.
	// -----------------------------------------------------------------

	/**
	 * Internal — take the caseType's default route for the row just published,
	 * when this publish is entitled to it.
	 *
	 * 🔴 THIS USED TO PIN UNCONDITIONALLY, AND THAT IS WHY TWO TEMPLATES ON ONE
	 * CASE TYPE ENDED WITH THE SECOND ONE OWNING IT. Publishing a new version
	 * of the default route should keep the default on the new version.
	 * Publishing a DIFFERENT route should not touch the default at all: the
	 * spoedeisende route becoming the route every handhavingszaak takes is
	 * exactly the accident this change exists to stop.
	 *
	 * Entitled means one of:
	 *   - nothing is recorded yet, so the first published route is the default;
	 *   - the recorded default no longer resolves, so it is repaired;
	 *   - the recorded default is on the same route.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 * @param string $id The definition UUID just published.
	 * @param string $variant The route it is on.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 */
	private function takeDefaultWhenEntitled(string $caseTypeId, string $id, string $variant): void {
		$pinned = $this->guard->defaultDefinitionIdFor(caseTypeId: $caseTypeId);
		if ($pinned !== '' && $pinned !== $id) {
			$pinnedRow = $this->repository->findById(id: $pinned);
			if ($pinnedRow !== null && $this->guard->variantOf(row: $pinnedRow) !== $variant) {
				$this->logger->info(
					'Dossiq: publish() left the default route alone, this definition is on another route',
					[
						'app' => Application::APP_ID,
						'id' => $id,
						'caseType' => $caseTypeId,
						'route' => $variant,
						'defaultRoute' => $this->guard->variantOf(row: $pinnedRow),
					]
				);
				return;
			}
		}

		$this->repository->pinWorkflowDefinition(caseTypeId: $caseTypeId, definitionId: $id);
	}//end takeDefaultWhenEntitled()

	/**
	 * Internal — move the currently active definition of a caseType's ROUTE to
	 * deprecated+inactive, unless it is the row being published itself.
	 *
	 * Scoped to the route on purpose. A case type may carry several routes, and
	 * publishing one of them must leave the others backing new cases.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 * @param string $variant The route being published.
	 * @param string $id The definition UUID being published.
	 *
	 * @return bool True when nothing had to change or the write succeeded.
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 */
	private function deprecatePreviousActive(string $caseTypeId, string $variant, string $id): bool {
		$previousActive = $this->getActiveDefinitionFor(caseTypeId: $caseTypeId, variant: $variant);
		if ($previousActive === null || (string)($previousActive['id'] ?? '') === $id) {
			return true;
		}

		$saved = $this->repository->save(
			payload: [
				'lifecycleStatus' => self::STATUS_DEPRECATED,
				'isActive' => false,
			],
			uuid: (string)$previousActive['id'],
		);

		return ($saved !== null);
	}//end deprecatePreviousActive()

	/**
	 * Build the saveObject payload that flips a draft to published+active,
	 * including the authorization-enriched transitions when any resolved.
	 *
	 * @param array<int, array<string, mixed>>|null $authoredTransitions Enriched transitions, or null when none.
	 *
	 * @return array<string, mixed> The publish payload.
	 */
	private function buildPublishPayload(?array $authoredTransitions): array {
		$payload = [
			'lifecycleStatus' => self::STATUS_PUBLISHED,
			'isActive' => true,
			'isDraft' => false,
		];

		if ($authoredTransitions !== null) {
			$payload['transitions'] = json_encode($authoredTransitions);
		}

		return $payload;
	}//end buildPublishPayload()

	/**
	 * Build a title for a cloned draft.
	 *
	 * @param string $base The source title
	 *
	 * @return string
	 */
	private function cloneTitle(string $base): string {
		return rtrim($base) . ' (kopie)';
	}//end cloneTitle()
}//end class
