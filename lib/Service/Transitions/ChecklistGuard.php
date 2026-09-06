<?php

/**
 * Dossiq Checklist Guard evaluator.
 *
 * Guard config shape: `{type: 'checklist', taskId: <uuid>, requiredItems?: [<itemLabel>, ...]}`.
 * Loads the referenced task and verifies that every required checklist item
 * is marked `checked: true`. If `requiredItems` is omitted, all items on the
 * task must be checked.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Guard: verifies checklist items on the case's tasks are complete.
 *
 * `taskId` narrows the check to one task. A workflow TEMPLATE cannot know a
 * runtime task uuid, so a template's checklist guard names none and the guard
 * reads every task linked to the case — the same corpus the frontend's own
 * evaluator uses. `requiredItems` narrows that corpus to named labels, and a
 * named label the checklist does not carry counts as missing.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T05
 */
class ChecklistGuard implements GuardEvaluatorInterface {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister + config
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Evaluate the checklist guard.
	 *
	 * @param array<string, mixed> $guardConfig Guard configuration
	 * @param array<string, mixed> $case Case object; its tasks are the corpus when no taskId is named
	 * @param string $userId Current user UID (unused)
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return new GuardResult(passed: false, failureMessage: 'Opslag niet beschikbaar');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$taskSchema = $this->settingsService->getConfigValue(key: 'task_schema');
		if ($register === '' || $taskSchema === '') {
			return new GuardResult(passed: false, failureMessage: 'Taak-register niet geconfigureerd');
		}

		$taskId = (string)($guardConfig['taskId'] ?? '');
		if ($taskId !== '') {
			return $this->evaluateNamedTask(
				objectService: $objectService,
				register: $register,
				taskSchema: $taskSchema,
				taskId: $taskId,
				requiredItems: ($guardConfig['requiredItems'] ?? null),
			);
		}

