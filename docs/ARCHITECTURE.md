# Procest — Architecture & Data Model

## 1. Overview

Procest is a case management (zaakgericht werken) app for Nextcloud, built as a thin client on OpenRegister. It manages cases, tasks, statuses, roles, results, and decisions. Cases are governed by configurable **case types** that control behavior: allowed statuses, required fields, processing deadlines, retention rules, and more.

### Architecture Pattern

```
┌─────────────────────────────────────────────────┐
│  Procest Frontend (Vue 2 + Pinia)               │
│  - Case list/detail views                       │
│  - Task management                              │
│  - Decision tracking                            │
│  - My Work (werkvoorraad) dashboard             │
│  - Admin settings (case types, statuses, etc.)  │
└──────────────┬──────────────────────────────────┘
               │ REST API calls
┌──────────────▼──────────────────────────────────┐
│  OpenRegister API                                │
│  /api/objects/{register}/{schema}/{id}           │
│  - CRUD operations                              │
│  - Search, pagination, filtering                │
└──────────────┬──────────────────────────────────┘
               │
┌──────────────▼──────────────────────────────────┐
│  OpenRegister Storage (PostgreSQL)               │
│  - JSON object storage                          │
│  - Schema validation                            │
└─────────────────────────────────────────────────┘
```

Procest owns **no database tables**. All data is stored as OpenRegister objects, defined by schemas in a dedicated register.

## 2. Standards Research

### 2.1 Standards Evaluated

| Standard | Type | Coverage | Maturity | Relevance |
|----------|------|----------|----------|-----------|
| **CMMN 1.1** | International (OMG) | Case plans, tasks, milestones, sentries, case file items | Mature | **HIGH** — designed for case management |
| **BPMN 2.0** | International (OMG) | Processes, tasks, gateways, events | Very mature, widely adopted | **HIGH** — task/workflow modeling |
| **DMN 1.x** | International (OMG) | Decision tables, decision logic | Mature, growing | **MEDIUM** — decision modeling |
| **Schema.org** | International (W3C) | Action, Project, GovernmentService, Role | Very mature | **MEDIUM** — semantic vocabulary |
| **Dublin Core (ISO 15836)** | International | 15 metadata elements for documents | Very mature | **LOW** — document metadata |
| **ZGW APIs (VNG)** | Dutch gov | Zaak, Status, Resultaat, Besluit, Rol | Production, mandated NL | **HIGH** — Dutch API interoperability |
| **RGBZ** | Dutch gov | Information model behind ZGW | Stable (v1.0) | **HIGH** — field-level reference |
| **ZGW Catalogi API** | Dutch gov | ZaakType, StatusType, ResultaatType, RolType, etc. | Production (v1.3.x) | **HIGH** — case type system reference |

### 2.2 Design Principle: International First

> **Data storage uses international standards. Dutch government standards are an API mapping layer.**

This means:
- Objects in OpenRegister are modeled after **CMMN, schema.org, and BPMN** concepts
- When exposing a ZGW-compatible API, we **map** our international objects to ZGW field names
- This makes Procest usable outside the Netherlands while remaining interoperable with Dutch systems

### 2.3 Key Findings

1. **CMMN 1.1** (Case Management Model and Notation) is the only international standard specifically designed for case management. It defines cases, tasks, milestones, case file items, and sentries (event-condition guards). It uses a declarative model: specify what is allowed, not the exact flow.

2. **BPMN 2.0** is the dominant process standard. Camunda deprecated native CMMN support in favor of implementing CMMN patterns via BPMN, citing readability. We follow this pragmatic approach.

3. **ZGW/RGBZ** defines the complete Dutch government case model (Zaak, Status, Resultaat, Besluit, Rol). It is production-ready and mandated for municipalities. We map to it, not build on it.

4. **ZGW Catalogi API** defines `ZaakType` as a comprehensive case type system controlling: allowed statuses, result types with archival rules, role types, custom properties, required documents, processing deadlines, confidentiality defaults, and publication rules. This is the most complete case type reference available.

