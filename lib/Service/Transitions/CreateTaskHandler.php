<?php

/**
 * Dossiq createTask action handler.
 *
 * Action config shape: `{type: 'createTask', title?, assignee?, dueIn?: '<duration>'}`.
 * Creates a task linked to the case via OpenRegister ObjectService.
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
use Psr\Log\LoggerInterface;

/**
 * Built-in handler for `createTask` automatic actions.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T08
 */
class CreateTaskHandler implements ActionHandlerInterface {
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
	 * Handle the createTask action.
	 *
	 * @param array<string, mixed> $actionConfig Action configuration
	 * @param array<string, mixed> $case Case object
	 * @param array<string, mixed> $transitionContext Transition context
	 *
	 * @return ActionResult
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$objectService = $this->settingsService->getObjectService();
			if ($objectService === null) {
				return new ActionResult(succeeded: false, error: 'storage_unavailable');
			}

			$register = $this->settingsService->getConfigValue(key: 'register');
			$taskSchema = $this->settingsService->getConfigValue(key: 'task_schema');
			if ($register === '' || $taskSchema === '') {
				return new ActionResult(succeeded: false, error: 'task_schema_not_configured');
			}

			$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
			$task = [
				'title' => (string)($actionConfig['title'] ?? sprintf('Taak na transitie %s', $transitionContext['transitionLabel'] ?? '')),
				'case' => $caseId,
				// The task schema's lifecycle starts at 'available' (enum:
				// available|active|completed|terminated|disabled). Writing 'open'
				// produced an object no transition could advance.
				'status' => 'available',
				'assignee' => (string)($actionConfig['assignee'] ?? ''),
			];

			// On the flow path the engine's RegistryStepDispatcher already runs
			// this handler inside `ObjectService::runAs()` as the run's acting
			// identity (openregister#3332); on the interactive path the ambient
			// session user answers the permission checks. No local wrap needed.
			$created = $objectService->saveObject(object: $task, register: $register, schema: $taskSchema);
			$taskId = '';
			if (is_array($created) === true) {
				$taskId = (string)($created['id'] ?? '');
			}

			return new ActionResult(succeeded: true, data: ['taskId' => $taskId]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CreateTaskHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);
			return new ActionResult(succeeded: false, error: 'create_task_failed');
		}//end try
	}//end handle()
}//end class
