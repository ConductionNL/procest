# termijnbewaking-op-engine-timers Specification

**Status:** proposed (phase 1 implemented)
**Scope:** dossiq
**Tier:** V1
**Depends on:** OpenRegister `flow-business-timers` (merged on openregister `development`):
`OCA\OpenRegister\Service\Flow\Timer\FlowTimerService`, `FlowTimerSweep`, the seeded
`nl-termijn-default` ladder and `nl-national` working calendar, `OCA\OpenRegister\Event\FlowTimerFiredEvent`.

## Purpose

One clock. The AWB termijnbewaking keeps its domain rules and its case data in dossiq, but the
measuring of time — durable firing, escalation rungs, opschorting arithmetic, catch-up after
downtime — is done by armed OpenRegister FlowTimers instead of app-local cron sweeps.

## ADDED Requirements

### Requirement: REQ-TOT-001 — Every active beslistermijn is an armed FlowTimer

dossiq SHALL arm exactly one OpenRegister FlowTimer per active TermijnInstance
(subjectType `object`, purpose `due`, legalEffect `wettelijk`, SLA in `calendarDays`, ladder
`nl-termijn-default`, anchored at the instance's `startDate`), store the timer uuid on the
instance as `engineTimerId`, and cancel every open timer of the instance when the term completes.
The timer SHALL NOT carry an enforcing outcome: reaching the beslistermijn never transitions the
case.

#### Scenario: Creating a termijn arms a timer

- **GIVEN** a zaaktype with an active TermijnDefinitie of 56 days
- **WHEN** a TermijnInstance is created for a case
- **THEN** a FlowTimer MUST be armed with a 56 `calendarDays` SLA anchored at the start date
- **AND** the instance MUST carry the timer uuid as `engineTimerId`
- @e2e exclude covered by TermijnTimerService unit tests over the engine stub

#### Scenario: Completing the term cancels its timers

- **GIVEN** an instance with an armed beslistermijn timer
- **WHEN** the term is marked completed
- **THEN** every open timer of the instance MUST be cancelled with a recorded reason
- @e2e exclude covered by TermijnService unit tests over the engine stub

### Requirement: REQ-TOT-002 — Opschorting maps onto engine suspend and resume

`registerPauze()` SHALL suspend the beslistermijn timer (basis `Awb 4:5`, the rationale as the
recorded reason) and `resumeAfterPauze()` SHALL resume it, while the instance's own
`endDateCurrent` arithmetic (extend by the pause, pull back the unused days) stays unchanged as
case data. The two arithmetics SHALL agree: after a pause of which C days were consumed, both the
instance's `endDateCurrent` and the engine's re-projected fire moment equal the pre-pause
deadline plus C days.

#### Scenario: Pause and resume keep both clocks equal

- **GIVEN** a 56-day term started 1 June, paused for 14 days, resumed after 7
- **WHEN** both the case data and the engine mapping are computed
- **THEN** `endDateCurrent` MUST be 3 August
- **AND** the engine resume MUST have been called so its re-projection lands on the same date
- @e2e exclude covered by the opschorting fixture-pair unit test

#### Scenario: The hersteltermijn itself is watched

- **GIVEN** a paused instance whose pause deadline passes without an aanvulling
- **WHEN** the helper timer's rung fires
- **THEN** a `pause-expired` TermijnGebeurtenis (basis `AWB 4:5`) MUST be recorded
- **AND** a fire arriving after the instance already resumed MUST be ignored
- @e2e exclude covered by TermijnTimerFiredListener unit tests

### Requirement: REQ-TOT-003 — Escalation is the engine ladder, bookkeeping stays case data

Threshold escalation SHALL be driven by `FlowTimerFiredEvent` rung fires from the seeded
`nl-termijn-default` ladder (14/7/2/0), not by an app-local sweep. The listener SHALL map the
rung messages `termijn-14d`/`termijn-7d`/`termijn-2d`/`termijn-overschreden` to the existing
threshold buckets and route them through `DeadlineEscalationService::notifyThreshold()`, so the
instance's `notificatiesVerstuurd` list remains the domain dedup source and the case data stays
readable exactly as today. The `slaBreached:0` rung SHALL flip the instance to `exceeded` and
record the `exceeded` event (basis `AWB 4:13`); a rung for an instance already terminal SHALL be
ignored.

#### Scenario: A rung fire dispatches one escalation

- **GIVEN** an armed dossiq termijn timer whose 7-day rung fires
- **WHEN** the listener handles the event
- **THEN** threshold 7 MUST be dispatched once and recorded in `notificatiesVerstuurd`
- **AND** a second fire of the same rung MUST dispatch nothing
- @e2e exclude covered by TermijnTimerFiredListener unit tests

#### Scenario: The breach rung flips the instance

- **GIVEN** a `lopend` instance whose `slaBreached:0` rung fires
- **WHEN** the listener handles the event
- **THEN** the instance status MUST become `exceeded`
- **AND** an `exceeded` TermijnGebeurtenis MUST be recorded with basis `AWB 4:13`
- @e2e exclude covered by TermijnTimerFiredListener unit tests

### Requirement: REQ-TOT-004 — No dossiq TimedJob computes deadline thresholds

dossiq SHALL NOT ship a TimedJob that computes deadline thresholds, days-left buckets or overdue
state; the engine sweep owns firing. `DailyDeadlineScanJob` and `DeadlineDailyScanService` are
retired. A structural test SHALL enforce this against every job under `lib/BackgroundJob/`, with
a reason-bearing allowlist naming, per remaining engine, the phase and task that retires it; the
allowlist SHALL shrink to empty as phases 2 to 4 land.

#### Scenario: The daily scan is gone

- **GIVEN** the dossiq source tree
- **WHEN** the structural test runs
- **THEN** `DailyDeadlineScanJob` MUST NOT exist and MUST NOT be registered in info.xml
- @e2e exclude covered by the architecture unit test

#### Scenario: A new threshold-computing job is refused

- **GIVEN** a TimedJob source containing deadline-threshold computation and no allowlist entry
- **WHEN** the structural test runs
- **THEN** it MUST fail naming the file and instructing the author to arm a FlowTimer
- @e2e exclude covered by the architecture unit test's self-check fixtures

### Requirement: REQ-TOT-005 — Dwangsom accrual is a derivation, settled at decision time

Dwangsom accrual (AWB 4:17 tiers, plafond) SHALL stay dossiq domain arithmetic and SHALL be
computed as a derivation from the berekening's `startDate` and the clock
(`accrueThrough()`), idempotent per day and catch-up-safe, rather than advanced by cron ticks.
`stopForBeschikking()` SHALL settle the accrual through the stop moment before locking
`definitiveAmount`. The per-day tier arithmetic SHALL be unchanged: the derived value at day N
equals N applications of the legacy daily step.

#### Scenario: Catch-up equals the legacy daily series

- **GIVEN** a lopend berekening whose startDate lies 5 days in the past
- **WHEN** `accrueThrough()` runs once
- **THEN** `currentDag` MUST be 5 and `cumulativeAmount` MUST equal five daily tier-1 tariffs
- @e2e exclude covered by the dwangsom fixture-pair unit test

#### Scenario: The decision settles the exact amount

- **GIVEN** a lopend berekening that no fire has synced for days
- **WHEN** the beschikking stops it
- **THEN** `definitiveAmount` MUST reflect the accrual through the stop moment, not the last sync
- @e2e exclude covered by DwangsomCalculationService unit tests

### Requirement: REQ-TOT-006 — In-flight terms are migrated onto timers exactly once

A repair step SHALL arm timers for existing TermijnInstances that are `lopend`, `verlengd` or
`paused` and carry no `engineTimerId`, using `days(startDate → endDateCurrent)` as the SLA so the
engine fire moment equals the current deadline including past pauses and extensions; `paused`
rows SHALL be suspended immediately after arming. The step SHALL be idempotent, SHALL skip
terminal instances, and SHALL NOT cause duplicate escalations: catch-up rung fires are absorbed
by the `notificatiesVerstuurd` dedup.

#### Scenario: A verlengd instance arms at its current deadline

- **GIVEN** an in-flight instance extended to a later `endDateCurrent`
- **WHEN** the repair step runs
- **THEN** the armed timer's SLA MUST span start to the CURRENT deadline
- @e2e exclude covered by ArmTermijnEngineTimers unit tests

#### Scenario: Running the step twice arms nothing new

- **GIVEN** instances already carrying `engineTimerId`
- **WHEN** the repair step runs again
- **THEN** no timer MUST be armed
- @e2e exclude covered by ArmTermijnEngineTimers unit tests
