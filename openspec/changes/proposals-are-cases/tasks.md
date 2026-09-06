<!-- SPDX-License-Identifier: EUPL-1.2 -->
# Tasks: proposals-are-cases

## 1. Schemas

- [x] 1.1 Drop `proposal`, `parafeerroute`, `parafeeractie` and `paraferingAuditEntry` from `lib/Settings/dossiq_register.json`, its register schema list, its four seed routes and its `/voorstellen/:voorstelId/audit-trail` page entry.
- [x] 1.2 Drop the same four from `lib/Settings/dossiq_mock_register.json`, with the twelve mock seed objects.
- [x] 1.3 Delete `lib/Settings/register.d/74-parafering-to-decidiq.json`.
- [x] 1.4 Verify both files still round-trip byte-identically at their own indent, so the diff is deletions only. Measured: 493 and 484 deletions, 0 insertions.

## 2. Backend

- [x] 2.1 Delete the runtime: `VoorstelBesluitController`, `ParaferingAuditExportController`, `ParafeerTransitionEvent`, `VoorstelSubmitGuard`, `ApprovalStepNotificationListener`, `ParaferingAuditListener`, `ParaferingConcludedListener`, `MigrateParafeerroutesToDecidiq`, `RaiseInFlightParaferingenInDecidiq`, `ParaferingNotificationService`, `VoorstelStatusGuard`, `BesluitvormingActivateHandler`, `DossiqTxBesluitvormingActivateNode`, and the whole of `lib/Service/Parafeer/` and `lib/Service/Parafering/`.
- [x] 2.2 Delete `lib/Service/Support/ObjectArrayNormalizer.php`. It was extracted FROM the five parafering services and has zero consumers once they go.
- [x] 2.3 Unwire the registrars, `GuardRegistry`, `ActionHandlerRegistry` and `DossiqFlowNodeListener`.
- [x] 2.4 Remove the voorstel routes and the two repair steps from `appinfo/routes.php` and `appinfo/info.xml`.
- [x] 2.5 Remove `AdviceDelegationService::raiseVoorstelBesluit()`, whose only caller was the deleted controller.
- [x] 2.6 Drop the voorstel surface from `LinkInFlightRemainingDecisionsRepair`, keeping bezwaar, advies and consultatie.
- [x] 2.7 Drop `voorstel_schema`, `parafeerroute_schema`, `parafeeractie_schema` and `parafering_audit_entry_schema` from `SettingsService` and `SchemaSlugMap`, `parafeerroute` from `StoreController`, and `proposal.routeSnapshot` from `JsonEncodedStringProperties`.

## 3. Templates

- [x] 3.1 Remove the `parafeerroute` block from all three bvw bundles.
- [x] 3.2 Remove the `voorstelStatus` guard. **Do this in the same commit as 2.1**: an unknown guard type evaluates as `passed: false`, so the class and the guard entry cannot be separated without stranding every besluitvorming case at `Parafering`.
- [x] 3.3 Remove the `besluitvormingActivate` automatic action, whose handler goes in 2.1.
- [x] 3.4 Keep the `Parafering` status step, the `Voorstel opstellen` status, the `Steller` role and the voorstel document types. They are the case type's vocabulary, not the retired schema.
- [x] 3.5 Strip the parafeerroute seeding from `TemplateBundleSeeder` and `BesluitvormingTemplateService`.

## 4. Frontend

- [x] 4.1 Delete `src/views/voorstellen/`, `voorstelBesluitApi.js`, `parafeerActieApi.js` and `BesluitRegistration.vue`.
- [x] 4.2 Remove the `Voorstellen` menu entry, the `Voorstellen`, `VoorstelDetail` and `ParafeerrouteDetail` pages, and the `proposal` deepLink from `src/manifest.json`.
- [x] 4.3 Remove the five voorstel formatters from `formatters.js`, along with `voorstelSteps`, `rowUpdated` and the now-unused `t` import.
- [x] 4.4 Remove the three schema registrations from `store.js` and the registry/customComponents entries, including the `voorstelReminder` handler.
- [x] 4.5 Fix the stale `CaseDetail` `_note`, which still listed `voorstel.case` among the FK-scoped child collections after the widget had already gone.

## 5. Menu layout

- [x] 5.1 Add no `removals` entry and no `removalsReplacedBy` waiver. The PAGE is deleted, not hidden, so no waiver is owed. This answers the question the 2026-09-02 note left open, and the answer is that the question did not apply.
- [x] 5.2 Rewrite that note to record what replaced the surface.
- [x] 5.3 Confirm gate-53 reports no new finding. Measured: 7 WARN, all pre-existing, none naming `Voorstellen`.

## 6. Specs and siblings

- [x] 6.1 Remove REQ-BVW-002 and REQ-BVW-003 from `besluitvorming-workflow`, and amend REQ-BVW-001-B and REQ-BVW-008-B.
- [x] 6.2 Delete the six unreferenced parafering and voorstel specs.
- [x] 6.3 Delete the five sibling changes that plan work on the retired concept.
- [x] 6.4 Update `docs/decisions/besluitvorming-vs-decidesk.md`, whose 2026-06-22 recommendation is the reason the surface stayed.

## 7. Tests

- [x] 7.1 Delete the 16 test files written against the retired classes.
- [x] 7.2 Repoint `JsonEncodedStringPropertiesTest` from `proposal.routeSnapshot` onto `case.statusHistory`, the same shape on a surviving schema.
- [x] 7.3 Fix the allowlists in `RepairStepRegistrationTest`, `SeedWriteIdentityTest`, `LocalStatusMachineryTest` and `SubsidieFragmentTest`.
- [x] 7.4 Remove the voorstel constructions from `GuardDialectTest` and `WorkflowGuardConformanceTest` without breaking the positional `GuardRegistry` construction.
- [x] 7.5 Remove the voorstel blocks from four e2e specs and `searchableSchemas.spec.js`.

## 8. Verification

- [x] 8.1 PHPUnit: 2911 tests, 0 failures, 0 warnings, 1 pre-existing skip.
- [x] 8.2 Vitest: 35 files, 359 tests, all passing.
- [x] 8.3 ESLint: 0 errors. Warnings 839 to 823, none new.
- [x] 8.4 PHPCS 0 errors, PHPMD clean on both rulesets, Psalm no errors, PHPStan no errors.
- [x] 8.5 Hydra gates: all 75 applicable green.

## 9. Pre-existing issues fixed on the way

Per the project rule that pre-existing quality issues are fixed when encountered.

- [x] 9.1 `tests/Stubs/AppHost/Service/StoreDescriptor.php` had drifted from the real OpenRegister class, which gained a fifth constructor parameter (`array $types = []`). Added it.

  Recorded because getting here took two wrong turns. `StubApiDriftTest` compares `tests/Stubs/` against a SIBLING CHECKOUT at `../openregister`, and in a shared dev workspace that is another session's live working tree. It reported the drift, was red on clean `development` too, and then reported it in the OPPOSITE direction twenty minutes later when that checkout switched to a feature branch without the parameter. Reading that as noise, the fix was reverted. CI then failed on it, because CI clones openregister FRESH. Checked against the canonical remote (`gh api .../openregister/contents/...?ref=development`), the real class does take five. The sibling checkout is not evidence about anything; the remote is.

- [x] 9.2 `SetupControllerStatusTest` fed `install()` a narrower array than `DemoDataService` actually returns, so the controller read two undefined keys and PHPUnit reported two warnings. The double now carries the real shape, and the test asserts the counts it was named for.
