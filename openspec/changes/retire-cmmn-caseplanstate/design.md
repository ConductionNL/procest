# Design: retire-cmmn-caseplanstate

## 1. Construct mapping

Every dossiq CMMN construct has a named counterpart in openregister's case layer (`flow-cmmn-case-semantics`, merged). Nothing is reinvented; where behaviour differs the difference is stated.

| dossiq construct | openregister counterpart | Notes |
|---|---|---|
| `CaseModelEngine` (transition orchestration, plan view) | `CasePlanService` + `CasePlanStateMachine` | One transition per call there too; the plan view is a query over rows. |
| `PlanItemTransitions` (exhaustive legal-edge table) | `CasePlanTransitions` | Same six states, same table, same milestone asymmetry (a milestone may only go `available → completed` or `available → terminated`). No mapping needed on state values. |
| `PlanItemStateMachine` | `CasePlanStateMachine` | Illegal transitions are refused naming item, type, from-state and to-state; dossiq's `IllegalPlanItemTransitionException` retires with the engine. |
| `SentryEvaluator` (`{field, operator, value}` if-part, `onPart` on plan items and case-file items) | `CaseSentryEvaluator` (JSONLogic if-part via `FlowExpression::isTrue()`, on-part from the flow event catalog: `case.item.completed` / `.terminated` / `.disabled`) | Dialect conversion at projection and migration time, table in section 2. dossiq's dialect is deliberately not adopted upstream (a fourth condition dialect, openregister#2787). |
| `PlanItemCascade` (depth-bounded fixpoint) | `CasePlanCascade` | Same fixpoint idea, bounded upstream as well. |
| `PlanItemTree` (stage-completion rule) | Stage completion as a written rule (required children terminal, no child active) | Identical rule, now specified instead of implied. |
| `CasePlanRepository` (blob read-modify-write on `case.casePlanState`) | `openregister_case_items` rows + append-only `openregister_case_item_audit` | Rows are queryable and transitionable concurrently; the blob is neither. |
| `casePlanState.planItemStates` | The `state` column, one row per item | |
| `casePlanState.milestones` | Milestone plan-item rows | |
| `casePlanState.caseFile` | Declared properties on the case object itself | Sentries read the object through `FlowExpression`; an ordinary object write may satisfy a sentry. Case-file slots become declared schema properties, because openregister drops what the schema does not declare. |
| `casePlanState.eventLog` | `openregister_case_item_audit` | Append-only; the migration imports history, section 3. |
| `CaseModelEngine::getPlanItemAuthorization()` (answer returned to the caller) | `CasePlanAuthorizationService` (decides itself, fail-closed; indeterminate answers deny) | A behaviour improvement, not a regression: dossiq's caller-interpreted answer was the weaker shape. |
| `CmmnCaseController` routes `plan` / `enable` / `complete` / `terminate` / `signal` on `/api/case/{caseId}/cmmn-plan*` | openregister `CaseController` on `/api/cases`: read plan by object uuid, transition an item, enable a discretionary item, attach an ad-hoc item, list enableable items, list cases by item type and state | `signal` (case-file event) becomes an ordinary object write that sentries observe. Ad-hoc items are new capability dossiq's engine never had. |
| `cmmnApi.js`, `CmmnCasePlanPanel.vue`, `CmmnPlanItemNode.vue` | A case-detail panel over openregister's `/api/cases` | Same widget slot (`cmmn-case-plan`), new data source. |
| `caseModel` definition (register.d fragment) | No counterpart is needed | Stays as dossiq authoring data; projected per case, section 2. |

The case anchor needs no mapping at all: openregister's plan items anchor on the OpenRegister object uuid, and a dossiq case already is an OpenRegister object.

## 2. Projection: definition to plan items

openregister deliberately has no `caseModel` entity; a case's plan items are rows created for that case. dossiq therefore keeps `caseModel` as its authoring artifact and projects it at case start.

`CasePlanProjectionService`, on creation of a case whose caseType has `handlingModel: cmmn`:

1. Resolves the published `caseModel` for the caseType (the `CaseModelLoader` rule: exactly one published model, none means an empty plan).
2. Creates one openregister plan item per defined `planItem`: type, name, parent link, `discretionary` flag, converted entry and exit criteria.
3. Converts each sentry: the on-part `{planItem, standardEvent}` becomes the matching `case.item.completed` / `.terminated` / `.disabled` catalog event; an on-part on a `caseFileItem` becomes the object-write event for the case object. The if-part converts per this table:

| dossiq operator | JSONLogic |
|---|---|
| `eq` | `{"==": [{"var": f}, v]}` |
| `neq` | `{"!=": [{"var": f}, v]}` |
| `gt` / `gte` / `lt` / `lte` | `{">": …}` / `{">=": …}` / `{"<": …}` / `{"<=": …}` |
| `in` | `{"in": [{"var": f}, v]}` |
| `notIn` | `{"!": {"in": [{"var": f}, v]}}` |
| `truthy` | `{"!!": {"var": f}}` |
| `falsy` | `{"!": {"var": f}}` |

