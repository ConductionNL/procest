## 1. Schema

- [x] 1.1 Add `flowRun` and `flowNode` to the `task` schema in `lib/Settings/dossiq_register.json`; verify a task round-trips both through the object store and that existing tasks (both null) still save
- [x] 1.2 Add the six status types and the `omgevingsvergunning-kleinbouw` case type to the seed data; verify the seed imports and the status types resolve from the case type

## 2. The flow declaration

- [x] 2.1 Declare the `case-behandeling` flow as `x-openregister-flows` on the `case` schema: trigger on create, status steps, completeness switch, applicant-task loop, two decisions, employee task, commission decision, document, close; verify it imports into the flow store
  - Shipped under the display name `Case behandeling` (the importer keys on `name`; no slug field). Substance matches the task: `object.created` trigger, five `dossiq.setStatus` steps, `check-complete` switch, capped applicant loop, three `dossiq.requestDecision` steps (register B, tweede toets, commissie), `task-behandelaar`, `besluit-document`, close.
  - The shipped `besluit-document` config (`template`/`outputName`) was one its node class refuses: `DossiqMergeTemplateNode` requires `templateSlug` + `targetField`, so every run stranded at the document step. Fixed to the required keys, writing to the new `case.besluitDocument` property; pinned by `CaseFlowDeclarationTest::testTheDocumentStepConfigMatchesItsNodeAndItsSchema`.
- [x] 2.2 Verify the shipped flow arrives DISABLED and ownerless, and that re-importing the register updates it rather than creating a second copy
  - e2e `the shipped flow imports, and imports INERT` asserts disabled + ownerless, and that the instance (which re-imports on every upgrade) holds exactly one copy.
- [x] 2.3 Verify the completeness loop's three exits (complete / under cap / at cap) and that the at-cap exit moves the case to `gestrand` and ends the run without hitting the engine's transition ceiling
  - `CaseFlowDeclarationTest`: unconditional exit to `status-gestrand` → `end-gestrand`, counter incremented inside the loop, cap condition `< 3`, `maxTransitions: 200` never reached.

## 3. Human steps

- [x] 3.1 Make the applicant-ask step create a `task` stamped with the run and node, then suspend; verify the task names the case and what is missing, and the run's status is suspended
- [x] 3.2 Implement task completion → `FlowRunService::signal()` in a `TaskCompletionService`; verify the run resumes at the node that asked and the outcome reaches the following steps
  - Shipped as `lib/Listener/TaskCompletionResumeListener.php` rather than a service: tasks are generic OpenRegister objects, so completion is an object update and the listener is the only seam that sees it. The intent (completion resumes the run at the asking node, with the outcome in the payload) is met and tested.
