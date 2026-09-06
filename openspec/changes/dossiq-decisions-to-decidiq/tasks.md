# Tasks — dossiq drops its own decision functionality

## Phase 1: Audit (done in this change)

- [x] Audit every decision-shaped item in lib/, lib/Settings and src/; classify A/B/C/BLOCKED with evidence (design.md sections 1-4).
- [x] Measure decidiq's capability per item against the running seam (design.md section 6).

## Phase 2: Category B, implemented now

- [x] Remove `src/components/tabs/CaseDecisionsTab.vue` and its `registry.js` + `customComponents.js` entries.
- [x] Remove `src/dialogs/VoorstelCreateDialog.vue`.
- [x] Mark the `decisionTable` schema and the DMN service/controller deprecated, retirement blocked on openregister `flow-decision-tables`.
- [x] Add `tests/Unit/Service/LocalDecisionAuthoringTest.php`: closed reason-bearing allowlists for decision-schema writers and `DecisionTableEvaluator` consumers; a new match fails.
- [x] Verify no e2e spec, vitest suite or manifest references the removed components (grep before removal; suites green after).

## Phase 3: Quality

- [x] eslint + vitest green after the UI removals.
- [x] PHP analyzers individually (phpcs, phpstan, psalm; phpmd per subdir) green on the touched files.
- [x] hydra gates `--scope-to-diff` with 0 FAIL on the diff.

## Phase 4: Blocked retirements (do NOT check these off here)

- [ ] BLOCKED on openregister `flow-decision-tables`: retire the DMN stack (schema `decisionTable`, `DecisionTableService`, `DecisionTableController` + 5 `/api/decisions` routes, `EvaluateDecisionHandler`, `DossiqTxEvaluateDecisionNode`, `DecisionTablesTab.vue`, `MigrateLhsToDecisionTablesCommand`, `LhsMatrixDecisionTableMigrator`) and shrink the test's DMN allowlist to empty.
- [ ] BLOCKED on decidiq: add `woo-decision` to `DecisionIntegrationService::ALLOWED_TYPES` (and its pinned schema enum homes), then repoint `WOODecisionService`'s raise through the delegation seam; the assembly and Art. 5.1/5.2 guard stay.

## Phase 5: Ruben's calls (grey areas, design.md section 3)

- [ ] DECISION C-2: parafering runtime to decidiq's approval-route engine, or keep the administrative-law record local? Recommendation: keep for now.
- [ ] DECISION C-3: DROP/LVBB publication mechanics. Recommendation: keep (REQ-DCDH-007 leaves side effects to the caller). Rules the fate of the unmounted `BesluitPublicatiePanel.vue` too.
- [ ] DECISION C-1/C-4/C-8: confirm outcome storage on the case is case data (recommendation: yes, keep).
- [ ] DECISION C-9: what the bvw templates seed once C-2/C-3 are ruled.
