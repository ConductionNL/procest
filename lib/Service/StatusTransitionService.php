<?php

/**
 * Dossiq Status Transition Service.
 *
 * The single deterministic write path for `case.status` across Dossiq. The
 * REST API, the case detail UI, and the bezwaar/parafering/VTH workflow
 * specs all funnel transitions through `execute()`. Responsibilities:
 *
 *  - load the active workflow template per caseType (via WorkflowTemplateLoader)
 *  - evaluate every transition's guards (via GuardRegistry)
 *  - update `case.status` atomically with a `statusRecord` write
 *  - dispatch automatic actions sequentially (via SideEffectDispatcher)
 *  - replay transition history from the `statusRecord` chain
 *
 * Three collaborators carry the concerns that are not transition decisions:
 * {@see Transitions\CaseStatusStore} owns every OpenRegister read/write,
 * {@see Transitions\TransitionAuthorizer} owns the OR-RBAC group gate, and
 * {@see Transitions\TransitionSpecReader} owns the template dialects a
 * transition's guards and actions may be spelled in.
 *
 * Identity is ALWAYS derived from IUserSession when the caller does not pass
 * an explicit userId. Static error messages only — never bubble exception
 * detail to controllers or callers.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\GuardFailedException;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\SideEffectDispatcher;
use OCA\Dossiq\Service\Transitions\TransitionAuthorizer;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The status-transition engine.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T10
 */
class StatusTransitionService {

	/**
	 * Group ID used to gate admin-only free-form transitions. Matches the
	 * naming used elsewhere in Dossiq for the admin role.
	 *
	 * Re-exported from TransitionAuthorizer, which owns the group gate, so
	 * existing `StatusTransitionService::ADMIN_GROUP_ID` callers keep reading
	 * the single source of truth.
	 */
	public const ADMIN_GROUP_ID = TransitionAuthorizer::ADMIN_GROUP_ID;

