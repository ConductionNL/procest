# Case Type System Specification

## Purpose

Case types are configurable definitions that control the behavior of cases. A case type determines which statuses are allowed, what roles can be assigned, which custom fields are required, processing deadlines, confidentiality defaults, and archival rules. This is the international equivalent of ZGW's `ZaakType`, modeled after CMMN 1.1 `CaseDefinition` concepts.

Case types form a hierarchy where the CaseType is the central configuration entity:

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

**Standards**: CMMN 1.1 (CaseDefinition), ZGW Catalogi API (ZaakType), Schema.org (`PropertyValueSpecification`)
**Feature tier**: MVP (core type CRUD, statuses, deadlines, draft/published, validity), V1 (result types, role types, property definitions, document types, decision types, confidentiality, suspension/extension)

## Data Model

### Case Type Entity

| Property | Type | CMMN / Schema.org | ZGW Mapping | Required |
|----------|------|-------------------|-------------|----------|
| `title` | string | `schema:name` | `zaaktype_omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `identifier` | string | `schema:identifier` | `identificatie` | Auto |
| `purpose` | string | -- | `doel` | Yes |
| `trigger` | string | -- | `aanleiding` | Yes |
| `subject` | string | -- | `onderwerp` | Yes |
| `initiatorAction` | string | -- | `handeling_initiator` | Yes |
| `handlerAction` | string | -- | `handeling_behandelaar` | Yes |
| `origin` | enum: internal, external | -- | `indicatie_intern_of_extern` | Yes |
| `processingDeadline` | duration (ISO 8601) | CMMN TimerEventListener | `doorlooptijd_behandeling` | Yes |
| `serviceTarget` | duration (ISO 8601) | -- | `servicenorm_behandeling` | No |
| `suspensionAllowed` | boolean | -- | `opschorting_en_aanhouding_mogelijk` | Yes |
| `extensionAllowed` | boolean | -- | `verlenging_mogelijk` | Yes |
| `extensionPeriod` | duration (ISO 8601) | -- | `verlengingstermijn` | Conditional (required if extensionAllowed) |
| `confidentiality` | enum | -- | `vertrouwelijkheidaanduiding` | Yes |
| `publicationRequired` | boolean | -- | `publicatie_indicatie` | Yes |
| `publicationText` | string | -- | `publicatietekst` | No |
| `responsibleUnit` | string | -- | `verantwoordelijke` | Yes |
| `referenceProcess` | string | -- | `referentieproces_naam` | No |
| `isDraft` | boolean | -- | `concept` | No (default: true) |
| `validFrom` | date | -- | `datum_begin_geldigheid` | Yes |
| `validUntil` | date | -- | `datum_einde_geldigheid` | No |
| `keywords` | string[] | -- | `trefwoorden` | No |
| `subCaseTypes` | reference[] | CMMN CaseTask | `deelzaaktypen` | No |

### Status Type Entity

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `statustype_omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `order` | integer (1-9999) | CMMN Milestone sequence | `statustypevolgnummer` | Yes |
| `isFinal` | boolean | CMMN terminal state | (last in order) | No (default: false) |
| `targetDuration` | duration | -- | `doorlooptijd` | No |
| `notifyInitiator` | boolean | -- | `informeren` | No (default: false) |
| `notificationText` | string | -- | `statustekst` | No |

### Result Type Entity (V1)

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `archiveAction` | enum: retain, destroy | -- | `archiefnominatie` | No |
| `retentionPeriod` | duration (ISO 8601) | -- | `archiefactietermijn` | No |
| `retentionDateSource` | enum | -- | `afleidingswijze` | No |

### Role Type Entity (V1)

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:roleName` | `omschrijving` | Yes |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `genericRole` | enum | -- | `omschrijvingGeneriek` | Yes |

### Property Definition Entity (V1)

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `eigenschapnaam` | Yes |
| `definition` | string | `schema:description` | `definitie` | Yes |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `format` | enum: text, number, date, datetime | -- | `formaat` | Yes |
| `maxLength` | integer | -- | `lengte` | No |
| `allowedValues` | string[] | -- | `waardenverzameling` | No |
| `requiredAtStatus` | reference | Status at which this must be filled | `statustype` | No |

### Document Type Entity (V1)

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `omschrijving` | Yes |
| `category` | string | -- | `informatieobjectcategorie` | Yes |
| `caseType` | reference | Parent case type | `zaaktype` | Yes |
| `direction` | enum: incoming, internal, outgoing | -- | `richting` | Yes |
| `order` | integer | -- | `volgnummer` | Yes |
| `confidentiality` | enum | -- | `vertrouwelijkheidaanduiding` | No |
| `requiredAtStatus` | reference | Status requiring this document | `statustype` | No |

### Decision Type Entity (V1)

| Property | Type | Source | ZGW Mapping | Required |
|----------|------|--------|-------------|----------|
| `name` | string | `schema:name` | `omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `category` | string | -- | `besluitcategorie` | No |
| `objectionPeriod` | duration (ISO 8601) | -- | `reactietermijn` | No |
| `publicationRequired` | boolean | -- | `publicatie_indicatie` | Yes |
| `publicationPeriod` | duration (ISO 8601) | -- | `publicatietermijn` | No |

