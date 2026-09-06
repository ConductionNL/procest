<?php

/**
 * Move a case to a named status.
 *
 * WHY THIS EXISTS ALONGSIDE {@see SetFieldHandler}. `setField` writes a literal
 * value, which is right for a date or a flag and wrong for `status`: status is a
 * reference to a `statusType` object whose uuid is minted per installation. A
 * flow SHIPPED with the app therefore cannot carry one — it would be correct on
 * the machine it was authored on and wrong everywhere else, and wrong in the
 * quiet way, by writing a uuid nothing resolves.
 *
 * So this names the status the way a person does — "in behandeling" — and
 * resolves it within the case's OWN case type at run time.
 *
 * 🔴 A NAME IS NOT AN IDENTIFIER EITHER, WHICH IS WHY `role` EXISTS. Naming the
 * status fixed the uuid problem and left a second one standing: the shipped
 * `Case behandeling` flow moved cases by six literal names, and only ONE case
 * type in the whole app carried all six. Measured on the shipped demo caseload,
 * 8 of 18 runs died at `status_not_found_on_case_type` — a subsidy is under
 * `Beoordeling` and a complaint under `Onderzoek`, and both are right. On top of
 * that `statusType.name` is declared `x-translatable`, so a literal is broken by
 * a language change alone.
 *
 * A step therefore says what the status MEANS — `role: in-progress` — and the
 * name is kept as the fallback for a case type nobody has annotated. See
 * {@see StatusTypeLookup::idForRole()}.
 *
 * 🔴 IT REFUSES RATHER THAN SKIPS, UNLESS THE STEP SAYS THE PHASE IS OPTIONAL.
 * An unresolvable status fails the step. The tempting alternative — leave the
 * status alone and carry on — produces a run that completes while the case never
 * moved, which the applicant experiences as a case frozen at "received" and the
 * handler experiences as a flow that says it worked. A status move is the
 * applicant-facing signal; failing to make one is a failure, not a detail.
 *
 * `required: false` is the ONE exception, and it is a claim the flow author
 * makes about that step, not a fallback this handler chooses. Some phases are
 * genuinely particular to one process: a pothole report has no `Bij commissie`
 * because it never goes to a planning commission, and inventing one for it
 * would be worse than not having it. Such a step records itself as SKIPPED,
 * with the role it wanted, so the history says the phase was passed over rather
 * than silently claiming a move that never happened.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Handles the `setStatus` action: move a case to a status named by the flow.
 *
 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
 */
