---
status: done
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
---

## Purpose

Defines the end-to-end bezwaar (objection) lifecycle: the pre-seeded Bezwaar case type, status/role types, AWB-compliant deadline calculation, the objection object schema, the Bezwaren index UI surface, and the lifecycle listener and seed repair steps that drive bezwaar and beroep workflows.

## Requirements

**Bezwaren list surface (UI surface)**

### Requirement: Bezwaren list surface

Objections SHALL be listed on the Cases page, narrowed to the Bezwaar case type.
`/bezwaren/:id` SHALL stay registered so every objection detail link keeps
resolving.

REPLACED 2026-09-02. The standalone `/bezwaren` index page is retired, not hidden.
It was an index over register `dossiq` and schema `case` whose only narrowing was
`filter: { caseType: <bezwaar> }`. Cases carries the same register and the same
schema, and narrows the same field through its `folderSidebar`
(`filterField: caseType`). Picking the Bezwaar folder returns the rows the retired
page returned, so this is the same query on the same data rather than an
approximation.

@e2e exclude The list shell is covered by `case-management/spec.md#cases-index-page-renders-list-shell`, which drives the same `CnIndexPage` over the same register and schema.

**ADDED Requirements**

### Requirement: Bezwaar Case Type Pre-Seeded Configuration

The system SHALL provide a pre-seeded "Bezwaar" (objection) case type with AWB chapter 6/7 compliant configuration. The case type SHALL be imported via the repair step alongside existing case types.

@e2e exclude Bezwaar lifecycle is V1 seed data + case type config; imported via repair step, covered by PHPUnit.

**Feature tier**: V1
**ZGW mapping**: `zaaktype` with `omschrijving` "Bezwaar"
**CMMN mapping**: CaseDefinition with TimerEventListeners for legal deadlines

| Property | Value |
|----------|-------|
| `title` | Bezwaar |
| `description` | Bezwaarprocedure conform Awb hoofdstuk 6 en 7 |
| `processingDeadline` | P6W (6 weeks, Awb art. 7:10 lid 1) |
| `extensionAllowed` | true |
| `extensionPeriod` | P6W (6 weeks extension, Awb art. 7:10 lid 3) |
| `suspensionAllowed` | true |
| `origin` | external |
| `trigger` | Bezwaarschrift van belanghebbende |
| `subject` | Bezwaar tegen besluit |

#### Scenario: Bezwaar case type is available after installation

- **WHEN** the Dossiq app repair step runs
- **THEN** a case type "Bezwaar" SHALL exist in the dossiq register
- **AND** the case type SHALL have `processingDeadline` set to `P6W`
- **AND** the case type SHALL have `extensionAllowed` set to `true` with `extensionPeriod` `P6W`
- **AND** the case type SHALL have `suspensionAllowed` set to `true`

### Requirement: Bezwaar Status Types

The system SHALL provide pre-seeded status types for the Bezwaar case type that reflect the AWB-mandated process phases. Status types SHALL be ordered to enforce the legal process sequence.

@e2e exclude V1 seed/config — bezwaar status types imported via repair step, covered by PHPUnit.

**Feature tier**: V1
**ZGW mapping**: `statustype` linked to bezwaar `zaaktype`

| Order | Status Type | Description | AWB Reference |
|-------|-------------|-------------|---------------|
| 1 | Ontvangen | Bezwaarschrift is ontvangen | Art. 6:4 |
| 2 | Ontvankelijkheidstoets | Toetsing op ontvankelijkheid | Art. 6:5, 6:6 |
| 3 | In behandeling | Inhoudelijke behandeling gestart | Art. 7:2 |
| 4 | Hoorzitting gepland | Hoorzitting is ingepland | Art. 7:2 |
| 5 | Hoorzitting afgerond | Hoorzitting heeft plaatsgevonden | Art. 7:7 |
| 6 | Advies uitgebracht | Bezwaarschriftencommissie heeft advies uitgebracht | Art. 7:13 |
| 7 | Beslissing op bezwaar | Besluit op bezwaar is genomen | Art. 7:11, 7:12 |
| 8 | Afgehandeld | Bezwaarprocedure is volledig afgerond | -- |
| -- | Niet-ontvankelijk | Bezwaar is niet-ontvankelijk verklaard | Art. 6:6 |
| -- | Ingetrokken | Bezwaar is ingetrokken door indiener | Art. 6:21 |

#### Scenario: All bezwaar status types are seeded

- **WHEN** the repair step completes
- **THEN** 10 status types SHALL exist for the Bezwaar case type
- **AND** they SHALL be ordered from 1 (Ontvangen) through 8 (Afgehandeld) with Niet-ontvankelijk and Ingetrokken as terminal statuses