## Requirements

---

### REQ-CT-01: Case Type CRUD

**Feature tier**: MVP

The system MUST support creating, reading, updating, and deleting case types. Case types are managed by admins via the Nextcloud admin settings page. See wireframe 3.6 (Admin Settings -- Case Type Management) in DESIGN-REFERENCES.md.

#### Scenario CT-01a: Create a case type

- GIVEN an admin on the Procest settings page
- WHEN they click "Add Case Type" and fill in:
  - Title: "Omgevingsvergunning"
  - Purpose: "Beoordelen bouwplannen"
  - Trigger: "Aanvraag van burger/bedrijf"
  - Subject: "Bouw- en verbouwactiviteiten"
  - Processing deadline: "P56D" (56 days)
  - Origin: "external"
  - Confidentiality: "internal"
  - Responsible unit: "Afdeling Vergunningen, Gemeente Amsterdam"
  - Valid from: "2026-01-01"
- AND submits the form
- THEN the system MUST create an OpenRegister object in the `procest` register with the `caseType` schema
- AND `isDraft` MUST default to `true`
- AND a unique `identifier` MUST be auto-generated

#### Scenario CT-01b: Update a case type

- GIVEN an existing case type "Omgevingsvergunning"
- WHEN the admin changes the `processingDeadline` from "P56D" to "P42D"
- THEN the system MUST update the OpenRegister object
- AND the change MUST NOT affect existing cases (only new cases use the updated deadline)

#### Scenario CT-01c: Delete a case type with no active cases

- GIVEN a case type "Testtype" that has no cases associated with it
- WHEN the admin deletes the case type
- THEN the system MUST remove the case type and all linked sub-types (status types, result types, role types, property definitions, document types, decision types)
- AND a confirmation dialog MUST be shown before deletion

#### Scenario CT-01d: Delete a case type with active cases -- blocked

- GIVEN a case type "Omgevingsvergunning" with 10 active cases
- WHEN the admin attempts to delete the case type
- THEN the system MUST reject the deletion
- AND display: "Cannot delete case type 'Omgevingsvergunning': 10 active cases are using this type. Close or reassign all cases first."

#### Scenario CT-01e: Case type list display

- GIVEN case types: "Omgevingsvergunning" (published, default), "Subsidieaanvraag" (published), "Klacht behandeling" (published), "Bezwaarschrift" (draft)
- WHEN the admin views the case type list
- THEN each case type MUST display: title, status (Published/Draft), deadline, number of statuses, number of result types, validity period
- AND the default case type MUST be visually indicated (e.g., star icon)
- AND draft types MUST be visually distinct (e.g., warning badge)

---

### REQ-CT-02: Case Type Draft/Published Lifecycle

**Feature tier**: MVP

The system MUST support a draft/published lifecycle for case types. Draft case types MUST NOT be usable for creating cases.

#### Scenario CT-02a: New case type defaults to draft

- GIVEN an admin creating a new case type
- WHEN the case type is created
- THEN `isDraft` MUST be `true` by default
- AND the case type MUST show a "DRAFT" badge in the admin list

#### Scenario CT-02b: Publish a case type -- success

- GIVEN a draft case type "Subsidieaanvraag" with:
  - All required fields filled (title, purpose, trigger, subject, processingDeadline, origin, confidentiality, responsibleUnit, validFrom)
  - At least one status type defined: "Ontvangen" (order 1), "In behandeling" (order 2), "Afgerond" (order 3, isFinal = true)
- WHEN the admin sets `isDraft = false`
- THEN the case type MUST become "Published"
- AND the case type MUST become available for creating new cases

#### Scenario CT-02c: Publish a case type -- blocked, no status types

