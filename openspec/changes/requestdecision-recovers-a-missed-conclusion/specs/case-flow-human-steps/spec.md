# case-flow-human-steps Specification (delta)

**Status**: in progress
**Scope**: dossiq

## Purpose

A decision step advances on the state of the decision it raised, so a
conclusion whose announcement was missed or refused is delivered by the next
heartbeat instead of wedging the run forever.

## ADDED Requirements

### Requirement: A consumer can read back a decision it raised

`ContractDecisionDelegationService` SHALL be able to ask decidiq what became of
a Decision it raised, by dispatching decidiq's `DecisionStateRequestedEvent`
and reading the answer the listener writes back synchronously — the same
request/response-over-the-bus shape the raise already uses (ADR-041).

The read SHALL name the Nextcloud uid it is scoped to, and SHALL NOT be
dispatched with an empty one. decidiq refuses a read that names no identity
rather than treating it as a system caller, and an app that cannot name one has
nothing to ask.

The result SHALL distinguish six facts, because a caller acts differently on
each: the seam could not answer, the read was refused, no such decision exists,
the decision is still open, the decision was concluded with an outcome, and the
decision was withdrawn. In particular, an UNREADABLE seam SHALL NOT be reported
as a refusal or as a missing decision.

A status word this app does not recognise SHALL be reported as still open. It
can only come from a newer decidiq, and waiting through a vocabulary extension
costs a heartbeat while guessing that it means "decided" would advance a case on
an outcome nobody here can name.

This is NOT a second delivery mechanism. `DecisionConcludedEvent` remains how a
conclusion arrives; this is what a consumer consults when it did not.

#### Scenario: The read seam is not installed

- **GIVEN** an instance where decidiq's `DecisionStateRequestedEvent` class does not exist
- **WHEN** the delegation service is asked for a decision's state
- **THEN** it reports the state as unreadable, and never as a missing or refused decision

#### Scenario: A read naming no identity is not dispatched

- **GIVEN** a caller with no acting uid to name
- **WHEN** it asks the delegation service for a decision's state
- **THEN** no event is dispatched and the state is reported as unreadable

`@e2e exclude` an in-process cross-app event contract with no user-visible
surface of its own; pinned by ContractDecisionDelegationReadTest and end to end
through the real engine by tests/Unit/Flow/RequestDecisionHeartbeatRecoveryTest.php.

## MODIFIED Requirements

### Requirement: A decision step advances on its decision, not on a signal

`dossiq.requestDecision` SHALL raise exactly one decision on its first pass,
remember its ref in this node's resume slot, and on EVERY later pass read that
decision back before deciding anything. The decision's state, not the presence
of a signal, SHALL determine whether the run advances.

- A decision concluded with an outcome SHALL advance the run, whether or not an
  announcement arrived.
- A decision still open SHALL re-suspend the run WITHOUT touching the resume
  slot, so the remembered ref and the time it was asked survive every heartbeat.
- A decision that was WITHDRAWN SHALL fail the step. The question was taken off
  the table: continuing would move the case past a decision nobody made, and
  suspending would wait for an answer that can never come.
- A decision that no longer exists SHALL fail the step naming it, rather than
  waiting forever on a record that is gone.
- A read that is REFUSED SHALL fail the step. decidiq answered and would not
  report the decision to the identity this run raised it as, which is a
  misconfiguration to surface rather than a state to poll.
- A read that is UNREADABLE SHALL re-suspend rather than fail. An unreachable
  seam says nothing about the decision, and treating a hiccup as "gone" would
  fail a case whose decision is sitting there taken.

The system SHALL NOT raise a second decision on a re-entry, and SHALL NOT
restamp when the decision was asked.

#### Scenario: A heartbeat delivers a conclusion whose announcement never arrived

- **GIVEN** a run suspended on a decision that decidiq has since concluded, whose conclusion never reached the run
- **WHEN** the heartbeat wakes the run and no signal is in hand
- **THEN** the node re-reads the decision, finds it concluded, and the run advances with the outcome on its items

#### Scenario: A heartbeat with the decision still open parks again on the same decision

- **GIVEN** a run suspended on a decision decidiq has not concluded
- **WHEN** the heartbeat wakes the run
- **THEN** the run suspends again on the same ref, no second decision is raised, and the asked-at time is unchanged

#### Scenario: A withdrawn decision fails the step

- **GIVEN** a run suspended on a decision decidiq reports as withdrawn
- **WHEN** the run next re-enters the step
- **THEN** the step fails naming the decision, and the run neither advances nor waits on

#### Scenario: An unreadable seam buys another heartbeat

- **GIVEN** a run suspended on a decision, and a decidiq that cannot answer the read
- **WHEN** the heartbeat wakes the run
- **THEN** the run suspends again rather than failing, and the decision is read again on the next heartbeat

`@e2e exclude` a suspend/resume timing path with no user-visible surface of its
own; pinned end to end through the real engine by
tests/Unit/Flow/RequestDecisionHeartbeatRecoveryTest.php.

### Requirement: The decision decides the outcome and the wake decorates it

The outcome `dossiq.requestDecision` writes onto every item under its
`signalKey` SHALL be derived from what decidiq reported — its status, the
decision ref, this node's id, when it was decided and whether it was signed —
and SHALL record whether it was delivered by a wake or recovered by a heartbeat.
A signal payload MAY contribute fields the read does not carry, and SHALL NOT
override the fields the decision decides.

A signal SHALL NOT be able to answer for a decision that is still open. The run
holds ONE signal slot, so a flow with two decisions would otherwise have the
second read the answer given to the first.

#### Scenario: A signal cannot answer for an open decision

- **GIVEN** a run suspended on a decision decidiq has not concluded
- **WHEN** a signal carrying a decision reaches the run
- **THEN** the node suspends again, because decidiq says the question is unanswered

#### Scenario: The announced and recovered paths agree

- **GIVEN** two runs on the same decision, one advanced by the announcement and one recovered by a heartbeat
- **THEN** both carry the same decision, status, ref and node under the step's key, differing only in whether it was recovered

`@e2e exclude` the shape of a value passed between flow steps; pinned by
RequestDecisionHeartbeatRecoveryTest and DossiqRequestDecisionNodeTest.

### Requirement: A decision step names the identity it raised the decision as

`dossiq.requestDecision` SHALL record the run's acting identity in its resume
slot when it raises a decision, and SHALL scope its read back to that identity.
decidiq stamps a Decision's owner from the uid that created it, so a read naming
any other uid is answered "not permitted".

A run that names no acting identity SHALL re-suspend and log, rather than
dispatching a read decidiq would refuse or inventing a system caller. A run
parked BEFORE this behaviour shipped, whose slot therefore records no identity,
SHALL fall back to the run's current acting identity, so it gains the recovery
on its next heartbeat without a repair step.

#### Scenario: A run parked before the change recovers without a repair

- **GIVEN** a run suspended on a decision whose resume slot records a ref but no raising identity
- **WHEN** the heartbeat wakes the run
- **THEN** the node reads the decision back as the run's current acting identity and recovers

`@e2e exclude` an authorization-scoping detail of an in-process read with no
user-visible surface; pinned by RequestDecisionHeartbeatRecoveryTest.
