# Tasks — termijnbewaking on engine timers

## Phase 1: core termijn engine (implemented in this change)

- [x] 1.1 Lazy engine resolution: the existing generic
      `SettingsService::getOpenRegisterClass()` resolves
      `OCA\OpenRegister\Service\Flow\Timer\FlowTimerService` at call time, null on absence
      (D-7) — no new resolver needed.
- [x] 1.2 `lib/Service/TermijnTimerService.php` — the arm mapping (D-1): `armBeslistermijn()`,
      `armHersteltermijn()`, `suspendBeslistermijn()` (basis `Awb 4:5`), `resumeBeslistermijn()`,
      `extendBeslistermijn()` (standard → `extend()`, supervisor → `extendWithOverride()`),
      `cancelForInstance()`. Every call degrades to a logged no-op when the engine is absent.
- [x] 1.3 `TermijnService::createTermijnInstance()` arms the timer and stores `engineTimerId`;
      `markTermijnCompleted()` cancels every open timer of the instance with a recorded reason.
- [x] 1.4 `DeadlinePauseService` maps opschorting onto engine suspend/resume (D-2) and arms/ignores
      the hersteltermijn helper timer; pause bookkeeping fields (`pauzeStartDatum`,
      `pauzeDuurDagen`) are now DECLARED on the deadlineInstance schema so OpenRegister stops
      silently dropping them (fixes the resume consumed-days arithmetic in production).
- [x] 1.5 `DeadlineExtensionService` mirrors verdaging to the engine after the domain refusal
      rules pass (standard vs supervisor override).
- [x] 1.6 `lib/Listener/TermijnTimerFiredListener.php` consumes `FlowTimerFiredEvent` (D-4);
      registered in `ObjectListenerRegistrar`.
- [x] 1.7 `DwangsomCalculationService::accrueThrough()` replaces `calculateDaily()` (D-3):
      derived, catch-up-safe accrual; `stopForBeschikking()` settles through the stop moment first.
- [x] 1.8 RETIRE `DailyDeadlineScanJob` + `DeadlineDailyScanService` (delete classes, drop the
      info.xml job registration, delete the scan test).
- [x] 1.9 `lib/Repair/ArmTermijnEngineTimers.php` (D-5), registered in info.xml post-migration.
- [x] 1.10 Schema: add `engineTimerId`, `pauseTimerId`, `pauzeStartDatum`, `pauzeDuurDagen`,
      `voltooiDatum` to `deadlineInstance` in `lib/Settings/register.d/60-termijnbewaking.json`.
- [x] 1.11 Structural test `tests/Unit/Architecture/TimedJobDeadlineThresholdTest.php` (D-6).
- [x] 1.12 Fixture pairs proving unchanged AWB date arithmetic: opschorting pair (D-2), verdaging
      day impact, dwangsom tier series (accrueThrough day N == N legacy daily ticks), threshold
      buckets == ladder rungs.
- [x] 1.13 Engine-call stubs for the unit bootstrap (`FlowTimer`, `FlowTimerService`,
      `FlowTimerFiredEvent`) mirroring the REAL signatures (a stub that agrees with the caller
      cannot fail).
- [x] 1.14 Quality: `php -l`, phpcs, phpmd (per subdir), psalm, phpstan, phpunit, hydra gates
      `--scope-to-diff`; grep `tests/e2e` for assertions on the retired job (none exist — the e2e
      surface only touches the termijn dashboard page shell).

## Phase 2: sibling engines (staged)

- [ ] 2.1 **WOO** — `WOODeadlineService` + `WOODeadlineCheckJob`: arm one `due`/`wettelijk` timer
      per Woo-verzoek (28d, verdaging +14d via `extend()`); the check job's threshold walk moves
      to the ladder; opschorting (zienswijze) onto suspend/resume. The Woo dwangsom regime
      (€15/day, max €500) stays a dossiq calculation (already carried by
      `deviatingPenaltyPaymentRegime`). Retire `WOODeadlineCheckJob`; remove its allowlist entry.
      NOTE: the WOO service owns its own notified-thresholds list — migrate it to
      `notificatiesVerstuurd`-style dedup consulted by the shared listener.
- [ ] 2.2 **Bezwaar** — `Beschikking/BezwaarTermijnScheduler` + `BezwaarTermijnJob`: the
      bezwaartermijn (6 weeks after bekendmaking, AWB 6:7) is anchor-shaped — arm with
      `anchorEvent: bekendmaking`, and use `supersede()` when the bekendmaking moves. Retire
      `BezwaarTermijnJob`; remove its allowlist entry. NOTE: the scheduler currently advances
      beschikking status from cron; that transition becomes a listener consuming the timer fire,
      keeping the state machine in `StateMachineService`.
- [ ] 2.3 **DSO** — `DsoDeadlineJob` advances case status from cron: replace with an armed timer
      per DSO-zaak and a `FlowTimerFiredEvent` consumer that drives the SAME
      `StatusTransitionService` path a user action takes (no cron-only transition code). Retire
      the job; remove its allowlist entry.
- [x] 2.4 **Vergadering** — DELIVERED BY RETIREMENT, not by migration: the wave-5 status sweep
      (`openspec/changes/case-status-onto-engine-lifecycle`) found the engine dead — the job
      scanned for cases with a literal `status: 'planned'`, which `case.status` (a statusType
      reference) can never hold, and the only writer of such cases was removed earlier. The job,
      `VergaderingCaseService`, their tests and the allowlist entry are gone; there was nothing
      to arm a timer for.
- [ ] 2.5 **Advice** — `AdviceDeadlineJob` (the fifth sibling, found during phase 1's structural
      sweep): advice-request deadlines onto armed timers; retire the job; remove its allowlist
      entry.
- [ ] 2.6 Shared: extend `TermijnTimerFiredListener` (or split per engine) on `metadata.kind`;
      each retirement carries its own fixture pair for the date arithmetic that moves.

## Phase 3: milestones (staged)

- [ ] 3.1 `Milestone/StalledCaseDetector` + `BottleneckDetectionJob`: the stalled-threshold
      becomes an armed `due`/`none` timer per active milestone (SLA in `businessDays` against the
      seeded `nl-national` calendar); detection-on-cron becomes rung fires. Retire
      `BottleneckDetectionJob`; remove its allowlist entry.
- [ ] 3.2 `MilestoneService`: replace the app-local working-day math with the engine
      `SlaCalculator` + `WorkingCalendarService` (fixture pair: same business-day counts across a
      weekend + Dutch national holiday).

## Phase 4: KCC (staged)

- [ ] 4.1 Retire `Kcc/SlaCalculator` (the name-for-name duplicate): `CallbackService` and the KCC
      routing consult the engine `SlaCalculator` against the organisation calendar; the KCC
      callback SLA (2 working hours) becomes `{value: 2, unit: hours}` timers on the callback
      object with the KCC ladder as escalationRules. Fixture pair: identical due moments for the
      documented KCC cases before and after.
- [ ] 4.2 Sweep `lib/` for remaining `->diff(` deadline math outside the allowlisted calculation
      classes; tighten the structural test's allowlist to empty.

## Verify

- [ ] V.1 `openspec validate termijnbewaking-op-engine-timers --strict` exits 0.
- [ ] V.2 After each phase: the structural test's allowlist shrank; hydra gates green on the diff.
