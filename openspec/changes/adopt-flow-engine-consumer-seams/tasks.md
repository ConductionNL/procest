# Tasks: adopt-flow-engine-consumer-seams

> The dossiq follow-up openregister#3332 named. Counterpart: the engine's
> `flow-engine-consumer-seams` change, MERGED FIRST — dossiq deletes its local
> scoping and guard assuming the engine already holds both.

## Implementation Tasks

### Task 1: Retire the local runAs wrapper
- **spec_ref**: `openspec/changes/adopt-flow-engine-consumer-seams/specs/case-flow-human-steps/spec.md#requirement-flow-storage-work-runs-under-the-engines-native-scoping`
- **files**: deleted — `lib/Service/FlowRunAsScope.php`, `tests/Unit/Service/FlowRunAsScopeTest.php`; unwrapped — `lib/Flow/DossiqAskPersonNode.php`, `lib/Flow/DossiqRequestDecisionNode.php`, `lib/Flow/DossiqEnsureCommitteeNode.php`, `lib/Service/Transitions/{SetStatusHandler,SetFieldHandler,CreateTaskHandler,CreateSubCaseHandler,EvaluateDecisionHandler,BesluitvormingActivateHandler,BesluitvormingPublishHandler}.php`, `lib/Service/Actions/MergeTemplateHandler.php`, `lib/Service/CaseFieldWriter.php` (comment)
- [x] Implement
- [x] Test — the per-handler suites drop their scope collaborators; the runAs-obedience tests retire WITH the wrapper (the behaviour is engine-owned and proven by openregister's RegistryStepDispatcherRunAsTest), each suite noting where the duty went

### Task 2: Invert the scanner
- **spec_ref**: `.../specs/case-flow-human-steps/spec.md#requirement-flow-storage-work-runs-under-the-engines-native-scoping`
- **files**: `tests/Unit/Flow/FlowStorageRunsAsTheRunsIdentityTest.php`
- [x] Implement — from "storage-performing flow files MUST wrap" to "flow files MUST NOT wrap", keeping the detector self-check and pinning the wrapper file as deleted
- [x] Test — the inverted sweep is the test

### Task 3: The listener onto signalAs
- **spec_ref**: `.../specs/task-management/spec.md#requirement-a-completed-task-resumes-its-run-through-the-guarded-seam`
- **files**: `lib/Listener/TaskCompletionResumeListener.php`, `tests/Unit/Listener/TaskCompletionResumeListenerTest.php`, `tests/Stubs/Service/Flow/FlowRunSignalService.php`, `tests/Stubs/Exception/FlowSignalRefused.php`, `tests/Stubs/Service/Flow/FlowRunService.php`, `tests/bootstrap.php`
- [x] Implement — one guarded call, node-addressed, per-reason refusal logging; FlowRunMapper/FlowRunService/FlowRunAssignee/IGroupManager leave the constructor
- [x] Test — actor and node handed to the seam, refusal obeyed quietly, vanished run completes quietly

### Task 4: Nothing resurrected
- **spec_ref**: `.../specs/task-management/spec.md#requirement-a-completed-task-resumes-its-run-through-the-guarded-seam`
- **files**: none
- [x] Verify — `ParaafResumeListener` and the `MergeTemplateHandler` signal wrapper stayed retired (dossiq#1666); the registrar's human-step registration is unchanged
