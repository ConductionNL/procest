<?php

/**
 * Dossiq Seed Bezwaar Workflow Definition Repair Step
 *
 * Idempotently seeds a published workflowTemplate for the pre-seeded
 * Bezwaar caseType, expressing the AWB-grounded state machine
 * (Ontvangen → Ontvankelijkheidstoets → ...) plus legal-posture
 * guards (verdaging/opschorting/niet-ontvankelijk/intrekking require
 * a non-empty awbReference). All status transitions flow through the
 * status-transition-engine; there is NO bespoke BezwaarController or
 * BezwaarLifecycleService.
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
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\WorkflowDefinitionService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Seed the canonical bezwaar workflow definition (published, version 1).
 *
 * @spec openspec/specs/bezwaar-lifecycle/spec.md
 */
class SeedBezwaarWorkflowDefinition implements IRepairStep {

	use RunsUnderSystemIdentity;
	use SearchesObjects;

	/**
	 * Required guards for transitions that change legal posture
	 * — keyed by toStatus name, value is the human reason key.
	 *
	 * @var array<string, string>
	 */
	private const LEGAL_POSTURE_TARGETS = [
		'Inadmissible' => 'Niet-ontvankelijk vergt AWB-motivering (6:6)',
		'Withdrawn' => 'Intrekking vergt AWB-motivering (6:21)',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param WorkflowDefinitionService $workflowService The workflow definition service
	 * @param LoggerInterface $logger Logger
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
	 * @spec openspec/specs/bezwaar-lifecycle/spec.md
	 */
	public function getName(): string {
		return 'Seed canonical bezwaar workflow definition (AWB-compliant state machine)';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output Repair output channel
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister not available — skipping bezwaar workflow seed.');
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->warning('OpenRegister ObjectService not resolvable — skipping bezwaar workflow seed.');
			return;
		}

		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses `create` for 'Anonymous'. Without it this seed writes nothing
		// and says so only in a warning, which does not fail an upgrade.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->runInner(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * The seed itself.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 */
	private function runInner(object $objectService, IOutput $output): void {
		$register = $this->settingsService->getConfigValue('register');
		$caseTypeSchema = $this->settingsService->getConfigValue('case_type_schema');
		$statusSchema = $this->settingsService->getConfigValue('status_type_schema');
		$templateSchema = $this->settingsService->getConfigValue('workflow_template_schema');

		$missingConfig = in_array('', [$register, $caseTypeSchema, $statusSchema, $templateSchema], true);
		if ($missingConfig === true) {
			$output->warning('Bezwaar workflow seed: required schema config missing — skipping.');
			return;
		}

		// Locate the bezwaar caseType.
		$caseTypeId = $this->resolveSeedableCaseTypeId(
			objectService: $objectService,
			register: $register,
			caseTypeSchema: $caseTypeSchema,
			output: $output,
		);

		if ($caseTypeId === '') {
			return;
		}

		$required = [
			'Received',
			'AdmissibilityCheck',
			'In handling',
			'Hearing planned',
			'Hearing completed',
			'Advice uitgebracht',
			'Decision on objection',
			'Handled',
			'Inadmissible',
			'Withdrawn',
		];

		$statusByName = $this->resolveStatusIndex(
			objectService: $objectService,
			register: $register,
			statusSchema: $statusSchema,
			caseTypeId: $caseTypeId,
			required: $required,
			output: $output,
		);

		if ($statusByName === null) {
			return;
		}

		$steps = $this->buildSteps(statusByName: $statusByName, ordered: $required);
		$transitions = $this->buildTransitions(statusByName: $statusByName);

		$description = 'Canonical bezwaar lifecycle state machine: Ontvangen → Afgehandeld with terminal '
			. 'Niet-ontvankelijk/Ingetrokken. Transitions wired through the status-transition-engine; '
			. 'deadlines computed declaratively on the bezwaar schema (x-openregister-calculations, ADR-022).';

		$template = [
			'title' => 'Bezwaar — AWB-compliant workflow',
			'description' => $description,
			'caseType' => $caseTypeId,
			'version' => 1,
			'isActive' => true,
			'isDraft' => false,
			'lifecycleStatus' => WorkflowDefinitionService::STATUS_PUBLISHED,
			'steps' => json_encode($steps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'transitions' => json_encode($transitions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'nodePositions' => '',
		];

		$this->persistTemplate(
			objectService: $objectService,
			register: $register,
			caseTypeSchema: $caseTypeSchema,
			templateSchema: $templateSchema,
			caseTypeId: $caseTypeId,
			template: $template,
			output: $output,
		);
	}//end runInner()

	/**
	 * Locate the bezwaar caseType that still needs a workflow definition.
	 *
	 * @param object $objectService Resolved OR ObjectService
	 * @param string $register The register id
	 * @param string $caseTypeSchema The caseType schema id
	 * @param IOutput $output Repair output channel
	 *
	 * @return string The caseType UUID, or an empty string when not seedable
	 */
	private function resolveSeedableCaseTypeId(
		object $objectService,
		string $register,
		string $caseTypeSchema,
		IOutput $output,
	): string {
		try {
			$caseTypes = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $caseTypeSchema,
				filters: ['identifier' => 'objectionProceeding', '_limit' => 5],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: bezwaar workflow seed — failed to list caseTypes',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			$output->warning('Could not list caseTypes — skipping bezwaar workflow seed.');
			return '';
		}

		if ($caseTypes === []) {
			$output->info('Bezwaar caseType not present yet — skipping workflow seed.');
			return '';
		}

		$caseType = $this->normalize(object: $caseTypes[0]);
		if ($caseType === null) {
			return '';
		}

		$caseTypeId = (string)($caseType['id'] ?? '');
		if ($caseTypeId === '') {
			return '';
		}

		// Idempotent guard.
		$existingVersions = $this->workflowService->listVersions($caseTypeId);
		if ($existingVersions !== []) {
			$output->info('Bezwaar workflow definition already present — skipping seed.');
			return '';
		}

		return $caseTypeId;
	}//end resolveSeedableCaseTypeId()

	/**
	 * Load the caseType's statusType rows and index them by name, asserting
	 * that every required status is present.
	 *
	 * @param object $objectService Resolved OR ObjectService
	 * @param string $register The register id
	 * @param string $statusSchema The statusType schema id
	 * @param string $caseTypeId The bezwaar caseType UUID
	 * @param array<int, string> $required Status names the workflow needs
	 * @param IOutput $output Repair output channel
	 *
	 * @return array<string, array<string, mixed>>|null Indexed rows, or null when not seedable
	 */
	private function resolveStatusIndex(
		object $objectService,
		string $register,
		string $statusSchema,
		string $caseTypeId,
		array $required,
		IOutput $output,
	): ?array {
		// Pull statusType rows for the bezwaar caseType.
		try {
			$statusRows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $statusSchema,
				filters: ['caseType' => $caseTypeId, '_limit' => 50],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: bezwaar workflow seed — failed to list statusTypes',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($statusRows === []) {
			$output->info('Bezwaar statusTypes missing — skipping workflow seed.');
			return null;
		}

		$statusByName = [];
		foreach ($statusRows as $raw) {
			$row = $this->normalize(object: $raw);
			if ($row === null) {
				continue;
			}

			$name = (string)($row['name'] ?? '');
			$id = (string)($row['id'] ?? '');
			if ($name !== '' && $id !== '') {
				$statusByName[$name] = $row;
			}
		}

		foreach ($required as $name) {
			if (isset($statusByName[$name]) === false) {
				$output->warning('Bezwaar workflow seed: missing statusType "' . $name . '" — skipping seed.');
				return null;
			}
		}

		return $statusByName;
	}//end resolveStatusIndex()

	/**
	 * Save the workflowTemplate and pin the caseType to it.
	 *
	 * @param object $objectService Resolved OR ObjectService
	 * @param string $register The register id
	 * @param string $caseTypeSchema The caseType schema id
	 * @param string $templateSchema The workflowTemplate schema id
	 * @param string $caseTypeId The bezwaar caseType UUID
	 * @param array<string, mixed> $template The workflowTemplate payload
	 * @param IOutput $output Repair output channel
	 *
	 * @return void
	 */
	private function persistTemplate(
		object $objectService,
		string $register,
		string $caseTypeSchema,
		string $templateSchema,
		string $caseTypeId,
		array $template,
		IOutput $output,
	): void {
		try {
			$created = $objectService->saveObject(
				object: $template,
				register: $register,
				schema: $templateSchema,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: bezwaar workflow seed — failed to save workflowTemplate',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			$output->warning('Bezwaar workflow seed: save failed — see log.');
			return;
		}

		$createdNormalized = $this->normalize(object: $created);
		$newId = (string)($createdNormalized['id'] ?? '');

		if ($newId !== '') {
			try {
				$objectService->saveObject(
					object: ['workflowDefinition' => $newId],
					register: $register,
					schema: $caseTypeSchema,
					uuid: (string)$caseTypeId,
				);
			} catch (\Throwable $e) {
				$this->logger->error(
					'Dossiq: bezwaar workflow seed — failed to pin caseType',
					['app' => Application::APP_ID, 'exception' => $e->getMessage()]
				);
			}
		}

		$output->info('Seeded canonical bezwaar workflow definition.');
	}//end persistTemplate()

	/**
	 * Build step records from statusType rows.
	 *
	 * @param array<string, array<string, mixed>> $statusByName Status rows indexed by name
	 * @param array<int, string> $ordered Ordered status names
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildSteps(array $statusByName, array $ordered): array {
		$steps = [];
		$order = 1;
		foreach ($ordered as $name) {
			$row = $statusByName[$name];
			$steps[] = [
				'id' => $this->uuid(),
				'title' => $name,
				'description' => (string)($row['description'] ?? ''),
				'status' => (string)($row['id'] ?? ''),
				'order' => $order,
				'assigneeRole' => '',
				'isRequired' => false,
				'checklist' => [],
				'automaticActions' => [],
			];
			$order++;
		}

		return $steps;
	}//end buildSteps()

	/**
	 * Build the bezwaar state-machine transition matrix.
	 *
	 * @param array<string, array<string, mixed>> $statusByName Status rows indexed by name
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildTransitions(array $statusByName): array {
		$matrix = [
			['Received',              'AdmissibilityCheck', 'Intake compleet'],
			['AdmissibilityCheck', 'In handling',         'Ontvankelijk'],
			['AdmissibilityCheck', 'Inadmissible',      'Niet-ontvankelijk (motivering vereist)'],
			['In handling',         'Hearing planned',    'Hoorzitting ingepland'],
			['In handling',         'Advice uitgebracht',     'Hoorrecht afgezien (rechtstreeks advies)'],
			['In handling',         'Decision on objection',  'Hoorrecht afgezien (rechtstreeks beslissing)'],
			['Hearing planned',    'Hearing completed',   'Hoorzitting uitgevoerd'],
			['Hearing completed',   'Advice uitgebracht',     'Advice uitgebracht'],
			['Hearing completed',   'Decision on objection',  'Geen commissie — direct beslissing'],
			['Advice uitgebracht',     'Decision on objection',  'Beslissing genomen'],
			['Decision on objection',  'Handled',            'Beslissing verzonden'],
			['*',                      'Withdrawn',            'Bezwaar ingetrokken (AWB 6:21)'],
		];

		$transitions = [];
		foreach ($matrix as $row) {
			[$fromName, $toName, $label] = $row;
			$fromId = '*';
			if ($fromName !== '*') {
				$fromId = (string)($statusByName[$fromName]['id'] ?? '');
			}

			$toId = (string)($statusByName[$toName]['id'] ?? '');

			$guards = [];
			if (isset(self::LEGAL_POSTURE_TARGETS[$toName]) === true) {
				$guards[] = [
					'type' => 'requiredField',
					'config' => [
						'field' => 'awbReference',
						'message' => self::LEGAL_POSTURE_TARGETS[$toName],
					],
				];
			}

			$transitions[] = [
				'id' => $this->uuid(),
				'fromStatus' => $fromId,
				'toStatus' => $toId,
				'label' => $label,
				'guards' => $guards,
				'automaticActions' => [],
				'allowedRoles' => [],
			];
		}//end foreach

		return $transitions;
	}//end buildTransitions()

	/**
	 * Normalize an OR object into a flat array.
	 *
	 * @param mixed $object Object or array
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalize(mixed $object): ?array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}

			return null;
		}

		return null;
	}//end normalize()

	/**
	 * Generate a v4 UUID.
	 *
	 * @return string
	 */
	private function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end uuid()
}//end class
