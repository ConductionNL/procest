<?php

/**
 * Dossiq setField action handler.
 *
 * Action config shape: `{type: 'setField', field: 'endDate', value: '<value-or-now>'}`.
 * Writes the named field on the case via OpenRegister ObjectService. Special
 * `value` macros: `__now__` becomes the current ISO-8601 timestamp.
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

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Built-in handler for `setField` automatic actions.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T08
 */
class SetFieldHandler implements ActionHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister + config
	 * @param CaseFieldWriter $caseWriter Applies ONLY this handler's field to the stored case
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseFieldWriter $caseWriter,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the setField action.
	 *
	 * @param array<string, mixed> $actionConfig Action configuration
	 * @param array<string, mixed> $case Case object
	 * @param array<string, mixed> $transitionContext Transition context
	 *
	 * @return ActionResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$field = (string)($actionConfig['field'] ?? '');
			if ($field === '') {
				return new ActionResult(succeeded: false, error: 'set_field_missing_field');
			}

			$value = $actionConfig['value'] ?? null;
			if ($value === '__now__') {
				$value = (new DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
			}

			$objectService = $this->settingsService->getObjectService();
			if ($objectService === null) {
				return new ActionResult(succeeded: false, error: 'storage_unavailable');
			}

			$register = $this->settingsService->getConfigValue(key: 'register');
			$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
			if ($register === '' || $caseSchema === '') {
				return new ActionResult(succeeded: false, error: 'case_schema_not_configured');
			}

			// On the flow path the engine's RegistryStepDispatcher already runs
			// this handler inside `ObjectService::runAs()` as the run's acting
			// identity (openregister#3332), and on the interactive path the
			// ambient session user answers the permission checks — so no local
			// runAs wrap is needed here any more.
			//
			// ONLY the configured field is written. `$case` is a snapshot of
			// the flow item, and full-saving a snapshot erases whatever other
			// writers stored after it was taken (the besluitDocument clobber,
			// measured live on the closure rig).
			$this->caseWriter->write(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				case: $case,
				changes: [$field => $value]
			);

			return new ActionResult(
				succeeded: true,
				data: ['field' => $field],
				caseChanges: [$field => $value]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SetFieldHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);
			return new ActionResult(succeeded: false, error: 'set_field_failed');
		}//end try
	}//end handle()
}//end class