class SetStatusHandler implements ActionHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Resolves the object service and the configured schemas.
	 * @param StatusTypeLookup $statuses       Resolves a status name to its id within a case type.
	 * @param CaseFieldWriter $caseWriter      Applies ONLY this handler's field to the stored case.
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly StatusTypeLookup $statuses,
		private readonly CaseFieldWriter $caseWriter,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The action id this handler answers to.
	 *
	 * @return string The action type.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	public function type(): string {
		return 'setStatus';
	}//end type()

	/**
	 * Move the case to the named status.
	 *
	 * @param array $actionConfig       `{type: 'setStatus', status: '<name>'}`.
	 * @param array $case               The case being walked.
	 * @param array $transitionContext  The surrounding transition's context.
	 *
	 * @return ActionResult Success with the resolved status, or a named failure.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/status-transition-engine/spec.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$statusName = trim((string)($actionConfig['status'] ?? ''));
			$statusRole = trim((string)($actionConfig['role'] ?? ''));
			if ($statusName === '' && $statusRole === '') {
				return new ActionResult(succeeded: false, error: 'set_status_missing_status');
			}

			$caseTypeId = (string)($case['caseType'] ?? '');
			if ($caseTypeId === '') {
				return new ActionResult(succeeded: false, error: 'case_has_no_case_type');
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

			// The RESOLVE and the WRITE run under one identity. On the flow
			// path that identity is the run's acting identity: the engine's
			// RegistryStepDispatcher executes this handler inside
			// `ObjectService::runAs()` (openregister#3332), which is what
			// un-stranded the cases FlowRunWorker refused as 'Anonymous'. On
			// the interactive path the ambient session user answers the
			// permission checks. No local runAs wrap is needed here any more.
			//
			// ONLY `status` is written. `$case` is a SNAPSHOT of the flow
			// item, not the stored case; full-saving it here is what erased
			// `besluitDocument` one step after the document step wrote it
			// (measured live: case a53cfc92/dc16d6dd, audits 512→515 and
			// 725→728, same second). The writer applies this handler's field
			// to the STORED case and touches nothing else.
			$statusId = $this->resolve(caseTypeId: $caseTypeId, role: $statusRole, name: $statusName);
			if ($statusId === '') {
				return $this->unresolved(
					actionConfig: $actionConfig,
					case: $case,
					caseTypeId: $caseTypeId,
					role: $statusRole,
					name: $statusName
				);
			}

			$this->caseWriter->write(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				case: $case,
				changes: ['status' => $statusId]
			);

			return new ActionResult(
				succeeded: true,
				data: ['status' => $statusId, 'statusName' => $statusName, 'statusRole' => $statusRole],
				caseChanges: ['status' => $statusId]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SetStatusHandler failed',
				['exception' => $e->getMessage(), 'context' => $transitionContext],
			);

			return new ActionResult(succeeded: false, error: 'set_status_failed');
		}//end try
	}//end handle()

	/**
	 * The statusType this step means, within this case's own case type.
	 *
	 * ROLE FIRST, NAME SECOND. The role is what the flow MEANS and the name is
	 * what one case type happens to call it, so a case type that has been
	 * annotated is addressed by meaning and one that has not falls back to
	 * exactly the behaviour it had before roles existed. Never the other way
	 * round: a case type carrying both an `in-progress` role and a literal
	 * "In behandeling" on DIFFERENT statuses is telling us the role is the
	 * deliberate one.
	 *
	 * @param string $caseTypeId The case's own case type.
	 * @param string $role       The role the step named, or ''.
	 * @param string $name       The literal name the step named, or ''.
	 *
	 * @return string The statusType UUID, or '' when neither direction resolves.
	 *
	 * @spec openspec/changes/case-flow-status-roles/specs/status-transition-engine/spec.md
	 */
	private function resolve(string $caseTypeId, string $role, string $name): string {
		if ($role !== '') {
			$byRole = $this->statuses->idForRole(caseTypeId: $caseTypeId, role: $role);
			if ($byRole !== '') {
				return $byRole;
			}
		}

		if ($name !== '') {
			return $this->statuses->idForName(caseTypeId: $caseTypeId, statusName: $name);
		}

		return '';
	}//end resolve()

	/**
	 * What a status this case type does not model means for the step.
	 *
	 * A phase the flow author declared optional is SKIPPED and recorded as
	 * skipped; anything else FAILS. `required` is read as "anything but an
	 * explicit false is required", so a step that forgets to say is required —
	 * the safe reading, since the cost of a wrongly-required step is a visible
	 * failure and the cost of a wrongly-optional one is a case that never moves
	 * and says nothing.
	 *
	 * @param array  $actionConfig The step config, carrying `required`.
	 * @param array  $case         The case being walked.
	 * @param string $caseTypeId   The case's own case type.
	 * @param string $role         The role the step named, or ''.
	 * @param string $name         The literal name the step named, or ''.
	 *
	 * @return ActionResult A recorded skip, or a named failure.
	 *
	 * @spec openspec/changes/case-flow-status-roles/specs/status-transition-engine/spec.md
	 */
	private function unresolved(
		array $actionConfig,
		array $case,
		string $caseTypeId,
		string $role,
		string $name,
	): ActionResult {
		// Says what was ACTUALLY tried, both directions when both were given:
		// "no status with role X" alone would send an operator looking for a
		// role on a case type whose name never matched either, and the fix
		// differs.
		$tried = [];
		if ($role !== '') {
			$tried[] = 'role "' . $role . '"';
		}

		if ($name !== '') {
			$tried[] = 'name "' . $name . '"';
		}

		$wanted = implode(' or ', $tried);
		$context = ['caseType' => $caseTypeId, 'case' => ($case['id'] ?? null)];

		if (($actionConfig['required'] ?? true) === false) {
			$this->logger->info(
				'Dossiq setStatus: skipping an optional phase; this case type models no status with ' . $wanted,
				$context
			);

			return new ActionResult(
				succeeded: true,
				data: [
					'skipped' => true,
					'reason' => 'case_type_models_no_such_phase',
					'statusRole' => $role,
					'statusName' => $name,
				]
			);
		}

		// Named, not generic: an operator reading the run history has to be
		// able to see WHICH status could not be found, because the fix is
		// almost always a missing status on the case type.
		$this->logger->warning('Dossiq setStatus: the case type has no status with ' . $wanted, $context);

		return new ActionResult(succeeded: false, error: 'status_not_found_on_case_type');
	}//end unresolved()
}//end class