# case-flow-human-steps Specification (delta)

**Status**: in progress
**Scope**: dossiq

## Purpose

Flow-facing storage work relies on the engine's native runAs scoping; the
local wrapper retires.

## MODIFIED Requirements

### Requirement: Flow storage work runs under the engine's native scoping

Flow nodes and the transition/action handlers they delegate to SHALL perform
their storage work bare: on the flow path the engine's
`RegistryStepDispatcher` executes every contributed node inside
`ObjectService::runAs()` as the run's validated acting identity
(openregister#3332), and on the interactive path the ambient session user
answers the permission checks. The system SHALL NOT keep a local runAs
wrapper, and no flow-facing file may wrap its storage work in one — a manual
wrap re-creates the per-consumer copy of an engine rule and nests a second
scope inside the dispatcher's.

`lib/Service/FlowRunAsScope.php` SHALL stay deleted.

#### Scenario: No flow file wraps runAs manually

- **GIVEN** the flow-facing directories (lib/Flow, lib/Service/Transitions, lib/Service/Actions)
- **WHEN** the structural sweep runs
- **THEN** no file references the retired wrapper, storage-performing files still exist (the detector self-check), and the wrapper file itself is absent

`@e2e exclude` a structural source sweep, not a user journey; pinned by the
inverted FlowStorageRunsAsTheRunsIdentityTest.

#### Scenario: A worker-driven flow write acts as the run's identity

- **GIVEN** a flow run whose runAs names an enabled account, executing under FlowRunWorker
- **WHEN** a dossiq node or handler performs storage work
- **THEN** the write happens under that identity, scoped by the dispatcher, with no dossiq wrap involved

`@e2e case-flow-live-journeys.spec.ts` exercises the seeded case flow under
the worker end to end; the scoping mechanism is OpenRegister's
(RegistryStepDispatcherRunAsTest).
