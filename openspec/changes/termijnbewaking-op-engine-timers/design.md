# Design — termijnbewaking on engine timers

## D-1. The arm mapping, per termijn type

One FlowTimer per TermijnInstance, armed at instance creation (and by the repair step for
in-flight instances):

| Config key | Value | Why |
| --- | --- | --- |
| `subjectType` | `object` | The TermijnInstance is an OpenRegister object; a run is not required (the spec makes run-less subjects first-class). |
| `subjectUuid` | the TermijnInstance id | The timer measures the term, not the case; one case can carry several terms over its life. |
| `appId` | `dossiq` | The listener's ownership filter. |
| `purpose` | `due` | Advisory: reaching the beslistermijn must NOT close or transition the case. The work stays open and answerable (AWB: overschrijding starts dwangsom exposure, it does not end the zaak). |
| `legalEffect` | `wettelijk` | It is a statutory term; the engine records the breach permanently. |
| `sla` | `{value: standardDurationDays, unit: calendarDays}` | AWB beslistermijnen count calendar days. This preserves dossiq's `start + N days` arithmetic exactly. |
| `calendar` | none (resolution falls through to the organisation default, else seeded `nl-national`) | Irrelevant for `calendarDays` arithmetic but required to resolve. |
| `ladder` | `nl-termijn-default` | The seeded 14/7/2/0 ladder — the same matrix `DeadlineEscalationService::DEFAULT_MATRIX` hardcodes, with the same `termijn-14d`/`termijn-7d`/`termijn-2d`/`termijn-overschreden` message identities. Explicit rather than implicit so an organisation override is a deliberate act. |
| `extensionMax` | TermijnDefinitie `countExtensions`, default 1 | The AWB 4:14 ceiling travels to the engine at arm time; dossiq's own `assertExtensionPermitted()` stays the first, authoritative refusal. |
| `anchorEvent` / `anchorEventAt` | `case_created` at the instance `startDate` | The anchor is stored so a moved anchor can supersede (engine spec D-4); dossiq does not move it in phase 1. |
| `metadata` | `{source: dossiq-termijn, kind: beslistermijn, termijnInstanceId, caseId, deadlineDefinition, basis}` | The listener resolves the domain side from metadata, never from parsing titles. |

No `onExpiry`: a `due` timer refuses one, and that is the point — nothing about the engine may
transition the case.

**The hersteltermijn helper timer** (armed at `registerPauze`, while the main timer is suspended):
`purpose: due`, `legalEffect: none`, `sla {value: durationDays, unit: calendarDays}`, one explicit
escalation rule `{trigger: slaBreached, offset: 0, notifyRole: [handler], priority: high, message:
pauze-verlopen}` (explicit rules override the ladder, so no 14/7/2 rungs fire on a 14-day pause),
`metadata.kind: hersteltermijn`. It replaces the scan's pause-expiry detection.

## D-2. The opschorting mapping (AWB 4:5 / 4:15)

`registerPauze()` keeps its case-data arithmetic (extend `endDateCurrent` by the pause days, park
the pause bookkeeping on the instance) and additionally:

1. `FlowTimerService::suspend(timerUuid, rationale, until: pauseDeadline, actor: null, basis: 'Awb 4:5')`
   — the engine banks consumed budget and stops the clock; while suspended the timer neither fires
   nor escalates nor reports overdue.
2. Arms the hersteltermijn helper timer and stores its uuid as `pauseTimerId`.

`resumeAfterPauze()` keeps its case-data arithmetic (pull back the unused pause days) and calls
`FlowTimerService::resume(timerUuid, reason, actor: null)` — the engine re-projects
`fire_at = now + (budget − consumed)`.

The two arithmetics agree by construction: for a pause of P days with C consumed,
dossiq computes `end + P − (P − C) = end + C` and the engine computes
`resume_moment + remaining = anchor + budget + C` — the same date at day granularity. The paired
fixture test (`testOpschortingArithmeticPairsWithEngineSuspendResume`) pins this equality.