Fidelity notes: dossiq's `eq`/`neq` are loose comparisons by design and JSONLogic's `==`/`!=` are loose too, so semantics carry over. dossiq's `in` used strict `in_array`; JSONLogic `in` is looser on numeric strings. The projection test suite includes a fixture per operator, and the conversion refuses (and reports) any operator outside this closed table rather than guessing.

`FlowExpression::isTrue()` returns false for an expression it cannot evaluate, which preserves dossiq's fail-closed rule for malformed conditions.

## 3. Migration of in-flight cases

The command is `occ dossiq:cmmn:migrate-case-plans` (`--dry-run`, `--strict`), also run as a repair step. Scope: every case whose caseType has `handlingModel: cmmn` and whose `casePlanState` is non-empty.

**Per case, the step is convergent, not skip-based:**

1. Decode the blob (`planItemStates`, `milestones`, `caseFile`, `eventLog`). Undecodable JSON makes the case unmappable.
2. Resolve the `caseModel` that backed it, to recover each item's type, parent, discretionary flag and criteria.
3. Diff against the plan items openregister already holds for this case uuid, keyed on the definition item id. Create only the missing rows, carrying the recorded state verbatim (the six states are identical on both sides). A crash mid-case therefore resumes by creating what is missing; it never duplicates and never skips a half-written case.
4. Write case-file values onto the case object as declared properties through the ordinary object-write path.
5. Append one audit entry per created row with cause `migration:retire-cmmn-caseplanstate`, and import the blob's `eventLog` entries as audit records marked imported. Audit entries are only appended for rows created in this run, so a re-run adds none.
6. Verify: the row set matches the blob's item set and every state matches. Only then clear the blob (an ordinary object write setting `casePlanState` to empty). Clearing is the per-case commit point; until it happens the blob remains the recoverable source.

**Unmappable cases.** A case is left completely intact (blob included) and reported with its uuid and a reason when: the blob does not decode; it references an item id no resolvable `caseModel` declares; a recorded state is outside the six; or a sentry uses an operator outside the conversion table. The command prints the report and exits non-zero under `--strict`; the repair step logs the report and continues, so an upgrade never bricks on one bad case. Disposition of reported cases is manual and the blob is never destroyed for them.

**Idempotency proof.** The unit suite runs the migration twice over a fixture set (fully populated, half-written, empty, unmappable) and asserts identical rows, no duplicate audit entries, and untouched unmappable cases both times.

## 4. Rollout and rollback

Three releases, in order:

- **R1, bridge.** Projection, consumption and the migration ship. The case-detail panel reads openregister rows when they exist for the case and falls back to the local engine while a blob is present. A reverse projection (`occ dossiq:cmmn:rollback-case-plans`) can regenerate a blob from rows; it is total because rows carry strictly more information than the blob. Rollback here is: stop preferring rows, run the reverse projection where wanted.
- **R2, drain.** The repair step migrates on upgrade; operators run the command for stragglers. Exit gate: the drain report shows zero populated blobs and zero unmappable cases.
- **R3, removal.** Delete the runtime, controller, routes, frontend consumers and tests; remove the `casePlanState` property from `dossiq_register.json` and `dossiq_mock_register.json`; drop the reverse projection; shrink the structural test's allowlist to empty. Rollback after R3 is a release rollback; that is why R3 waits for the R2 gate.

Ordering hazard, restated from the proposal because it is the one that destroys data: openregister drops undeclared properties on save. The schema keeps `casePlanState` declared until the R2 gate is met; removing it earlier silently strips every remaining blob on the next save of those cases.

## 5. Risks

- **Undeclared-property stripping** (above). Mitigation: R3 is gated, and the structural test allowlists the migration's blob access until R3 so the guard cannot force a premature removal.
- **e2e specs encode the old runtime.** `tests/e2e/case-detail-kpis-and-tabs.spec.ts` allowlists the panel's `cmmn-plan` probe 409s in its console and network assertions. After R3 those allowances match nothing; they are removed with the routes, and the case-detail e2e run proves BPMN cases unaffected.
- **The coexistence test retires with the engine.** `CmmnBpmnCoexistenceRegressionTest` asserts the engine leaves BPMN caseTypes alone; its subject disappears. Its invariant (BPMN untouched by the cmmn path) moves into the retirement e2e journey and the structural test.
- **`handlingModel: cmmn` changes meaning**, from "the local CaseModelEngine drives this caseType" to "the openregister case layer drives it". The enum value stays; its description in `dossiq_register.json` is rewritten so no stale prose points at the removed engine.
- **Runtime dependency on openregister hardens.** After R3 there is no fallback: an unavailable openregister means the adaptive panel fails closed with an error, never a silently empty plan. dossiq already cannot function without openregister, so this widens no real window.
- **Sentry conversion fidelity.** Covered per operator by projection fixtures (section 2); the closed conversion table refuses the unknown instead of approximating it.
