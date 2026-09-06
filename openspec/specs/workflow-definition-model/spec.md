---
status: done
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
---

## Purpose

@e2e exclude Workflow definition model is V1; data model and step validation are backend concerns covered by PHPUnit.

## Requirements

### Requirement: Workflow Template Data Model

The system SHALL store workflow definitions as OpenRegister objects in the `dossiq` register under a `workflowTemplate` schema. A workflow template defines the ordered process steps, status transitions, guards, and automatic actions for a specific zaaktype. The model aligns with CMMN 1.1 CasePlanModel concepts and maps to ZGW Catalogi StatusType sequences.

**Feature tier**: V1

| Property | Type | CMMN Mapping | Description |
|----------|------|-------------|-------------|
| `title` | string | CasePlanModel name | Human-readable workflow name |
| `description` | string | -- | Purpose and usage notes |
| `caseType` | reference (UUID) | CaseDefinition | The zaaktype this workflow belongs to |
| `version` | integer | -- | Auto-incrementing version number |
| `isActive` | boolean | -- | Whether this is the active version |
| `isDraft` | boolean | -- | Draft templates cannot be used for new cases |
| `steps` | array of WorkflowStep | Stage[] | Ordered process steps |
| `transitions` | array of StatusTransition | Sentry[] | Allowed status transitions with guards |
| `createdAt` | datetime | -- | Creation timestamp |
| `updatedAt` | datetime | -- | Last modification timestamp |

#### Scenario: Create workflow template for a zaaktype

- **WHEN** an administrator creates a new workflow template for zaaktype "Omgevingsvergunning"
- **THEN** the template SHALL be stored as an OpenRegister object with `isDraft: true` and `version: 1`
- **AND** the template SHALL reference the zaaktype UUID in `caseType`

#### Scenario: Workflow template references existing status types

- **WHEN** a workflow template defines transitions between statuses
- **THEN** each status referenced in transitions SHALL correspond to a StatusType defined on the linked zaaktype
- **AND** the system SHALL validate referential integrity on save

### Requirement: Workflow Step Data Model

The system SHALL define workflow steps as embedded objects within a workflow template. Each step represents a unit of work within a process phase, aligned with CMMN HumanTask or ProcessTask concepts.

**Feature tier**: V1

| Property | Type | CMMN Mapping | Description |
|----------|------|-------------|-------------|
| `id` | string (UUID) | PlanItem ID | Unique step identifier |
| `title` | string | HumanTask name | Step display name |
| `description` | string | -- | Instructions for the handler |
| `status` | reference | Stage | Which status this step belongs to |
| `order` | integer | -- | Execution order within the status |
| `assigneeRole` | reference | -- | Which RoleType can execute this step |
| `isRequired` | boolean | -- | Whether the step must be completed before transition |
| `checklist` | array of ChecklistItem | -- | Items to verify before marking step complete |
| `automaticActions` | array of ActionRef | -- | Actions triggered on step completion |

#### Scenario: Step belongs to a status phase

- **WHEN** a step is created with `status` referencing StatusType "In behandeling"
- **THEN** the step SHALL appear in the workflow editor under that status phase
- **AND** the step SHALL be ordered by its `order` property relative to sibling steps

#### Scenario: Required step blocks status transition

- **WHEN** a step with `isRequired: true` is not yet completed
- **THEN** the status transition that exits that step's status phase SHALL be blocked

### Requirement: Status Transition Data Model

The system SHALL define status transitions as embedded objects within a workflow template. Each transition defines a valid path between two statuses with optional pre-conditions (guards).

**Feature tier**: V1

| Property | Type | CMMN Mapping | Description |
|----------|------|-------------|-------------|
| `id` | string (UUID) | Sentry ID | Unique transition identifier |
| `fromStatus` | reference | Exit criterion source | Source status |
| `toStatus` | reference | Entry criterion target | Target status |
| `label` | string | -- | Transition button label (e.g., "Goedkeuren") |
| `guards` | array of Guard | OnPart/IfPart | Pre-conditions |
| `automaticActions` | array of ActionRef | -- | Actions triggered on transition |
| `allowedRoles` | array of reference | -- | Which RoleTypes may trigger this transition |

Guard types:
- `checklist`: All checklist items must be checked
- `requiredField`: Specific case fields must be filled
- `requiredDocument`: Specific document types must be uploaded
- `roleGuard`: User must have specific role on the case
- `customExpression`: JSONPath expression that must evaluate to true

#### Scenario: Transition with all guards met

- **WHEN** a case handler triggers transition "Goedkeuren" from "In behandeling" to "Afgehandeld"
- **AND** all guards (checklist complete, required documents uploaded, handler has role "behandelaar") are satisfied
- **THEN** the transition SHALL proceed and the case status SHALL change to "Afgehandeld"

#### Scenario: Transition with unmet guards

- **WHEN** a case handler triggers transition "Goedkeuren" but the required document "Besluit" is not uploaded
- **THEN** the transition SHALL be blocked
- **AND** the system SHALL display: "Kan niet doorgaan: document 'Besluit' is vereist"

### Requirement: Pre-Seeded Bezwaar Workflow Template

The system SHALL provide a pre-seeded workflow template for the Bezwaar case type that encodes the AWB-mandated process steps, transitions, and guards. The template SHALL be imported via the repair step alongside the bezwaar case type.

**Feature tier**: V1

The bezwaar workflow template SHALL define the following transitions:

| From Status | To Status | Label | Guards |
|-------------|-----------|-------|--------|
| Ontvangen | Ontvankelijkheidstoets | Start toets | roleGuard: Behandelaar bezwaar |
| Ontvankelijkheidstoets | In behandeling | Ontvankelijk | requiredField: isTimely assessment |
| Ontvankelijkheidstoets | Niet-ontvankelijk | Niet-ontvankelijk verklaren | requiredField: dispositionDetails |
| In behandeling | Hoorzitting gepland | Hoorzitting plannen | -- |
| In behandeling | Advies uitgebracht | Hoorrecht afgezien | requiredField: hearingWaived=true |
| Hoorzitting gepland | Hoorzitting afgerond | Hoorzitting afronden | requiredField: minutesSummary |
| Hoorzitting afgerond | Advies uitgebracht | Advies uitbrengen | requiredField: advisoryReport |
| In behandeling | Beslissing op bezwaar | Direct beslissen | roleGuard: Beslisser (when no committee) |
| Advies uitgebracht | Beslissing op bezwaar | Beslissing nemen | requiredField: dispositionType, dispositionDetails |
| Beslissing op bezwaar | Afgehandeld | Afronden | checklist: decision letter sent, rechtsmiddelenclausule included |
| Any active | Ingetrokken | Intrekken | requiredField: withdrawal reason |

The workflow template SHALL include workflow steps for each status phase:

| Status Phase | Steps |
|-------------|-------|
| Ontvangen | Registreer bezwaarschrift, Controleer volledigheid, Bevestig ontvangst |
| Ontvankelijkheidstoets | Toets termijn, Toets belanghebbendheid, Toets besluit-karakter |
| In behandeling | Stel dossier samen, Informeer primair beslisser, Plan hoorzitting of registreer afzien |
| Hoorzitting gepland | Verstuur uitnodigingen, Bereid hoorzitting voor |
| Hoorzitting afgerond | Maak verslag, Deel verslag met partijen |
| Advies uitgebracht | Stel advies op, Deel advies met bestuursorgaan |
| Beslissing op bezwaar | Neem beslissing, Stel besluit op, Verstuur besluit met rechtsmiddelenclausule |

#### Scenario: Bezwaar workflow template is seeded after repair

- **WHEN** the Dossiq app repair step runs
- **THEN** a workflow template SHALL exist for the Bezwaar case type
- **AND** the template SHALL contain all defined transitions with their guards
- **AND** the template SHALL contain all defined steps per status phase
- **AND** the template SHALL be published (isDraft: false, isActive: true)

#### Scenario: Administrator can customize the bezwaar workflow

- **WHEN** an administrator opens the Bezwaar case type in the admin settings
- **THEN** the pre-seeded workflow template SHALL be visible in the workflow tab
- **AND** the administrator SHALL be able to create a new version to customize steps and transitions
- **AND** the original pre-seeded template SHALL remain as a base version

### Requirement: Pre-Seeded Beroep Workflow Template

The system SHALL provide a pre-seeded workflow template for the Beroep case type with transitions for tracking court proceedings.

**Feature tier**: V1

| From Status | To Status | Label | Guards |
|-------------|-----------|-------|--------|
| Beroep ontvangen | Verweerschrift in voorbereiding | Start verweer | roleGuard: Behandelaar |
| Verweerschrift in voorbereiding | Verweerschrift ingediend | Verweer indienen | requiredDocument: Verweerschrift |
| Verweerschrift ingediend | Zitting gepland | Zitting plannen | -- |
| Zitting gepland | Zitting afgerond | Zitting afronden | -- |
| Zitting afgerond | Uitspraak ontvangen | Uitspraak registreren | requiredField: ruling outcome |
| Uitspraak ontvangen | Afgehandeld | Afronden | -- |
| Any active | Ingetrokken | Intrekken | -- |
| Any active | Schikking | Schikking treffen | requiredField: settlement details |

#### Scenario: Beroep workflow template is seeded after repair

- **WHEN** the Dossiq app repair step runs
- **THEN** a workflow template SHALL exist for the Beroep case type
- **AND** the template SHALL contain all defined transitions
- **AND** the template SHALL be published (isDraft: false, isActive: true)

<!-- BEGIN retrofit-2026-05-24-workflow-definition-model -->

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

Version selection SHALL be deterministic: **at most one active version per (case type, route) at any time**; a case bound to a specific version SHALL continue to use that version even after a newer one is published (versions, not branches).

Several routes MAY be active on one case type at the same time. That is what a route is for, and it is the one thing this rule permits that the per-case-type rule did not. A case type with one route behaves exactly as it did before. `getActiveDefinitionFor($caseTypeId)` called without a route SHALL return the case type's default route, so every caller written before routes existed keeps reading what it read before. See `openspec/specs/workflow-variants/spec.md`.

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

### REQ-003: MigrateWorkflowDefinitions SHALL be a one-shot repair step for legacy data

`OCA\Dossiq\Repair\MigrateWorkflowDefinitions` SHALL run as a Nextcloud repair step that detects legacy inline workflow definitions on case-type records and lifts them into stand-alone workflow definition entities. The repair step SHALL be idempotent: on a fully-migrated dataset it SHALL be a no-op, and re-running it SHALL NOT duplicate definitions.

#### Scenario: Idempotent re-run
- **GIVEN** a dossiq instance where MigrateWorkflowDefinitions has already run
- **WHEN** `MigrateWorkflowDefinitions::run($output)` runs again
- **THEN** no new workflow definitions SHALL be created and the output SHALL log the no-op

Notes
- Once every active deployment is past the migration window, this repair step is a candidate for removal in a future cleanup spec.

<!-- END retrofit-2026-05-24-workflow-definition-model -->
