# Case Types (MVP) — Delta Specification

## Purpose

Implement the MVP tier of the case type system. This delta spec scopes the existing `case-types/spec.md` requirements to what will be built in this change: core CRUD, draft/publish lifecycle, validity periods, status type management, deadline configuration, extension config, default type, validation, General+Statuses tabs, and error scenarios.

## ADDED Requirements

_No new requirements added. All requirements below reference existing spec requirements scoped to MVP tier._

## MODIFIED Requirements

### Requirement: REQ-CT-01 Case Type CRUD (MVP scope)

The system MUST support creating, reading, updating, and deleting case types. Case types are managed by admins via the Nextcloud admin settings page. This MVP implements scenarios CT-01a through CT-01e from the main spec.

#### Scenario: CT-01a Create a case type

- GIVEN an admin on the Procest admin settings page
- WHEN they click "Add Case Type" and fill in the required fields (title, purpose, trigger, subject, processingDeadline, origin, confidentiality, responsibleUnit, validFrom)
- AND submit the form
- THEN the system MUST create an OpenRegister object in the `procest` register with the `caseType` schema
- AND `isDraft` MUST default to `true`
- AND a unique `identifier` MUST be auto-generated (format: `CT-{timestamp}`)

#### Scenario: CT-01b Update a case type

- GIVEN an existing case type "Omgevingsvergunning"
- WHEN the admin changes the `processingDeadline` from "P56D" to "P42D" and saves
- THEN the system MUST update the OpenRegister object via PUT with the full object

#### Scenario: CT-01c Delete a case type with no active cases

- GIVEN a case type with no cases referencing it
- WHEN the admin clicks "Delete" and confirms
- THEN the system MUST delete the case type
- AND all linked status types MUST also be deleted

#### Scenario: CT-01d Prevent deletion of case type with active cases

- GIVEN a case type referenced by active cases
- WHEN the admin attempts to delete
- THEN the system MUST show an error: "Cannot delete: active cases are using this type"

#### Scenario: CT-01e Case type list display

- GIVEN multiple case types exist
- WHEN the admin views the case type list
- THEN each type MUST display: title, status (Published/Draft badge), processing deadline, status type count, validity period
- AND the default type MUST show a star indicator

### Requirement: REQ-CT-02 Draft/Published Lifecycle (MVP scope)

The system MUST support a draft/published lifecycle for case types. Draft case types MUST NOT be usable for creating cases.

#### Scenario: CT-02a New case type defaults to draft

- GIVEN an admin creating a new case type
- WHEN the case type is saved
- THEN `isDraft` MUST be `true`
- AND the list MUST show a "DRAFT" badge

#### Scenario: CT-02b Publish with valid configuration

- GIVEN a draft case type with all required fields AND at least one status type with one final status AND validFrom set
- WHEN the admin clicks "Publish"
- THEN `isDraft` MUST be set to `false`
- AND the type becomes available for creating cases

#### Scenario: CT-02c Publish blocked — no status types

- GIVEN a draft case type with no status types
- WHEN the admin clicks "Publish"
- THEN the system MUST show: "Cannot publish: at least one status type must be defined"

#### Scenario: CT-02d Publish blocked — no final status

- GIVEN a draft case type with status types but none marked `isFinal`
- WHEN the admin clicks "Publish"
- THEN the system MUST show: "Cannot publish: at least one status type must be marked as final"

#### Scenario: CT-02e Publish blocked — no validFrom

- GIVEN a draft case type without `validFrom`
- WHEN the admin clicks "Publish"
- THEN the system MUST show: "Cannot publish: 'Valid from' date must be set"

#### Scenario: CT-02f Unpublish a case type

- GIVEN a published case type
- WHEN the admin clicks "Unpublish"
- THEN the system MUST warn about impact on new case creation
- AND if confirmed, revert to draft

### Requirement: REQ-CT-03 Validity Periods (MVP scope)

The system MUST support validity windows on case types.

#### Scenario: CT-03a Valid type shown in admin list

- GIVEN a case type with `validFrom` and optional `validUntil`
- WHEN displayed in the admin list
- THEN it MUST show the validity range (e.g., "Jan 2026 — Dec 2027" or "Jan 2026 — (no end)")

#### Scenario: CT-03b Expired type indicator

- GIVEN a case type with `validUntil` in the past
- WHEN displayed in the admin list
- THEN it MUST show an "Expired" indicator

### Requirement: REQ-CT-04 Status Type Management (MVP scope)

The system MUST support defining ordered status types for each case type.

#### Scenario: CT-04a Add status types

- GIVEN a case type in edit mode, Statuses tab active
- WHEN the admin fills in name and order and clicks "Add"
- THEN a new status type MUST be created as an OpenRegister object linked to the case type via the `caseType` field

#### Scenario: CT-04b Reorder via drag

- GIVEN a case type with multiple status types
- WHEN the admin drags a status type to a new position
- THEN `order` values MUST be recalculated and persisted for all affected status types

#### Scenario: CT-04c Edit a status type

- GIVEN an existing status type
- WHEN the admin changes fields (name, isFinal, notifyInitiator, notificationText)
- THEN the status type MUST be updated

