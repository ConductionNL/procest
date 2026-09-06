# dossiq-decisions-to-decidiq

dossiq owns cases; decidiq owns decisions. dossiq raises every decision in decidiq over the typed event seam and records only what decidiq concluded.

### Requirement: REQ-DDTD-001 — No local decision authoring

dossiq SHALL NOT author a decision verdict it computed itself. Every new decision SHALL be raised in decidiq via the delegation services (`DecisionRequestedEvent`) or the `dossiq.requestDecision` flow node, and SHALL fail closed when decidiq is unavailable. Decision outcomes on a case SHALL be written only as projections of a decidiq conclusion (`BesluitMaterialisationService`), as record keeping mandated by Awb/ZGW, or by one-time migrations. The set of files allowed to write decision-schema objects is closed and reason-bearing.

#### Scenario: A new decision writer fails the structural test

- **GIVEN** a new file under `lib/` that calls `saveObject`/`updateObject` and references a decision schema binding
- **WHEN** `LocalDecisionAuthoringTest` runs
- **THEN** the test fails and names the delegation seam as the door to use

`@e2e exclude` structural invariant over source files; asserted by the PHPUnit structural test, no browser surface exists.

#### Scenario: The allowlist cannot grow silently

- **GIVEN** the closed allowlist of today's decision-schema writers
- **WHEN** an entry is added without a reason string
- **THEN** the test fails

`@e2e exclude` structural invariant over source files; asserted by the PHPUnit structural test.

### Requirement: REQ-DDTD-002 — No local decision-authoring UI

dossiq SHALL NOT ship UI that creates, edits or deletes decision or proposal records locally. The decidiq leaf (`BesluitvormingLeafTab`, `case-decidesk-decisions`) is the authoring surface. Read-only display of outcomes stored on the case (the `case-decisions` widget, detail pages kept routable per ADR-044) is case data and stays.

#### Scenario: The retired components are gone

- **GIVEN** the built bundle
- **WHEN** the source is searched for `CaseDecisionsTab` and `VoorstelCreateDialog`
- **THEN** no component, registry entry or import remains

`@e2e exclude` the components were mounted on no page and asserted by no e2e spec; absence is verified by grep and the vitest/eslint suites.

### Requirement: REQ-DDTD-003 — Local DMN evaluation is deprecated

dossiq's decision-table stack (schema, service, controller, transition handler, admin tab) is deprecated. It SHALL keep working until openregister `flow-decision-tables` lands, and SHALL be retired then. No new consumer of `DecisionTableEvaluator` may appear in dossiq: the set of referencing files is closed and shrinks to empty at retirement.

#### Scenario: A new evaluator consumer fails the structural test

- **GIVEN** a new file under `lib/` referencing `DecisionTableEvaluator`
- **WHEN** `LocalDecisionAuthoringTest` runs
- **THEN** the test fails and points at openregister `flow-decision-tables`

`@e2e exclude` structural invariant over source files; asserted by the PHPUnit structural test.
