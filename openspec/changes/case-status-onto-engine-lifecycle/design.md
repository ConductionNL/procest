# Design: the status machinery inventory and its verdicts

## 1. The case status engine: duplication or domain?

`StatusTransitionService` plus `lib/Service/Transitions/*` (guards,
action handlers, `CaseStatusStore`, `TransitionAuthorizer`,
`TransitionSpecReader`, `SideEffectDispatcher`) is the single
deterministic write path for `case.status`. The REST API
(`StatusTransitionController`), the case detail UI, bulk transitions
(`BulkStatusTransitionService`), and the bezwaar/VTH listeners all funnel
through `execute()`.

Verdict: STAYS, with the line recorded. Three reasons, each verifiable in
the tree:

1. **OR cannot carry it yet.** `case.status` references a `statusType`
   object minted per installation and scoped per caseType; the machine is
   a per-caseType dynamic graph. OR's `x-openregister-lifecycle` is
   static and enum-anchored (`LifecycleAnnotationValidator` requires every
   `from`/`to`/`initial`/`final` to be a member of the field's enum). The
   case schema's own `status` property description records the ruling:
   "revert to readOnly + lifecycle transitions once OR supports FK-based
   status graphs". The object-form `initial` (`{from: caseType, field:
   initialStatus}`) is already consumed declaratively on create; the
   transitions are not expressible.
2. **The flow seam is single-implementation, so there is no parallel
   re-derivation.** The `DossiqTx*` flow nodes (setStatus, setField,
   notify, webhook, ...) are thin wrappers over the SAME
   `lib/Service/Transitions/` handlers `SideEffectDispatcher` fires; a
   flow-driven status change and a user-driven one run identical code.
   The "recompute overwrites its source" trap needs two derivers; here
   there is one.
3. **The retirement path already exists and is deliberately staged.**
   `workflow-definitions-to-flow` projects every workflowTemplate onto a
   disabled OR flow and its task 4 records why the template (and with it
   this sequencer's authoring source) cannot go yet: the projection is
   unadopted and the definition still carries per-step SLAs, checklists
   and roles the projection does not. Deleting or thinning the sequencer
   now would be the half-delete this change refuses.

`WorkflowEngineService` is the exception inside this cluster: a facade
re-exporting four method signatures over `StatusTransitionService` and
`WorkflowDefinitionService`, promised as "the public consumer entry
point" by the archived workflow-engine-enhancement change. Its only
reference in the tree is its own unit test. A dead second entry point to
a transition engine is exactly where a divergent call-path grows.
Verdict: DELETE.

## 2. The vergadering mini-engine: dead machinery

`VergaderingCaseService` carries a four-state machine
(`planned/lopend/completed/cancelled`) and `VergaderingDeadlineJob` runs
`checkDeadlines()` nightly. Evidence it can never do anything:

- `checkDeadlines()` filters the CASE schema on `status = 'planned'`, a
  literal string. `case.status` holds statusType UUID references
  (section 1); the filter can never match a case the app created.
- The only writer that ever produced literal-status vergadering cases,
  `createForVergadering()`, was already removed, with the removal note in
  the service itself: "a writer with no event to write on".
- `advanceStatus()` has no caller outside `checkDeadlines()`. There is no
  controller and no route; the frontend has none; the vergadering detail
  surface is retired and `retired-surfaces.spec.ts` asserts the route no
  longer renders.
- The vergadering deadline concern itself (agenda deadlines for
  besluitvorming) moved with the vergader machinery to decidiq
  (`migrate-committees-to-decidiq`, dossiq#1654 line of work).

Verdict: DELETE service, job, tests, and the info.xml registration.
`termijnbewaking-op-engine-timers` task 2.4 staged "timer per vergadering
deadline, listener drives the transition" on the assumption of a live
engine; there is nothing to migrate, so that task is amended to record
the retirement and point here. The `TimedJobDeadlineThresholdTest`
allowlist entry for the job shrinks with it, which is the direction that
test is built to enforce.

## 3. The declared-lifecycle drift: complaint

The complaint schema (both `dossiq_register.json` and
`dossiq_mock_register.json`) declares an `x-openregister-lifecycle` in
the legacy list dialect (`[{from, to, label}]`, no `field`, no
`initial`) with DUTCH state names (`ontvangen`, `ontvangst_bevestigd`,
`in_behandeling`, ...). Three sources disagree with it:

- the schema's own `status` enum is English (`received`,
  `receipt_confirmed`, `in_handling`, `hoorgesprek_planned`,
  `hoorgesprek_completed`, `handled`, `withdrawn`),
- `ComplaintService::TRANSITIONS` validates the English machine,
- stored values are English since the `RenameDutchValueDecisions` repair.

OR's `LifecycleAnnotationValidator` requires the object form and rejects
states outside the enum, so the declared machine is dead as written: two
machines over one object, one of them a corpse. The fix aligns the
declaration with reality: object-form dialect, `field: status`,
`initial: received`, `final: [handled, withdrawn]`, verb-keyed
transitions mirroring `ComplaintService::TRANSITIONS` state for state.

Thinning `ComplaintService::transitionStatus()` onto OR's
`TransitionEngine` endpoint is STAGED (tasks section 4), not done here:
it changes the controller's error surface and needs parity fixtures. The
same staging covers `ConsultationService::STATUS_TRANSITIONS`, which was
verified transition-for-transition to AGREE with its declared lifecycle
(pure re-derivation, the safe kind, but still a second copy).

## 4. The rest of the census, with verdicts

Every `const *TRANSITIONS` / `const VALID_STATUSES` under `lib/`:

| File | Verdict | Why |
| --- | --- | --- |
| `Service/StateMachineService.php` (beschikking) | domain, stays | AWB legal machine with content immutability and immutable stateMachineLog records; termijnbewaking 2.2 keeps it explicitly and moves only the cron trigger |
| `Service/Zaakdossier/InformatieobjectStatusLifecycle.php` + `Service/ZaakdossierService.php` re-export | domain canon, stays | ZGW/DRC-mandated document status vocabulary; also declared on the document schema lifecycle |
| `Service/ComplaintService.php` | agrees after this change; thinning staged | section 3 |
| `Service/ConsultationService.php` | agrees with declared lifecycle; thinning staged | section 3 |
| `Service/AdviceService.php` | stays; timer side is termijnbewaking 2.5 | membership check plus `AdviceAuthorizationGuard` (domain authz); declared `adviesAanvraag` lifecycle (receive/expire) agrees |
| `Service/Subsidie/SubsidieService.php` | domain, stays; declaring a schema lifecycle is recorded follow-up | subsidy process machine, no declared lifecycle yet |
| `Service/Bezwaar/AdvisoryCommitteeService.php` | owned by migrate-committees-to-decidiq | committee machinery leaves dossiq entirely |
| `Service/TenantSaasService.php` | owned by tenancy-onto-openregister-organisation | tenant lifecycle is not case machinery |
| `Service/Parafeer/ParaferingConclusionService.php` | stays | audit vocabulary of concluded outcomes; the runtime already lives in decidiq (dossiq#1666), guarded by LocalParaferingRuntimeTest |
| `Service/VergaderingCaseService.php` | DELETED | section 2 |

Out of the census but in the audit's scope: the CMMN
`PlanItemStateMachine`/`PlanItemTransitions` cluster is owned end-to-end
by the open `retire-cmmn-caseplanstate` change and untouched here; the
WOO/bezwaar/DSO cron status advances are timer-shaped and staged as
termijnbewaking 2.1-2.3, each explicitly required there to drive "the
SAME StatusTransitionService path a user action takes".

## 5. The scanner

`tests/Unit/Service/LocalStatusMachineryTest.php`, the
LocalParaferingRuntimeTest pattern:

1. RETIRED CLASSES: `WorkflowEngineService`, `VergaderingCaseService`,
   `VergaderingDeadlineJob` may not return as files under `lib/`.
2. CENSUS: every file declaring a transition-table constant
   (`const *TRANSITIONS =` or `const *VALID_STATUSES =`) must sit in a
   closed allowlist carrying the reason from section 4. A new file with
   such a constant fails the suite naming itself.
3. HONESTY: every allowlist entry must still exist and still match the
   detector, so the list cannot rot.

Lexical and per-file, like its siblings: a machine hidden behind
indirection passes it, and the per-surface unit tests carry the finer
assertions. It exists so the NEXT local status machine cannot ship
quietly.