- GIVEN a draft case type "Bezwaarschrift" with no status types defined
- WHEN the admin attempts to publish (set `isDraft = false`)
- THEN the system MUST reject the publication
- AND display: "Cannot publish case type 'Bezwaarschrift': at least one status type must be defined"

#### Scenario CT-02d: Publish a case type -- blocked, no final status

- GIVEN a draft case type with 2 status types, neither marked `isFinal = true`
- WHEN the admin attempts to publish
- THEN the system MUST reject the publication
- AND display: "Cannot publish case type: at least one status type must be marked as final"

#### Scenario CT-02e: Publish a case type -- blocked, validFrom not set

- GIVEN a draft case type with `validFrom` not set
- WHEN the admin attempts to publish
- THEN the system MUST reject the publication
- AND display: "Cannot publish case type: 'Valid from' date must be set"

#### Scenario CT-02f: Unpublish a case type

- GIVEN a published case type "Klacht behandeling" with 3 active cases
- WHEN the admin sets `isDraft = true` (unpublish)
- THEN the system MUST warn: "Unpublishing this case type will prevent new cases from being created. 3 existing cases will continue to function."
- AND if confirmed, the case type MUST revert to draft
- AND existing cases MUST NOT be affected

---

### REQ-CT-03: Case Type Validity Periods

**Feature tier**: MVP

The system MUST support validity windows on case types. Cases can only be created with case types that are within their validity window.

#### Scenario CT-03a: Case type within validity window

- GIVEN a case type "Omgevingsvergunning" with `validFrom = "2026-01-01"` and `validUntil = "2027-12-31"`
- AND today is "2026-06-15"
- WHEN a user views the case type in the creation dropdown
- THEN the case type MUST be available for selection

#### Scenario CT-03b: Case type expired

- GIVEN a case type "Bouwvergunning 2024" with `validUntil = "2025-12-31"`
- AND today is "2026-02-25"
- WHEN a user views the case creation form
- THEN this case type MUST NOT appear in the dropdown (or MUST appear greyed out with "Expired" label)
- AND if selected via API, the system MUST reject with: "Case type 'Bouwvergunning 2024' expired on 2025-12-31"

#### Scenario CT-03c: Case type not yet valid

- GIVEN a case type "Nieuwe Subsidie 2027" with `validFrom = "2027-01-01"`
- AND today is "2026-02-25"
- WHEN a user views the case creation form
- THEN this case type MUST NOT appear in the dropdown (or MUST appear greyed out with "Not yet valid" label)

#### Scenario CT-03d: Case type with no end date

- GIVEN a case type "Klacht behandeling" with `validFrom = "2026-01-01"` and `validUntil` not set
- AND today is "2030-12-31"
- WHEN a user views the case creation form
- THEN the case type MUST be available (no expiry)

#### Scenario CT-03e: Validity displayed in admin list

- GIVEN case types with varying validity periods
- WHEN the admin views the case type list
- THEN each type MUST display its validity range: "Valid: Jan 2026 -- Dec 2027" or "Valid: Jan 2026 -- (no end)"

---

### REQ-CT-04: Status Type Management

**Feature tier**: MVP

The system MUST support defining ordered status types for each case type. Status types control the lifecycle phases a case can go through. See wireframe 3.7 (Admin Settings -- Case Type Detail) in DESIGN-REFERENCES.md.

#### Scenario CT-04a: Add status types to a case type

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin adds the following status types:
  1. "Ontvangen" (order: 1)
  2. "In behandeling" (order: 2, notifyInitiator: true, notificationText: "Uw zaak is in behandeling genomen")
  3. "Besluitvorming" (order: 3)
  4. "Afgehandeld" (order: 4, isFinal: true, notifyInitiator: true, notificationText: "Uw zaak is afgehandeld")
- THEN each status type MUST be created as an OpenRegister object linked to the case type
- AND they MUST be ordered by the `order` field
- AND the admin MUST see the ordered list with drag handles for reordering

#### Scenario CT-04b: Reorder status types via drag

- GIVEN a case type with status types in order: [Ontvangen(1), In behandeling(2), Besluitvorming(3), Afgehandeld(4)]
- WHEN the admin drags "Besluitvorming" before "In behandeling"
- THEN the `order` values MUST be recalculated: [Ontvangen(1), Besluitvorming(2), In behandeling(3), Afgehandeld(4)]
- AND the change MUST be persisted

#### Scenario CT-04c: Edit a status type

