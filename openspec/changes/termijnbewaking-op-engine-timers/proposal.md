# Proposal: termijnbewaking-op-engine-timers

kind: mechanism switch / duplicate-clock removal — cites **ADR-022** (apps-consume-or-abstractions)
and the One Engine audit. Umbrella change for moving all five dossiq statutory deadline engines onto
OpenRegister's business-timer stack (`flow-business-timers`, merged on openregister `development`).
Phase 1 (the core AWB termijn engine) is implemented in this change; phases 2 to 4 are staged as
ordered tasks with per-engine notes and land as follow-up changes under this umbrella.

## Summary

The One Engine audit found five app-local deadline clocks in dossiq running beside the engine's own
timer layer, including a name-for-name `SlaCalculator` duplicate:

1. **Core termijnbewaking** — `TermijnService`, `DeadlineDailyScanService` (the daily sweep),
   `DeadlineEscalationService` (the 14/7/2/0 threshold buckets), `DeadlinePauseService`
   (AWB 4:5/4:15 opschorting), `DeadlineExtensionService` (AWB 4:14 verdaging),
   `NoticeOfDefaultService`, `DwangsomCalculationService`, jobs `DailyDeadlineScanJob` and
   `DeadlineNotificationDispatchJob`.
2. **Siblings** — `WOODeadlineService` + `WOODeadlineCheckJob`,
   `Beschikking/BezwaarTermijnScheduler` + `BezwaarTermijnJob`, `DsoDeadlineJob`,
   `VergaderingDeadlineJob` (the last two advance case status from cron).
3. **Milestones** — `Milestone/StalledCaseDetector` + `BottleneckDetectionJob` +
   `MilestoneService` (working-day math duplicating the engine's `WorkingCalendarService`).
4. **KCC** — `Kcc/SlaCalculator` (the literal duplicate of the engine class) + `CallbackService`.

OpenRegister now ships the durable business-timer stack: `FlowTimerService` (arm / suspend / resume /
extend / supersede / cancel), `FlowTimerSweep` (the ONE sweep, bounded by index), the seeded
`nl-termijn-default` escalation ladder (14/7/2/0 — the same matrix dossiq's
`DeadlineEscalationService::DEFAULT_MATRIX` hardcodes, down to the `termijn-14d` message names),
`SlaCalculator` + `WorkingCalendarService` (seeded `nl-national`), and `FlowTimerFiredEvent` as the
seam between deciding WHEN (the engine) and doing the domain WHAT (dossiq).

**The split, per the audit's ruling:**

- **AWB arithmetic STAYS in dossiq** as contributed calculations and configuration: the dwangsom
  tariff tiers (AWB 4:17), hersteltermijn consumption, extension ceilings from the
  TermijnDefinitie. They become ladder/calendar configuration or calculation classes the timers
  consult.
- **Sweeps, threshold buckets, pause/resume state and escalation dispatch MOVE** to armed
  FlowTimers. The engine sweep replaces `DailyDeadlineScanJob`; the seeded ladder replaces the
  threshold matrix; engine suspend/resume replaces app-local pause state; `FlowTimerFiredEvent`
  replaces the scan-driven escalation dispatch.
- **Deadline visibility on the case stays case data**: `endDateCurrent` and friends remain readable
  on the TermijnInstance exactly as today; the timer is the clock, not the projection.

**What phase 1 changes (implemented here):**

1. A new `TermijnTimerService` arms one `due` / `wettelijk` FlowTimer per TermijnInstance
   (subjectType `object`, SLA in `calendarDays` preserving the AWB calendar-day arithmetic,
   ladder `nl-termijn-default`, anchor `case_created` at the instance's `startDate`,
   `extensionMax` from the TermijnDefinitie).
2. Opschorting maps onto engine `suspend()`/`resume()` (basis `Awb 4:5` / `Awb 4:15`); a second,
   advisory helper timer watches the hersteltermijn itself so the `pause-expired` signal survives
   the scan's retirement.
3. Verdaging maps onto engine `extend()` (standard, AWB 4:14 lid 1) and `extendWithOverride()`
   (supervisor, AWB 4:14 lid 3).
4. A new `TermijnTimerFiredListener` consumes `FlowTimerFiredEvent`: preBreach rungs drive the
   existing `DeadlineEscalationService::notifyThreshold()` bookkeeping (the instance's
   `notificatiesVerstuurd` list stays the domain dedup source), the `slaBreached:0` rung flips the
   instance to `exceeded` and records the event, and every fire syncs dwangsom accrual.
5. Dwangsom accrual becomes a derivation: `DwangsomCalculationService::accrueThrough()` computes
   the accrued amount from `startDate` to the clock (the same per-day tier arithmetic, applied
   catch-up-safe), replacing the cron-tick `calculateDaily()`. `stopForBeschikking()` settles
   through the stop moment first, so the legally binding `definitiveAmount` no longer depends on
   how many cron ticks happened to run.
6. `DailyDeadlineScanJob` and `DeadlineDailyScanService` are RETIRED (deleted); the engine sweep
   replaces them. A structural test pins the class: no dossiq TimedJob may compute deadline
   thresholds, with a reason-bearing allowlist for the engines that move in phases 2 to 4.
7. A repair step arms timers for existing in-flight TermijnInstances (idempotent; suspended for
   paused rows), registered in `appinfo/info.xml`. Re-fired past rungs are absorbed by the
   `notificatiesVerstuurd` dedup, so migration causes no duplicate escalations.

**What stays unchanged:** every AWB rule and its date arithmetic (proven by fixture pairs), the
TermijnInstance/TermijnGebeurtenis data shapes and event vocabulary, the controllers' HTTP surface,
`NoticeOfDefaultService`'s validity rules, and `DeadlineNotificationDispatchJob` (a QueuedJob
delivery vehicle, not a clock — it computes nothing).

