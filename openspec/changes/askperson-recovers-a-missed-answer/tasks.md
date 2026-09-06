# Tasks: askperson-recovers-a-missed-answer

> The dossiq half of openregister#3358. The engine's heartbeat recovery was
> proven on `openregister.user-task`; the shipped case flow waits on
> `dossiq.askPerson`, so the recovery never reached the flow that motivated it.

## Implementation Tasks

### Task 1: The ask reads its task on re-entry
- **spec_ref**: `openspec/changes/askperson-recovers-a-missed-answer/specs/case-flow-human-steps/spec.md#requirement-an-ask-advances-on-its-task-not-on-a-signal`
- **files**: `lib/Flow/DossiqAskPersonNode.php`
- [x] Implement — create-once on the first pass; on every later pass read the task the slot names. Completed advances, open re-suspends untouched, terminated/disabled fails the step, a vanished row fails it, an unreadable store buys another heartbeat.
- [x] Test — `tests/Unit/Flow/DossiqAskPersonNodeTest.php`, whose object-service fake now SERVES READS as well as taking writes; a fake that took writes and served nothing let the whole re-entry path be "tested" against nothing.

### Task 2: The task decides, the wake decorates
- **spec_ref**: `.../specs/case-flow-human-steps/spec.md#requirement-the-task-decides-the-answer-and-the-wake-decorates-it`
- **files**: `lib/Flow/DossiqAskPersonNode.php`
- [x] Implement — the answer bag is built from the row and merged OVER the signal payload, carrying `recovered` so a run that advanced without its wake can be found afterwards.
- [x] Test — a signal cannot answer for an open task; the delivered and recovered paths agree.

### Task 3: Prove it through the real engine
- **spec_ref**: `.../specs/case-flow-human-steps/spec.md#requirement-a-flow-engine-test-may-drive-the-real-engine`
- **files**: `tests/bootstrap.php`, `tests/Unit/Flow/AskPersonHeartbeatRecoveryTest.php`
- [x] Implement — the bootstrap registers OpenRegister's real source ahead of the stubs, plus its non-Nextcloud dependencies behind this app's own resolution, when `DOSSIQ_REAL_FLOW_ENGINE=1`. The suite sets that at file scope and runs in separate processes, so nothing else in the app changes.
- [x] Test — refused signal → completed task → heartbeat → run advances, driven through the real FlowRunService, engine, registry, dispatcher, stream walk, claims, commit path and the real `FlowRunSignalService` guard. Verified NEGATIVELY: restoring the signal-only behaviour reds two of the four.

### Task 4: Stubs that declare what they stand for
- **spec_ref**: `.../specs/case-flow-human-steps/spec.md#requirement-a-flow-engine-test-may-drive-the-real-engine`
- **files**: `tests/Stubs/Flow/FlowNodeRegistry.php`, `tests/Stubs/Flow/RegisterFlowNodesEvent.php`, `tests/Unit/Service/Transitions/SideEffectDispatcherTest.php`, `tests/Unit/Service/Actions/AutomaticActionFlowMigratorTest.php`, `tests/Unit/Flow/DossiqFlowNodeListenerTest.php`
- [x] Implement — both stubs take the constructor arguments the real classes require, and the event carries a registry instead of an accessor it invented. Six call sites that would fatal against a real OpenRegister are corrected.
- [x] Test — the listener suite now reads the contribution off the CATALOGUE, which is where a contributed node actually lands.

### Task 5: Nothing to migrate
- **spec_ref**: `.../specs/case-flow-human-steps/spec.md#requirement-an-ask-advances-on-its-task-not-on-a-signal`
- **files**: none
- [x] Verify — the node id, the resume-slot key (`taskId`), the task schema and every shipped `dossiq.askPerson` step are unchanged, so no flow definition moves and no repair step is needed. An in-flight run keeps its slot and gains the recovery on its next heartbeat; a run wedged today recovers within one heartbeat of deploy.