#### Scenario: Skip hearing when right is waived

- **WHEN** the bezwaarmaker waives the right to a hearing (afzien van hoorrecht)
- **THEN** the case SHALL be able to transition from "Ontvankelijkheidstoets" or "In behandeling" directly to "Advies uitgebracht" or "Beslissing op bezwaar"
- **AND** the skip SHALL be recorded in the case audit trail with reason "Belanghebbende heeft afgezien van het recht te worden gehoord"

### Requirement: Bezwaar Role Types

The system SHALL provide pre-seeded role types for the Bezwaar case type covering all participants in the objection process.

@e2e exclude V1 seed/config — bezwaar role types imported via repair step, covered by PHPUnit.

**Feature tier**: V1
**ZGW mapping**: `roltype` linked to bezwaar `zaaktype`

| Role Type | Generic Role | Description |
|-----------|-------------|-------------|
| Bezwaarmaker | initiator | De persoon die bezwaar maakt |
| Behandelaar bezwaar | handler | Ambtenaar die het bezwaar behandelt |
| Voorzitter commissie | decision_maker | Voorzitter bezwaarschriftencommissie |
| Lid commissie | advisor | Lid van de bezwaarschriftencommissie |
| Secretaris commissie | coordinator | Secretaris van de bezwaarschriftencommissie |
| Vertegenwoordiger | stakeholder | Gemachtigde of vertegenwoordiger van bezwaarmaker |
| Primair beslisser | advisor | Ambtenaar die het oorspronkelijke besluit nam |

#### Scenario: All bezwaar role types are seeded

- **WHEN** the repair step completes
- **THEN** 7 role types SHALL exist for the Bezwaar case type
- **AND** each role type SHALL map to a standard generic role

### Requirement: Bezwaar Deadline Calculation

The system SHALL automatically calculate legal deadlines when a bezwaar case is created, based on AWB articles 6:7, 6:8, 7:10, and 7:24.

@e2e exclude AWB deadline math is a backend service; covered by PHPUnit, not a UI surface.

**Feature tier**: V1

**Implementation**: Deadlines SHALL be declared via OR's
`x-openregister-calculations` annotation on the bezwaar case schema,
not via a dossiq-specific `BezwaarDeadlineService`. Each AWB article
maps to a calculation rule with `formula`, `inputs`, and `outputField`
(per ADR-022, dossiq-adopt-or-abstractions; cross-reference
Algemene wet bestuursrecht art. 6:7, 6:8, 7:10, 7:24). See OR's
computed-fields capability (`RenderObject.php:1418`).

#### Scenario: Standard deadline calculation on case creation

- **WHEN** a bezwaar case is created with `ontvangstdatum` 2026-03-01 (Monday)
- **THEN** the system SHALL calculate:
  - `ontvangstbevestigingDeadline`: within 1 week (2026-03-08)
  - `afhandelDeadline`: 6 weeks from ontvangstdatum (2026-04-12)
  - `verdagingMogelijk`: true (6-week extension available per art. 7:10 lid 3)
- **AND** these deadlines SHALL be stored as properties on the case object

#### Scenario: Extended deadline after verdaging

- **WHEN** the behandelaar extends the deadline (verdaging) on a bezwaar case with original deadline 2026-04-12
- **THEN** the new `afhandelDeadline` SHALL be 2026-05-24 (original + 6 weeks)
- **AND** the extension SHALL be recorded in the audit trail
- **AND** the bezwaarmaker SHALL be notified of the extension per art. 7:10 lid 3

#### Scenario: Deadline suspension (opschorting)

- **WHEN** the behandelaar suspends the deadline because additional information is requested from the bezwaarmaker
- **THEN** the deadline clock SHALL stop
- **AND** the suspension SHALL be recorded with start date and reason
- **AND** when the bezwaarmaker provides the information, the deadline clock SHALL resume with the remaining days added

#### Scenario: Deadline warning notification

- **WHEN** a bezwaar case is within 5 working days of its `afhandelDeadline`
- **AND** the case status is not "Afgehandeld", "Niet-ontvankelijk", or "Ingetrokken"
- **THEN** the system SHALL create a warning notification for the bezwaar behandelaar
- **AND** the case SHALL appear in the dashboard overdue/at-risk section

### Requirement: Bezwaar Objection Object Schema

The system SHALL store bezwaarschrift (objection letter) details as an OpenRegister object linked to the bezwaar case. This captures the formal objection content separately from the case metadata.

@e2e exclude Schema definition (V1 seed); covered by PHPUnit + OpenRegister schema validation, not a UI surface.

