# askPerson recovers a missed answer

## Why

openregister#3358 taught the flow engine's suspend heartbeat to re-read a
completed task instead of re-suspending forever. Before it, a refused or lost
completion signal wedged a run permanently: `resume_at` rolled 11:28 → 11:29 →
11:30 while the task sat `completed` in somebody's finished list.

On the rig, half of that fix was proven live — the parked node kept its slot and
no duplicate task appeared — and the other half never fired. The recovery lives
in OpenRegister's `UserTaskNode` / `PortalTaskNode` (`openregister.user-task`),
and the shipped dossiq case flow waits on `dossiq.askPerson`, whose `execute()`
advanced only on a signal and never re-read its task. The engine's recovery did
not reach the flow that motivated it.

## The decision: the node stays, and becomes honest

One-engine says an app contributes DOMAIN nodes, not a second task-waiting
mechanism, so retiring `dossiq.askPerson` onto `openregister.user-task` was the
preferred reading. It is not available yet, and the reason is not the node — it
is what the node's task IS.

**The two nodes create tasks in two different places.**
`openregister.user-task` creates an OpenRegister `Task` row through
`FlowTaskBridge` → `TaskService`: its own table, its own inbox, its own verbs.
`dossiq.askPerson` creates a dossiq CASE TASK — an ordinary object of this app's
`task` schema, carrying `case`, `checklist`, `blocksCase` and `workflowStepId`,
and read by the app all over:

| Capability askPerson has | Where it lives |
| --- | --- |
| The task is a case task, linked by `case` | `task` schema in `dossiq_register.json` |
| Shown on the case's Tasks tab, and openable as a task page | `src/components/tabs/CaseTasksTab.vue`, the task detail page |
| Counted in the work queue and the dashboards | `WorkQueueService`, `KpiAggregationService`, `MyTasksWidget`, `TaskRemindersWidget` |
| Moved by bulk reassignment and by substitution | `CaseReassignmentService`, `SubstitutedWorkResolver`, `BulkReassignModal` |
| Blocks a status transition until its checklist is ticked | `ChecklistGuard` |
| Created by the transition vocabulary too, in the same schema | `CreateTaskHandler`, `DossiqTxCreateTaskNode` |
| Seeded by the demo caseload | `DemoCaseloadGateway`, `demo_caseload_seed_data.json` |
| "A case is waiting on this task" on the task itself | `src/components/flow/TaskWaitingCaseSection.vue` |

Retiring the node moves its tasks out of that schema and every one of those
surfaces stops seeing them. That is not a node retirement, it is a migration of
this app's whole task store onto the engine's task service — a programme with
its own migration of stored objects, its own UI work, and its own answer for
in-flight runs. Doing it as a side effect of a recovery fix would trade a wedged
run for tasks nobody can find.

What askPerson genuinely has that the engine node lacks is therefore ONE thing:
its task is a dossiq case task. Everything else the engine node offers —
candidate pools and routing strategies, expiry with `onTimeout`/`onReject`,
outcome routing, forms, business timers, an advance budget — askPerson simply
does not have, and a step that wants them should be an
`openregister.user-task` today. Nothing here argues against the retirement; it
argues that the retirement is a task-store migration, and this change is not it.

So: **keep the node, and give it the recovery**, which is the fallback the
finding named. The node now re-reads the task its resume slot names on every
re-entry, exactly as `UserTaskNode` does, and terminality — a property of that
row — is what advances the run.

## What changes

- `DossiqAskPersonNode::execute()` re-reads its task on re-entry. `completed`
  advances the run whether or not a signal arrived; still-open re-suspends
  without touching the slot; `terminated` / `disabled` fail the step, because a
  withdrawn ask is neither an answer nor something to wait for; a task that no
  longer exists fails the step rather than waiting forever; an unreachable store
  buys one more heartbeat instead of being read as "gone".
- The answer written under `signalKey` is built from the TASK and the wake only
  decorates it. That also removes a race the node used to lose:
  `context.signal` is one slot per RUN, so a flow with two asks had the second
  read the answer given to the first.
- No flow definition changes and no repair step. The slot key (`taskId`) and the
  task schema are untouched, so an in-flight run parked on `dossiq.askPerson`
  keeps its slot and simply gains the recovery on its next heartbeat — including
  the runs that are wedged right now, which recover within one heartbeat of
  deploy with no operator action.

## Migration

Nothing to migrate. The shipped flows keep their `dossiq.askPerson` steps, the
stored flow definitions are unchanged, and the resume-slot shape is
backwards-compatible: `taskId` is read where it was written. A run currently
parked in the wedge is the change's first beneficiary rather than its casualty.

## Known gap left open

`dossiq.requestDecision` has the same shape of defect — it advances only on
`DecisionConcludedEvent` and cannot re-read what it asked for. It is NOT fixed
here because the fix needs something dossiq does not have:
`ContractDecisionDelegationService` can raise a decision and cannot read one
back, so there is nothing for a heartbeat to consult. Closing it needs a decidiq
read seam first, and inventing one inside a dossiq node would be the second
implementation this programme exists to avoid.

## Testing posture

The recovery is proven through the REAL engine, not a mocked seam.
openregister#3362 measured what the alternative is worth: 30 of 32 added
statements uncovered, because every recovery test mocked `FlowTaskBridge`. The
property that matters is not inside the node — it is what the engine hands a
parked node when it re-enters it on a timer.

`tests/bootstrap.php` can therefore register OpenRegister's own source and its
dependencies in place of this app's stubs, and does so ONLY for the suite that
asks (`DOSSIQ_REAL_FLOW_ENGINE=1`, in a separate process). Measured rather than
assumed: switching the whole suite over surfaces 48 stub-versus-real
disagreements, every one a genuine finding and none of them this change's
business. Two of them are fixed here because they were in reach and mechanical —
`FlowNodeRegistry` and `RegisterFlowNodesEvent` stubs that took no constructor
arguments while the real classes require them, so six call sites built them in a
way that fatals against a real OpenRegister and stayed green here.