5. **Schema.org** provides `Action` (with `actionStatus`, `agent`, `result`), `Project`, and `Role` — useful as semantic annotations but not domain-specific enough for case management.

6. **Nextcloud** provides built-in Calendar (CalDAV), activity tracking, and file management that we reuse where possible. Deck was evaluated but is not suitable (no PHP API, model doesn't fit).

## 3. Data Model Decisions

### 3.1 Standards Hierarchy

| Layer | Standard | Purpose |
|-------|----------|---------|
| **Primary (storage)** | CMMN 1.1 concepts + schema.org vocabulary | International data model |
| **Semantic** | Schema.org JSON-LD | Type annotations for linked data |
| **API mapping** | ZGW/RGBZ field names | Dutch government interoperability |
| **Type system reference** | ZGW Catalogi API (ZaakType) | Case type behavioral controls |
| **Nextcloud native** | Calendar, Contacts, Files, Activity | Reuse where possible |

### 3.2 Entity Definitions

#### Case Type

A case type is a configurable definition that controls the behavior of cases: which statuses are allowed, which roles can be assigned, what properties are required, processing deadlines, and more. This is the international equivalent of ZGW's `ZaakType`.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **CMMN concept** | `CaseDefinition` / `CasePlanModel` template | CMMN separates case definition from case instance |
| **ZGW equivalent** | `ZaakType` (Catalogi API 1.3.x) | Full behavioral case type — our primary reference |
| **Versioning** | Case types have validity periods and draft/published status | From ZGW: `concept`, `validFrom`, `validUntil` |

**Core properties**:

| Property | Type | CMMN / Schema.org | ZGW Mapping | Required |
|----------|------|------------------|-------------|----------|
| `title` | string | `schema:name` | `zaaktype_omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `identifier` | string | `schema:identifier` | `identificatie` | Auto |
| `purpose` | string | — | `doel` | Yes |
| `trigger` | string | — | `aanleiding` | Yes |
| `subject` | string | — | `onderwerp` | Yes |
| `initiatorAction` | string | — | `handeling_initiator` | Yes |
| `handlerAction` | string | — | `handeling_behandelaar` | Yes |
| `origin` | enum: internal, external | — | `indicatie_intern_of_extern` | Yes |
| `processingDeadline` | duration (ISO 8601) | CMMN TimerEventListener | `doorlooptijd_behandeling` | Yes |
| `serviceTarget` | duration (ISO 8601) | — | `servicenorm_behandeling` | No |
| `suspensionAllowed` | boolean | — | `opschorting_en_aanhouding_mogelijk` | Yes |
| `extensionAllowed` | boolean | — | `verlenging_mogelijk` | Yes |
| `extensionPeriod` | duration (ISO 8601) | — | `verlengingstermijn` | Conditional |
| `confidentiality` | enum | — | `vertrouwelijkheidaanduiding` | Yes |
| `publicationRequired` | boolean | — | `publicatie_indicatie` | Yes |
| `publicationText` | string | — | `publicatietekst` | No |
| `responsibleUnit` | string | — | `verantwoordelijke` | Yes |
| `referenceProcess` | string | — | `referentieproces_naam` | No |
| `isDraft` | boolean | — | `concept` | No (default: true) |
| `validFrom` | date | — | `datum_begin_geldigheid` | Yes |
| `validUntil` | date | — | `datum_einde_geldigheid` | No |
| `keywords` | string[] | — | `trefwoorden` | No |
| `subCaseTypes` | reference[] | CMMN CaseTask | `deelzaaktypen` | No |

**Confidentiality levels** (from ZGW, internationally applicable):

| Level | ZGW Dutch | Description |
|-------|-----------|-------------|
| `public` | openbaar | Publicly accessible |
| `restricted` | beperkt_openbaar | Restricted public access |
| `internal` | intern | Internal use only |
| `case_sensitive` | zaakvertrouwelijk | Case-confidential |
| `confidential` | vertrouwelijk | Confidential |
| `highly_confidential` | confidentieel | Highly confidential |
| `secret` | geheim | Secret |
| `top_secret` | zeer_geheim | Top secret |

#### Status Type

A configurable status definition linked to a case type. Controls which lifecycle phases a case can go through.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **CMMN concept** | Milestone + PlanItem states | Milestones mark case progression |
| **Schema.org type** | `schema:ActionStatusType` | Standard status enumeration |
| **ZGW equivalent** | `StatusType` (Catalogi API) | Per-case-type status definitions |

**Core properties**:

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `statustype_omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `order` | integer (1–9999) | CMMN Milestone sequence | `statustypevolgnummer` | Yes |
| `isFinal` | boolean | CMMN terminal state | (last in order) | No (default: false) |
| `targetDuration` | duration | — | `doorlooptijd` | No |
| `notifyInitiator` | boolean | — | `informeren` | No (default: false) |
| `notificationText` | string | — | `statustekst` | No |

#### Result Type

A configurable result definition linked to a case type. Controls which outcomes are possible and how they affect archival.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **CMMN concept** | Case outcome | Case completion result type |
| **ZGW equivalent** | `ResultaatType` (Catalogi API) | Result type with archival rules |

**Core properties**:

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `archiveAction` | enum: retain, destroy | — | `archiefnominatie` | No |
| `retentionPeriod` | duration (ISO 8601) | — | `archiefactietermijn` | No |
| `retentionDateSource` | enum | — | `afleidingswijze` | No |

**Retention date source values** (from ZGW, internationally applicable):

| Value | ZGW Dutch | Description |
|-------|-----------|-------------|
| `case_completed` | afgehandeld | Case completion date |
| `decision_effective` | ingangsdatum_besluit | Decision effective date |
| `decision_expiry` | vervaldatum_besluit | Decision expiry date |
| `fixed_period` | termijn | Case completion + fixed period |
| `related_case` | gerelateerde_zaak | Related case completion |
| `parent_case` | hoofdzaak | Parent case completion |
| `custom_property` | eigenschap | Value of a case property |
| `custom_date` | ander_datumkenmerk | Manually determined date |

#### Role Type

A configurable role definition linked to a case type. Controls which participant roles can be assigned.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:Role` | Standard role qualification |
| **ZGW equivalent** | `RolType` (Catalogi API) | Per-case-type role definitions |

**Core properties**:

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:roleName` | `omschrijving` | Yes |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `genericRole` | enum | — | `omschrijvingGeneriek` | Yes |

**Standard generic roles** (from ZGW, internationally applicable):

| Role | ZGW Dutch | Description |
|------|-----------|-------------|
| `initiator` | Initiator | Started the case |
| `handler` | Behandelaar | Processes the case |
| `advisor` | Adviseur | Provides advice |
| `decision_maker` | Beslisser | Makes decisions |
| `stakeholder` | Belanghebbende | Has interest in outcome |
| `coordinator` | Zaakcoördinator | Coordinates the case |
| `contact` | Klantcontacter | Contact person |
| `co_initiator` | Mede-initiator | Co-initiator |

#### Property Definition

A configurable custom field definition linked to a case type. Controls which additional data fields cases of this type must capture.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:PropertyValueSpecification` | Defines expected property values |
| **ZGW equivalent** | `Eigenschap` (Catalogi API) | Case-type-specific custom properties |

**Core properties**:

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `eigenschapnaam` | Yes |
| `definition` | string | `schema:description` | `definitie` | Yes |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `format` | enum: text, number, date, datetime | — | `formaat` | Yes |
| `maxLength` | integer | — | `lengte` | No |
| `allowedValues` | string[] | — | `waardenverzameling` | No |
| `requiredAtStatus` | reference | Status at which this must be filled | `statustype` | No |

#### Document Type

A configurable document type definition linked to a case type. Controls which document types are expected.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:DigitalDocument` | Document type definition |
| **ZGW equivalent** | `InformatieObjectType` + `ZaakTypeInformatieObjectType` | Document type requirements |

**Core properties**:

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `omschrijving` | Yes |
| `category` | string | — | `informatieobjectcategorie` | Yes |
| `caseType` | reference | Parent case type | `zaaktype` (via through table) | Yes |
| `direction` | enum: incoming, internal, outgoing | — | `richting` | Yes |
| `order` | integer | — | `volgnummer` | Yes |
| `confidentiality` | enum | — | `vertrouwelijkheidaanduiding` | No |
| `requiredAtStatus` | reference | Status requiring this document | `statustype` | No |

#### Decision Type

A configurable decision type definition linked to a case type.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **Schema.org type** | `schema:ChooseAction` definition | Decision type specification |
| **ZGW equivalent** | `BesluitType` (Catalogi API) | Administrative decision types |

**Core properties**:

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `category` | string | — | `besluitcategorie` | No |
| `objectionPeriod` | duration (ISO 8601) | — | `reactietermijn` | No |
| `publicationRequired` | boolean | — | `publicatie_indicatie` | Yes |
| `publicationPeriod` | duration (ISO 8601) | — | `publicatietermijn` | No |

#### Case (Zaak)

A case is a coherent body of work with a defined lifecycle, initiation, and result. Cases are governed by a case type.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **CMMN concept** | `CasePlanModel` / Case instance | CMMN's core concept — a case with a plan |
| **Schema.org type** | `schema:Project` | "An enterprise planned to achieve a particular aim" |
| **ZGW mapping** | `Zaak` | Direct mapping for Dutch API compatibility |

**Core properties** (international → ZGW mapping):

| Property | Type | CMMN/Schema.org Source | ZGW Mapping | Required |
|----------|------|----------------------|-------------|----------|
| `title` | string | `schema:name` | `omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `identifier` | string | `schema:identifier` | `identificatie` | Auto |
| `caseType` | reference | CMMN CaseDefinition | `zaaktype` | Yes |
| `status` | reference | CMMN PlanItem lifecycle | `status` (URL ref) | Yes |
| `result` | reference | CMMN case outcome | `resultaat` (URL ref) | No |
| `startDate` | date | `schema:startDate` | `startdatum` | Yes |
| `endDate` | date | `schema:endDate` | `einddatum` | No |
| `plannedEndDate` | date | — | `einddatumGepland` | No |
| `deadline` | date | — | `uiterlijkeEinddatumAfdoening` | Auto (from caseType) |
| `confidentiality` | enum | — | `vertrouwelijkheidaanduiding` | No (default from caseType) |
| `assignee` | string | CMMN HumanTask.assignee | — | No |
| `priority` | enum | `schema:priority` | — | No |
| `parentCase` | reference | CMMN CaseTask (sub-case) | `hoofdzaak` | No |
| `relatedCases` | array | — | `relevanteAndereZaken` | No |
| `geometry` | GeoJSON | `schema:geo` | `zaakgeometrie` | No |

**Case type behavioral controls on cases**:
- `deadline` is auto-calculated: `startDate` + `caseType.processingDeadline`
- `confidentiality` defaults from `caseType.confidentiality`
- Only status types linked to the case type are allowed
- Only role types linked to the case type are allowed
- Property definitions linked to the case type must be satisfied before reaching required statuses
- Document types linked to the case type define which documents are expected

#### Task

A work item within a case.

| Aspect | Decision | Rationale |
|--------|----------|-----------|
| **CMMN concept** | `HumanTask` | CMMN's primary task type |
| **Schema.org type** | `schema:Action` | With `actionStatus` for lifecycle |

**Core properties**:

| Property | Type | CMMN/Schema.org Source | Required |
|----------|------|----------------------|----------|
| `title` | string | `schema:name` | Yes |
| `description` | string | `schema:description` | No |
| `status` | enum | CMMN PlanItem states: available, active, completed, terminated | Yes |
| `assignee` | string | CMMN assignee | No |
| `case` | reference | CMMN parent case | Yes |
| `dueDate` | datetime | `schema:endTime` | No |
| `priority` | enum | `schema:priority` | No |
| `completedDate` | datetime | `schema:endTime` | No |

**Task status values** (from CMMN PlanItem lifecycle):

| Status | CMMN State | Description |
|--------|-----------|-------------|
| `available` | Available | Task can be started |
| `active` | Active | Task is being worked on |
| `completed` | Completed | Task finished successfully |
| `terminated` | Terminated | Task stopped before completion |
| `disabled` | Disabled | Task not applicable |

#### Role (Rol)

The relationship between a person/organization and a case.

**Core properties**:

| Property | Type | Schema.org Source | ZGW Mapping | Required |
|----------|------|------------------|-------------|----------|
| `name` | string | `schema:roleName` | `omschrijving` | Yes |
| `description` | string | `schema:description` | `roltoelichting` | No |
| `roleType` | reference | — | `omschrijvingGeneriek` (via RoleType) | Yes |
| `case` | reference | — | `zaak` | Yes |
| `participant` | string (user UID or contact ref) | `schema:agent` | `betrokkene` | Yes |

#### Result (Resultaat)

The outcome of a case.

**Core properties**:

| Property | Type | Source | Required |
|----------|------|--------|----------|
| `name` | string | `schema:name` | Yes |
| `description` | string | `schema:description` | No |
| `case` | reference | Parent case | Yes |
| `resultType` | reference | ResultType definition | Yes |

#### Decision (Besluit)

A formal decision made on a case.

**Core properties**:

| Property | Type | Schema.org Source | ZGW Mapping | Required |
|----------|------|------------------|-------------|----------|
| `title` | string | `schema:name` | — | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `case` | reference | — | `zaak` | No |
| `decisionType` | reference | — | `besluittype` | No |
| `decidedBy` | string | `schema:agent` | — | No |
| `decidedAt` | datetime | `schema:endTime` | `datum` | No |
| `effectiveDate` | date | `schema:startTime` | `ingangsdatum` | No |
| `expiryDate` | date | `schema:endTime` | `vervaldatum` | No |

### 3.3 Case Type System Overview

The case type system forms a hierarchy where the CaseType is the central configuration entity controlling all related type definitions:

```
CaseType
├── StatusType[]         — Allowed lifecycle phases (ordered)
├── ResultType[]         — Allowed outcomes (with archival rules)
├── RoleType[]           — Allowed participant roles
├── PropertyDefinition[] — Required custom data fields
├── DocumentType[]       — Required document types
├── DecisionType[]       — Allowed decision types
└── subCaseTypes[]       — Allowed sub-case types
```

**Behavioral controls**:

| Control | How | ZGW Reference |
|---------|-----|---------------|
| **Allowed statuses** | StatusTypes linked to CaseType; ordered by `order` | StatusType → ZaakType FK |
| **Status transitions** | Only sequential progression through ordered statuses | `statustypevolgnummer` sequence |
| **Required fields per status** | PropertyDefinition with `requiredAtStatus` reference | Eigenschap → StatusType FK |
| **Required documents per status** | DocumentType with `requiredAtStatus` reference | ZaakTypeInformatieObjectType → StatusType FK |
| **Processing deadline** | Auto-calculated: `case.startDate` + `caseType.processingDeadline` | `doorlooptijd_behandeling` |
| **Suspension/extension** | Controlled by `suspensionAllowed` and `extensionAllowed` | `opschorting_en_aanhouding_mogelijk`, `verlenging_mogelijk` |
| **Confidentiality default** | Cases inherit `caseType.confidentiality` | `vertrouwelijkheidaanduiding` |
| **Archival per result** | ResultType defines archival action and retention period | `archiefnominatie`, `archiefactietermijn` |
| **Draft/published lifecycle** | CaseType has `isDraft`; draft types cannot create cases | `concept` |
| **Validity window** | CaseType has `validFrom` / `validUntil` | `datum_begin_geldigheid`, `datum_einde_geldigheid` |

### 3.4 My Work (Werkvoorraad)

A cross-entity workload view showing all items assigned to the current user. No new entity is needed — this is a frontend aggregation pattern.

**How it works**:
- Query cases with `assignee == currentUser` and non-final status
- Query tasks with `assignee == currentUser` and status `available` or `active`
- Optionally include leads and requests from Pipelinq (`assignedTo == currentUser`)
- Merge, sort by priority then due date, display as unified card list

**Required fields for My Work** (already present on Case, Task, and Pipelinq entities):

| Field | Case | Task | Pipelinq Lead | Pipelinq Request |
|-------|------|------|--------------|-----------------|
| `assignee` / `assignedTo` | Yes | Yes | Yes | Yes |
| `priority` | Yes | Yes | Yes | Yes |
| `deadline` / `dueDate` | Yes | Yes | Yes | — |
| `status` | Yes | Yes | (via stage) | Yes |
| Entity type label | "Case" | "Task" | "Lead" | "Request" |

### 3.5 Relationship to Pipelinq

Procest receives cases from Pipelinq through the **request-to-case** (verzoek-to-zaak) flow:

```
Pipelinq (CRM)                    Procest (Case Management)
┌──────────────┐                  ┌──────────────┐
│   Client     │                  │    Case       │
│   Contact    │──── Request ────>│    Task       │
│   Lead       │   (verzoek)     │    Status     │
│   Pipeline   │                  │    Role       │
└──────────────┘                  │    Result     │
                                  │    Decision   │
                                  │    CaseType   │
                                  └──────────────┘
```

When a Pipelinq Request is converted to a Case:
- The requesting client is linked as a `Role` (type: `initiator`) on the case
- The request description becomes the case description
- The request category informs the case type selection
- The case type determines initial status, deadline, and required fields

### 3.6 Admin Settings

Procest exposes a **Nextcloud admin settings panel** for case type configuration and general app settings. Configuration objects are stored in OpenRegister.

**Configurable by admin**:

| Setting | Type | Description |
|---------|------|-------------|
| Case type management | CRUD | Create, edit, publish, version case types |
| Status type management | CRUD | Create, edit, reorder status types per case type |
| Result type management | CRUD | Define result types with archival rules per case type |
| Role type management | CRUD | Define allowed roles per case type |
| Property definitions | CRUD | Define custom fields per case type |
| Document type definitions | CRUD | Define required document types per case type |
| Decision type management | CRUD | Define decision types |
| Confidentiality levels | Display | Customize visibility of confidentiality options |
| Default case type | Selection | Which case type is used by default |

### 3.7 Nextcloud Integration Strategy

**Principle: reuse Nextcloud native objects where possible, reference by ID, don't duplicate.**

OpenRegister objects store case-specific fields plus **foreign keys** (vCard UID, calendar event UID, file ID, user UID, Talk token) pointing to Nextcloud native entities.

#### REUSE from Nextcloud

| Feature | OCP Interface | What to Reuse | How |
|---------|--------------|---------------|-----|
| **Calendar** | `OCP\Calendar\IManager` | Case deadlines, task due dates, hearing dates | Create VEVENT for deadlines, VTODO for tasks. Reference by event UID. Expose case deadlines via `ICalendarProvider`. |
| **Contacts** | `OCP\Contacts\IManager` | Case participants (initiator, stakeholder) | Reference persons by vCard UID. Search via `IManager::search()`. |
| **Users** | `OCP\IUserManager` | Case handlers, coordinators, assignees | Reference by user UID. Use for authentication and authorization. |
| **Files** | `OCP\Files\IRootFolder` | Case documents, evidence, attachments | Reference by Nextcloud file ID. Resolve via `IRootFolder->getById()`. |
| **Activity** | `OCP\Activity\IManager` | Case audit trail / activity timeline | Publish events ("Case opened", "Status changed", "Decision made"). Implement `IProvider`. |
| **Talk** | `OCP\Talk\IBroker` | Per-case discussion threads | Create conversation per case. Store token in OpenRegister. |
| **Comments** | `OCP\Comments\ICommentsManager` | Notes on cases, tasks, decisions | Attach comments using objectType + objectId. |
| **System Tags** | `OCP\SystemTag\ISystemTagObjectMapper` | Categorize and cross-reference case documents | Tag files with case references. |

#### NOT reusing Deck

Deck was evaluated but is **not suitable** for case task management:
- No PHP-level OCP interfaces (REST API only)
- Board/Stack/Card model doesn't map well to case lifecycle
- Case tasks need parent-case references, CMMN lifecycle states, and role-based assignment
- **Decision**: Build case tasks in OpenRegister, optionally sync to CalDAV VTODO for native task integration

#### BUILD in OpenRegister (case-specific)

| What | Why Not Reuse |
|------|---------------|
| **Case types** | Domain-specific behavioral configuration with no Nextcloud equivalent |
| **Status/Result/Role/Decision/Document/Property types** | Case-type-specific definitions controlling case behavior |
| **Cases** | Domain-specific lifecycle, type system, confidentiality, archival rules |
| **Tasks** | CMMN lifecycle states, parent-case binding, role-based assignment |
| **Roles** | Case-specific role assignments (initiator, handler, advisor, decision maker) |
| **Results** | Case outcome classification, archival regime |
| **Decisions** | Formal decisions with effective/expiry dates, decision type |

#### Key OCP Interfaces

```php
// Calendar - create task deadlines
$calendarManager = \OCP\Server::get(\OCP\Calendar\IManager::class);
$builder = $calendarManager->createEventBuilder(); // NC 31+
$builder->setSummary('Deadline: Case #456')->setStartDate($deadline);

// Contacts - look up case participants
$contactsManager = \OCP\Server::get(\OCP\Contacts\IManager::class);
$results = $contactsManager->search($name, ['FN', 'EMAIL']);

// Activity - publish case events (audit trail)
$activityManager = \OCP\Server::get(\OCP\Activity\IManager::class);
$event = $activityManager->generateEvent();
$event->setApp('procest')->setType('case_status_change')
      ->setSubject('Case status changed to {status}', ['status' => $newStatus])
      ->setObject('case', $caseId, $caseTitle);
$activityManager->publish($event);

// Files - resolve case documents
$rootFolder = \OCP\Server::get(\OCP\Files\IRootFolder::class);
$files = $rootFolder->getById($documentId);

// Talk - per-case discussion
$broker = \OCP\Server::get(\OCP\Talk\IBroker::class);
$conversation = $broker->createConversation('Case: ' . $caseTitle, $participantIds);
```

## 4. OpenRegister Configuration

### Register

| Field | Value |
|-------|-------|
| Name | `procest` |
| Slug | `procest` |
| Description | Case management register |

### Schema Definitions

Schemas MUST be defined in `lib/Settings/procest_register.json` using OpenAPI 3.0.0 format, following the pattern used by opencatalogi and softwarecatalog.

**Schemas**:
- `caseType` — Case type definition (CMMN CaseDefinition)
- `statusType` — Status type per case type (CMMN Milestone)
- `resultType` — Result type per case type (with archival rules)
- `roleType` — Role type per case type (schema:Role)
- `propertyDefinition` — Custom field definition (schema:PropertyValueSpecification)
- `documentType` — Document type requirement (schema:DigitalDocument)
- `decisionType` — Decision type definition (schema:ChooseAction)
- `case` — Case instance (schema:Project)
- `task` — Task within a case (schema:Action)
- `role` — Role assignment on a case (schema:Role instance)
- `result` — Case outcome (schema:Action.result)
- `decision` — Formal decision (schema:ChooseAction instance)

The configuration is imported via `ConfigurationService::importFromApp()` in the repair step.

## 5. ZGW API Mapping Layer

When Procest exposes a ZGW-compatible API (future work), the mapping is:

| Procest Object | ZGW Resource | ZGW API |
|----------------|-------------|---------|
| CaseType | ZaakType | Catalogi API 1.3.x |
| StatusType | StatusType | Catalogi API |
| ResultType | ResultaatType | Catalogi API |
| RoleType | RolType | Catalogi API |
| PropertyDefinition | Eigenschap | Catalogi API |
| DocumentType | InformatieObjectType | Catalogi API |
| DecisionType | BesluitType | Catalogi API |
| Case | Zaak | Zaken API 1.5.x |
| (status instance) | Status | Zaken API |
| Result | Resultaat | Zaken API |
| Role | Rol | Zaken API |
| Decision | Besluit | Besluiten API 1.0.x |

Field-level mappings are documented per entity in section 3.2 above.

## 6. Open Research Questions

1. ~~**Nextcloud Deck reuse**~~ — **RESOLVED**: Deck is not suitable. No PHP API, model doesn't fit case lifecycle.

2. ~~**Case type system**~~ — **RESOLVED**: Case types are a core feature with full behavioral controls, modeled after ZGW ZaakType but using international terminology.

3. **CMMN runtime** — Should Procest implement CMMN runtime semantics (sentries, entry/exit criteria) or keep the simpler status-based lifecycle? Current decision: start with ordered status progression, add CMMN features as needed.

4. **Archival** — ZGW has detailed archival rules. The case type system now includes result-based archival configuration (`archiveAction`, `retentionPeriod`, `retentionDateSource`). Runtime archival enforcement is deferred until compliance requirements emerge.

5. **DMN for decisions** — Should Procest support DMN decision tables for automated decision logic? This is ambitious but aligns with the OMG "Triple Crown" (BPMN + CMMN + DMN). Deferred.

6. **Case type versioning** — ZGW supports versioning of case types (new versions when modifications are made). Should Procest implement version chains? Current decision: `validFrom`/`validUntil` for validity windows, explicit versioning deferred.

7. **Cross-app My Work** — Should the My Work view in Procest also show Pipelinq leads/requests? Requires cross-app API calls or a shared dashboard widget.

## 7. References

### Primary Standards (International)
- [CMMN 1.1 (OMG)](https://www.omg.org/spec/CMMN/1.1/About-CMMN) — Case Management Model and Notation
- [BPMN 2.0 (OMG)](https://www.omg.org/spec/BPMN/2.0.2/) — Business Process Model and Notation
- [DMN (OMG)](https://www.omg.org/dmn/) — Decision Model and Notation
- [Schema.org](https://schema.org/) — Linked data vocabulary

### Schema.org Types Used
- [schema:Project](https://schema.org/Project) — Case
- [schema:Action](https://schema.org/Action) — Task (with ActionStatusType)
- [schema:Role](https://schema.org/Role) — Case participant role
- [schema:ChooseAction](https://schema.org/ChooseAction) — Decision
- [schema:PropertyValueSpecification](https://schema.org/PropertyValueSpecification) — Custom field definition
- [schema:DigitalDocument](https://schema.org/DigitalDocument) — Document type
- [schema:identifier](https://schema.org/identifier) — Unique identifiers

### Dutch Standards (API Mapping Layer)
- [ZGW API Standards](https://vng-realisatie.github.io/gemma-zaken/standaard/) — Zaken, Besluiten, Catalogi, Documenten APIs
- [ZGW Catalogi API](https://vng-realisatie.github.io/gemma-zaken/standaard/catalogi/index) — Case type definitions (ZaakType, StatusType, etc.)
- [RGBZ](https://vng-realisatie.github.io/RGBZ/) — Information model for ZGW
- [GEMMA Online](https://www.gemmaonline.nl/) — Dutch municipal architecture

### Industry References
- [OpenZaak](https://github.com/open-zaak/open-zaak) — ZGW reference implementation (ZaakType model source)
- [Valtimo](https://docs.valtimo.nl/) — Case management platform with ZGW integration
- [Flowable CMMN](https://www.flowable.com/open-source/docs/cmmn/ch06-cmmn) — CMMN engine reference
- [Camunda CMMN Patterns via BPMN](https://camunda.com/blog/2023/07/cmmn-patterns-bpmn/) — Pragmatic CMMN approach