**Feature tier**: V1
**Schema.org mapping**: `schema:Message` with `schema:about` referencing the contested decision

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `case` | reference (UUID) | Yes | The bezwaar case this objection belongs to |
| `contestedDecision` | reference (UUID) | Yes | The original besluit being contested |
| `grounds` | string (text) | Yes | The grounds for objection (gronden van bezwaar) |
| `requestedRelief` | string (text) | No | What outcome the bezwaarmaker seeks |
| `receivedDate` | date | Yes | Date the bezwaarschrift was received |
| `receivedChannel` | enum | Yes | How it was received (brief, email, formulier, balie) |
| `isTimely` | boolean | No | Whether the objection was filed within the 6-week term (art. 6:7) |
| `timelinessAssessment` | string | No | Explanation of timeliness determination |
| `proVoorziening` | boolean | No | Whether a voorlopige voorziening (interim relief) was requested |
| `attachments` | array of references | No | Supporting documents uploaded by bezwaarmaker |

#### Scenario: Create objection linked to contested decision

- **WHEN** an intake worker creates a bezwaar case
- **THEN** an objection object SHALL be created linking the case to the original besluit
- **AND** the `contestedDecision` field SHALL reference the UUID of the besluit being contested
- **AND** the `grounds` field SHALL contain the bezwaarmaker's stated reasons

#### Scenario: Timeliness check (ontvankelijkheidstoets)

- **WHEN** the behandelaar performs the ontvankelijkheidstoets
- **AND** the bezwaarschrift was received more than 6 weeks after the besluit publication date
- **THEN** the `isTimely` field SHALL default to `false`
- **AND** the system SHALL display a warning: "Bezwaartermijn mogelijk overschreden"
- **AND** the behandelaar SHALL be able to override with a `timelinessAssessment` explanation (e.g., verschoonbare termijnoverschrijding)

<!-- BEGIN retrofit-2026-05-24-bezwaar-lifecycle -->

**Listener + Seed Surface (retrofit)**

### Requirement: BezwaarLifecycleListener SHALL react to lifecycle events and update bezwaar state

`OCA\Dossiq\Listener\BezwaarLifecycleListener` SHALL implement `OCP\EventDispatcher\IEventListener::handle($event)` and react to: bezwaar created (set initial status + assigned reviewer), bezwaar hearing scheduled (block status changes until hearing concludes), bezwaar decision made (compute decision deadline + propagate to case timeline), and bezwaar withdrawn (terminate the lifecycle). The listener SHALL be idempotent on repeated event delivery — handler effects SHALL be guarded by the bezwaar's current state so a re-played event is a no-op when the transition already occurred.

@e2e exclude Backend PHP event listener; idempotent state-machine logic covered by PHPUnit, not a UI surface.

#### Scenario: Replayed creation event is a no-op
- **GIVEN** a bezwaar already at status `in-behandeling` from a prior creation event
- **WHEN** the same `BezwaarCreatedEvent` is dispatched again
- **THEN** the listener SHALL detect the existing state and SHALL NOT re-trigger status / assignment side effects

### Requirement: SeedBezwaarBeroepData SHALL seed shared reference data for bezwaar + beroep

`OCA\Dossiq\Repair\SeedBezwaarBeroepData` SHALL run on app install/upgrade and create the shared reference data for bezwaar + beroep workflows: status types, role types, decision-type enumerations, and any standing notification templates. The seeder SHALL be idempotent — pre-existing records (matched by slug/code) SHALL be left untouched, and re-running the repair SHALL be safe.

@e2e exclude Backend repair-step seeder; idempotency covered by PHPUnit, not a UI surface.

#### Scenario: Repair re-runs on upgrade
- **WHEN** a dossiq upgrade triggers repair steps
- **THEN** `SeedBezwaarBeroepData::run($output)` SHALL skip rows already in the database and only add net-new reference data introduced in this release

### Requirement: SeedBezwaarWorkflowDefinition SHALL seed the canonical bezwaar workflow definition

`OCA\Dossiq\Repair\SeedBezwaarWorkflowDefinition` SHALL create the canonical bezwaar workflow definition (status nodes, transitions, role guards, deadline rules) as a published version 1 record. The seeder SHALL be idempotent — if a published bezwaar workflow already exists, the repair SHALL be a no-op rather than creating a competing version. If the existing record is on an older schema version, the seeder SHALL migrate it forward in-place rather than seeding a parallel record.

@e2e exclude Backend repair-step workflow seeder; idempotent no-op covered by PHPUnit, not a UI surface.

#### Scenario: Existing v1 workflow is preserved
- **GIVEN** a bezwaar workflow already published as v1
- **WHEN** `SeedBezwaarWorkflowDefinition::run($output)` runs
- **THEN** no new workflow record SHALL be created and the output SHALL log the no-op

<!-- END retrofit-2026-05-24-bezwaar-lifecycle -->
