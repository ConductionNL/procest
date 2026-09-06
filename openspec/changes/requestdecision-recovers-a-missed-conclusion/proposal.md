# requestDecision recovers a missed conclusion

## Why

dossiq#1756 closed this wedge for `dossiq.askPerson` and left it open here, and
said so:

> `dossiq.requestDecision` has the same shape of defect. It is NOT fixed here
> because the fix needs something dossiq does not have:
> `ContractDecisionDelegationService` can raise a decision and cannot read one
> back, so there is nothing for a heartbeat to consult.

The node raises a decision in decidiq and suspends with a resume time as a
safety net. That safety net was a timer that could do nothing but suspend again:
`execute()` advanced only when a signal carrying a `decision` arrived, and the
signal is delivered by `DecisionConcludedListener` reacting to decidiq's
announcement. A conclusion whose announcement was missed — the listener threw,
the app was mid-upgrade, the run had already been resumed by something else, the
delivery was refused — therefore wedged the run PERMANENTLY, with `resume_at`
rolling forward while the Decision sat concluded in decidiq.

decidiq#1118 has now shipped the missing half: `DecisionStateRequestedEvent` +
`DecisionStateRequestedListener`, a synchronous request/response over the event
bus in the same ADR-041 shape `DecisionRequestedEvent` already uses. So there is
now something for a heartbeat to consult, and this change consults it.

## What changes

- `ContractDecisionDelegationService::readDecisionState()` — the READ half of
  the seam this service already owns the raise half of. It dispatches decidiq's
  read event and reports one of six states: `decided`, `open`, `withdrawn`,
  `gone`, `refused`, `unreadable`. Six rather than a boolean, because a caller
  acts differently on each and collapsing them is how an unreachable
  OpenRegister comes to read as a vanished decision.
- `DossiqRequestDecisionNode::execute()` re-reads the decision its resume slot
  names on every re-entry. Concluded advances the run whether or not an
  announcement arrived; still open re-suspends **without touching the slot**;
  withdrawn fails the step, because a question taken off the table is neither an
  answer nor something to wait for; a vanished record fails it rather than
  waiting forever; a refused read fails it, because no number of heartbeats
  fixes an authorization mistake; an unreadable seam buys one more heartbeat
  instead of being read as "gone".
- The outcome written under `signalKey` is built from what decidiq REPORTED and
  the wake only decorates it. That also removes a race the node used to lose:
  `context.signal` is one slot per RUN, so a flow with two decisions had the
  second read the answer given to the first.

## Naming the identity, which the seam requires

The bus carries no session, and the heartbeat that motivates this runs under the
cron worker where `IUserSession` holds nobody. decidiq therefore scopes the read
to the uid the event names and REFUSES an event naming none — deliberately, so a
consumer cannot read back decisions its own runs never raised.

So the node records the run's acting identity (`FlowRunService::RUN_AS_CONTEXT_KEY`,
which the engine stamps into every node context) into its resume slot as
`raisedBy` at raise time, and reads back as that identity. Recorded rather than
taken fresh, because decidiq stamps a Decision's owner from the uid that created
it: a run whose `runAs` changed after it parked would otherwise start being
refused the decision it raised itself. That mirrors what askPerson does with the
rendered `assignee`, and for the same reason.

A slot with no `raisedBy` — every run parked before this deploys — falls back to
the run's current acting identity, which is the same uid in every case that is
not the pathological one, so an in-flight run recovers with no repair step.

## Fail closed is not fail on silence

The node's fail-closed posture is unchanged and is worth restating, because this
change makes the distinction load-bearing. Failing closed means the run never
advances past a decision nobody made. It does NOT mean failing a run because the
reader was briefly unavailable. So a raise that cannot reach decidiq still fails
the step, a refusal fails the step, and an unreadable seam suspends.

## Migration

**Nothing to migrate.** The node id, the resume-slot key (`decisionRef`), the
decision types and every shipped `dossiq.requestDecision` step are unchanged, so
no flow definition moves, no stored definition is rewritten, and no repair step
is needed. An in-flight run parked on the node keeps its slot and gains the
recovery on its next heartbeat — **a run wedged today recovers within one
heartbeat of deploy, with no operator action.**

One behaviour changes for a case that was already broken: a WITHDRAWN decision
used to advance the run carrying `decision: withdrawn`, on the announcement path
only, leaving every downstream branch to notice. It now fails the step. A
withdrawn decision is not an answer, and a case that carried on past one
proceeded as though somebody had decided it.

## Tests that pin the behaviour, not the mock

`tests/Unit/Flow/RequestDecisionHeartbeatRecoveryTest.php` drives the **real
engine** — real `FlowRunService`, `FlowEngine`, `FlowNodeRegistry`,
`RegistryStepDispatcher`, stream walk, claims and commit path over in-memory
mappers — with the real `ContractDecisionDelegationService` dispatching into an
in-memory decidiq that answers the real event contract, including its
authorization scoping. openregister#3362 measured what the alternative is worth:
30 of 32 added statements uncovered, because every recovery test mocked the
seam it was meant to exercise. A mocked bridge cannot catch this class of defect
at all.

Verified NEGATIVELY: restoring the signal-only behaviour reds the recovery
tests.

The suite refuses to pretend: without the real engine it reports as skipped on a
developer machine, and **fails under CI**, where the sibling checkout is part of
the PHPUnit job.
