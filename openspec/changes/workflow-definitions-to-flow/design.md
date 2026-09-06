# Design: what the projection has to carry, and what the engine can hold

## Why this document exists

Task 4 of this change — retire `workflowTemplate` and collapse the two menu
entries — was deferred with a one-line reason: *the definition still carries
per-step SLAs, checklists and roles the projection does not.*

That reason is **out of date, and it was about the migrator rather than the
engine.** Measured against OpenRegister as it stands, every one of those three
has a home. What is missing is the projection, which today emits only status
nodes and drops `steps` entirely.

This records the mapping, field by field, against what was actually verified in
OpenRegister's source rather than assumed. It exists because the failure mode
here is specific and quiet: **a projection onto a model that cannot hold what
the source held does not fail, it forgets.** Enabling such a projection and
retiring the source is how a workflow silently loses its escalation ladder.

## What the projection emits today

`WorkflowTemplateFlowMigrator::graphOf()` reads `transitions` and nothing else.
Each distinct status becomes a `dossiq.setStatus` node; each transition becomes
an edge. `steps` is never read.

So the current flow is the state machine's *shape* and none of its *work*: no
task, no assignee, no checklist, no deadline, no guard, no action.

## The step contract, from the schema

Each `workflowStep` carries: `id`, `title`, `description`, `status`, `order`,
`routingRule {strategy, roleType, roleTypes, fallback}`, `isRequired`,
`checklist[]`, `automaticActions[]`, and a v1.1 `config` of
`{sla {value, unit}, requiredFields[], autoActions[], escalationRule {trigger,
offset, offsetUnit, notifyRole, escalateToRole, openIncident}}`.

## The mapping, verified against OpenRegister source

| Step field | Engine home | Verified where |
|---|---|---|
| `title`, `description` | `openregister.user-task` config | `UserTaskNode::configKeys()` |
| `routingRule.strategy` | `routingStrategy` | same |
| `routingRule.roleType` | `candidateRole` | same |
| `routingRule.fallback` | `routingFallback` | same |
| `isRequired`, `priority` | `priority`, `failOnReject` | same |
| `checklist[]` | `formFields` + `formRequireChecklist` | `TaskFormReader::CONFIG_KEYS` |
| `config.requiredFields[]` | form required fields | same |
| `config.sla` | **OPEN** — see below | `flow-business-timers` spec |
| `config.escalationRule` | **OPEN** — see below | same |
| `automaticActions[]` | action nodes | existing node catalogue |
| transition `guards[]` | router / switch nodes | existing node catalogue |

Two corrections to the obvious guess, and both matter:

1. **The config key is `formRequireChecklist`, not `requireChecklist`.** The
   `TaskFormReader` prefix is part of the key. A node written with the short
   name would validate as an unknown key and the checklist would simply not
   appear.

2. **`dueAt` is ADVISORY.** Its own help text says so: *"a date, a field like
   `{{ deadline }}`, or a relative time like `+3 days`. Advisory."* Projecting
   `config.sla` onto `dueAt` and calling the SLA carried would be the exact
   forgetting this document warns about — the date would show and nothing would
   escalate. The SLA belongs on a **business timer**, which is where the
   enforcing half lives.

## The SLA and escalation mapping is OPEN, and this is the blocker

The engine plainly has the CAPABILITY. `flow-business-timers` requires *an
advisory due date notifies; an enforcing expiry transitions*, *business time is
measured against ONE resolvable working calendar*, *each escalation rung fires
exactly once*, and *an escalation rule is validated against its SLA in
commensurable units*. That last one is dossiq's own rule, independently arrived
at: the step schema says *"requires sla present; preBreach offset must be <=
sla.value"*. Two models agreeing that closely is strong evidence this is a
translation rather than an approximation.

