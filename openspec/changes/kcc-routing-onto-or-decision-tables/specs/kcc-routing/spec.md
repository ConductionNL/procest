# kcc-routing Specification (delta)

**Status**: in progress
**Scope**: dossiq

## Purpose

KCC routing-rule evaluation runs on OpenRegister's shared decision-table
evaluator; dossiq keeps the KCC condition dialect, its derivations, and agent
ranking.

## ADDED Requirements

### Requirement: Routing rules evaluate through the shared decision-table engine

The system SHALL evaluate KCC routing rules by compiling them into an inline
decision table (hit policy FIRST, enabled rules ascending by priority,
declaration order breaking ties) and running it through
`OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator`. The system SHALL NOT
keep a private rule-matching engine on the runtime path.

The observable contract SHALL be the legacy one: the first enabled rule whose
conditions all hold wins; no match answers null; a rule that could never
match under the legacy engine (contradictory equalities, malformed time
window, unknown condition type, empty conditions, disabled) still never
matches.

#### Scenario: A keyword rule routes a contact moment

- **GIVEN** an enabled rule with a keyword condition and a contact moment whose subject carries the keyword
- **WHEN** routing is evaluated
- **THEN** the rule's domain, team and escalation team are answered via the shared evaluator

`@e2e exclude` pure backend evaluation with no owned UI surface; pinned by
RoutingTableEvaluatorTest and the parity matrix.

#### Scenario: The lowest-priority match wins regardless of listing order

- **GIVEN** two matching enabled rules with priorities 9 and 1, listed in that order
- **WHEN** routing is evaluated
- **THEN** the priority-1 rule is answered

`@e2e exclude` pure backend evaluation; pinned by RoutingTableEvaluatorTest.

#### Scenario: No matching rule answers null

- **GIVEN** rules none of which match the contact moment
- **WHEN** routing is evaluated
- **THEN** the evaluation answers null and the caller reports `matched: false`

`@e2e exclude` pure backend evaluation; pinned by RoutingTableEvaluatorTest.

### Requirement: Domain derivations stay in dossiq

The system SHALL derive the shared engine's inputs itself: the lower-cased
subject+summary haystack, keyword and regex predicates as boolean columns,
the KvK-number customer-type rule, minutes-since-midnight for time windows,
and the lower-cased day of week. The shared unary-test grammar SHALL NOT be
extended with substring or regex operators for this consumer.

#### Scenario: Customer type is derived from the reference

- **GIVEN** rules routing bedrijf, burger and anoniem to different teams
- **WHEN** contact moments with an 8-digit reference, a non-numeric reference and no reference are routed
- **THEN** they route to the bedrijf, burger and anoniem teams respectively

`@e2e exclude` pure backend derivation; pinned by RoutingTableEvaluatorTest
and the parity matrix.

### Requirement: The evaluation fails closed without the shared engine

When OpenRegister's evaluator class is unavailable the system SHALL refuse
routing loudly. The system SHALL NOT fall back to a private matcher.

#### Scenario: A missing evaluator refuses rather than guesses

- **GIVEN** an environment where the shared evaluator class does not exist
- **WHEN** routing is evaluated
- **THEN** a RuntimeException names what is missing and nothing is routed

`@e2e exclude` requires an instance without OpenRegister, which the e2e rig
cannot provide; the guard is a two-line class_exists refusal exercised only
when the autoloadable stub is absent.

### Requirement: The legacy engine survives only as the parity oracle

The legacy `RoutingEngine::evaluate()` SHALL remain, deprecated and closed to
new callers, until the staged retirement completes; a parity test SHALL drive
both paths over one pinned fixture matrix covering every condition type and
every never-matching rule shape.

#### Scenario: The two paths agree across the fixture matrix

- **GIVEN** the pinned rule set and the moment-by-time sweep
- **WHEN** both the legacy engine and the table compilation evaluate every cell
- **THEN** every cell agrees, and the sweep contains both matches and refusals

`@e2e exclude` a structural parity assertion between two backend evaluators;
KccRoutingParityTest is the pin.