- GIVEN a status type "In behandeling" (order 2) on case type "Omgevingsvergunning"
- WHEN the admin changes `notifyInitiator` from false to true and sets `notificationText` to "Uw zaak is in behandeling genomen"
- THEN the status type MUST be updated
- AND the change MUST apply to future status transitions (not retroactive)

#### Scenario CT-04d: Delete a status type

- GIVEN a case type "Omgevingsvergunning" with 4 status types
- AND no active cases are currently at the status "Besluitvorming"
- WHEN the admin deletes the "Besluitvorming" status type
- THEN the status type MUST be removed
- AND the remaining status types MUST retain their relative order

#### Scenario CT-04e: Cannot delete status type in use

- GIVEN a case type "Omgevingsvergunning"
- AND 3 active cases are currently at status "In behandeling"
- WHEN the admin attempts to delete "In behandeling"
- THEN the system MUST reject the deletion
- AND display: "Cannot delete status type 'In behandeling': 3 active cases are currently at this status"

#### Scenario CT-04f: At least one final status required

- GIVEN a case type with 3 status types, one marked `isFinal = true`
- WHEN the admin attempts to unmark the final status (set `isFinal = false`)
- AND no other status is marked as final
- THEN the system MUST reject the change
- AND display: "At least one status type must be marked as final"

#### Scenario CT-04g: Status type order is required

- GIVEN an admin adding a new status type
- WHEN they submit without setting the `order` field
- THEN the system MUST reject the submission
- AND display: "Order is required for status types"

#### Scenario CT-04h: Status type name is required

- GIVEN an admin adding a new status type
- WHEN they submit with an empty `name`
- THEN the system MUST reject the submission
- AND display: "Status type name is required"

#### Scenario CT-04i: Status type notification fields

- GIVEN a status type with `notifyInitiator = true`
- WHEN displayed in the admin edit view
- THEN the notification checkbox MUST be checked
- AND the notification text field MUST be visible and editable
- AND the notification text SHOULD be displayed below the status name in the ordered list

---

### REQ-CT-05: Processing Deadline Configuration

**Feature tier**: MVP

The system MUST support configuring a processing deadline on each case type. The deadline is an ISO 8601 duration that controls automatic deadline calculation on cases.

#### Scenario CT-05a: Set processing deadline

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin sets `processingDeadline = "P56D"` (56 days)
- THEN the system MUST store the duration in ISO 8601 format
- AND the admin UI MUST display this as "56 days"

#### Scenario CT-05b: Invalid processing deadline format

- GIVEN a case type in edit mode
- WHEN the admin enters "56 days" (not ISO 8601) as the processing deadline
- THEN the system MUST reject the input
- AND display: "Processing deadline must be a valid ISO 8601 duration (e.g., P56D for 56 days, P8W for 8 weeks)"

#### Scenario CT-05c: Service target (optional)

- GIVEN a case type "Omgevingsvergunning" with `processingDeadline = "P56D"`
- WHEN the admin also sets `serviceTarget = "P42D"` (42 days)
- THEN the service target MUST be stored separately
- AND cases SHOULD display both the service target and the hard deadline

#### Scenario CT-05d: Deadline calculation on case creation

- GIVEN a case type with `processingDeadline = "P56D"`
- WHEN a case is created with `startDate = "2026-03-01"`
- THEN the case `deadline` MUST be calculated as "2026-04-26" (March 1 + 56 days)

---

### REQ-CT-06: Extension and Suspension Configuration

**Feature tier**: MVP (extension), V1 (suspension)

The system MUST support configuring extension and suspension rules on case types.

#### Scenario CT-06a: Enable extension with period

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin sets `extensionAllowed = true` and `extensionPeriod = "P28D"`
- THEN cases of this type MUST allow one deadline extension of 28 days

#### Scenario CT-06b: Extension period required when extension allowed

- GIVEN a case type with `extensionAllowed = true`
- WHEN the admin leaves `extensionPeriod` empty
- THEN the system MUST reject the save
- AND display: "Extension period is required when extension is allowed"

#### Scenario CT-06c: Disable extension

- GIVEN a case type "Klacht behandeling" in edit mode
- WHEN the admin sets `extensionAllowed = false`
- THEN the `extensionPeriod` field MUST be hidden or disabled
- AND cases of this type MUST NOT allow deadline extensions

#### Scenario CT-06d: Enable suspension (V1)

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin sets `suspensionAllowed = true`
- THEN cases of this type MUST allow suspension (pausing the deadline countdown)

