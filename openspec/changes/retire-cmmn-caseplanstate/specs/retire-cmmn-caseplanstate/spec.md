# retire-cmmn-caseplanstate

dossiq owns cases; openregister owns case semantics. The local CMMN runtime and the `case.casePlanState` blob retire, and adaptive case plans live as openregister plan-item rows served on `/api/cases`.

## ADDED Requirements

### Requirement: REQ-RCMN-001 Case semantics are consumed from openregister

For caseTypes with `handlingModel: cmmn`, dossiq SHALL read the case plan from, and transition plan items through, openregister's `/api/cases` surface. dossiq SHALL NOT evaluate plan-item lifecycle, sentries, cascades or stage completion locally. A published `caseModel` SHALL be projected onto openregister plan items at case start by `CasePlanProjectionService`, converting sentries per the closed table in design.md section 2 and refusing what the table does not name. When openregister cannot be reached, the adaptive plan surface SHALL fail closed with an error, never render a silently empty plan, and never fall back to local evaluation.

#### Scenario: A CMMN case shows the plan openregister holds

- **GIVEN** a case of a caseType with `handlingModel: cmmn` and a published caseModel
- **WHEN** the case detail is opened
- **THEN** the adaptive plan panel renders the stages, tasks and milestones that openregister's `/api/cases` returns for the case's object uuid, and no `/apps/dossiq/api/case/*/cmmn-plan*` request is made
- @e2e covered by the retirement journey spec, `tests/e2e/cmmn-retirement.spec.ts` (tasks.md 5.1)

#### Scenario: An illegal transition is refused by openregister, not judged locally

- **GIVEN** a plan item in a terminal state
- **WHEN** a further transition is requested from the panel
- **THEN** openregister refuses it naming the item, type, from-state and to-state, and dossiq relays that refusal without a second local opinion
- @e2e exclude refusal semantics are asserted by openregister's transition-table unit matrix; dossiq only relays the error, which the vitest error-path test covers

#### Scenario: openregister unavailable fails closed

- **GIVEN** a CMMN case whose plan request to openregister fails
- **WHEN** the case detail is opened
- **THEN** the panel shows an error state and offers retry; it does not render an empty plan and does not fall back to local evaluation
- @e2e exclude an openregister outage is not reproducible on the shared e2e instance without harming neighbouring specs; asserted by a vitest failure-path test over the panel

### Requirement: REQ-RCMN-002 In-flight cases are drained losslessly

Every case with a populated `casePlanState` blob SHALL be converted to openregister case-item rows by `occ dossiq:cmmn:migrate-case-plans` (also run as a repair step): recorded states carried verbatim, parent links and discretionary flags recovered from the backing caseModel, case-file values written onto the case object as declared properties, and history imported into the append-only audit. The migration SHALL be convergent: keyed on the case uuid and definition item id, it creates only missing rows, appends audit entries only for rows created in that run, and clears the blob only after verifying the row set and states match it. A case the migration cannot map SHALL be left completely intact, blob included, and reported with its uuid and a reason; the command SHALL exit non-zero under `--strict` while the repair step logs and continues.

#### Scenario: A drained case shows the same plan

- **GIVEN** a CMMN case with a populated `casePlanState` blob recording items in mixed states
- **WHEN** the migration runs and the case detail is opened afterwards
- **THEN** the panel shows the same items in the same states, now served from openregister rows, and the case's blob is empty
- @e2e covered by the migration scenario in the retirement journey spec, `tests/e2e/cmmn-retirement.spec.ts` (tasks.md 5.2)

#### Scenario: A re-run and a crash resume change nothing they should not

- **GIVEN** a fixture set of fully migrated, half-written, empty and unmappable cases
- **WHEN** the migration runs twice
- **THEN** both runs converge on identical rows, no duplicate audit entries exist, and the unmappable cases are byte-identical to before
- @e2e exclude occ-level behaviour with no browser surface; asserted by the double-run PHPUnit suite over the fixture set

#### Scenario: An unmappable case is reported, never destroyed