	/**
	 * Constructor.
	 *
	 * @param WorkflowTemplateLoader $templateLoader Active workflowTemplate loader
	 * @param GuardRegistry $guardRegistry Guard registry
	 * @param SideEffectDispatcher $sideEffectDispatcher Side-effect dispatcher
	 * @param CaseStatusStore $store OpenRegister persistence for the engine
	 * @param TransitionAuthorizer $authorizer OR-RBAC group gate
	 * @param TransitionSpecReader $specReader Guard/action shape reader
	 * @param IUserSession $userSession Current session
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly WorkflowTemplateLoader $templateLoader,
		private readonly GuardRegistry $guardRegistry,
		private readonly SideEffectDispatcher $sideEffectDispatcher,
		private readonly CaseStatusStore $store,
		private readonly TransitionAuthorizer $authorizer,
		private readonly TransitionSpecReader $specReader,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the set of transitions available to a user on a case.
	 *
	 * @param string $caseId Case UUID
	 * @param string|null $userId Optional explicit user UID; defaults to IUserSession
	 *
	 * @return array{transitions: array<int, array<string, mixed>>, current: array<string, mixed>}
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function getAvailableTransitions(string $caseId, ?string $userId = null): array {
		$userId = $this->resolveUserId(explicit: $userId);
		$case = $this->store->loadCase(caseId: $caseId);
		if ($case === null) {
			return ['transitions' => [], 'current' => []];
		}

		$currentId = (string)($case['status'] ?? '');

		// 🔑 THE CASE, NOT ITS CASE TYPE. A case type may carry several routes,
		// and a case pinned to one of them must be offered that route's
		// transitions. Asking by case type alone returned whichever active
		// definition the store listed first.
		$template = $this->templateLoader->getTemplateForCase(case: $case);

		$result = [
			'transitions' => [],
			'current' => ['statusId' => $currentId, 'statusName' => $this->store->lookupStatusName(statusTypeId: $currentId)],
		];

		if ($template === null) {
			return $result;
		}

		$transitions = $template['transitions'] ?? [];
		if (is_array($transitions) === false) {
			return $result;
		}

		foreach ($transitions as $transition) {
			if (is_array($transition) === false) {
				continue;
			}

			if ((string)($transition['fromStatus'] ?? '') !== $currentId) {
				continue;
			}

			$guards = $this->specReader->extractGuards(transition: $transition);
			$eval = $this->guardRegistry->evaluateAll(guards: $guards, case: $case, userId: $userId);

			// Drop transitions whose role guard hides them silently.
			if ($this->specReader->isRoleHidden(evalResults: $eval) === true) {
				continue;
			}

			$failed = array_values(array_filter($eval, static fn (array $guard): bool => $guard['passed'] === false));

			$result['transitions'][] = [
				'id' => (string)($transition['id'] ?? ''),
				'label' => (string)($transition['label'] ?? ''),
				'toStatus' => (string)($transition['toStatus'] ?? ''),
				'guardsPassed' => count($failed) === 0,
				'failedGuards' => $failed,
			];
		}//end foreach

		return $result;
	}//end getAvailableTransitions()

	/**
	 * Execute a guarded transition.
	 *
	 * @param string $caseId Case UUID
	 * @param string $transitionId Transition id from the workflow the case runs on
	 * @param string|null $comment Optional free-form comment
	 * @param string|null $userId Optional explicit user UID; defaults to IUserSession
	 *
	 * @return array{status: string, statusRecord: array<string, mixed>, dispatchedActions: array<int, array<string, mixed>>, version: int}
	 *
	 * @throws GuardFailedException When server-side re-evaluation fails any guard
	 * @throws RuntimeException When case/transition/template are not found
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function execute(string $caseId, string $transitionId, ?string $comment, ?string $userId = null): array {
		$userId = $this->resolveUserId(explicit: $userId);
		$case = $this->store->loadCase(caseId: $caseId);
		if ($case === null) {
			throw new RuntimeException('case_not_found');
		}

		// H2: Capture the @self.version at read time for the optimistic lock check below.
		$readVersion = (int)(($case['@self']['version'] ?? ($case['version'] ?? 0)));

		$transition = $this->templateLoader->getTransitionForCase(case: $case, transitionId: $transitionId);
		if ($transition === null) {
			throw new RuntimeException('transition_not_found');
		}

		$currentId = (string)($case['status'] ?? '');

		$eval = $this->assertTransitionAllowed(
			case: $case,
			transition: $transition,
			caseId: $caseId,
			transitionId: $transitionId,
			currentId: $currentId,
			userId: $userId,
		);

		$toStatus = (string)($transition['toStatus'] ?? '');
		if ($toStatus === '') {
			throw new RuntimeException('transition_missing_to_status');
		}

		// H2: Optimistic concurrency guard — re-load the case immediately before writing
		// and abort if its status changed since we read it (concurrent transition executed
		// between our guard evaluation and our save).
		$caseAtSave = $this->assertNoConcurrentChange(
			caseId: $caseId,
			readVersion: $readVersion,
			currentId: $currentId,
		);

		// Status mutation BEFORE side-effects per REQ-STE-5-002.
		// Include @self.version so the store can detect a concurrent modification.
		$caseAtSave['status'] = $toStatus;
		if (isset($caseAtSave['@self']) === false || is_array($caseAtSave['@self']) === false) {
			$caseAtSave['@self'] = [];
		}

		$caseAtSave['@self']['version'] = $readVersion;
		$savedCase = $this->store->saveCase(case: $caseAtSave);
		$savedVersion = (int)(($savedCase['@self']['version'] ?? ($savedCase['version'] ?? 0)));

		// Alias for the remainder of the method.
		$case = $savedCase;

		$label = (string)($transition['label'] ?? '');
		$record = $this->store->writeStatusRecord(
			caseId: $caseId,
			toStatus: $toStatus,
			fromStatus: $currentId,
			label: $label,
			comment: $comment,
			evaluatedGuards: $eval,
			noWorkflowTemplate: false,
		);

		$statusRecordId = (string)($record['id'] ?? '');
		$context = [
			'fromStatus' => $currentId,
			'toStatus' => $toStatus,
			'transitionLabel' => $label,
			'userId' => $userId,
			'statusRecordUuid' => $statusRecordId,
		];

		$actions = $this->specReader->extractActions(transition: $transition);
		$dispatched = $this->sideEffectDispatcher->dispatch(actions: $actions, case: $case, transitionContext: $context);

		// Update the statusRecord with the actual dispatched-action results.
		$record = $this->persistDispatchedActions(
			record: $record,
			dispatched: $dispatched,
			statusRecordId: $statusRecordId,
		);

		return [
			'status' => 'ok',
			'statusRecord' => $record,
			'dispatchedActions' => $dispatched,
			'version' => $savedVersion,
		];
	}//end execute()

	/**
	 * Re-evaluate every server-side precondition for a transition.
	 *
	 * @param array<string, mixed> $case The loaded case
	 * @param array<string, mixed> $transition The transition definition
	 * @param string $caseId Case UUID (for logging)
	 * @param string $transitionId Transition id (for logging)
	 * @param string $currentId The case's current statusType UUID
	 * @param string $userId The acting user UID
	 *
	 * @return array<int, array<string, mixed>> The guard evaluation results
	 *
	 * @throws GuardFailedException When server-side re-evaluation fails any guard
	 * @throws RuntimeException When the from-status or group authorization gate rejects
	 */
	private function assertTransitionAllowed(
		array $case,
		array $transition,
		string $caseId,
		string $transitionId,
		string $currentId,
		string $userId,
	): array {
		$fromStatus = (string)($transition['fromStatus'] ?? '');
		if ($fromStatus !== '' && $fromStatus !== $currentId) {
			throw new RuntimeException('transition_from_status_mismatch');
		}

		// OR-RBAC role-routing gate (ADR-022). At publish time
		// WorkflowDefinitionService resolves each transition's assignee role
		// to its `roleType.ncGroupId` and freezes the literal group id(s) on
		// the transition `authorization` list — the same OR PR #153 gate
		// format OR enforces declaratively on schemas that carry an
		// x-openregister-lifecycle. `case.status` is a per-caseType dynamic
		// state machine with no static lifecycle table, so OR cannot enforce
		// it on saveObject; this engine therefore enforces the SAME group
		// model here using OR's single trusted membership check (IGroupManager),
		// not a bespoke role-resolution scheme. An empty/absent list = open.
		if ($this->authorizer->isTransitionGroupAuthorized(transition: $transition, userId: $userId) === false) {
			throw new RuntimeException('transition_unauthorized');
		}

		// Defence in depth — re-evaluate guards on the server side.
		$guards = $this->specReader->extractGuards(transition: $transition);
		$eval = $this->guardRegistry->evaluateAll(guards: $guards, case: $case, userId: $userId);
		$failed = array_values(array_filter($eval, static fn (array $guard): bool => $guard['passed'] === false));
		// @phpstan-ignore greaterThan.alwaysFalse (PHPDoc type marks passed as bool, but runtime values may differ)
		if (count($failed) > 0) {
			$this->logger->info('StatusTransitionService: guards failed', ['caseId' => $caseId, 'transitionId' => $transitionId]);
			throw new GuardFailedException(failedGuards: $failed);
		}

		return $eval;
	}//end assertTransitionAllowed()

