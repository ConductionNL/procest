# KCC routing onto OpenRegister's decision tables

## Why

The fleet audit flagged dossiq's KCC routing rules as table-shaped engine
duplication: `RoutingEngine::evaluate()` is an ordered-rules, first-match,
all-conditions-hold evaluator — a decision table with a FIRST hit policy,
grown privately inside the KCC module. The directive is that apps get no rule
engines of their own: OpenRegister ships the fleet's one evaluator
(`lib/Service/Dmn`, openregister#3329), pure and directly constructible for
exactly this kind of non-flow consumer, and humaniq#289 has already walked the
adoption path this change follows.

dossiq's generic DMN surface (`EvaluateDecisionHandler`,
`DecisionTableController`) already consumes the shared evaluator
(`dossiq-consumes-shared-dmn`). The KCC routing rules were the remaining
private matcher.

## What changes

- New `RoutingTableEvaluator` (lib/Service/Kcc): compiles the KCC routing
  rules into one inline decision table with hit policy FIRST and evaluates it
  through `OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator`, constructed
  directly. It fails closed when the evaluator is absent — no fallback
  matcher.
- `RoutingRuleService::route()` evaluates through it. Agent ranking
  (`RoutingEngine::rankAgents()`) is untouched: workload scoring and
  continuity are KCC domain logic, not a rule engine.
- The legacy `RoutingEngine::evaluate()` stays as the PARITY ORACLE, marked
  deprecated with a no-new-callers instruction. `KccRoutingParityTest` drives
  both paths over one pinned fixture matrix (all six condition types, the
  priority order, the disabled flag, every never-matching rule shape, 40
  moment×time cells). Its staged retirement is tasks section 5.
- `tests/Stubs/Service/Dmn` becomes verbatim copies of OpenRegister's pure
  Dmn classes (pinned at openregister@2839ab901a), loaded only when the real
  classes are absent, so the standalone suite proves the real evaluation
  semantics rather than a signature-only stub's agreement with itself.

## The line drawn

Generic rule evaluation — cell matching, hit policies, typed coercion — is
OpenRegister's. KCC keeps its domain semantics: the condition dialect and its
derivations (the subject+summary haystack, keyword and regex predicates, the
KvK-number customer-type rule, time-of-day windows, day-of-week), the routing
result vocabulary, escalation, and agent ranking. Keyword and regex matching
deliberately do NOT move into the shared unary-test grammar: they compile to
boolean columns whose values dossiq derives — the humaniq `derive` seam.

## What does not change

The routing contract observable by callers: first enabled rule in ascending
priority wins, all of a rule's conditions must hold, no match answers null,
and a rule the legacy engine could never match (contradictory equalities, a
malformed time window, an unknown condition type, no conditions) still never
matches. The parity test is the proof.
