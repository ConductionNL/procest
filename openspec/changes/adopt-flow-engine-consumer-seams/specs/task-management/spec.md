# task-management Specification (delta)

**Status**: in progress
**Scope**: dossiq

## Purpose

The task-completion resume travels through the engine's guarded signal seam.

## MODIFIED Requirements

### Requirement: A completed task resumes its run through the guarded seam

When a flow task transitions to completed, the system SHALL resume the run it
names via `FlowRunSignalService::signalAs()`, handing the seam the session
user as the actor and the task's node as the addressed node. The system SHALL
NOT consult the assignee rule itself and SHALL NOT call the unguarded
`FlowRunService::signal()` primitive.

A `FlowSignalRefused` SHALL be obeyed: the task stays completed, the run is
not advanced, nothing is retried. A NOT_ASSIGNEE refusal is recorded as a
warning tying the engine's audit to the task; RUN_NOT_FOUND and NOT_SUSPENDED
are recorded quietly, because a task naming a vanished or already-advanced
run is completable and its completer did nothing wrong.

#### Scenario: Completing a flow task resumes its run

- **GIVEN** a suspended run awaiting a task
- **WHEN** the task transitions to completed
- **THEN** the run is signalled through `signalAs` with the completer as actor and the task's node addressed

`@e2e case-flow-live-journeys.spec.ts` drives the live task-completion resume;
the seam call shape is pinned by TaskCompletionResumeListenerTest.

#### Scenario: A refusal from the seam withholds the resume

- **GIVEN** a completed task whose completer is not the awaiting step's assignee
- **WHEN** the seam refuses with NOT_ASSIGNEE
- **THEN** the run is not advanced and the refusal is recorded as a warning

`@e2e exclude` the guard itself is OpenRegister's, mutation-tested there; the
listener's obedience is unit-pinned (TaskCompletionResumeListenerTest).

#### Scenario: A task whose run has gone still completes quietly

- **GIVEN** a completed task naming a run uuid the engine cannot resolve
- **WHEN** the seam refuses with RUN_NOT_FOUND
- **THEN** the completion stands and the refusal is recorded as information, not an error

`@e2e exclude` requires deleting a run out from under a task mid-journey;
unit-pinned (TaskCompletionResumeListenerTest).