- **GIVEN** a case whose blob does not decode, or references an item id no resolvable caseModel declares
- **WHEN** the migration runs
- **THEN** the case keeps its blob unchanged, the report names its uuid and the reason, and `--strict` makes the command exit non-zero
- @e2e exclude occ report surface; asserted by PHPUnit over the unmappable fixtures

### Requirement: REQ-RCMN-003 No CMMN runtime remains

After the removal release, `lib/Service/Cmmn/` SHALL NOT exist and no file under `lib/` or `src/` SHALL reference an `OCA\Dossiq\Service\Cmmn` class, `CmmnCaseController`, or the `casePlanState` property, outside a closed, reason-bearing allowlist. The allowlist covers the migration's blob access until the removal release and SHALL be empty after it. A structural test enforces this mechanically so a new local case engine cannot ship one convenient commit at a time.

#### Scenario: A new CMMN runtime reference fails the structural test

- **GIVEN** a new file under `lib/` or `src/` referencing `OCA\Dossiq\Service\Cmmn` or reading `casePlanState`
- **WHEN** `CmmnRetirementTest` runs
- **THEN** the test fails and names openregister's `/api/cases` surface as the door to use
- @e2e exclude structural invariant over source files; asserted by the PHPUnit structural test, no browser surface exists

#### Scenario: The retired code is gone

- **GIVEN** the removal release
- **WHEN** the tree is searched for the nine runtime classes, the controller, `cmmnApi.js`, the two Vue components and their registry and manifest entries
- **THEN** none remains, and the structural test's allowlist is empty
- @e2e exclude absence is verified by the structural test and grep; the surviving user surface is asserted by the retirement journey spec

### Requirement: REQ-RCMN-004 The blob retires after the drain, not before

The `casePlanState` property SHALL stay declared in the case schema until the drain report shows zero populated blobs and zero unmappable cases, because openregister silently drops undeclared properties on save and an early removal would destroy remaining blobs. In the removal release the property is removed from `dossiq_register.json` and `dossiq_mock_register.json`, the five `/api/case/{caseId}/cmmn-plan*` routes are gone, and `handlingModel: cmmn` is redescribed as selecting the openregister case layer.

#### Scenario: The schema keeps the property while any blob remains

- **GIVEN** a deployment where the drain report still lists populated or unmappable cases
- **WHEN** the case schema shipped by dossiq is inspected
- **THEN** `casePlanState` is still declared, so no ordinary save strips a remaining blob
- @e2e exclude a release-ordering invariant with no browser surface; asserted by the structural test tying property removal to the empty allowlist

#### Scenario: The old routes answer 404 after removal

- **GIVEN** the removal release
- **WHEN** `/apps/dossiq/api/case/{caseId}/cmmn-plan` is requested
- **THEN** the router answers 404 and nothing in the response names the retired engine
- @e2e covered by the route-absence probe in the retirement journey spec, `tests/e2e/cmmn-retirement.spec.ts` (tasks.md 5.1)

### Requirement: REQ-RCMN-005 The case detail renders the openregister plan

The `cmmn-case-plan` widget slot SHALL be served by a panel over openregister's `/api/cases`: items grouped by stage with state badges, enable, complete and terminate actions, discretionary items listed when enableable, and attach-an-ad-hoc-item where openregister authorizes it. BPMN-managed caseTypes SHALL be unaffected: the panel renders nothing for them and their status transitions keep flowing through `StatusTransitionService`.

#### Scenario: Working a plan item through the panel

- **GIVEN** a CMMN case with an enabled human task
- **WHEN** the caseworker completes it from the panel
- **THEN** the transition goes to openregister, the panel reflects the new state, and dependent items whose sentries fired become available
- @e2e covered by the retirement journey spec, `tests/e2e/cmmn-retirement.spec.ts` (tasks.md 5.1)

#### Scenario: A BPMN case is untouched

- **GIVEN** a case of a caseType with `handlingModel: bpmn`
- **WHEN** the case detail is opened
- **THEN** the adaptive plan panel renders nothing, no `/api/cases` plan request is made for it, and the status-transition actions behave as before
- @e2e covered by the updated case-detail spec, `tests/e2e/case-detail-kpis-and-tabs.spec.ts` (tasks.md 5.3)
