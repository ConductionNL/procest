# workflow-definitions-to-flow Specification

**Status**: in progress
**Scope**: dossiq

## Purpose

Give each `workflowTemplate` a representation in the one place ADR-065 says a
flow may live, without taking the running engine away from it.

## ADDED Requirements

### Requirement: REQ-WDF-001 A definition is projected onto a flow

The system SHALL provide an `occ` command that, for each stored
`workflowTemplate`, writes an OpenRegister flow whose nodes are the template's
statuses and whose edges are its transitions.

It SHALL be a command and not a repair step: `FlowService` refuses to create a
flow without a signed-in owner, and a repair step running under `occ upgrade`
has none.

#### Scenario: A template becomes a flow of its statuses

- **GIVEN** a template with three distinct statuses across two transitions
- **WHEN** the migration runs
- **THEN** a flow exists with three `dossiq.setStatus` nodes and two edges

#### Scenario: A template with no usable transitions is skipped

- **GIVEN** a template whose transitions are empty
- **WHEN** the migration runs
- **THEN** it is reported as skipped and NO flow is written, because a flow with
  nodes and no way between them looks like a migration that worked

#### Scenario: A wildcard source is skipped

- **GIVEN** a transition whose `fromStatus` is `*`
- **WHEN** the template is projected
- **THEN** that transition contributes no edge, because an edge with no source
  node is not drawable
- **AND** the remaining transitions still project

### Requirement: REQ-WDF-002 The projection arrives disabled

A projected flow SHALL be created with `enabled: false`.

The template still drives cases through `StatusTransitionService`. An enabled
projection is a second thing driving the same case, so every status change would
fire twice from the moment the migration runs — and would look like it worked.

#### Scenario: A freshly projected flow is disabled

- **WHEN** a template is projected
- **THEN** the stored flow reports `enabled: false`

### Requirement: REQ-WDF-003 Statuses travel by name

A projected node SHALL carry the status NAME, never a `statusType` id.

A statusType uuid is minted per installation, so a flow carrying one is portable
nowhere. `dossiq.setStatus` resolves a name inside the case's own case type.

#### Scenario: The nodes carry names

- **GIVEN** a template whose transitions name `Ontvangen` and `In behandeling`
- **WHEN** it is projected
- **THEN** the nodes' `status` config values are those names

### Requirement: REQ-WDF-004 A re-run updates rather than duplicating

The migration SHALL resolve an already-projected flow by a provenance marker
stored on the flow, and update it. It SHALL NOT match on the flow's name: a name
is editable, and matching on one would mint a second flow as soon as somebody
renamed the first.

#### Scenario: Running twice yields one flow

- **GIVEN** a template already projected
- **WHEN** the migration runs again
- **THEN** the existing flow is updated and the run reports it as updated

#### Scenario: A dry run writes nothing

- **WHEN** the migration runs with `--dry-run`
- **THEN** no flow is written and the report still names what would happen

### Requirement: REQ-WDF-005 A partial run reports itself as partial

One template that cannot be projected SHALL NOT abandon the rest, and the
command SHALL exit non-zero when any template failed.

#### Scenario: A failed write is counted

- **GIVEN** a flow write that is refused
- **WHEN** the migration runs
- **THEN** that template is counted as failed and the others still project

### Requirement: REQ-WDF-006 Flows is the single authoring entry

The settings menu SHALL offer exactly one entry for authoring how a case moves,
and it SHALL be Flows.

Two entries stood next to each other at orders 96 and 97, named Flows and
Workflow definitions, wearing `Sitemap` and `SitemapOutline`. They read as one
feature listed twice, and the pair was actively misleading rather than merely
redundant: editing a definition does not reach the running flow unless somebody
re-runs the projection, and re-running it overwrites whatever was authored on
the canvas. A reader who picked the wrong one of two near-identical entries got
a screen whose edits quietly did nothing.

The definitions page SHALL remain routable. Losing a menu entry is not losing
the page: a projected flow was generated FROM a definition, so a legacy link
must still land on the definition rather than on the dashboard. This follows the
precedent set for Approval routes, which lost its entry for the same reason when
an approval route became a flow.

#### Scenario: The menu offers Flows once

- **GIVEN** the settings menu
- **THEN** Flows is present and Workflow definitions is absent

#### Scenario: The definitions page survives its menu entry

- **WHEN** `/settings/workflow-definitions` is opened directly
- **THEN** the definitions page renders rather than the dashboard
