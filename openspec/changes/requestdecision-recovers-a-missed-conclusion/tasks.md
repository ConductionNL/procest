# Tasks: requestdecision-recovers-a-missed-conclusion

> The gap dossiq#1756 left open by name. It could not be closed then because
> `ContractDecisionDelegationService` could raise a decision and not read one
> back; decidiq#1118 shipped the read seam, so a heartbeat now has something to
> consult.

## Implementation Tasks

### Task 1: Read the decision back
- **spec_ref**: `openspec/changes/requestdecision-recovers-a-missed-conclusion/specs/case-flow-human-steps/spec.md#requirement-a-consumer-can-read-back-a-decision-it-raised`
- **files**: `lib/Service/ContractDecisionDelegationService.php`
- [x] Implement — `readDecisionState()` dispatches decidiq's `DecisionStateRequestedEvent` and reports one of six states. Guarded by `class_exists` so dossiq stays installable without decidiq, and never dispatched with an empty actor, which decidiq refuses by design. Unresolved is reported as unreadable, distinctly from refused.
- [x] Test — `tests/Unit/Service/ContractDecisionDelegationReadTest.php`, over the real event class, covering all six answers plus the absent-seam and no-actor paths.

### Task 2: The decision step advances on its decision
- **spec_ref**: `.../specs/case-flow-human-steps/spec.md#requirement-a-decision-step-advances-on-its-decision-not-on-a-signal`
- **files**: `lib/Flow/DossiqRequestDecisionNode.php`
- [x] Implement — raise-once on the first pass; on every later pass read the decision the slot names. Decided advances, open re-suspends untouched, withdrawn fails the step, a vanished record fails it, a refused read fails it, an unreadable seam buys another heartbeat.
- [x] Test — `tests/Unit/Flow/DossiqRequestDecisionNodeTest.php`, whose delegation double now ANSWERS READS as well as taking raises; a double that took raises and answered nothing left the whole re-entry path untested.

### Task 3: The decision decides, the wake decorates
- **spec_ref**: `.../specs/case-flow-human-steps/spec.md#requirement-the-decision-decides-the-outcome-and-the-wake-decorates-it`
- **files**: `lib/Flow/DossiqRequestDecisionNode.php`
- [x] Implement — the outcome bag is built from decidiq's answer and merged OVER the signal payload, carrying `recovered` so a run that advanced without its announcement can be found afterwards.
- [x] Test — a signal cannot answer for an open decision; the announced and recovered paths agree.

### Task 4: Name the identity the seam requires
- **spec_ref**: `.../specs/case-flow-human-steps/spec.md#requirement-a-decision-step-names-the-identity-it-raised-the-decision-as`
- **files**: `lib/Flow/DossiqRequestDecisionNode.php`
- [x] Implement — `raisedBy` is recorded from the run's acting identity at raise time and used to scope the read; the run's current `runAs` is the fallback for a slot written before this change; a run naming no identity suspends and logs rather than dispatching a read decidiq would refuse.
- [x] Test — a slot with no `raisedBy` still recovers; a read named as somebody else is refused by the harness's real authorization scoping and fails the step.

### Task 5: Prove it through the real engine
- **spec_ref**: `.../specs/case-flow-human-steps/spec.md#requirement-a-decision-step-advances-on-its-decision-not-on-a-signal`
- **files**: `tests/Unit/Flow/RequestDecisionHeartbeatRecoveryTest.php`, `tests/Stubs/Decidiq/Event/DecisionStateRequestedEvent.php`, `tests/bootstrap.php`
- [x] Implement — the event stub mirrors decidiq's real API rather than the call site's assumption about it, and the bootstrap loads it only when decidiq is absent. One namespace, because the read half was added after the rename and `OCA\Decidesk\Event\DecisionStateRequestedEvent` has never existed.
- [x] Test — missed announcement → decision concluded in decidiq → heartbeat → run advances, driven through the real FlowRunService, engine, registry, dispatcher, stream walk, claims and commit path, with the real delegation service dispatching into an in-memory decidiq. Verified NEGATIVELY: restoring the signal-only behaviour reds it.

### Task 6: Nothing to migrate
- **spec_ref**: `.../specs/case-flow-human-steps/spec.md#requirement-a-decision-step-advances-on-its-decision-not-on-a-signal`
- **files**: none
- [x] Verify — the node id, the resume-slot key (`decisionRef`), the decision types and every shipped `dossiq.requestDecision` step are unchanged, so no flow definition moves and no repair step is needed. An in-flight run keeps its slot and gains the recovery on its next heartbeat.
