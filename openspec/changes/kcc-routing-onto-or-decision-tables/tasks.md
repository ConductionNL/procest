# Tasks: kcc-routing-onto-or-decision-tables

> Wave 4 of the fleet's engine consolidation. Reference adopter: humaniq#289;
> engine: openregister#3329 (`lib/Service/Dmn`, merged).

## Implementation Tasks

### Task 1: The compiler-evaluator
- **spec_ref**: `openspec/changes/kcc-routing-onto-or-decision-tables/specs/kcc-routing/spec.md#requirement-routing-rules-evaluate-through-the-shared-decision-table-engine`
- **files**: `lib/Service/Kcc/RoutingTableEvaluator.php`
- [x] Implement — compile (FIRST, stable priority sort, quoted literals, folded time windows, boolean derive columns, inexpressible rules dropped), evaluate, `no_rule_matched` → null, fail closed without the engine
- [x] Test — `RoutingTableEvaluatorTest` (keyword, no-match, priority, disabled, conjunction, time window incl. boundary, quoted `-` channel, every inexpressible shape, customer types, empty rule set)

### Task 2: Real semantics in the standalone suite
- **spec_ref**: `.../spec.md#requirement-routing-rules-evaluate-through-the-shared-decision-table-engine`
- **files**: `tests/Stubs/Service/Dmn/DecisionTableEvaluator.php`, `tests/Stubs/Service/Dmn/UnaryTestEvaluator.php`
- [x] Implement — verbatim copies of openregister@2839ab901a's pure Dmn classes replace the signature-only stubs (loaded only when the real classes are absent)
- [x] Test — the existing Dmn consumers (EvaluateDecisionHandlerTest, DecisionTableControllerTest) stay green over the real copies

### Task 3: The consumer switch
- **spec_ref**: `.../spec.md#requirement-routing-rules-evaluate-through-the-shared-decision-table-engine`
- **files**: `lib/Service/Kcc/RoutingRuleService.php`
- [x] Implement — `route()` evaluates through `RoutingTableEvaluator`; `rankAgents()` stays on `RoutingEngine`
- [x] Test — covered by the parity matrix plus the untouched RoutingEngineTest ranking tests

### Task 4: The parity oracle
- **spec_ref**: `.../spec.md#requirement-the-legacy-engine-survives-only-as-the-parity-oracle`
- **files**: `tests/Unit/Service/Kcc/KccRoutingParityTest.php`, `lib/Service/Kcc/RoutingEngine.php`
- [x] Implement — `RoutingEngine::evaluate()` marked deprecated/no-new-callers; 40-cell moment×time sweep with every never-matching shape at priority 0
- [x] Test — the sweep asserts agreement everywhere AND that it exercises both matches and refusals across several winning rules

## Section 5: Staged retirement (NOT this change)

- [ ] After the shared path has run real data (one release), delete
      `RoutingEngine::evaluate()`, `ruleMatches()`, `conditionMatches()`,
      `timeOfDayMatches()` and the legacy halves of `RoutingEngineTest`;
      rehome `customerType()` coverage onto `RoutingTableEvaluatorTest`;
      retire `KccRoutingParityTest` with the oracle it exists to consult.
- [ ] `RoutingEngine` then shrinks to agent ranking; consider renaming it
      `AgentRanker` in the same pass so the name stops promising an engine.