		return $this->evaluateCaseTasks(
			objectService: $objectService,
			register: $register,
			taskSchema: $taskSchema,
			case: $case,
			requiredItems: ($guardConfig['requiredItems'] ?? null),
		);
	}//end evaluate()

	/**
	 * Evaluate the checklist of the one task the guard names.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register identifier.
	 * @param string $taskSchema The task schema identifier.
	 * @param string $taskId The task the guard names.
	 * @param mixed $requiredItems Optional allow-list of required labels.
	 *
	 * @return GuardResult
	 */
	private function evaluateNamedTask(
		object $objectService,
		string $register,
		string $taskSchema,
		string $taskId,
		mixed $requiredItems,
	): GuardResult {
		try {
			$task = $this->toArray(value: $objectService->find($taskId, register: $register, schema: $taskSchema));
		} catch (\Throwable $e) {
			$this->logger->error('ChecklistGuard: task load failed', ['exception' => $e->getMessage()]);
			return new GuardResult(passed: false, failureMessage: 'Gekoppelde taak niet gevonden');
		}

		return $this->verdict(tasks: [$task], requiredItems: $requiredItems);
	}//end evaluateNamedTask()

	/**
	 * Evaluate the checklists of every task linked to the case.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register identifier.
	 * @param string $taskSchema The task schema identifier.
	 * @param array<string, mixed> $case The case object.
	 * @param mixed $requiredItems Optional allow-list of required labels.
	 *
	 * @return GuardResult
	 */
	private function evaluateCaseTasks(
		object $objectService,
		string $register,
		string $taskSchema,
		array $case,
		mixed $requiredItems,
	): GuardResult {
		$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
		if ($caseId === '') {
			return new GuardResult(passed: false, failureMessage: 'Zaak niet herkend voor checklistcontrole');
		}

		try {
			$tasks = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $taskSchema,
				filters: ['case' => $caseId, '_limit' => 200],
			);
		} catch (\Throwable $e) {
			$this->logger->error('ChecklistGuard: case task list failed', ['exception' => $e->getMessage()]);
			return new GuardResult(passed: false, failureMessage: 'Taken van de zaak niet gevonden');
		}

		return $this->verdict(tasks: $tasks, requiredItems: $requiredItems);
	}//end evaluateCaseTasks()

	/**
	 * Turn a set of tasks into a guard verdict.
	 *
	 * @param array<int, mixed> $tasks The loaded task objects.
	 * @param mixed $requiredItems Optional allow-list of required labels.
	 *
	 * @return GuardResult
	 */
	private function verdict(array $tasks, mixed $requiredItems): GuardResult {
		$missing = $this->collectMissingItems(tasks: $tasks, requiredItems: $requiredItems);
		if ($missing === []) {
			return new GuardResult(passed: true);
		}

		return new GuardResult(
			passed: false,
			failureMessage: sprintf("%d checklistitem niet afgevinkt: '%s'", count($missing), $missing[0]),
			details: ['missing' => $missing],
		);
	}//end verdict()

	/**
	 * Collect the labels of checklist items that are not yet ticked off.
	 *
	 * When `requiredItems` is a non-empty array only those labels are
	 * considered, and a label the checklist does not carry at all counts as
	 * missing; otherwise every unchecked item with a label counts.
	 *
	 * @param array<int, mixed> $tasks The loaded task objects
	 * @param mixed $requiredItems Optional allow-list of required labels
	 *
	 * @return array<int, string>
	 */
	private function collectMissingItems(array $tasks, mixed $requiredItems): array {
		[$ticked, $unticked] = $this->labelStates(tasks: $tasks);

		if (is_array($requiredItems) === false || $requiredItems === []) {
			return array_values(array_unique($unticked));
		}

		// A named item that is nowhere to be found is missing, not satisfied:
		// an allow-list that silently passes when the checklist does not carry
		// the item at all is a guard that cannot fail.
		$missing = [];
		foreach ($requiredItems as $required) {
			$label = trim((string)$required);
			if ($label !== '' && in_array($label, $ticked, true) === false) {
				$missing[] = $label;
			}
		}

		return $missing;
	}//end collectMissingItems()

	/**
	 * Split every labelled checklist item across the tasks into ticked and not.
	 *
	 * @param array<int, mixed> $tasks The loaded task objects.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>} [ticked, unticked]
	 */
	private function labelStates(array $tasks): array {
		$ticked = [];
		$unticked = [];
		foreach ($tasks as $task) {
			if (is_array($task) === false) {
				continue;
			}

			foreach ($this->resolveItems(task: $task) as $item) {
				$label = '';
				if (is_array($item) === true) {
					$label = $this->itemLabel(item: $item);
				}

				if ($label === '') {
					continue;
				}

				if ((bool)($item['checked'] ?? false) === true) {
					$ticked[] = $label;
					continue;
				}

				$unticked[] = $label;
			}
		}//end foreach

		return [$ticked, $unticked];
	}//end labelStates()

	/**
	 * Read the checklist items off a task object, tolerating both shapes.
	 *
	 * @param array<string, mixed> $task The loaded task object
	 *
	 * @return array<int|string, mixed>
	 */
	private function resolveItems(array $task): array {
		$items = $task['checklist'] ?? ($task['items'] ?? []);

		// The task schema stores `checklist` as a JSON-encoded string, which is
		// the shape the frontend decodes. Reading it as an array yielded an
		// empty item list, so every checklist guard passed on the one shape the
		// store actually holds.
		if (is_string($items) === true) {
			$decoded = json_decode($items, true);
			$items = [];
			if (is_array($decoded) === true) {
				$items = $decoded;
			}
		}

		if (is_array($items) === false) {
			return [];
		}

		return $items;
	}//end resolveItems()

	/**
	 * Read the display label off a single checklist item.
	 *
	 * @param array<string, mixed> $item A single checklist item
	 *
	 * @return string
	 */
	private function itemLabel(array $item): string {
		return (string)($item['label'] ?? ($item['name'] ?? ''));
	}//end itemLabel()

	/**
	 * Coerce ObjectService results to array.
	 *
	 * @param mixed $value Mixed result from ObjectService
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end toArray()
}//end class