**What is not established is how a projection DECLARES one.** A business timer
is a runtime record armed against a subject, not a field in a flow document:
*"Timers are not columns on the subject; the subject's own advisory and
enforcing dates are a PROJECTION of the timers that bear on it."* And
`UserTaskNode::configKeys()` offers `dueAt` and `expiresAt` but **no reference
to an escalation ladder**.

So writing an SLA into the projected flow today would produce, at best, an
advisory date and no escalation — which is precisely the forgetting this
document opens by warning about. Two questions have to be answered in
OpenRegister before the projection is written, not after:

1. What arms a business timer when a user-task node runs — is it derived from
   `dueAt`/`expiresAt`, or armed by something else?
2. How does a node or flow name the `escalation-ladder` it escalates through?

### ANSWERED 2026-09-03, and the answer is "nothing does"

`FlowTimerService::arm(array $config, ...)` takes exactly what a dossiq step
holds: `sla {value, unit}`, `calendar`, `ladder`, `escalationRules`, plus
`purpose` (due|expiry), `legalEffect`, `onExpiry` and `extensionMax`, bound to a
`subjectType`/`subjectUuid` with optional `runUuid`/`nodeId`. Field for field,
the model fits.

**But `arm()` has NO production caller.** Measured across OpenRegister's whole
tree: every reference to `->arm(` lives in
`tests/Unit/Service/Flow/Timer/FlowTimerServiceTest.php`. Not `UserTaskNode`,
not the flow engine, not a listener, and there is no DI registration in
`AppInfo`. The capability is built, tested, and unwired.

So the SLA CANNOT be carried by writing flow JSON, because nothing reads an SLA
off a node and arms a timer. There are two ways it ever could:

- **OpenRegister wires it** — `UserTaskNode` arms a timer from its own config
  when the task opens, and cancels it on completion. This is the right home
  (ADR-065: the engine is OpenRegister's), and it is an upstream dependency.
- **dossiq arms it** — a listener calls `arm()` when a projected user task
  opens. That puts timer orchestration back in a leaf app, which is the thing
  this whole change exists to stop.

**Consequence for sequencing.** Step 1 below carries every other field and
leaves `config.sla` and `config.escalationRule` on the template. Step 4
(retiring `workflowTemplate`) therefore CANNOT complete until the timer is
wired upstream — retiring the only home of a per-step SLA while nothing else
can hold it is the exact forgetting this document opens by warning about.

Until those have answers, **step 1 can carry every other field and must leave
the SLA on the template.** A projection that silently drops the escalation
ladder while reporting success is worse than one that has not been written.

## Sequencing, and the one irreversible step

0. **Settle the two timer questions above with whoever owns OpenRegister's
   flow runtime.** Everything after this depends on the answer, and guessing it
   produces a migration that reports success and forgets.
1. Extend the projection to emit user-task nodes, guards and actions — and the
   timers and ladders once step 0 says how.
2. **Diff every live template against its projection and prove nothing is
   dropped.** This is the gate. Not "the migration reported success" — a
   field-by-field diff, because the whole failure mode is a projection that
   reports success while forgetting.
3. Cut `StatusTransitionService` over to the engine.
4. Retire `workflowTemplate` and collapse `WorkflowDefinitionsMenu` into
   `FlowsMenu`.

Only step 3 changes how a live case moves, and it is the one that cannot be
undone by re-running anything.

## Two tests that will go red, and should

- `changed-surfaces.spec.ts` asserts **every projected flow is disabled**. That
  is correct today and becomes wrong at step 3, for the same reason the LHS
  decision-table projection had to flip: once the flow is the engine, a disabled
  projection silently hands the work back to the old runtime. INVERT it, do not
  delete it.
- Anything asserting the `Workflow definitions` settings entry exists.

## What is NOT in scope

Authoring. Retiring `workflowTemplate` moves the *runtime* to flows; the flow
canvas is then the authoring surface. If the canvas cannot yet express a
per-step SLA an administrator can edit, step 4 waits — retiring the only way to
edit a live workflow is not a migration, it is a removal.