#### Scenario CT-06e: Disable suspension (V1)

- GIVEN a case type "Melding" with `suspensionAllowed = false`
- WHEN a handler attempts to suspend a case of this type
- THEN the system MUST reject the suspension

---

### REQ-CT-07: Result Type Management

**Feature tier**: V1

The system SHOULD support defining result types with archival rules for each case type. See wireframe 3.7 in DESIGN-REFERENCES.md.

#### Scenario CT-07a: Add result types to a case type

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin adds result types:
  - "Vergunning verleend" (archiveAction: retain, retentionPeriod: P20Y, retentionDateSource: case_completed)
  - "Vergunning geweigerd" (archiveAction: destroy, retentionPeriod: P10Y, retentionDateSource: case_completed)
  - "Ingetrokken" (archiveAction: destroy, retentionPeriod: P5Y, retentionDateSource: case_completed)
- THEN each result type MUST be created as an OpenRegister object linked to the case type
- AND the admin list MUST display: name, archive action, retention period

#### Scenario CT-07b: Edit a result type

- GIVEN a result type "Vergunning verleend" with `retentionPeriod = "P20Y"`
- WHEN the admin changes `retentionPeriod` to "P25Y"
- THEN the result type MUST be updated
- AND the change MUST apply to future case closures only

#### Scenario CT-07c: Delete a result type

- GIVEN a result type "Ingetrokken" not referenced by any closed cases
- WHEN the admin deletes it
- THEN the result type MUST be removed from the case type

#### Scenario CT-07d: Delete result type in use -- blocked

- GIVEN a result type "Vergunning verleend" referenced by 5 closed cases
- WHEN the admin attempts to delete it
- THEN the system MUST reject the deletion
- AND display: "Cannot delete result type 'Vergunning verleend': referenced by 5 closed cases"

#### Scenario CT-07e: Retention date source options

- GIVEN the result type edit form
- WHEN the admin selects the `retentionDateSource` dropdown
- THEN the options MUST include: case_completed, decision_effective, decision_expiry, fixed_period, related_case, parent_case, custom_property, custom_date

---

### REQ-CT-08: Role Type Management

**Feature tier**: V1

The system SHOULD support defining allowed role types for each case type. See wireframe 3.7 in DESIGN-REFERENCES.md.

#### Scenario CT-08a: Add role types to a case type

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin adds role types:
  - "Aanvrager" (genericRole: initiator)
  - "Behandelaar" (genericRole: handler)
  - "Technisch adviseur" (genericRole: advisor)
  - "Beslisser" (genericRole: decision_maker)
- THEN each role type MUST be created as an OpenRegister object linked to the case type
- AND the admin list MUST display: name, generic role

#### Scenario CT-08b: Generic role options

- GIVEN the role type creation form
- WHEN the admin selects the `genericRole` dropdown
- THEN the options MUST include: initiator, handler, advisor, decision_maker, stakeholder, coordinator, contact, co_initiator

#### Scenario CT-08c: Role types restrict case role assignment

- GIVEN a case of type "Omgevingsvergunning" with role types ["Aanvrager", "Behandelaar", "Technisch adviseur", "Beslisser"]
- WHEN a user adds a participant to the case
- THEN the role selection MUST only show roles from the case type's role type list
- AND the user MUST NOT be able to assign "Zaakcoordinator" if it is not defined

#### Scenario CT-08d: Edit a role type

- GIVEN a role type "Technisch adviseur" with genericRole "advisor"
- WHEN the admin renames it to "Externe adviseur"
- THEN the name MUST be updated
- AND existing role assignments on cases MUST reflect the new name

#### Scenario CT-08e: Delete a role type not in use

- GIVEN a role type "Beslisser" not assigned on any active cases
- WHEN the admin deletes it
- THEN the role type MUST be removed from the case type

---

### REQ-CT-09: Property Definition Management

**Feature tier**: V1

The system SHOULD support defining custom field requirements for each case type. See wireframe 3.7 in DESIGN-REFERENCES.md.

#### Scenario CT-09a: Add property definitions

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin adds property definitions:
  - "Kadastraal nummer" (format: text, maxLength: 20, requiredAtStatus: "In behandeling")
  - "Bouwkosten" (format: number, requiredAtStatus: "Besluitvorming")
  - "Oppervlakte" (format: number, no requiredAtStatus)
  - "Bouwlagen" (format: number, no requiredAtStatus)
