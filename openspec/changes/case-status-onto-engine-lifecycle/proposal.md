# Case status onto the engine lifecycle line

## Why

The fleet audit's wave 5 asks two questions of dossiq's status machinery:
is the case status engine flow-engine duplication, and which of the small
per-domain state machines still advance status outside the one engine line.
OpenRegister owns the fleet's flow engine (flows, nodes, signals, timers)
and its object lifecycle facility (`x-openregister-lifecycle`, validated by
`LifecycleAnnotationValidator`, driven by `TransitionEngine` with a sugar
HTTP endpoint per schema).

The inventory (design.md section 1) answers honestly: the case status
engine is NOT deletable duplication, it is the app's single write path for
a per-caseType dynamic statusType graph that OR's static, enum-anchored
lifecycle cannot express yet, and its flow adoption path already exists
(the `DossiqTx*` nodes wrap the same handlers; `workflow-definitions-to-flow`
projects the templates onto flows and stages the retirement). What IS
deletable or wrong, this change takes:

- a dead second entry point to the engine (`WorkflowEngineService`, zero
  production callers),
- a dead mini state machine (`VergaderingCaseService` plus its nightly
  job) that writes literal status strings into a field that holds
  statusType references, scanning for cases that cannot exist,
- a declared lifecycle that provably never ran: the complaint schema's
  `x-openregister-lifecycle` is in the legacy list dialect with Dutch
  state names while the status enum, the service table, and stored data
  are English, so the declared machine names states the enum forbids.

## What changes

- DELETE `lib/Service/WorkflowEngineService.php` and its unit test. It is
  a facade over `StatusTransitionService` promised by the archived
  workflow-engine-enhancement change; the consumers never appeared and the
  controllers call `StatusTransitionService` directly. A dead second door
  to the transition engine is how a parallel sequencer ships quietly.
- DELETE `lib/Service/VergaderingCaseService.php`,
  `lib/BackgroundJob/VergaderingDeadlineJob.php`, their unit tests, and
  the info.xml job registration. Evidence of deadness in design.md
  section 2; the termijnbewaking staged task 2.4 assumed a live engine to
  migrate and is amended to record the retirement instead.
- FIX the complaint `x-openregister-lifecycle` in both register manifests
  (`dossiq_register.json`, `dossiq_mock_register.json`): rewrite to the
  object-form dialect OR validates (`field`, `initial`, `transitions`,
  `final`), with the English states the enum declares, mirroring
  `ComplaintService::TRANSITIONS` exactly. The declared machine and the
  imperative table then agree; retiring the table in favour of OR's
  `TransitionEngine` is staged, not half-done here.
- ADD `tests/Unit/Service/LocalStatusMachineryTest.php`, the class-catching
  scanner in the LocalParaferingRuntimeTest pattern: no retired status
  machinery class returns, and no NEW local transition table ships outside
  a closed, reason-bearing allowlist.

## The line drawn

OpenRegister owns transition sequencing that is static and enum-anchored
(`x-openregister-lifecycle` plus `TransitionEngine`) and everything
flow-, timer-, and task-shaped. dossiq keeps: the per-caseType dynamic
case machine (until OR supports FK-based status graphs, the ruling the
case schema itself records), the AWB beschikking machine
(`StateMachineService`, kept explicitly by termijnbewaking 2.2), the
ZGW/DRC document status canon (`InformatieobjectStatusLifecycle`), and
domain validation tables that agree with a declared schema lifecycle
while their thinning is staged. Every kept table is allowlisted in the
scanner with its reason, so the next local machine cannot ship unseen.

## What does not change

- `StatusTransitionService` and `lib/Service/Transitions/*` stay the single
  write path for `case.status`; no behaviour change.
- The CMMN runtime is owned by the open `retire-cmmn-caseplanstate`
  change and is untouched here.
- The WOO, bezwaar, DSO cron deadline engines are timer-shaped and stay
  staged in `termijnbewaking-op-engine-timers` phase 2.
- Tenant lifecycle machinery is owned by
  `tenancy-onto-openregister-organisation`.
- No frontend changes; the vergadering UI surface was already retired
  (`tests/e2e/spec-coverage/retired-surfaces.spec.ts` asserts it).