**Known engine gap:** `FlowTimerService` has no single-timer cancel (only `cancelForSubject`,
which would also hit the suspended main timer). A helper timer left armed after an on-time
aanvulling therefore still fires its `pauze-verlopen` rung later; the listener drops the fire
when the instance is no longer `paused`. Upstream candidate: `cancel(uuid, reason)`.

## D-3. What stays domain arithmetic in dossiq

- **Dwangsom tiers and plafond (AWB 4:17)** — `DwangsomCalculationService`, now exposed as a
  derivation: `accrueThrough(id, now)` applies the identical per-day tier step
  (`applyDailyAccrual`) from the persisted `currentDag` up to the day count implied by
  `startDate → now`, capped by the plafond. Catch-up-safe and idempotent per day, so it needs no
  cron: the listener syncs it on every fire, and `stopForBeschikking()` settles through the stop
  moment before locking `definitiveAmount`. The old `calculateDaily()` (one tick per cron run,
  wrong under missed or doubled runs, and accruing during the grace window) is removed.
- **Hersteltermijn consumption** — `resumeAfterPauze()`'s consumed/unused split, unchanged.
- **Extension ceilings** — the TermijnDefinitie's `countExtensions`, enforced first by
  `assertExtensionPermitted()`, mirrored to the engine as `extensionMax`.
- **Ingebrekestelling validity (AWB 4:17)** — `NoticeOfDefaultService`, unchanged.
- **The escalation matrix as domain bookkeeping** — `DeadlineEscalationService.notifyThreshold()`
  keeps writing `notificatiesVerstuurd`; it is now called by the listener instead of the scan, and
  its threshold list doubles as the migration dedup (D-5).

## D-4. The listener

`TermijnTimerFiredListener` handles `FlowTimerFiredEvent`, filtering
`timer.appId === 'dossiq'` and `metadata.source === 'dossiq-termijn'`:

- `kind: rung`, rung message `termijn-14d`/`termijn-7d`/`termijn-2d` → map to bucket 14/7/2 and
  call `DeadlineEscalationService::notifyThreshold(instance, bucket)` (idempotent on
  `notificatiesVerstuurd`).
- rung `slaBreached:0` (message `termijn-overschreden`) → flip the instance to `exceeded`, record
  the `exceeded` TermijnGebeurtenis (basis `AWB 4:13`), then `notifyThreshold(instance, 0)`.
- rung message `pauze-verlopen` (`metadata.kind: hersteltermijn`) → record the `pause-expired`
  event (basis `AWB 4:5`) if and only if the instance is still `paused`.
- every handled fire ends by syncing dwangsom accrual for the instance's `lopend` berekeningen.

The listener never throws out of `handle()`: a domain-side failure is logged and must not poison
the engine sweep.

## D-5. Migration (repair step)

`ArmTermijnEngineTimers` (post-migration, idempotent): for every TermijnInstance with status
`lopend`/`verlengd`/`paused` and no `engineTimerId`, arm with
`sla = days(startDate → endDateCurrent)` anchored at `startDate` — so the engine's fire moment
equals the CURRENT deadline including past pauses and extensions — and suspend immediately for
`paused` rows. Instances already `exceeded`/`completed`/`withdrawn` are skipped. Past rungs the
engine then fires in catch-up are absorbed because `notifyThreshold` consults
`notificatiesVerstuurd` first: the engine's fire ledger records the rung, the domain sends
nothing twice.

## D-6. The structural test

`tests/Unit/Architecture/TimedJobDeadlineThresholdTest.php`:

1. asserts `DailyDeadlineScanJob` no longer exists (retired, not allowlisted);
2. scans every `lib/BackgroundJob/*.php` TimedJob for deadline-threshold computation signals and
   fails any hit that is not in the reason-bearing allowlist. The allowlist names the six
   remaining engines, each with the phase and task that retires it — so the allowlist shrinks to
   empty as phases 2 to 4 land, and a NEW deadline-computing TimedJob fails the build with an
   instruction to arm a FlowTimer instead.

## D-7. Degradation posture

Every engine call goes through `SettingsService::getFlowTimerService()` (container-resolved at
call time, same as `getObjectService()`); a Throwable from the engine is logged at warning and the
domain flow continues on case data. This mirrors the app's existing optional-OpenRegister posture
and keeps unit tests runnable against stubs.
