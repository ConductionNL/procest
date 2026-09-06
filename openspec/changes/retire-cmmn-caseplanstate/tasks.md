# Tasks: retire the CMMN runtime and the casePlanState blob

Ordering matters: group 1 ships first (bridge), group 2 drains, groups 3 to 5 are the removal release and only land once the drain report shows zero populated blobs and zero unmappable cases (design.md section 4).

## 1. Bridge: consume openregister's case layer

- [ ] 1.1 `lib/Service/CasePlanProjectionService.php`: at case start for `handlingModel: cmmn`, project the published `caseModel` onto openregister plan items via `/api/cases`; convert sentries per the closed operator table in design.md section 2, refusing and reporting any operator or on-part outside it. Projection fixtures per operator.
- [ ] 1.2 Repoint the frontend: the `cmmn-case-plan` widget slot renders the plan from openregister's `/api/cases` (read plan, transition, enable discretionary, attach ad-hoc, list enableable); error state fails closed with retry when openregister is unreachable. Vitest covers the error path.
- [ ] 1.3 Bridge read preference: prefer openregister rows when the case has them, fall back to the local engine only while a blob is present; ship `occ dossiq:cmmn:rollback-case-plans` (reverse projection, rows to blob) for R1 rollback.

## 2. Drain: migrate in-flight cases

- [ ] 2.1 `occ dossiq:cmmn:migrate-case-plans` (`--dry-run`, `--strict`) plus repair step: per case, decode the blob, recover item metadata from the backing caseModel, create only the missing openregister rows keyed on (case uuid, item id) with states carried verbatim, write case-file values as declared case properties, import `eventLog` into the audit, verify, then clear the blob as the per-case commit point.
- [ ] 2.2 Unmappable handling: undecodable blob, orphan item id, unknown state or unconvertible sentry leaves the case intact and reports uuid plus reason; `--strict` exits non-zero, the repair step logs and continues.
- [ ] 2.3 Idempotency proof: PHPUnit runs the migration twice over the fixture set (populated, half-written, empty, unmappable) asserting identical rows, no duplicate audit entries and untouched unmappable cases.

## 3. Remove: backend

- [ ] 3.1 Delete `lib/Service/Cmmn/` (all nine classes), `lib/Controller/CmmnCaseController.php` and the five `/api/case/{caseId}/cmmn-plan*` entries in `appinfo/routes.php`; drop the R1 rollback command.
- [ ] 3.2 Remove `casePlanState` from `dossiq_register.json` and `dossiq_mock_register.json` and rewrite the `handlingModel` `cmmn` description to name the openregister case layer. Gated on the group 2 exit report: openregister strips undeclared properties on save, so this lands last.
- [ ] 3.3 Retire the engine's unit tests (`tests/Unit/Service/Cmmn/`, `CmmnCaseControllerTest`) and `CmmnBpmnCoexistenceRegressionTest`; its BPMN-untouched invariant moves to the structural test and the e2e journey (5.3).

## 4. Remove: frontend, and the guard

- [ ] 4.1 Delete `src/services/cmmnApi.js`, `src/views/cases/components/CmmnCasePlanPanel.vue` and `CmmnPlanItemNode.vue`, and their `registry.js` and `manifest.json` entries; verify the replacement panel from 1.2 owns the `cmmn-case-plan` slot and the built bundle contains none of the retired components.
- [ ] 4.2 `tests/Unit/Service/CmmnRetirementTest.php` (class-catching guard): fails on any reference under `lib/` or `src/` to `OCA\Dossiq\Service\Cmmn`, `CmmnCaseController` or `casePlanState` outside a closed, reason-bearing allowlist; the allowlist holds only the migration's blob access until this release and is asserted empty after 3.1 and 3.2 land.

## 5. E2E coverage

- [ ] 5.1 `tests/e2e/cmmn-retirement.spec.ts`: the retirement journey. Anchors `@e2e openspec/specs/retire-cmmn-caseplanstate/spec.md#a-cmmn-case-shows-the-plan-openregister-holds`, `#working-a-plan-item-through-the-panel` and `#the-old-routes-answer-404-after-removal` (open a CMMN case, work an item through the panel, probe the retired routes for 404).
- [ ] 5.2 Migration proof in the same spec: seed a case with a populated blob, run the migration, assert the case detail shows the same items in the same states from openregister rows; anchor `#a-drained-case-shows-the-same-plan`.
- [ ] 5.3 Update `tests/e2e/case-detail-kpis-and-tabs.spec.ts`: remove the `cmmn-plan` 409 allowances from its console and network assertions and anchor `#a-bpmn-case-is-untouched` on the BPMN case-detail assertions.

## 6. Quality

- [ ] 6.1 Analyzers green on the touched files: lint, phpcs, psalm, phpstan individually, phpmd per subdir; eslint and vitest for the frontend removals.
- [ ] 6.2 Hydra gates with `--scope-to-diff` report 0 FAIL on the diff; gate-16 `@spec` tags on every changed method point at `openspec/specs/retire-cmmn-caseplanstate/spec.md`.
