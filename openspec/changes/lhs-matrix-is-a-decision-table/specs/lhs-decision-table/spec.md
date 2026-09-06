# lhs-decision-table Specification

## Purpose

The enforcement matrix becomes a decision table, evaluated by the one evaluator
the fleet shares, instead of a hand-indexed dictionary in dossiq.

## ADDED Requirements

### Requirement: REQ-LDT-001 A matrix projects onto a decision table

The system SHALL project each stored LHS matrix onto a decision table whose
inputs are severity, behaviour and actorType, whose output is the intervention,
and whose rules are the matrix cells.

Each rule SHALL be identified by its own triple, so a rule is traceable back to
the cell it came from without depending on order.

#### Scenario: Each cell becomes a rule

- **GIVEN** a matrix of four cells over two severities, two behaviours and one actor type
- **WHEN** it is projected
- **THEN** the table MUST declare those three inputs and one output, and carry
  four rules, each identified by its own `severity:behaviour:actorType`

#### Scenario: Both stored shapes are read

- **GIVEN** a matrix whose axes and cells are stored as JSON strings
- **THEN** it MUST project identically to one storing them as arrays

### Requirement: REQ-LDT-002 The table declares UNIQUE

The projected table SHALL declare the `UNIQUE` hit policy.

A grid has exactly one cell per triple. UNIQUE turns an overlapping pair into a
refusal, where the hand-indexed dictionary silently keeps whichever cell was
read last.

#### Scenario: The projection declares UNIQUE

- **GIVEN** any projected matrix
- **THEN** its `hitPolicy` MUST be `UNIQUE`

### Requirement: REQ-LDT-003 An inconsistent matrix is refused, not projected

The system SHALL skip a matrix in which any cell names a value that is not on
the corresponding axis, and SHALL report the reason.

Projecting it would carry the defect across while looking like a migration that
worked: the rule would be unreachable in the table exactly as the cell is
unreachable in the matrix. dossiq#1596 is that defect, shipped.

#### Scenario: A cell off its axis skips the matrix

- **GIVEN** a matrix whose cell names an actor type absent from its actorTypeAxis
- **WHEN** the migration runs
- **THEN** no table MUST be written for it, and the summary MUST count it
  skipped with the reason

### Requirement: REQ-LDT-004 The projection arrives disabled

The projected table SHALL be created disabled.

The matrix still drives recommendations. A table that also answered would be a
second source of truth for an enforcement decision.

#### Scenario: A freshly projected matrix is disabled

- **GIVEN** any projected matrix
- **THEN** the table MUST carry `enabled: false`

### Requirement: REQ-LDT-005 The evaluator is the lookup

`LhsRecommendationService::recommend()` SHALL resolve the prescribed
intervention by evaluating the projected decision table through OpenRegister,
and SHALL read the matrix directly only when this instance has no enabled
projection for it.

The projection SHALL arrive ENABLED. With the evaluator as the lookup, a
disabled table does not withhold a second opinion, it silently hands the
question back to the matrix and makes the migration a no-op that reports
success.

The matrix path SHALL be retained. Projecting a table needs an owner for the
object it writes, so it is a command a person runs rather than something an
upgrade performs; an instance that has not run it must still be able to
enforce.

@e2e exclude Server-side resolution between two lookup paths. Which of them answered is not observable in a browser: both produce the same recommendation row for a consistent matrix, and that identity is the point. Covered by LhsOverrideAuthorizationTest, which pins the matrix path by injecting a lookup that answers null, and by the migrator suite for the table path.

#### Scenario: An enabled projection answers

- **GIVEN** a matrix with an enabled projected decision table
- **WHEN** a recommendation is requested for a triple the table covers
- **THEN** the intervention MUST come from the evaluator

#### Scenario: No projection falls back to the matrix

- **GIVEN** an instance where the projection has never been run
- **WHEN** a recommendation is requested
- **THEN** the intervention MUST be read from the matrix cells

#### Scenario: A disabled projection is not consulted

- **GIVEN** a projected table an administrator has switched off
- **THEN** the matrix MUST answer instead

### Requirement: REQ-LDT-006 A re-run updates, and refuses an edited table

A re-run SHALL resolve the existing table by its provenance marker and update
it, rather than writing a second table carrying the same marker.

A re-run SHALL REFUSE a table whose rules differ from the projection, and
report the refusal. The projection is one-way and the matrix no longer has an
authoring surface, so overwriting edited rules would replace an
administrator's work with a source they cannot read.

Only the rules SHALL be compared. A renamed table, a reworded description or a
toggled enabled flag are an administrator's business.

@e2e exclude A property of the occ migration, which has no browser surface at all. Covered by LhsMatrixDecisionTableMigratorTest over a schema-aware fake register: a fake answering both schemas alike could not express "a table already exists" and the guard would never fire.

#### Scenario: A re-run does not duplicate

- **GIVEN** a matrix already projected once
- **WHEN** the migration runs again
- **THEN** exactly one table MUST exist, and the write MUST target it

#### Scenario: An edited table is refused

- **GIVEN** a projected table whose rules have been changed
- **WHEN** the migration runs again
- **THEN** nothing MUST be written for it
- **AND** the run MUST report it as skipped, naming the edit
