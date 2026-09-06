# Adopt the flow engine's consumer seams

## Why

openregister#3332 closed the two engine gaps every consuming app was
rebuilding, and named dossiq's follow-up explicitly:

1. **A guarded server-side signal.** `FlowRunSignalService::signalAs(runUuid,
   payload, actorUid, nodeId?)` resolves the run, applies the recorded-assignee
   rule (group resolution included), audits a refusal, and delivers — one
   typed `FlowSignalRefused` per refusal. dossiq's task-completion listener
   was the consumer that remembered to consult `FlowRunAssignee` itself; "each
   caller must remember" is the failure mode the seam removes.
2. **Native runAs scoping for contributed nodes.** `RegistryStepDispatcher`
   now executes every contributed node inside `ObjectService::runAs()` as the
   run's validated (exists AND enabled) acting identity. dossiq's local
   `FlowRunAsScope` — and the wrap in every flow node and transition/action
   handler — is the per-consumer copy of that engine rule.

## What changes

- `lib/Service/FlowRunAsScope.php` and its test are DELETED. The twelve wrap
  sites (3 flow nodes, 8 transition/action handlers via `runAsScope->call(`,
  plus the CaseFieldWriter guidance comment) unwrap: on the flow path the
  dispatcher scopes them, on the interactive path the ambient session user
  answers the permission checks — the same two-path behaviour the wrapper
  implemented locally.
- `TaskCompletionResumeListener` collapses the injected FlowRunAssignee guard
  + FlowRunMapper resolve + `runner->signal()` into ONE `signalAs(...)` call,
  addressed at the task's node, catching `FlowSignalRefused` (NOT_ASSIGNEE is
  the audit-worthy warning; RUN_NOT_FOUND and NOT_SUSPENDED are ordinary
  life, recorded quietly). The engine now audits the refusal itself; the
  listener's log ties it to the task.
- The DQ#1644 scanner (`FlowStorageRunsAsTheRunsIdentityTest`) INVERTS, per
  its own discipline: it used to red any storage-performing flow file that
  did NOT wrap; it now reds any flow file that DOES reference the retired
  wrapper, and pins `lib/Service/FlowRunAsScope.php` as deleted. A test that
  encoded the old requirement reddening after this change was expected — it
  is inverted, not deleted, so the defect family keeps a guard pointing the
  right way.
- Test stubs: `FlowRunSignalService` and `FlowSignalRefused` join
  tests/Stubs matching the real dev signatures; the FlowRunService stub gains
  the engine's `RUN_AS_CONTEXT_KEY` constant.

`ParaafResumeListener` and the `MergeTemplateHandler` signal wrapper were
already retired by dossiq#1666 (verified on development); nothing is
resurrected.

## Compatibility

This assumes the OpenRegister that ships #3332 — the same coupled-release
posture every dossiq flow change has taken. Behaviour on the interactive path
is unchanged; on the worker path the scoping and the assignee guard each have
exactly one implementation, the engine's.