- THEN each property definition MUST be created as an OpenRegister object linked to the case type
- AND the admin list MUST display: name, format, max length (if set), required at status (if set)

#### Scenario CT-09b: Property format options

- GIVEN the property definition creation form
- WHEN the admin selects the `format` dropdown
- THEN the options MUST include: text, number, date, datetime

#### Scenario CT-09c: Property with allowed values (enum)

- GIVEN the admin creating a property definition "Bouwtype"
- WHEN they set `allowedValues = ["Nieuwbouw", "Verbouw", "Uitbreiding", "Renovatie"]`
- THEN cases of this type MUST only accept values from this list for the "Bouwtype" field

#### Scenario CT-09d: Property required at status blocks status change

- GIVEN a property "Kadastraal nummer" with `requiredAtStatus` referencing "In behandeling"
- AND a case that has not filled this property
- WHEN the user attempts to advance the case to "In behandeling"
- THEN the system MUST reject the status change
- AND display: "Cannot advance to 'In behandeling': required property 'Kadastraal nummer' is missing"

#### Scenario CT-09e: Property with maxLength validation

- GIVEN a property "Kadastraal nummer" with `maxLength = 20`
- WHEN a user enters a value with 25 characters
- THEN the system MUST reject the input
- AND display: "Value exceeds maximum length of 20 characters"

#### Scenario CT-09f: Delete a property definition

- GIVEN a property definition "Oppervlakte" on case type "Omgevingsvergunning"
- WHEN the admin deletes it
- THEN the property definition MUST be removed
- AND existing property values on cases SHOULD be preserved (not deleted) but the field SHOULD no longer appear for new cases

---

### REQ-CT-10: Document Type Management

**Feature tier**: V1

The system SHOULD support defining required document types for each case type. See wireframe 3.7 in DESIGN-REFERENCES.md.

#### Scenario CT-10a: Add document types

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin adds document types:
  - "Bouwtekening" (category: "Tekening", direction: incoming, order: 1, requiredAtStatus: "In behandeling")
  - "Constructieberekening" (category: "Tekening", direction: incoming, order: 2, requiredAtStatus: "In behandeling")
  - "Situatietekening" (category: "Tekening", direction: incoming, order: 3, requiredAtStatus: "In behandeling")
  - "Welstandsadvies" (category: "Advies", direction: internal, order: 4, requiredAtStatus: "Besluitvorming")
  - "Vergunningsbesluit" (category: "Besluit", direction: outgoing, order: 5, requiredAtStatus: "Afgehandeld")
- THEN each document type MUST be created as an OpenRegister object linked to the case type
- AND the admin list MUST display: name, direction, required at status

#### Scenario CT-10b: Direction options

- GIVEN the document type creation form
- WHEN the admin selects the `direction` dropdown
- THEN the options MUST include: incoming, internal, outgoing

#### Scenario CT-10c: Document type required at status blocks status change

- GIVEN a document type "Welstandsadvies" with `requiredAtStatus` referencing "Besluitvorming"
- AND a case that has no "Welstandsadvies" file uploaded
- WHEN the user attempts to advance the case to "Besluitvorming"
- THEN the system MUST reject the status change
- AND display: "Cannot advance to 'Besluitvorming': required document 'Welstandsadvies' is missing"

#### Scenario CT-10d: Document type with confidentiality

- GIVEN a document type "Vergunningsbesluit" with `confidentiality = "case_sensitive"`
- WHEN a file of this type is uploaded to a case
- THEN the file SHOULD inherit the confidentiality level "case_sensitive"

#### Scenario CT-10e: Delete a document type

- GIVEN a document type "Situatietekening" on case type "Omgevingsvergunning"
- WHEN the admin deletes it
- THEN the document type MUST be removed from the case type
- AND existing uploaded files MUST NOT be deleted (files remain, only the requirement is removed)

---

### REQ-CT-11: Decision Type Management

**Feature tier**: V1

The system SHOULD support defining decision types for each case type.

#### Scenario CT-11a: Add decision types

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin adds a decision type:
  - Name: "Vergunningsbesluit"
  - Category: "Vergunning"
  - Objection period: "P42D" (42 days)
  - Publication required: true
  - Publication period: "P14D" (14 days)
- THEN the decision type MUST be created as an OpenRegister object linked to the case type

#### Scenario CT-11b: Decision type restricts case decisions

- GIVEN a case of type "Omgevingsvergunning" with decision type "Vergunningsbesluit"
- WHEN a user creates a decision on the case
- THEN the decision type selection MUST only show types defined by the case type