## Why

Two clocks over one term diverge: the scan buckets on days-left it computes itself, the engine
buckets on the ladder; a pause recorded in dossiq is invisible to the engine and vice versa. The
scan also carries the exact failure modes the engine spec was written against: it lists ALL
instances and filters in application code (unbounded), computes overdue on a cron tick rather than
on read, and stores threshold state that goes stale when the job does not run. The engine already
solves durable firing, exactly-once rungs, suspend arithmetic and catch-up after downtime — with
the same 14/7/2/0 ladder and the same message identities dossiq uses.

## Phasing

- **Phase 1 (this change): core termijn engine** — implemented.
- **Phase 2: sibling engines** — WOO, bezwaar, DSO, vergadering deadlines onto armed timers;
  the two jobs that advance case status from cron become `FlowTimerFiredEvent` consumers.
- **Phase 3: milestones** — `StalledCaseDetector`/`BottleneckDetectionJob` onto engine timers,
  `MilestoneService` working-day math onto the engine's `SlaCalculator`/`WorkingCalendarService`.
- **Phase 4: KCC** — retire the name-for-name `Kcc/SlaCalculator` duplicate; KCC callback SLAs
  consume the engine calculator against the seeded calendar.

## Impact

- Affected: `lib/Service/TermijnService.php`, `DeadlinePauseService.php`,
  `DeadlineExtensionService.php`, `DwangsomCalculationService.php`,
  `lib/Service/TermijnTimerService.php` (new), `lib/Listener/TermijnTimerFiredListener.php` (new),
  `lib/Repair/ArmTermijnEngineTimers.php` (new), `lib/Service/SettingsService.php`,
  `lib/AppInfo/Registrar/ObjectListenerRegistrar.php`, `appinfo/info.xml`,
  `lib/Settings/register.d/60-termijnbewaking.json`.
- Deleted: `lib/BackgroundJob/DailyDeadlineScanJob.php`, `lib/Service/DeadlineDailyScanService.php`.
- OpenRegister is consumed lazily through the container (same posture as
  `SettingsService::getObjectService()`): when the engine is unavailable every timer call degrades
  to a logged no-op and the domain flow proceeds on case data.