- [x] 3.3 🔴 Verify the AUTHORIZATION refusal: a user who is neither the assignee nor in the assigned group cannot complete the task, and therefore cannot resume the run — this test must fail if the check is removed, since the direct service call bypasses `refuseUnlessAssignee()`
  - Mutation-checked 2026-08-31: guard replaced with `if (false)` → `testARefusalFromTheAssigneeRuleWithholdsTheResume` red (mallory's completion resumed the run); restored → green; `git diff` empty.
- [x] 3.4 Verify the three degenerate completions: a task with no run resumes nothing and raises no error, completing twice resumes once, and a task whose run no longer exists still completes

## 4. Decisions via decidiq

- [x] 4.1 Add `DossiqRequestDecisionNode` raising the decision through `AdviceDelegationService`, storing the `decisionRef` and suspending; verify the run suspends and the ref is on the case
  - Drift: the ref lives in the run's per-node resume slot (the correlation key the resume matches on) and on the delegation record, not as a case property. That is where the resume path reads it, so the slot is the load-bearing home.
- [x] 4.2 Resume the waiting run from `DecisionConcludedListener`, matched on `decisionRef`; verify the concluded decision resumes it and an UNRELATED decision leaves it suspended
- [x] 4.3 Verify the fail-closed path: with decidiq unavailable the step fails and the run does not advance past the decision

## 5. Outcome

- [x] 5.1 Generate the decision document from the template and attach it to the case after final approval; verify it is attached before the case reaches its final status
  - `besluit-document` renders the inline template into `case.besluitDocument`; `testTheCaseIsNotClosedBeforeItsDocumentIsMade` pins that it sits before `status-afgehandeld` on every closing path.
- [x] 5.2 Verify a failed generation leaves the case OPEN with the failure recorded, and that a rejection closes the case with a rejection document
  - A failed merge throws (`testFailedActionThrowsRatherThanPassingThrough`), so the run stops before the closing status and the failure is on the run. Drift: rejection takes the same document step — the rendered besluit carries the commission's outcome (`{{case.commissieBesluit.decision}}`), so a rejected case closes with a document that says so, rather than with a second template.

## 6. Frontend

- [x] 6.1 Show both halves of the waiting relationship — on a task, that a case is waiting on it (linking to the case); on the case detail, its flow run and current stage; verify a non-flow task and a case with no run both render unchanged and without error
  - Task half DONE: `TaskWaitingCaseSection` on the manifest TaskDetail page names the waiting case and links to it; renders nothing for a task without a `flowRun` (pinned by `tests/vitest/flowTaskHelpers.spec.js`), so pre-existing tasks are unchanged.
  - Case half DONE: the `case-flow-runs` widget (`type: flow-runs`, `subject: '@objectId'`) on the manifest CaseDetail page shows the case's live run with its current step, and its finished runs underneath (nextcloud-vue 2.28.0 `CnFlowRunsWidget` in subject mode, over `GET /api/flow-runs/{active,completed}?subject=`). A case with no run reads "No flows have run yet" (its own state, distinct from "nothing running now"); the seeded incomplete case with a run lists that run and no other case's. Rows link to FlowDetail via `rowRoute` (dossiq has no run detail page; FlowDetail resolves a flow id, so `runRoute` would have opened nothing).
  - @e2e pending proof rig: `tests/e2e/case-detail-flow-runs.spec.ts` asserts the widget renders, the subject scoping and the never-ran empty state. Not run yet: the only local instance mounts another checkout as dossiq and never serves this bundle, so a green from it would prove nothing about this change.

## 7. End to end

- [x] 7.1 Playwright journey over the incomplete case: intake → applicant task → complete it → the case advances and its status changes; verify against the status the applicant sees, not against internal state
  - `tests/e2e/case-flow-human-steps.spec.ts` asserts what the applicant reads on the seeded incomplete case (`Ontvangen` / `Wacht op aanvulling`). The complete-and-advance half cannot run against a shared instance: the flow ships DISABLED by spec (2.2), and adopting it there would run it on every case anyone creates. It belongs to a disposable-instance suite where an operator enables the flow first.
- [ ] 7.2 Playwright journey over the happy path: complete case → two decisions → employee task → commission approval → document attached → case closed
  - Same constraint as 7.1's second half, plus a live decidiq round-trip per decision. The structural halves are pinned (`a complete case is not asked for anything` e2e; decision resume, fail-closed and document-before-close unit tests); the live click-through needs a dedicated instance with the flow adopted.
- [x] 7.3 Verify the traceability read: after the journey, the run reports the objects it touched grouped by node, and the case's history names the node that moved each status
  - e2e: `GET /api/flow-runs/{uuid}/objects` answers with `run` + `nodes[]`, and the endpoint 404s identically for a run that does not exist.

## 8. Quality

- [x] 8.1 Run `composer check:strict` and `npm run lint`, fixing pre-existing findings in the files touched
- [x] 8.2 Mutation-check the authorization guard from 3.3: remove it, confirm the suite goes red, restore, confirm the source is byte-identical
  - Also mutation-checked the 4.2 `decisionRef` match: `awaitsDecision` forced true → the unrelated-decision test red (2 failures); restored → green, diff empty.

**Acceptance criteria**

- A case created with a missing field produces a task for the applicant and a suspended run; completing the task advances the case.
- No decision is ever inferred: decidiq unavailable means the run stops, not that it proceeds.
- A case never reaches its final status without its decision document attached.
- Only the assignee (or assigned group) can complete a task and thereby resume a run.
- Existing tasks, which carry no run, behave exactly as before.
- i18n: new user-facing strings go through `t()`; Dutch keys present for the applicant-facing text.