#### Scenario CT-11c: Decision type with objection period

- GIVEN a decision type "Vergunningsbesluit" with `objectionPeriod = "P42D"`
- WHEN a decision of this type is recorded with `effectiveDate = "2026-03-01"`
- THEN the system SHOULD calculate and display the objection deadline: "2026-04-12"

---

### REQ-CT-12: Confidentiality Default

**Feature tier**: V1

The system SHOULD support confidentiality defaults on case types. Cases inherit the case type's confidentiality level.

#### Scenario CT-12a: Set confidentiality default

- GIVEN a case type "Omgevingsvergunning" in edit mode
- WHEN the admin sets `confidentiality = "internal"`
- THEN new cases of this type MUST default to confidentiality "internal"

#### Scenario CT-12b: Confidentiality level options

- GIVEN the case type confidentiality dropdown
- WHEN the admin opens the dropdown
- THEN the options MUST include: public, restricted, internal, case_sensitive, confidential, highly_confidential, secret, top_secret
- AND the options MUST be ordered from least to most restrictive

#### Scenario CT-12c: Overriding confidentiality on a case

- GIVEN a case type with `confidentiality = "internal"`
- AND a case created with this type (default "internal")
- WHEN the handler changes the case confidentiality to "confidential"
- THEN the case MUST update to "confidential"
- AND the audit trail MUST record the change

---

### REQ-CT-13: Default Case Type Selection

**Feature tier**: MVP

The system MUST support selecting a default case type in admin settings. The default case type is pre-selected when creating new cases.

#### Scenario CT-13a: Set default case type

- GIVEN case types "Omgevingsvergunning" (published), "Subsidieaanvraag" (published), "Klacht" (published)
- WHEN the admin marks "Omgevingsvergunning" as the default
- THEN "Omgevingsvergunning" MUST appear with a visual indicator (e.g., star) in the admin list
- AND the "New Case" form MUST pre-select "Omgevingsvergunning"

#### Scenario CT-13b: Only published case types can be default

- GIVEN a draft case type "Bezwaarschrift"
- WHEN the admin attempts to mark it as default
- THEN the system MUST reject the action
- AND display: "Only published case types can be set as default"

#### Scenario CT-13c: Change default case type

- GIVEN "Omgevingsvergunning" is the current default
- WHEN the admin sets "Subsidieaanvraag" as the new default
- THEN "Subsidieaanvraag" MUST become the default
- AND "Omgevingsvergunning" MUST lose its default status (only one default at a time)

---

### REQ-CT-14: Case Type Validation Rules

**Feature tier**: MVP

The system MUST enforce validation rules when creating or modifying case types.

#### Scenario CT-14a: Title is required

- GIVEN a case type creation form
- WHEN the admin submits with an empty title
- THEN the system MUST reject with error: "Title is required"

#### Scenario CT-14b: Processing deadline is required

- GIVEN a case type creation form
- WHEN the admin submits without a processing deadline
- THEN the system MUST reject with error: "Processing deadline is required"

#### Scenario CT-14c: Processing deadline must be valid ISO 8601 duration

- GIVEN a case type in edit mode
- WHEN the admin enters "two months" as the processing deadline
- THEN the system MUST reject with error: "Processing deadline must be a valid ISO 8601 duration (e.g., P56D, P8W, P2M)"

#### Scenario CT-14d: Valid ISO 8601 durations accepted

- GIVEN a case type in edit mode
- WHEN the admin enters any of: "P56D" (56 days), "P8W" (8 weeks), "P2M" (2 months), "P1Y" (1 year)
- THEN the system MUST accept the input
- AND display the human-readable equivalent

#### Scenario CT-14e: Required fields for case type

- GIVEN a case type creation form
- WHEN the admin leaves any of these fields empty: purpose, trigger, subject, origin, confidentiality, responsibleUnit
- THEN the system MUST reject the submission
- AND display validation errors for each missing required field

#### Scenario CT-14f: ValidUntil must be after validFrom

- GIVEN a case type with `validFrom = "2026-01-01"`
- WHEN the admin sets `validUntil = "2025-12-31"` (before validFrom)
- THEN the system MUST reject with error: "'Valid until' must be after 'Valid from'"

#### Scenario CT-14g: Extension period required when extension allowed

- GIVEN a case type with `extensionAllowed = true`
- WHEN the admin leaves `extensionPeriod` empty
- THEN the system MUST reject with error: "Extension period is required when extension is allowed"