#### Scenario: CT-04d Delete a status type

- GIVEN a status type not in use by any active case
- WHEN the admin deletes it
- THEN it MUST be removed
- AND remaining types retain relative order

#### Scenario: CT-04e Block deletion of in-use status type

- GIVEN a status type referenced by active cases
- WHEN the admin attempts to delete
- THEN the system MUST show: "Cannot delete: active cases are at this status"

#### Scenario: CT-04f Final status enforcement

- GIVEN only one status type marked `isFinal`
- WHEN the admin unchecks `isFinal`
- THEN the system MUST block with: "At least one status type must be marked as final"

#### Scenario: CT-04g Order is required

- GIVEN the admin adding a status type without `order`
- THEN the system MUST either reject or auto-assign the next order

#### Scenario: CT-04h Name is required

- GIVEN the admin adding a status type without `name`
- THEN the system MUST reject with: "Status type name is required"

### Requirement: REQ-CT-05 Processing Deadline Configuration (MVP scope)

The system MUST support ISO 8601 duration fields for processing deadlines.

#### Scenario: CT-05a Set and display processing deadline

- GIVEN the admin sets `processingDeadline = "P56D"`
- THEN the UI MUST display "56 days" as human-readable text
- AND store the ISO 8601 duration

#### Scenario: CT-05b Invalid format rejection

- GIVEN the admin enters "56 days" (not ISO 8601)
- THEN the system MUST reject with: "Must be a valid ISO 8601 duration (e.g., P56D)"

#### Scenario: CT-05c Service target (optional)

- GIVEN the admin sets `serviceTarget = "P42D"` alongside `processingDeadline = "P56D"`
- THEN both MUST be stored independently

### Requirement: REQ-CT-06 Extension Configuration (MVP scope)

The system MUST support configuring extension rules on case types.

#### Scenario: CT-06a Enable extension with period

- GIVEN the admin sets `extensionAllowed = true` and `extensionPeriod = "P28D"`
- THEN both MUST be stored

#### Scenario: CT-06b Extension period required when enabled

- GIVEN `extensionAllowed = true` and `extensionPeriod` empty
- THEN the system MUST reject: "Extension period is required when extension is allowed"

#### Scenario: CT-06c Disable extension hides period

- GIVEN `extensionAllowed = false`
- THEN `extensionPeriod` field MUST be hidden or disabled

### Requirement: REQ-CT-13 Default Case Type (MVP scope)

The system MUST support selecting a default case type.

#### Scenario: CT-13a Set default

- GIVEN published case types
- WHEN the admin clicks "Set as default" on one
- THEN it MUST be marked as default (star indicator)
- AND any previous default MUST lose its default status

#### Scenario: CT-13b Only published types can be default

- GIVEN a draft case type
- WHEN the admin tries to set it as default
- THEN the system MUST reject: "Only published case types can be set as default"

### Requirement: REQ-CT-14 Validation Rules (MVP scope)

The system MUST enforce validation when creating/modifying case types.

#### Scenario: CT-14a Required fields

- GIVEN a case type form
- WHEN the admin submits with any required field empty (title, purpose, trigger, subject, processingDeadline, origin, confidentiality, responsibleUnit)
- THEN the system MUST show validation errors for each missing field

#### Scenario: CT-14b ISO 8601 duration validation

- GIVEN a duration field (processingDeadline, serviceTarget, extensionPeriod)
- WHEN the admin enters invalid format
- THEN the system MUST reject with format guidance

#### Scenario: CT-14c ValidUntil after ValidFrom

- GIVEN `validFrom = "2026-01-01"` and `validUntil = "2025-12-31"`
- THEN the system MUST reject: "'Valid until' must be after 'Valid from'"

### Requirement: REQ-CT-15 Admin UI Tabs (MVP scope)

The case type editor MUST have General and Statuses tabs.

#### Scenario: CT-15a Tab layout

- GIVEN the admin editing a case type
- THEN the page MUST show tabs: General, Statuses
- AND "General" MUST be active by default

#### Scenario: CT-15b General tab content

- GIVEN the "General" tab active
- THEN editable fields MUST include: title, description, purpose, trigger, subject, processingDeadline, serviceTarget, extensionAllowed (with conditional period), origin, confidentiality, publicationRequired (with conditional text), validFrom, validUntil, responsibleUnit, referenceProcess, keywords

#### Scenario: CT-15c Statuses tab content

- GIVEN the "Statuses" tab active
- THEN an ordered list of status types MUST display with drag handles
- AND each shows: order, name, isFinal checkbox, notifyInitiator checkbox (with conditional text)
- AND an "Add" button MUST be available

### Requirement: REQ-CT-16 Error Scenarios (MVP scope)

The system MUST handle error scenarios gracefully.

#### Scenario: CT-16a Publish incomplete type

- GIVEN a type missing required fields
- WHEN admin publishes
- THEN validation errors MUST list all missing fields

#### Scenario: CT-16b Duplicate status order

- GIVEN a status type at order 1 exists
- WHEN admin adds another at order 1
- THEN the system MUST reject: "A status type with this order already exists"

## REMOVED Requirements

_None removed._