	/**
	 * Re-load the case immediately before writing and abort when another
	 * transition landed in the meantime (H2 optimistic concurrency guard).
	 *
	 * @param string $caseId Case UUID
	 * @param int $readVersion The @self.version captured at read time
	 * @param string $currentId The statusType UUID observed at read time
	 *
	 * @return array<string, mixed> The freshly loaded case
	 *
	 * @throws RuntimeException When the case vanished or was concurrently changed
	 */
	private function assertNoConcurrentChange(
		string $caseId,
		int $readVersion,
		string $currentId,
	): array {
		$caseAtSave = $this->store->loadCase(caseId: $caseId);
		if ($caseAtSave === null) {
			throw new RuntimeException('case_not_found');
		}

		$versionAtSave = (int)(($caseAtSave['@self']['version'] ?? ($caseAtSave['version'] ?? 0)));
		if ($versionAtSave !== $readVersion) {
			throw new RuntimeException('transition_conflict');
		}

		$statusAtSave = (string)($caseAtSave['status'] ?? '');
		if ($statusAtSave !== $currentId) {
			throw new RuntimeException('transition_conflict');
		}

		return $caseAtSave;
	}//end assertNoConcurrentChange()

	/**
	 * Persist the dispatched-action results onto the statusRecord.
	 *
	 * @param array<string, mixed> $record The statusRecord
	 * @param array<int, array<string, mixed>> $dispatched Dispatch results
	 * @param string $statusRecordId The statusRecord UUID
	 *
	 * @return array<string, mixed> The (possibly updated) statusRecord
	 */
	private function persistDispatchedActions(
		array $record,
		array $dispatched,
		string $statusRecordId,
	): array {
		if ($statusRecordId === '') {
			return $record;
		}

		$record['dispatchedActions'] = $dispatched;
		try {
			return $this->store->updateStatusRecord(record: $record);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatusTransitionService: dispatchedActions persist failed',
				['exception' => $e->getMessage(), 'statusRecord' => $statusRecordId],
			);
		}