---

### REQ-CT-15: Case Type Admin UI Tabs

**Feature tier**: MVP (General, Statuses), V1 (Results, Roles, Properties, Docs)

The case type edit page MUST be organized into tabs for managing the type and its sub-types. See wireframe 3.7 in DESIGN-REFERENCES.md.

#### Scenario CT-15a: Tab layout

- GIVEN the admin editing a case type "Omgevingsvergunning"
- WHEN the edit page loads
- THEN the page MUST display tabs: General, Statuses, Results, Roles, Properties, Docs
- AND the "General" tab MUST be active by default
- AND a "Save" button MUST be visible at the top

#### Scenario CT-15b: General tab content

- GIVEN the admin on the "General" tab
- THEN the tab MUST display editable fields for: title, description, purpose, trigger, subject, processing deadline (with ISO 8601 helper), service target, extension allowed (with conditional period), suspension allowed, origin, confidentiality, publication required (with conditional text), valid from, valid until, status (published/draft)

#### Scenario CT-15c: Statuses tab content

- GIVEN the admin on the "Statuses" tab
- THEN the tab MUST display an ordered list of status types with drag handles
- AND each status type MUST show: order number, name, isFinal checkbox, notifyInitiator checkbox (with conditional text field)
- AND an "Add" button MUST be available

#### Scenario CT-15d: Results tab content (V1)

- GIVEN the admin on the "Results" tab
- THEN the tab MUST display a list of result types
- AND each result type MUST show: name, archive action, retention period
- AND an "Add" button MUST be available

#### Scenario CT-15e: Roles tab content (V1)

- GIVEN the admin on the "Roles" tab
- THEN the tab MUST display a list of role types
- AND each role type MUST show: name, generic role
- AND an "Add" button MUST be available

#### Scenario CT-15f: Properties tab content (V1)

- GIVEN the admin on the "Properties" tab
- THEN the tab MUST display a list of property definitions
- AND each property MUST show: name, format, max length (if set), required at status (if set)
- AND an "Add" button MUST be available

#### Scenario CT-15g: Docs tab content (V1)

- GIVEN the admin on the "Docs" tab
- THEN the tab MUST display a list of document types
- AND each document type MUST show: name, direction (incoming/internal/outgoing), required at status (if set)
- AND an "Add" button MUST be available

---

### REQ-CT-16: Case Type Error Scenarios

**Feature tier**: MVP

The system MUST handle error scenarios gracefully for case type operations.

#### Scenario CT-16a: Publish incomplete case type

- GIVEN a case type with title and processing deadline filled but no purpose, trigger, or subject
- WHEN the admin attempts to publish
- THEN the system MUST reject with validation errors listing all missing required fields

#### Scenario CT-16b: Add status type without order

- GIVEN an admin adding a status type to a case type
- WHEN they submit without setting the `order` field
- THEN the system MUST either reject with "Order is required" or auto-assign the next sequential order number

#### Scenario CT-16c: Duplicate status type order

- GIVEN a case type with status type "Ontvangen" at order 1
- WHEN the admin adds a new status type "Intake" also at order 1
- THEN the system MUST reject with error: "A status type with order 1 already exists. Each status type must have a unique order."

#### Scenario CT-16d: Delete case type with closed cases

- GIVEN a case type "Subsidieaanvraag" with 5 closed cases and 0 active cases
- WHEN the admin attempts to delete the case type
- THEN the system MUST warn: "This case type is referenced by 5 closed cases. Deleting it will remove the type reference from those cases."
- AND if confirmed, the deletion SHOULD proceed

---

## UI References

- **Case Type List**: See wireframe 3.6 in DESIGN-REFERENCES.md (admin settings, case type cards with status/deadline/validity)
- **Case Type Editor**: See wireframe 3.7 in DESIGN-REFERENCES.md (tabbed interface: General, Statuses, Results, Roles, Properties, Docs)

## Dependencies

- **Case Management spec** (`../case-management/spec.md`): Cases reference case types for behavioral controls (statuses, deadlines, confidentiality, document requirements, property requirements, result types, role types).
- **OpenRegister**: All case type data is stored as OpenRegister objects in the `procest` register under the respective schemas (caseType, statusType, resultType, roleType, propertyDefinition, documentType, decisionType).
- **Nextcloud Admin Settings**: Case type management is exposed via the Nextcloud admin settings panel (`OCA\Procest\Settings\AdminSettings`).
