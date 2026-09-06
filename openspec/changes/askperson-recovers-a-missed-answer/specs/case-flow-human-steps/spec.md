# case-flow-human-steps Specification (delta)

**Status**: in progress
**Scope**: dossiq

## Purpose

A human step advances on the state of the task it created, so a completion
whose wake was refused or lost is delivered by the next heartbeat instead of
wedging the run forever.

## MODIFIED Requirements

### Requirement: An ask advances on its task, not on a signal

`dossiq.askPerson` SHALL create exactly one task on its first pass, remember it
in this node's resume slot, and on EVERY later pass read that task back before
deciding anything. The task's status, not the presence of a signal, SHALL
determine whether the run advances.

- A task at `completed` SHALL advance the run, whether or not a wake arrived.
- A task at any other non-terminal status SHALL re-suspend the run WITHOUT
  touching the resume slot, so the remembered task and the time it was asked
  survive every heartbeat.
- A task at `terminated` or `disabled` SHALL fail the step. The ask was
  withdrawn: continuing would move the case past a question nobody answered,
  and suspending would wait for an answer that can never come.
- A task that no longer exists SHALL fail the step naming it, rather than
  waiting forever on a row that is gone.
- A read that FAILS — an unreachable or unconfigured store — SHALL re-suspend
  rather than fail. A missing row and an unreadable store are different facts,
  and treating a hiccup as "gone" would fail a case whose task is sitting there
  answered.

The system SHALL NOT create a second task on a re-entry, and SHALL NOT restamp
when the task was asked.

#### Scenario: A heartbeat delivers a completion whose signal was refused

- **GIVEN** a run suspended on an ask, whose task's completion signal the engine's assignee guard refused
- **WHEN** the heartbeat wakes the run and no signal is in hand
- **THEN** the node re-reads the task, finds it completed, and the run advances with the answer on its items

#### Scenario: A heartbeat with the task still open parks again on the same task

- **GIVEN** a run suspended on an ask whose task is still open
- **WHEN** the heartbeat wakes the run
- **THEN** the run suspends again on the same task, no second task is created, and the asked-at time is unchanged

#### Scenario: Only the node whose task was answered advances

- **GIVEN** a run parked on two asks and only the first task completed
- **WHEN** the heartbeat wakes the run
- **THEN** the answered node advances and its slot is consumed, while the other keeps waiting on its own task

#### Scenario: A withdrawn ask fails the step

- **GIVEN** a run suspended on an ask whose task was terminated
- **WHEN** the run next re-enters the step
- **THEN** the step fails naming the task, and the run neither advances nor waits on

`@e2e exclude` a suspend/resume timing path with no user-visible surface of its
own; pinned end to end through the real engine by
tests/Unit/Flow/AskPersonHeartbeatRecoveryTest.php.

### Requirement: The task decides the answer and the wake decorates it

The answer `dossiq.askPerson` writes onto every item under its `signalKey`
SHALL be derived from the task row — its status, its id, this node's id, and
when it was completed — and SHALL record whether it was delivered by a wake or
recovered by a heartbeat. A signal payload MAY contribute fields the row does
not carry, such as who completed the task, and SHALL NOT override the fields
the row decides.

A signal SHALL NOT be able to answer for a task that is still open. The run
holds ONE signal slot, so a flow with two asks would otherwise have the second
read the answer given to the first.

#### Scenario: A signal cannot answer for an open task

- **GIVEN** a run suspended on an ask whose task is still open
- **WHEN** a signal carrying a decision reaches the run
- **THEN** the node suspends again, because the row says the question is unanswered

#### Scenario: The delivered and recovered paths agree

- **GIVEN** two runs on the same ask, one answered through the guarded wake and one recovered by a heartbeat
- **THEN** both carry the same decision, status, task id and node under the step's key, differing only in who answered and whether it was recovered

`@e2e exclude` the shape of a value passed between flow steps; pinned by
AskPersonHeartbeatRecoveryTest and DossiqAskPersonNodeTest.

## ADDED Requirements

### Requirement: A flow-engine test may drive the real engine

The unit suite SHALL be able to run a test against OpenRegister's real flow
engine — its own source and its composer dependencies — when that app is
checked out beside this one, and SHALL do so only for the suites that ask for
it, in a separate process. Every other suite keeps the stubs.

A suite that asks for the real engine and does not get it SHALL NOT pass. On a
developer machine it reports as skipped, naming what is missing; under CI, where
the sibling checkout is part of the job, it FAILS — a skip there would be the
instrument lying about the thing it exists to measure.

A stub of an OpenRegister class SHALL declare the same constructor as the real
class. A stub that is easier to build than the thing it stands for teaches the
suite a shape that fatals in production.

#### Scenario: The real engine is absent under CI

- **GIVEN** a CI run whose OpenRegister checkout or install did not complete
- **WHEN** the real-engine suite starts
- **THEN** it fails, naming the missing checkout, rather than skipping

`@e2e exclude` test-infrastructure behaviour with no runtime surface.
