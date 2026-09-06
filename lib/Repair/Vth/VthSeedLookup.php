<?php

/**
 * Dossiq VTH seed lookup.
 *
 * Every OpenRegister read the VTH workflow-template seed needs: resolving a
 * caseType slug to its UUID, the idempotency probe for an already-seeded
 * template, and the statusType name → UUID map. Also owns the system-principal
 * elevation the whole seed runs inside.
 *
 * Split out of {@see \OCA\Dossiq\Repair\SeedVthWorkflowTemplates} so the repair
 * step reads as orchestration only and every OpenRegister query — each of which
 * must soft-fail rather than abort the seed — lives behind one seam.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Vth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Vth;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * OpenRegister lookups for the VTH workflow-template seed.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */
class VthSeedLookup {

	use SearchesObjects;

	/**
	 * Constructor for VthSeedLookup.
	 *
	 * @param SettingsService $settingsService Settings service for OR access.
	 * @param VthSeedRowReader $rowReader Result-row coercion helper.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly VthSeedRowReader $rowReader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the seed operation with system privileges when OpenRegister offers them.
	 *
	 * This repair step runs without a Nextcloud user session — anonymous callers
	 * are fail-closed by OpenRegister RBAC (#1955) on every boot. Without the
	 * elevation every caseType/statusType lookup reads as empty and every
	 * template is (mis)reported as "caseType not found", never actually seeding.
	 * The elevation is scoped to this callable for the whole process, not to one
	 * ObjectService instance, so it also covers the nested
	 * WorkflowDefinitionService::createDraft()/publish() calls.
	 *
	 * Without an ObjectService there is nothing to elevate, so the callable is
	 * invoked directly.
	 *
	 * @param callable $operation The trusted, seed-data-driven operation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function runElevated(callable $operation): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$operation();
			return;
		}

		$this->runAsSystemIfAvailable(objectService: $objectService, operation: $operation);
	}//end runElevated()

	/**
	 * Resolve a caseType by the slug the template catalogue names.
	 *
	 * Three probes, because the seeds that create these rows do not agree on
	 * where the slug lives:
	 *
	 *   `identifier`  an object PROPERTY, written by the bezwaar/beroep seed.
	 *   `slug`        an object PROPERTY, written where a seed declares one.
	 *   `@self.slug`  the object's METADATA slug, which is the only one
	 *                 `VthSeedDataRepairStep` writes.
	 *
	 * 🔴 THE THIRD PROBE IS WHY THIS EVER FOUND ANYTHING. The VTH case types
	 * arrive with `identifier` empty and no `slug` property at all: their
	 * slug is metadata, and a metadata field is not reachable from a
	 * top-level filter. Both probes therefore missed every row that WAS
	 * there, and the step reported "caseType not found ... run
	 * base-register-seed-data first" for a seed that had just run. Measured
	 * on a clean rig on 2026-09-04: six VTH case types present, zero
	 * resolved, five of six templates skipped.
	 *
	 * That message is also why the ordering defect underneath it stayed
	 * hidden for so long. It names a plausible cause, so it reads as an
	 * ordering problem and not as a lookup that cannot see its own data. Both
	 * were real, and fixing only the order leaves the count at zero.
	 *
	 * Returns the empty string when not found.
	 *
	 * @param string $slug The caseType slug / identifier
	 *
	 * @return string The caseType UUID or empty string
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function resolveCaseTypeId(string $slug): string {
		$probes = [
			'identifier' => ['identifier' => $slug, '_limit' => 5],
			'slug' => ['slug' => $slug, '_limit' => 5],
			'@self.slug' => ['@self' => ['slug' => $slug], '_limit' => 5],
		];

		foreach ($probes as $field => $filters) {
			$rows = $this->query(
				schemaKey: 'case_type_schema',
				filters: $filters,
				failureMessage: 'Dossiq: VTH workflow template — caseType lookup failed',
				failureContext: ['field' => $field, 'slug' => $slug],
			);

			$id = $this->rowReader->firstId(rows: $rows);
			if ($id !== '') {
				return $id;
			}
		}

		return '';
	}//end resolveCaseTypeId()

	/**
	 * Find the workflowTemplate this catalogue entry already seeded, if any.
	 *
	 * 🔑 IT RETURNS THE ROW, NOT A YES OR NO. A boolean cannot tell a published
	 * template from the draft a failed publish left behind, and both answered
	 * "already present": three VTH templates sat at
	 * `lifecycleStatus=draft, isActive=false` on every instance, and every
	 * later repair run reported them as seeded and moved on. The caller
	 * publishes the draft instead.
	 *
	 * @param string $caseTypeId The caseType UUID
	 * @param string $title The template title
	 *
	 * @return array<string, mixed>|null The existing template, or null when there is none
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function findSeeded(string $caseTypeId, string $title): ?array {
		$rows = $this->query(
			schemaKey: 'workflow_template_schema',
			filters: [
				'caseType' => $caseTypeId,
				'title' => $title,
				'_limit' => 1,
			],
			failureMessage: 'Dossiq: VTH workflow template idempotency lookup failed',
			failureContext: ['caseType' => $caseTypeId, 'title' => $title],
		);

		return $this->rowReader->firstRow(rows: $rows);
	}//end findSeeded()

	/**
	 * Build a status name → UUID map for the statusTypes belonging to a
	 * given caseType.
	 *
	 * @param string $caseTypeId The caseType UUID
	 *
	 * @return array<string, string> Map of statusType name to UUID
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function buildStatusMap(string $caseTypeId): array {
		$rows = $this->query(
			schemaKey: 'status_type_schema',
			filters: ['caseType' => $caseTypeId, '_limit' => 500],
			failureMessage: 'Dossiq: VTH workflow template — statusType listing failed',
			failureContext: ['caseType' => $caseTypeId],
			failureLevel: LogLevel::ERROR,
		);

		return $this->rowReader->statusMap(rows: $rows);
	}//end buildStatusMap()

	/**
	 * Run one soft-failing OpenRegister query for the configured schema.
	 *
	 * Every VTH seed lookup is a precondition probe, never a hard dependency: a
	 * missing ObjectService, an unconfigured register/schema and a throwing
	 * search all mean "not found here", not "abort the seed". Returning an empty
	 * list for all three keeps that rule in one place.
	 *
	 * @param string $schemaKey Settings key naming the schema to query.
	 * @param array<string, mixed> $filters Object-field filters plus pagination keys.
	 * @param string $failureMessage Log message when the search throws.
	 * @param array<string, mixed> $failureContext Extra log context when the search throws.
	 * @param string $failureLevel PSR-3 level for that message.
	 *
	 * @return array<int, array<string, mixed>> Matching rows, or an empty list.
	 */
	private function query(
		string $schemaKey,
		array $filters,
		string $failureMessage,
		array $failureContext,
		string $failureLevel = LogLevel::DEBUG,
	): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaKey);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: $filters,
			);
		} catch (\Throwable $e) {
			$this->logger->log(
				$failureLevel,
				$failureMessage,
				array_merge(
					['app' => Application::APP_ID, 'exception' => $e->getMessage()],
					$failureContext
				)
			);
			return [];
		}//end try
	}//end query()
}//end class
