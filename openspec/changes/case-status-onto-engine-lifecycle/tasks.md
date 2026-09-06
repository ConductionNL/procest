# Tasks: case status onto the engine lifecycle line

## 1. Delete the dead second entry point

- [x] 1.1 Delete `lib/Service/WorkflowEngineService.php` and
      `tests/Unit/Service/WorkflowEngineServiceTest.php`. Verified before
      deletion: no reference under `lib/`, `src/`, `appinfo/` outside the
      pair.

## 2. Retire the dead vergadering mini-engine

- [x] 2.1 Delete `lib/Service/VergaderingCaseService.php`,
      `lib/BackgroundJob/VergaderingDeadlineJob.php`,
      `tests/Unit/Service/VergaderingCaseServiceTest.php`,
      `tests/Unit/BackgroundJob/VergaderingDeadlineJobTest.php`.
- [x] 2.2 Drop the `VergaderingDeadlineJob` entry from
      `appinfo/info.xml`, with a retirement note in the pattern of the
      DailyDeadlineScanJob note beside it.
- [x] 2.3 Shrink the `TimedJobDeadlineThresholdTest` allowlist by the
      vergadering entry (its honesty check demands it).
- [x] 2.4 Amend `openspec/changes/termijnbewaking-op-engine-timers/tasks.md`
      task 2.4: the vergadering engine is retired as dead machinery by this
      change; nothing migrates to a timer.

## 3. Fix the complaint declared lifecycle

- [x] 3.1 Rewrite the complaint `x-openregister-lifecycle` in
      `lib/Settings/dossiq_register.json` AND
      `lib/Settings/dossiq_mock_register.json` (the mock duplicates every
      slug) to the object-form dialect with the English enum states,
      mirroring `ComplaintService::TRANSITIONS` exactly:
      `field: status`, `initial: received`, `final: [handled, withdrawn]`,
      verbs confirmReceipt / startHandling / planHoorgesprek /
      completeHoorgesprek / handle / withdraw.
- [x] 3.2 Unit assertion that the declared machine and
      `ComplaintService::TRANSITIONS` stay equal edge-for-edge (in
      `LocalStatusMachineryTest`), so the next drift cannot open quietly.

## 4. The scanner

- [x] 4.1 `tests/Unit/Service/LocalStatusMachineryTest.php`: retired
      classes stay retired; the transition-table census is closed and
      reason-bearing; the allowlist carries no stale entries; the
      complaint declaration parity of 3.2.

## 5. Staged (recorded, not implemented here)

- [ ] 5.1 Thin `ComplaintService::transitionStatus()` and
      `ConsultationService` transitions onto OR's `TransitionEngine`
      (verb endpoint), with error-shape parity fixtures; then drop their
      tables from the scanner allowlist.
- [ ] 5.2 Declare a `subsidieProces` schema lifecycle for
      `SubsidieService::TRANSITIONS` and thin likewise.
- [ ] 5.3 Advice transition thinning follows termijnbewaking 2.5 (the
      expiry timer) so the cron seam and the machine move together.
- [ ] 5.4 The case machine itself: when OR ships FK-based status graphs,
      revert `case.status` to readOnly plus lifecycle transitions per the
      ruling recorded on the case schema, and retire
      `StatusTransitionService` behind the projection
      (`workflow-definitions-to-flow` task 4 owns the template side).

## Verify

- [x] V.1 `php -l`, phpcs, psalm, phpstan on touched files; phpmd per
      touched subdir; phpunit green including the new scanner.
- [x] V.2 Hydra gates `--scope-to-diff` report 0 FAIL on the diff.
- [x] V.3 `grep -rn tests/e2e` for vergadering and WorkflowEngine
      surfaces: only `retired-surfaces.spec.ts`, which asserts absence.
