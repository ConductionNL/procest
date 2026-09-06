<?php

/**
 * Dossiq evaluateDecision action handler.
 *
 * Action config shape: `{type: 'evaluateDecision', decisionKey: '<key>',
 * inputMapping?: {decisionInputName: caseFieldName}, outputMapping?:
 * {decisionOutputName: caseFieldName}}`. Looks up the named decision table,
 * builds its inputs from the case (same-name default when a mapping entry
 * is absent), evaluates it via the pure {@see DecisionTableEvaluator}, and writes
 * every output back onto the case via OpenRegister — the same write path
 * {@see SetFieldHandler} uses. This is the workflow-engine's hook into the
 * DMN capability (design.md Decision 5): a transition's
 * `automaticActions[]` entry is the ONLY thing that needs to reference a
 * decision by key for it to run automatically, so the capability is never
 * orphaned.
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
 *
 * @spec openspec/specs/dmn-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionEvaluationException;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\Dmn\DecisionTableService;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Built-in handler for `evaluateDecision` automatic actions.
 *
 * @spec openspec/specs/dmn-decision-tables/spec.md
 */
class EvaluateDecisionHandler implements ActionHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param DecisionTableService $tableService Decision-table storage/lookup.
	 * @param DecisionTableEvaluator $engine Pure evaluation engine.
	 * @param SettingsService $settingsService Bridge to OpenRegister + config.
	 * @param CaseFieldWriter $caseWriter Applies ONLY the decision's outputs to the stored case.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly DecisionTableService $tableService,
		private readonly DecisionTableEvaluator $engine,
		private readonly SettingsService $settingsService,
		private readonly CaseFieldWriter $caseWriter,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the evaluateDecision action.
	 *
	 * @param array<string, mixed> $actionConfig Action configuration.
	 * @param array<string, mixed> $case Case object.
	 * @param array<string, mixed> $transitionContext Transition context.
	 *
	 * @return ActionResult
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$decisionKey = trim((string)($actionConfig['decisionKey'] ?? ''));
			if ($decisionKey === '') {
				return new ActionResult(succeeded: false, error: 'evaluate_decision_missing_key');
			}

			// The LOOKUP and the WRITE run under one identity. On the flow
			// path the engine's RegistryStepDispatcher executes this handler
			// inside `ObjectService::runAs()` as the run's acting identity
			// (openregister#3332); on the interactive path the ambient session
			// user answers the permission checks. No local wrap needed.
			$table = $this->tableService->findByKey(key: $decisionKey);
			if ($table === null) {
				return new ActionResult(succeeded: false, error: 'decision_not_found');
			}

			$inputMapping = [];
			if (is_array($actionConfig['inputMapping'] ?? null) === true) {
				$inputMapping = $actionConfig['inputMapping'];
			}

			$outputMapping = [];
			if (is_array($actionConfig['outputMapping'] ?? null) === true) {
				$outputMapping = $actionConfig['outputMapping'];
			}

			$inputs = $this->buildInputs(table: $table, case: $case, inputMapping: $inputMapping);

			try {
				$result = $this->engine->evaluate(decisionTable: $table, inputs: $inputs);
			} catch (DecisionEvaluationException $e) {
				$this->logger->info(
					'EvaluateDecisionHandler: evaluation failed',
					['errorCode' => $e->getErrorCode(), 'details' => $e->getDetails(), 'decisionKey' => $decisionKey],
				);
				return new ActionResult(succeeded: false, error: $e->getErrorCode());
			}

			$changes = $this->writeOutputs(table: $table, case: $case, outputs: $result['outputs'], outputMapping: $outputMapping);

			return new ActionResult(
				succeeded: true,
				data: [
					'decisionKey' => $decisionKey,
					'outputs' => $result['outputs'],
					'matchedRuleIds' => $result['matchedRuleIds'],
				],
				caseChanges: $changes,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'EvaluateDecisionHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);
			return new ActionResult(succeeded: false, error: 'evaluate_decision_failed');
		}//end try
	}//end handle()

	/**
	 * Build the decision's inputs map from the case, applying `inputMapping`
	 * (decisionInputName => caseFieldName) with a same-name default.
	 *
	 * @param array<string, mixed> $table The decision table definition.
	 * @param array<string, mixed> $case The case object.
	 * @param array<string, mixed> $inputMapping Optional decisionInputName => caseFieldName map.
	 *
	 * @return array<string, mixed>
	 */
	private function buildInputs(array $table, array $case, array $inputMapping): array {
		$inputs = [];
		$declared = [];
		if (is_array($table['inputs'] ?? null) === true) {
			$declared = $table['inputs'];
		}

		foreach ($declared as $inputDef) {
			if (is_array($inputDef) === false) {
				continue;
			}

			$name = (string)($inputDef['name'] ?? '');
			if ($name === '') {
				continue;
			}

			$caseField = (string)($inputMapping[$name] ?? $name);
			$inputs[$name] = ($case[$caseField] ?? null);
		}

		return $inputs;
	}//end buildInputs()

	/**
	 * Write the decision's outputs to the stored case, applying
	 * `outputMapping` (decisionOutputName => caseFieldName) with a
	 * same-name default.
	 *
	 * ONLY the mapped outputs are written. `$case` is a snapshot of the flow
	 * item; full-saving it here erased whatever other writers stored after
	 * the snapshot was taken (the besluitDocument clobber, measured live on
	 * the closure rig). The writer applies the outputs to the STORED case.
	 *
	 * @param array<string, mixed> $table The decision table definition.
	 * @param array<string, mixed> $case The case snapshot; only its identity is used.
	 * @param array<string, mixed> $outputs The evaluated outputs, keyed by decision output name.
	 * @param array<string, mixed> $outputMapping Optional decisionOutputName => caseFieldName map.
	 *
	 * @return array<string, mixed> The case fields written, for the caller's outgoing snapshot.
	 *
	 * @throws \RuntimeException When OpenRegister/case schema is unavailable.
	 *
	 * @spec openspec/specs/dmn-decision-tables/spec.md
	 */
	private function writeOutputs(array $table, array $case, array $outputs, array $outputMapping): array {
		$declared = [];
		if (is_array($table['outputs'] ?? null) === true) {
			$declared = $table['outputs'];
		}

		$changes = [];
		foreach ($declared as $outputDef) {
			if (is_array($outputDef) === false) {
				continue;
			}

			$name = (string)($outputDef['name'] ?? '');
			if ($name === '') {
				continue;
			}

			$caseField = (string)($outputMapping[$name] ?? $name);
			$changes[$caseField] = ($outputs[$name] ?? null);
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('storage_unavailable');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($register === '' || $caseSchema === '') {
			throw new RuntimeException('case_schema_not_configured');
		}

		$this->caseWriter->write(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			case: $case,
			changes: $changes
		);

		return $changes;
	}//end writeOutputs()
}//end class
