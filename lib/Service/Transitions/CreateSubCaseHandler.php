<?php

/**
 * Dossiq createSubCase action handler.
 *
 * Action config shape: `{type: 'createSubCase', caseType: '<uuid>', title?, hoofdzaakField?: 'hoofdzaak'}`.
 * Creates a deelzaak linked to the parent case via `hoofdzaak`.
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
 * Built-in handler for `createSubCase` automatic actions.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T08
 */
class CreateSubCaseHandler implements ActionHandlerInterface {
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
	 * Handle the createSubCase action.
	 *
	 * @param array<string, mixed> $actionConfig Action configuration
	 * @param array<string, mixed> $case Parent case object
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
			$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
			if ($register === '' || $caseSchema === '') {
				return new ActionResult(succeeded: false, error: 'case_schema_not_configured');
			}

			$parentId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
			$subCase = [
				'title' => (string)($actionConfig['title'] ?? sprintf('Deelzaak na %s', $transitionContext['transitionLabel'] ?? '')),
				'caseType' => (string)($actionConfig['caseType'] ?? ''),
				'hoofdzaak' => $parentId,
			];

			// On the flow path the engine's RegistryStepDispatcher already runs
			// this handler inside `ObjectService::runAs()` as the run's acting
			// identity (openregister#3332); on the interactive path the ambient
			// session user answers the permission checks. No local wrap needed.
			$created = $objectService->saveObject(object: $subCase, register: $register, schema: $caseSchema);
			$subId = '';
			if (is_array($created) === true) {
				$subId = (string)($created['id'] ?? '');
			}

			return new ActionResult(succeeded: true, data: ['subCaseId' => $subId]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CreateSubCaseHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);
			return new ActionResult(succeeded: false, error: 'create_sub_case_failed');
		}//end try
	}//end handle()
}//end class
