# Workflow definition model, per-route uniqueness delta

**Spec refs**: `workflow-definition-model` (REQ-001, REQ-002), and the new `workflow-variants` capability.

## MODIFIED Requirements

### REQ-001: WorkflowDefinitionController SHALL expose lifecycle + lookup endpoints

`OCA\Dossiq\Controller\WorkflowDefinitionController` SHALL provide HTTP endpoints for: `publish($id)` (move draft → active), `deprecate($id)` (active → deprecated), `cloneDefinition($id)` (create new draft from existing), `active($caseTypeId)` (lookup currently-active version for a case type), and `forCase($caseId)` (lookup version bound to a specific case). Each endpoint SHALL delegate to `WorkflowDefinitionService` and SHALL reject lifecycle transitions that violate the draft → active → deprecated state machine.

`active($caseTypeId)` SHALL return the case type's default route. A clone SHALL carry its source definition's `variant`, so cloning a route produces a draft of that route and not of the default one.

#### Scenario: Publish a draft
- **WHEN** `POST /api/workflow-definitions/{id}/publish` is called on a draft definition
- **THEN** the definition's status SHALL flip to active and any previously-active version **of the same route on the same case type** SHALL be auto-deprecated
- **AND** an active definition on a different route of that case type SHALL be left published and active

#### Scenario: A clone stays on its own route
- **WHEN** `POST /api/workflow-definitions/{id}/clone` is called on a definition of the `spoedeisend` route
- **THEN** the resulting draft SHALL be on the `spoedeisend` route

### REQ-002: WorkflowDefinitionService SHALL implement the full lifecycle + version selection

`OCA\Dossiq\Service\WorkflowDefinitionService` SHALL provide the canonical version-selection logic (`getActiveDefinitionFor($caseTypeId, $variant)`, `getDefinitionForCase($caseId)`, `listVersions($caseTypeId)`) and the full lifecycle (`createDraft`, `publish`, `deprecate`, `cloneDefinition`, `getDefinition`).

Version selection SHALL be deterministic: **at most one active version per (case type, route) at any time**. A case bound to a specific version SHALL continue to use that version even after a newer one is published: versions, not branches.

Several routes MAY be active on one case type at the same time. That is what a route is for, and it is the one thing this rule now permits that it did not before. A case type with one route behaves exactly as it did under the per-case-type rule.

`getActiveDefinitionFor($caseTypeId)` called without a route SHALL return the case type's default route, so every existing caller keeps reading what it read before.

#### Scenario: Existing case keeps its bound version after a new one is published
- **GIVEN** case C bound to workflow version v1
- **WHEN** v2 is published for the same case type
- **THEN** `getDefinitionForCase(C.id)` SHALL still return v1 and only newly-created cases SHALL be bound to v2

#### Scenario: Publishing a route deprecates only that route
- **GIVEN** a case type with an active `regulier` v1 and an active `spoedeisend` v1
- **WHEN** `regulier` v2 is published
- **THEN** `regulier` v1 SHALL be deprecated
- **AND** `spoedeisend` v1 SHALL still be published and active

#### Scenario: One route per case type behaves as before
- **GIVEN** a case type whose definitions all carry no `variant`
- **WHEN** a new version is published
- **THEN** the previously active version SHALL be deprecated
- **AND** the case type SHALL have exactly one active definition

#### Scenario: A case type may not be left without a route
- **GIVEN** a case type with open cases and exactly one published definition
- **WHEN** that definition is deprecated
- **THEN** the deprecation SHALL be refused and the reason SHALL be logged