		return $record;
	}//end persistDispatchedActions()

	/**
	 * Execute an admin-only free-form transition for caseTypes without an active workflow template.
	 *
	 * @param string $caseId Case UUID
	 * @param string $toStatusId Target statusType UUID
	 * @param string|null $comment Optional free-form comment
	 * @param string|null $userId Optional explicit user UID; defaults to IUserSession
	 *
	 * @return array{status: string, statusRecord: array<string, mixed>}
	 *
	 * @throws RuntimeException When the caller is not in the admin group or the target is invalid
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function executeFreeForm(string $caseId, string $toStatusId, ?string $comment, ?string $userId = null): array {
		$userId = $this->resolveUserId(explicit: $userId);
		if ($this->authorizer->isAdmin(userId: $userId) === false) {
			throw new RuntimeException('forbidden_admin_only');
		}

		$case = $this->store->loadCase(caseId: $caseId);
		if ($case === null) {
			throw new RuntimeException('case_not_found');
		}

		$caseTypeId = (string)($case['caseType'] ?? '');
		$this->store->assertStatusBelongsToCaseType(caseTypeId: $caseTypeId, statusTypeId: $toStatusId);

		$currentId = (string)($case['status'] ?? '');
		$case['status'] = $toStatusId;
		$case = $this->store->saveCase(case: $case);

		$record = $this->store->writeStatusRecord(
			caseId: $caseId,
			toStatus: $toStatusId,
			fromStatus: $currentId,
			label: 'Free-form transition',
			comment: $comment,
			evaluatedGuards: [],
			noWorkflowTemplate: true,
		);

		return ['status' => 'ok', 'statusRecord' => $record];
	}//end executeFreeForm()

	/**
	 * Return the chronological history of transitions for a case.
	 *
	 * @param string $caseId Case UUID
	 *
	 * @return array{history: array<int, array<string, mixed>>, replayable: bool}
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function replay(string $caseId): array {
		$list = $this->store->findStatusRecords(caseId: $caseId);
		if ($list === null) {
			return ['history' => [], 'replayable' => false];
		}

		usort(
			$list,
			static function (array $left, array $right): int {
				$leftAt = (string)($left['createdAt'] ?? ($left['@self']['createdAt'] ?? ''));
				$rightAt = (string)($right['createdAt'] ?? ($right['@self']['createdAt'] ?? ''));
				return strcmp($leftAt, $rightAt);
			},
		);

		return ['history' => $list, 'replayable' => true];
	}//end replay()

	/**
	 * Check if the current (or given) user is in the dossiq admin group.
	 *
	 * @param string $userId UID
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function isAdmin(string $userId): bool {
		return $this->authorizer->isAdmin(userId: $userId);
	}//end isAdmin()

	// ------------------------------------------------------------------
	// Internal helpers
	// ------------------------------------------------------------------

	/**
	 * Resolve a user UID either from the explicit parameter or IUserSession.
	 *
	 * @param string|null $explicit Caller-supplied UID, or null
	 *
	 * @return string
	 */
	private function resolveUserId(?string $explicit): string {
		if ($explicit !== null && $explicit !== '') {
			return $explicit;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end resolveUserId()
}//end class
