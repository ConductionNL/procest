<?php

/**
 * Dossiq Migrate Workflow Definitions Repair Step
 *
 * Backfill repair step that promotes the implicit lifecycle of every
 * existing caseType into a seeded workflowTemplate published as
 * version 1. Idempotent — skips caseTypes that already have a
 * workflowDefinition reference set.
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
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\WorkflowDefinitionService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Backfill workflowTemplate objects from implicit statusType ordering.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
class MigrateWorkflowDefinitions implements IRepairStep {

	use SearchesObjects;

	/**
	 * Per-caseType migration outcomes reported by migrateCaseType().
	 */
	private const OUTCOME_MIGRATED = 'migrated';
	private const OUTCOME_SKIPPED = 'skipped';
	private const OUTCOME_NONE = 'none';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param WorkflowDefinitionService $workflowService The workflow definition service
	 * @param LoggerInterface $logger The logger interface
	 */
	public function __construct(
		private SettingsService $settingsService,
		private WorkflowDefinitionService $workflowService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function getName(): string {
		return 'Backfill workflowTemplate definitions for existing caseTypes';
	}//end getName()

	/**
	 * Run the backfill.
	 *
	 * @param IOutput $output Repair output channel
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister not available — skipping workflow backfill.');
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->warning('OpenRegister ObjectService not resolvable — skipping workflow backfill.');
			return;
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseTypeSchema = $this->settingsService->getConfigValue('case_type_schema');
		$statusSchema = $this->settingsService->getConfigValue('status_type_schema');
		$templateSchema = $this->settingsService->getConfigValue('workflow_template_schema');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');

		$missing = in_array('', [$register, $caseTypeSchema, $statusSchema, $templateSchema], true);
		if ($missing === true) {
			$output->warning('Workflow backfill: required schema configuration missing — skipping.');
			return;
		}

		$migrated = 0;
		$skipped = 0;

		// This repair step runs without a Nextcloud user session — anonymous
		// callers are fail-closed by OpenRegister RBAC (#1955) on every
		// boot, so the list/save calls below run inside runAsSystem(). The
		// elevation also covers the nested WorkflowDefinitionService::
		// listVersions() call, since it is scoped to this callable for the
		// whole process rather than to one ObjectService instance.
		$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: function () use (
				$objectService,
				$register,
				$caseTypeSchema,
				$statusSchema,
				$templateSchema,
				$caseSchema,
				$output,
				&$migrated,
				&$skipped
			): void {
				try {
					$caseTypes = $this->searchObjectsAsArrays(
						objectService: $objectService,
						register: $register,
						schema: $caseTypeSchema,
						filters: ['_limit' => 500]
					);
				} catch (\Throwable $e) {
					$this->logger->error(
						'Dossiq: workflow backfill failed to list caseTypes',
						['app' => Application::APP_ID, 'exception' => $e->getMessage()]
					);
					$output->warning('Could not list caseTypes — skipping workflow backfill.');
					return;
				}

				foreach ($caseTypes as $caseType) {
					$outcome = $this->migrateCaseType(
						caseType: $caseType,
						objectService: $objectService,
						register: $register,
						caseTypeSchema: $caseTypeSchema,
						statusSchema: $statusSchema,
						templateSchema: $templateSchema,
						caseSchema: $caseSchema,
					);

					if ($outcome === self::OUTCOME_SKIPPED) {
						$skipped++;
					}

					if ($outcome === self::OUTCOME_MIGRATED) {
						$migrated++;
					}
				}//end foreach
			}
		);

		$output->info(
			'Workflow backfill complete — migrated ' . $migrated . ', skipped ' . $skipped . '.'
		);
	}//end run()

	/**
	 * Migrate a single caseType row into a seeded workflowTemplate.
	 *
	 * @param mixed $caseType Raw caseType row from ObjectService
	 * @param object $objectService Resolved OR ObjectService
	 * @param string $register The register id
	 * @param string $caseTypeSchema The caseType schema id
	 * @param string $statusSchema The statusType schema id
	 * @param string $templateSchema The workflowTemplate schema id
	 * @param string $caseSchema The case schema id (may be empty)
	 *
	 * @return string One of the self::OUTCOME_* constants
	 */
	private function migrateCaseType(
		mixed $caseType,
		object $objectService,
		string $register,
		string $caseTypeSchema,
		string $statusSchema,
		string $templateSchema,
		string $caseSchema,
	): string {
		$row = $this->normalize(row: $caseType);
		if ($row === null) {
			return self::OUTCOME_NONE;
		}

		$caseTypeId = (string)($row['id'] ?? '');
		if ($caseTypeId === '') {
			return self::OUTCOME_NONE;
		}

		if ((string)($row['workflowDefinition'] ?? '') !== '') {
			return self::OUTCOME_SKIPPED;
		}

		// Already has at least one workflowTemplate? Skip — admin
		// will set the pin via the UI.
		$existing = $this->workflowService->listVersions($caseTypeId);
		if ($existing !== []) {
			return self::OUTCOME_SKIPPED;
		}

		$template = $this->buildTemplateFor(
			caseTypeId: $caseTypeId,
			caseType: $row,
			objectService: $objectService,
			register: $register,
			statusSchema: $statusSchema,
		);

		if ($template === null) {
			return self::OUTCOME_NONE;
		}

		try {
			$created = $objectService->saveObject(
				object: $template,
				register: $register,
				schema: $templateSchema,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: workflow backfill failed to save template',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return self::OUTCOME_NONE;
		}

		$createdNormalized = $this->normalize(row: $created);
		$newId = (string)($createdNormalized['id'] ?? '');

		// Pin the caseType to the new template.
		if ($newId !== '') {
			$this->pinCaseType(
				objectService: $objectService,
				register: $register,
				caseTypeSchema: $caseTypeSchema,
				caseSchema: $caseSchema,
				caseTypeId: $caseTypeId,
				caseType: $row,
				templateId: $newId,
			);
		}

		return self::OUTCOME_MIGRATED;
	}//end migrateCaseType()

	/**
	 * Pin a caseType, and its open cases, to a freshly created template.
	 *
	 * 🔑 THE PIN CARRIES THE WHOLE CASE TYPE. OpenRegister validates and stores
	 * the payload as the complete object, and `caseType` requires a title, so
	 * a one-key update is refused and this step's own catch swallows it. The
	 * row is already in hand from the listing above, so the pin is laid over
	 * it.
	 *
	 * @param object $objectService Resolved OR ObjectService
	 * @param string $register The register id
	 * @param string $caseTypeSchema The caseType schema id
	 * @param string $caseSchema The case schema id (may be empty)
	 * @param string $caseTypeId The caseType UUID
	 * @param array<string, mixed> $caseType The caseType row as read
	 * @param string $templateId The new template UUID
	 *
	 * @return void
	 */
	private function pinCaseType(
		object $objectService,
		string $register,
		string $caseTypeSchema,
		string $caseSchema,
		string $caseTypeId,
		array $caseType,
		string $templateId,
	): void {
		try {
			$objectService->saveObject(
				object: $this->withPin(row: $caseType, pin: ['workflowDefinition' => $templateId]),
				register: $register,
				schema: $caseTypeSchema,
				uuid: $caseTypeId,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: workflow backfill failed to pin caseType',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
		}

		// Pin existing open cases to workflowVersion = 1.
		if ($caseSchema === '') {
			return;
		}

		$this->pinOpenCases(
			objectService: $objectService,
			register: $register,
			caseSchema: $caseSchema,
			caseTypeId: $caseTypeId,
			templateId: $templateId,
		);
	}//end pinCaseType()

	/**
	 * Build a workflowTemplate payload from a caseType's statusType
	 * records.
	 *
	 * @param string $caseTypeId The caseType UUID
	 * @param array<string, mixed> $caseType Normalized caseType row
	 * @param object $objectService Resolved OR ObjectService
	 * @param string $register The register id
	 * @param string $statusSchema The statusType schema id
	 *
	 * @return array<string, mixed>|null
	 */
	private function buildTemplateFor(
		string $caseTypeId,
		array $caseType,
		object $objectService,
		string $register,
		string $statusSchema,
	): ?array {
		try {
			$statusRows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $statusSchema,
				filters: ['caseType' => $caseTypeId, '_limit' => 500],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: workflow backfill failed to list statusTypes',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($statusRows === []) {
			return null;
		}

		$statuses = [];
		foreach ($statusRows as $raw) {
			$row = $this->normalize(row: $raw);
			if ($row !== null && (string)($row['id'] ?? '') !== '') {
				$statuses[] = $row;
			}
		}

		if ($statuses === []) {
			return null;
		}

		usort(
			$statuses,
			static function (array $a, array $b): int {
				return (int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0);
			},
		);

		$steps = $this->buildSteps(statuses: $statuses);
		$transitions = $this->buildTransitions(statuses: $statuses);

		$title = trim((string)($caseType['title'] ?? 'Workflow'));
		if ($title === '') {
			$title = 'Workflow';
		}

		return [
			'title' => $title . ' — basis',
			'description' => 'Backfilled from implicit statusType ordering.',
			'caseType' => $caseTypeId,
			'version' => 1,
			'isActive' => true,
			'isDraft' => false,
			'lifecycleStatus' => WorkflowDefinitionService::STATUS_PUBLISHED,
			'steps' => json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'transitions' => json_encode($transitions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'nodePositions' => '',
		];
	}//end buildTemplateFor()

	/**
	 * Build the embedded step list from ordered statusType rows.
	 *
	 * @param array<int, array<string, mixed>> $statuses Ordered statusType rows
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildSteps(array $statuses): array {
		$steps = [];
		foreach ($statuses as $status) {
			if ((bool)($status['isFinal'] ?? false) === true) {
				continue;
			}

			$steps[] = [
				'id' => $this->uuid(),
				'title' => (string)($status['name'] ?? 'Stap'),
				'description' => (string)($status['description'] ?? ''),
				'status' => (string)($status['id'] ?? ''),
				'order' => (int)($status['order'] ?? 0),
				'assigneeRole' => '',
				'isRequired' => false,
				'checklist' => [],
				'automaticActions' => [],
			];
		}

		return $steps;
	}//end buildSteps()

	/**
	 * Build the embedded transition list from ordered statusType rows.
	 *
	 * @param array<int, array<string, mixed>> $statuses Ordered statusType rows
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildTransitions(array $statuses): array {
		$transitions = [];
		$count = count($statuses);
		for ($i = 0; $i < ($count - 1); $i++) {
			$from = $statuses[$i];
			$to = $statuses[($i + 1)];

			$transitions[] = [
				'id' => $this->uuid(),
				'fromStatus' => (string)($from['id'] ?? ''),
				'toStatus' => (string)($to['id'] ?? ''),
				'label' => (string)($to['name'] ?? 'Door'),
				'guards' => [],
				'automaticActions' => [],
				'allowedRoles' => [],
			];
		}

		return $transitions;
	}//end buildTransitions()

	/**
	 * Pin every open case of a caseType to workflowVersion 1 and bind it
	 * to the new workflowTemplate.
	 *
	 * @param object $objectService The OR ObjectService
	 * @param string $register The register id
	 * @param string $caseSchema The case schema id
	 * @param string $caseTypeId The caseType UUID
	 * @param string $templateId The new template UUID
	 *
	 * @return void
	 */
	private function pinOpenCases(
		object $objectService,
		string $register,
		string $caseSchema,
		string $caseTypeId,
		string $templateId,
	): void {
		try {
			$cases = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: ['caseType' => $caseTypeId, '_limit' => 500],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: workflow backfill failed to list cases',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return;
		}

		foreach ($cases as $row) {
			$case = $this->normalize(row: $row);
			if ($case === null) {
				continue;
			}

			$caseId = (string)($case['id'] ?? '');
			if ($caseId === '') {
				continue;
			}

			// Skip already-pinned cases.
			if ((string)($case['workflowTemplate'] ?? '') !== '') {
				continue;
			}

			try {
				$objectService->saveObject(
					object: $this->withPin(
						row: $case,
						pin: [
							'workflowTemplate' => $templateId,
							'workflowVersion' => 1,
						]
					),
					register: $register,
					schema: $caseSchema,
					uuid: (string)$caseId,
				);
			} catch (\Throwable $e) {
				$this->logger->error(
					'Dossiq: workflow backfill failed to pin case',
					['app' => Application::APP_ID, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach
	}//end pinOpenCases()

	/**
	 * Lay a pin over the row it belongs to, so the update carries a whole object.
	 *
	 * `@self` is OpenRegister's own envelope and `id` is the uuid, which travels
	 * as the `uuid` argument; neither belongs in the payload.
	 *
	 * @param array<string, mixed> $row The row as read.
	 * @param array<string, mixed> $pin The properties to set.
	 *
	 * @return array<string, mixed> The full object to store.
	 */
	private function withPin(array $row, array $pin): array {
		unset($row['@self'], $row['id']);

		return array_merge($row, $pin);
	}//end withPin()

	/**
	 * Coerce an OpenRegister result row to an associative array.
	 *
	 * @param mixed $row Result row from ObjectService
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalize(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialized = $row->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end normalize()

	/**
	 * Generate a UUID v4 for embedded step / transition identifiers.
	 *
	 * @return string
	 */
	private function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

		$hex = bin2hex($bytes);
		return substr($hex, 0, 8) . '-'
			. substr($hex, 8, 4) . '-'
			. substr($hex, 12, 4) . '-'
			. substr($hex, 16, 4) . '-'
			. substr($hex, 20, 12);
	}//end uuid()
}//end class
