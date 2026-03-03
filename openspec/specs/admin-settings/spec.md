# Admin Settings Specification

## Purpose

The admin settings page provides a Nextcloud admin panel for configuring Procest. Administrators manage case types and all their related type definitions: statuses, results, roles, properties, documents, and decisions. The case type system is the behavioral engine of Procest -- every aspect of how a case behaves (allowed statuses, deadlines, required fields, archival rules) is defined here. The admin settings UI follows a list-detail pattern: a case type list on the main page, and a tabbed detail/edit view per case type.

**Feature tiers**: MVP (admin page registration, access control, case type list, case type CRUD, status type CRUD with reorder, default case type, publish action, general tab); V1 (results tab, roles tab, properties tab, documents tab)

## Data Sources

All admin settings data is stored as OpenRegister objects in the `procest` register:
- **Case types**: schema `caseType`
- **Status types**: schema `statusType` (linked to caseType via `caseType` reference)
- **Result types**: schema `resultType` (linked to caseType via `caseType` reference)
- **Role types**: schema `roleType` (linked to caseType via `caseType` reference)
- **Property definitions**: schema `propertyDefinition` (linked to caseType via `caseType` reference)
- **Document types**: schema `documentType` (linked to caseType via `caseType` reference)

## Requirements

### REQ-ADMIN-001: Nextcloud Admin Panel Registration [MVP]

The system MUST register a settings page in the Nextcloud admin panel under the standard administration section.

#### Scenario: Admin settings page is accessible
- GIVEN a Nextcloud admin user
- WHEN they navigate to Administration settings
- THEN a "Procest" entry MUST appear in the admin settings navigation
- AND clicking "Procest" MUST display the Procest admin settings page

#### Scenario: Regular users cannot access admin settings
- GIVEN a regular (non-admin) Nextcloud user
- WHEN they attempt to navigate to Administration > Procest
- THEN the system MUST deny access
- AND the "Procest" entry MUST NOT appear in the regular user's settings navigation
- AND direct URL access to the admin settings endpoint MUST return HTTP 403

#### Scenario: Group admin access
- GIVEN a Nextcloud group admin (not full admin)
- WHEN they attempt to access Procest admin settings
- THEN the system MUST deny access (only full Nextcloud admins may configure case types)

### REQ-ADMIN-002: Case Type List View [MVP]

The admin settings MUST display a list of all case types with key metadata.

#### Scenario: List all case types
- GIVEN the following case types exist:
  | title                | isDraft | processingDeadline | statusCount | resultTypeCount | validFrom  | validUntil | isDefault |
  |----------------------|---------|-------------------|-------------|-----------------|------------|------------|-----------|
  | Omgevingsvergunning  | false   | P56D              | 4           | 3               | 2026-01-01 | 2027-12-31 | true      |
  | Subsidieaanvraag     | false   | P42D              | 3           | 2               | 2026-01-01 | (none)     | false     |
  | Klacht behandeling   | false   | P28D              | 3           | 2               | 2026-01-01 | (none)     | false     |
  | Bezwaarschrift       | true    | P84D              | 2           | 0               | (not set)  | (none)     | false     |
- WHEN the admin views the case type list
- THEN all 4 case types MUST be displayed
- AND each case type entry MUST show:
  - Title
  - Processing deadline in human-readable form (e.g., "56 days")
  - Count of linked status types (e.g., "4 statuses")
  - Count of linked result types (e.g., "3 result types")
  - Published/Draft badge
  - Validity period (e.g., "Jan 2026 -- Dec 2027" or "Jan 2026 -- (no end)")
- AND the default case type MUST be marked with a star icon or "(default)" label

#### Scenario: Draft types visually distinguished
- GIVEN case type "Bezwaarschrift" has `isDraft = true`
- WHEN the admin views the case type list
- THEN the draft type MUST display a warning badge (e.g., "DRAFT" in amber/yellow)
- AND the draft type SHOULD have a visually different background or border to distinguish it from published types
- AND the validity period MUST show "(not set)" when `validFrom` is not configured

#### Scenario: Click to edit case type
- GIVEN the case type list is displayed
- WHEN the admin clicks on "Omgevingsvergunning" or its "Edit" button
- THEN the system MUST navigate to the case type detail/edit view for "Omgevingsvergunning"

### REQ-ADMIN-003: Create Case Type [MVP]

The admin MUST be able to create new case types that start in draft status.

#### Scenario: Add a new case type
- GIVEN the admin is on the case type list
- WHEN they click "+ Add Case Type"
- THEN the system MUST present a case type creation form or navigate to a new case type detail view
- AND the new case type MUST have `isDraft = true` by default
- AND the admin MUST be able to fill in at minimum: title, purpose, trigger, subject, processingDeadline, origin, confidentiality, and responsibleUnit (all required fields per ARCHITECTURE.md)

#### Scenario: Created case type appears in list
- GIVEN the admin fills in the required fields and saves a new case type "Bezwaarschrift"
- WHEN the save completes successfully
- THEN the new case type MUST appear in the case type list with a "DRAFT" badge
- AND the admin MUST be redirected to (or remain on) the detail view to add statuses and other type definitions

#### Scenario: Validation on required fields
- GIVEN the admin tries to save a case type without filling in the title
- WHEN they click Save
- THEN the system MUST display a validation error indicating "Title is required"
- AND the case type MUST NOT be created
- AND all other required fields (purpose, trigger, subject, processingDeadline, origin, confidentiality, responsibleUnit) MUST also show validation errors if empty

### REQ-ADMIN-004: Case Type Detail/Edit View -- Tabbed Interface [MVP]

The case type detail view MUST use a tabbed interface for organizing the various type definitions.

#### Scenario: Tab layout
- GIVEN the admin opens the detail view for case type "Omgevingsvergunning"
- THEN the view MUST display the following tabs:
  - **General** (MVP) -- case type core fields
  - **Statuses** (MVP) -- status type management
  - **Results** (V1) -- result type management
  - **Roles** (V1) -- role type management
  - **Properties** (V1) -- property definition management
  - **Documents** (V1) -- document type management
- AND the "General" tab MUST be selected by default
- AND V1 tabs (Results, Roles, Properties, Documents) MAY be hidden or disabled until V1 is implemented

#### Scenario: Save button placement
- GIVEN the admin is editing a case type
- THEN a "Save" button MUST be visible at the top of the page (in the header area)
- AND the Save button MUST persist across tab switches (it is page-level, not tab-level)

### REQ-ADMIN-005: General Tab [MVP]

The General tab MUST allow editing all core case type fields.

#### Scenario: Display and edit general fields
- GIVEN the admin is on the General tab for "Omgevingsvergunning"
- THEN the following fields MUST be editable:
  | Field               | Value                              | Type          |
  |---------------------|------------------------------------|---------------|
  | Title               | Omgevingsvergunning                | text input    |
  | Description         | Vergunning voor bouwactiviteiten   | textarea      |
  | Purpose             | Beoordelen bouwplannen             | text input    |
  | Trigger             | Aanvraag van burger/bedrijf        | text input    |
  | Subject             | Bouw- en verbouwactiviteiten       | text input    |
  | Processing deadline | 56 (displayed as "P56D")           | number + unit |
  | Service target      | 42 (displayed as "P42D")           | number + unit |
  | Extension allowed   | checked                            | checkbox      |
  | Extension period    | 28 (displayed as "P28D")           | number + unit |
  | Suspension allowed  | checked                            | checkbox      |
  | Origin              | External                           | radio buttons |
  | Confidentiality     | Internal                           | select        |
  | Publication req.    | checked                            | checkbox      |
  | Publication text    | Bouwvergunning verleend...         | text input    |
  | Valid from          | 2026-01-01                         | date picker   |
  | Valid until         | 2027-12-31                         | date picker   |
  | Status              | Published / Draft                  | radio buttons |

#### Scenario: Processing deadline format validation
- GIVEN the admin enters "abc" in the processing deadline field
- WHEN they try to save
- THEN the system MUST display a validation error indicating the deadline must be a valid duration
- AND the system MUST accept ISO 8601 duration format (e.g., "P56D" for 56 days, "P8W" for 8 weeks)
- OR the system MUST provide a simplified input (number + unit selector: days/weeks/months) that converts to ISO 8601

#### Scenario: Extension period required when extension allowed
- GIVEN the admin checks "Extension allowed"
- WHEN they leave the "Extension period" field empty and try to save
- THEN the system MUST display a validation error: "Extension period is required when extension is allowed"

#### Scenario: Extension period hidden when extension not allowed
- GIVEN the admin unchecks "Extension allowed"
- THEN the "Extension period" field MUST be hidden or disabled
- AND any previously set extension period value SHOULD be cleared

### REQ-ADMIN-006: Status Type Management [MVP]

The Statuses tab MUST allow managing the ordered list of status types for a case type.

#### Scenario: List status types
- GIVEN case type "Omgevingsvergunning" has the following status types:
  | order | name             | isFinal | notifyInitiator | notificationText                        |
  |-------|------------------|---------|------------------|-----------------------------------------|
  | 1     | Ontvangen        | false   | false            |                                         |
  | 2     | In behandeling   | false   | true             | Uw zaak is in behandeling genomen       |
  | 3     | Besluitvorming   | false   | false            |                                         |
  | 4     | Afgehandeld      | true    | true             | Uw zaak is afgehandeld                  |
- WHEN the admin views the Statuses tab
- THEN all 4 status types MUST be displayed in order
- AND each status type MUST show: order number, name, isFinal checkbox, notifyInitiator toggle
- AND status types with `notifyInitiator = true` MUST show the notification text field below them

#### Scenario: Add a new status type
- GIVEN the admin is on the Statuses tab
- WHEN they click "+ Add" and enter name "Bezwaar"
- THEN a new status type MUST be created with the next sequential order number (5)
- AND the new status type MUST have `isFinal = false` by default
- AND the status type MUST be linked to the current case type

#### Scenario: Edit a status type
- GIVEN status type "Ontvangen" exists with order 1
- WHEN the admin changes the name to "Aanvraag ontvangen"
- AND clicks Save
- THEN the status type name MUST be updated to "Aanvraag ontvangen"
- AND existing cases with this status MUST reflect the updated name

#### Scenario: Reorder status types via drag-and-drop
- GIVEN 4 status types ordered: Ontvangen (1), In behandeling (2), Besluitvorming (3), Afgehandeld (4)
- WHEN the admin drags "Besluitvorming" above "In behandeling"
- THEN the order MUST be updated to: Ontvangen (1), Besluitvorming (2), In behandeling (3), Afgehandeld (4)
- AND all order fields MUST be recalculated as sequential integers starting from 1
- AND each status type row MUST display a drag handle icon (e.g., six dots / hamburger icon)

#### Scenario: Mark status as final
- GIVEN status type "Afgehandeld" with isFinal = false
- WHEN the admin checks the "Final" checkbox
- THEN `isFinal` MUST be set to true
- AND cases reaching this status will be treated as closed by the system

#### Scenario: Delete a status type
- GIVEN status type "Bezwaar" exists with no cases currently in that status
- WHEN the admin clicks delete on "Bezwaar"
- THEN the system MUST prompt for confirmation
- AND upon confirmation, the status type MUST be deleted
- AND the remaining status types MUST have their order numbers recalculated sequentially

#### Scenario: Delete status type with active cases
- GIVEN status type "In behandeling" has 5 cases currently in that status
- WHEN the admin tries to delete it
- THEN the system MUST display a warning: "This status is in use by 5 cases. Reassign them before deleting."
- AND the deletion MUST be blocked until no cases reference this status

#### Scenario: Status type notification configuration
- GIVEN status type "In behandeling" on the Statuses tab
- WHEN the admin toggles "Notify initiator" to ON
- THEN a text field for "Notification text" MUST appear below the toggle
- AND the admin MUST be able to enter text such as "Uw zaak is in behandeling genomen"
- AND when the toggle is OFF, the notification text field MUST be hidden

### REQ-ADMIN-007: Default Case Type Selection [MVP]

The admin MUST be able to designate one case type as the default.

#### Scenario: Set default case type
- GIVEN case types "Omgevingsvergunning" (default), "Subsidieaanvraag", "Klacht behandeling" exist
- WHEN the admin clicks the default indicator (star/checkbox) on "Subsidieaanvraag"
- THEN "Subsidieaanvraag" MUST become the default case type
- AND "Omgevingsvergunning" MUST lose its default status (only one default at a time)
- AND the star/indicator MUST move to "Subsidieaanvraag"

#### Scenario: Default case type must be published
- GIVEN a draft case type "Bezwaarschrift"
- WHEN the admin tries to set it as default
- THEN the system MUST display an error: "Only published case types can be set as default"
- AND the default MUST NOT change

#### Scenario: No default set
- GIVEN no case type is marked as default
- WHEN a user creates a new case
- THEN the case creation form MUST require explicit case type selection (no pre-selection)

### REQ-ADMIN-008: Case Type Publish Action [MVP]

The admin MUST be able to publish a draft case type after validating its completeness.

#### Scenario: Publish a complete case type
- GIVEN draft case type "Bezwaarschrift" with:
  - All required general fields filled in
  - At least 1 status type defined
  - `validFrom` date set
- WHEN the admin changes the status from "Draft" to "Published" and saves
- THEN the case type `isDraft` MUST be set to false
- AND the case type MUST now be available for creating new cases
- AND the case type list MUST show "Published" instead of "DRAFT"

#### Scenario: Publish incomplete case type -- no statuses
- GIVEN draft case type "Bezwaarschrift" with no status types defined
- WHEN the admin tries to publish it
- THEN the system MUST display a validation error: "At least one status type is required before publishing"
- AND the case type MUST remain as draft

#### Scenario: Publish incomplete case type -- no validFrom
- GIVEN draft case type "Bezwaarschrift" with status types but no `validFrom` date
- WHEN the admin tries to publish it
- THEN the system MUST display a validation error: "Valid from date is required before publishing"
- AND the case type MUST remain as draft

#### Scenario: Publish incomplete case type -- missing required general fields
- GIVEN draft case type "Bezwaarschrift" with `purpose` field empty
- WHEN the admin tries to publish it
- THEN the system MUST display validation errors for all missing required fields
- AND the case type MUST remain as draft

### REQ-ADMIN-009: Result Type Management [V1]

The Results tab SHOULD allow managing result types with archival rules per case type.

#### Scenario: List result types
- GIVEN case type "Omgevingsvergunning" has the following result types:
  | name                   | archiveAction | retentionPeriod | retentionDateSource |
  |------------------------|---------------|-----------------|---------------------|
  | Vergunning verleend    | retain        | P20Y            | case_completed      |
  | Vergunning geweigerd   | destroy       | P10Y            | case_completed      |
  | Ingetrokken            | destroy       | P5Y             | case_completed      |
- WHEN the admin views the Results tab
- THEN all 3 result types MUST be displayed
- AND each result type MUST show: name, archive action (retain/destroy), retention period in human-readable form (e.g., "20 years"), and retention date source

#### Scenario: Add a result type
- GIVEN the admin is on the Results tab
- WHEN they click "+ Add" and fill in:
  - Name: "Vergunning verleend"
  - Archive action: "retain"
  - Retention period: "P20Y" (20 years)
  - Retention date source: "case_completed"
- AND click Save
- THEN the result type MUST be created and linked to the current case type
- AND it MUST appear in the result types list

#### Scenario: Edit a result type
- GIVEN result type "Vergunning geweigerd" with retention period P10Y
- WHEN the admin changes the retention period to P15Y
- AND clicks Save
- THEN the retention period MUST be updated to P15Y

#### Scenario: Delete a result type
- GIVEN result type "Ingetrokken" with no cases referencing it
- WHEN the admin clicks delete
- THEN the system MUST prompt for confirmation
- AND upon confirmation, the result type MUST be deleted

#### Scenario: Delete result type in use
- GIVEN result type "Vergunning verleend" is referenced by 3 completed cases
- WHEN the admin tries to delete it
- THEN the system MUST display a warning: "This result type is in use by 3 cases and cannot be deleted"
- AND the deletion MUST be blocked

### REQ-ADMIN-010: Role Type Management [V1]

The Roles tab SHOULD allow managing role types with generic role mapping per case type.

#### Scenario: List role types
- GIVEN case type "Omgevingsvergunning" has the following role types:
  | name               | genericRole     |
  |--------------------|-----------------|
  | Aanvrager          | initiator       |
  | Behandelaar        | handler         |
  | Technisch adviseur | advisor         |
  | Beslisser          | decision_maker  |
- WHEN the admin views the Roles tab
- THEN all 4 role types MUST be displayed
- AND each role type MUST show the name and the generic role mapping

#### Scenario: Add a role type
- GIVEN the admin is on the Roles tab
- WHEN they click "+ Add" and enter:
  - Name: "Technisch adviseur"
  - Generic role: "advisor" (selected from dropdown)
- AND click Save
- THEN the role type MUST be created and linked to the current case type

#### Scenario: Generic role dropdown options
- GIVEN the admin is adding or editing a role type
- THEN the "Generic role" field MUST be a dropdown with the following options (from ARCHITECTURE.md):
  - initiator, handler, advisor, decision_maker, stakeholder, coordinator, contact, co_initiator
- AND the admin MUST select exactly one generic role per role type

#### Scenario: Edit a role type
- GIVEN role type "Behandelaar" with genericRole "handler"
- WHEN the admin changes the name to "Dossierbehandelaar"
- AND clicks Save
- THEN the role type name MUST be updated

#### Scenario: Delete a role type
- GIVEN role type "Technisch adviseur" with no active role assignments referencing it
- WHEN the admin clicks delete and confirms
- THEN the role type MUST be deleted

### REQ-ADMIN-011: Property Definition Management [V1]

The Properties tab SHOULD allow managing custom field definitions per case type.

#### Scenario: List property definitions
- GIVEN case type "Omgevingsvergunning" has the following property definitions:
  | name              | format | maxLength | requiredAtStatus  |
  |-------------------|--------|-----------|-------------------|
  | Kadastraal nummer | text   | 20        | In behandeling    |
  | Bouwkosten        | number | (none)    | Besluitvorming    |
  | Oppervlakte       | number | (none)    | (optional)        |
  | Bouwlagen         | number | (none)    | (optional)        |
- WHEN the admin views the Properties tab
- THEN all 4 property definitions MUST be displayed
- AND each MUST show: name, format, max length (if set), and the status at which it is required (or "optional")

#### Scenario: Add a property definition
- GIVEN the admin is on the Properties tab
- WHEN they click "+ Add" and fill in:
  - Name: "Kadastraal nummer"
  - Definition: "Het kadastrale perceelnummer"
  - Format: "text" (selected from dropdown: text, number, date, datetime)
  - Max length: 20
  - Required at status: "In behandeling" (selected from the case type's status types)
- AND click Save
- THEN the property definition MUST be created and linked to the current case type

#### Scenario: Required at status dropdown
- GIVEN the admin is adding a property definition
- THEN the "Required at status" field MUST be a dropdown populated with the case type's status types
- AND the dropdown MUST include an "(optional)" or "(not required)" option for properties that are never required

#### Scenario: Edit a property definition
- GIVEN property "Bouwkosten" with format "number"
- WHEN the admin changes the format to "text"
- AND clicks Save
- THEN the format MUST be updated to "text"

#### Scenario: Delete a property definition
- GIVEN property "Oppervlakte" exists
- WHEN the admin clicks delete and confirms
- THEN the property definition MUST be deleted
- AND any existing case property values for "Oppervlakte" SHOULD be retained on existing cases (orphaned but not lost)

### REQ-ADMIN-012: Document Type Management [V1]

The Documents tab SHOULD allow managing document type requirements per case type.

#### Scenario: List document types
- GIVEN case type "Omgevingsvergunning" has the following document types:
  | name                   | direction | requiredAtStatus   |
  |------------------------|-----------|---------------------|
  | Bouwtekening           | incoming  | In behandeling      |
  | Constructieberekening  | incoming  | In behandeling      |
  | Situatietekening       | incoming  | In behandeling      |
  | Welstandsadvies        | internal  | Besluitvorming      |
  | Vergunningsbesluit     | outgoing  | Afgehandeld         |
- WHEN the admin views the Documents tab
- THEN all 5 document types MUST be displayed
- AND each MUST show: name, direction (incoming/internal/outgoing), and required-at-status

#### Scenario: Add a document type
- GIVEN the admin is on the Documents tab
- WHEN they click "+ Add" and fill in:
  - Name: "Bouwtekening"
  - Category: "Tekeningen"
  - Direction: "incoming" (selected from dropdown: incoming, internal, outgoing)
  - Required at status: "In behandeling" (from case type's statuses)
- AND click Save
- THEN the document type MUST be created and linked to the current case type

#### Scenario: Direction dropdown options
- GIVEN the admin is adding or editing a document type
- THEN the "Direction" field MUST be a dropdown with options: incoming, internal, outgoing
- AND these MUST map to: documents received from initiator, internal working documents, and documents sent to initiator

#### Scenario: Edit a document type
- GIVEN document type "Welstandsadvies" with direction "internal"
- WHEN the admin changes the required-at-status from "Besluitvorming" to "In behandeling"
- AND clicks Save
- THEN the required-at-status MUST be updated

#### Scenario: Delete a document type
- GIVEN document type "Situatietekening" exists
- WHEN the admin clicks delete and confirms
- THEN the document type MUST be deleted from the case type

### REQ-ADMIN-013: Error Scenarios [MVP]

The admin settings MUST handle error conditions gracefully.

#### Scenario: Delete published case type with active cases
- GIVEN published case type "Omgevingsvergunning" has 10 active (non-final) cases
- WHEN the admin tries to delete the case type
- THEN the system MUST display a blocking error: "This case type has 10 active cases and cannot be deleted. Close or reassign all cases first."
- AND the case type MUST NOT be deleted

#### Scenario: Delete published case type with only completed cases
- GIVEN published case type "Klacht behandeling" has 5 cases, all with final status
- WHEN the admin tries to delete the case type
- THEN the system MUST display a warning: "This case type has 5 completed cases. Deleting it will make those cases reference a missing type. Proceed?"
- AND upon confirmation, the case type MUST be deleted
- AND the system SHOULD set `isDraft = true` or mark it as archived rather than hard-deleting

#### Scenario: Reorder to duplicate order numbers
- GIVEN the admin somehow creates two status types with the same order number (e.g., via concurrent editing)
- WHEN the system detects duplicate order numbers
- THEN the system MUST automatically renumber status types sequentially based on their current position
- AND display a notification: "Status order has been recalculated"

#### Scenario: Save fails due to network error
- GIVEN the admin edits a case type and clicks Save
- AND the API request fails due to a network error
- WHEN the error occurs
- THEN the system MUST display an error message: "Failed to save changes. Please try again."
- AND the form data MUST be preserved (not lost)
- AND the admin MUST be able to retry saving without re-entering data

#### Scenario: Concurrent editing conflict
- GIVEN admin "A" and admin "B" both open case type "Omgevingsvergunning" for editing
- AND admin "A" saves changes to the processing deadline
- WHEN admin "B" tries to save their changes
- THEN the system SHOULD detect the conflict (e.g., via version/timestamp comparison)
- AND display a warning: "This case type was modified by another user. Reload to see the latest version."
- OR the system MAY use last-write-wins if conflict detection is not implemented in MVP

### REQ-ADMIN-014: Validation Rules [MVP]

The admin settings MUST enforce validation rules on case type configuration.

#### Scenario: Processing deadline format validation
- GIVEN the admin enters a processing deadline
- THEN the system MUST validate it as a valid ISO 8601 duration (e.g., "P56D", "P8W", "P2M")
- AND if using a simplified input (number + unit), the system MUST convert to ISO 8601 on save
- AND invalid values (negative numbers, zero, non-numeric input) MUST be rejected with a clear error message

#### Scenario: Extension period required when extension allowed
- GIVEN the admin checks "Extension allowed" on the General tab
- WHEN they try to save without setting an extension period
- THEN the system MUST display: "Extension period is required when extension is allowed"
- AND the save MUST be blocked

#### Scenario: Valid from must precede valid until
- GIVEN the admin sets validFrom = 2027-01-01 and validUntil = 2026-12-31
- WHEN they try to save
- THEN the system MUST display: "Valid from date must be before valid until date"
- AND the save MUST be blocked

#### Scenario: At least one non-final status required
- GIVEN a case type with only one status type marked as `isFinal = true`
- WHEN the admin tries to save
- THEN the system MUST display a warning: "At least one non-final status is recommended for proper case lifecycle"
- AND the save MAY proceed (warning, not blocking)

#### Scenario: Status type name uniqueness within case type
- GIVEN case type "Omgevingsvergunning" already has a status type "Ontvangen"
- WHEN the admin tries to add another status type named "Ontvangen"
- THEN the system MUST display: "A status type with this name already exists for this case type"
- AND the creation MUST be blocked

### REQ-ADMIN-015: Case Type List Layout [MVP]

The case type list MUST follow the layout structure defined in DESIGN-REFERENCES.md section 3.6.

#### Scenario: List layout structure
- GIVEN the admin views the case type list
- THEN the page MUST display:
  - A page title "Administration > Procest"
  - A "CASE TYPES" section header with an "+ Add Case Type" button
  - A list of case type cards, each showing metadata as described in REQ-ADMIN-002
- AND published types MUST display a "Published" badge in a neutral/positive color
- AND draft types MUST display a "DRAFT" badge in amber/warning color with a different visual treatment
- AND the default case type MUST show a star icon or "(default)" label

#### Scenario: Empty case type list
- GIVEN no case types have been created
- WHEN the admin views the case type list
- THEN the system MUST display an empty state message (e.g., "No case types configured yet")
- AND the "+ Add Case Type" button MUST be prominently displayed
- AND the system SHOULD provide guidance (e.g., "Create your first case type to start managing cases")

### REQ-ADMIN-016: Case Type Detail Layout [MVP]

The case type detail/edit view MUST follow the layout structure defined in DESIGN-REFERENCES.md section 3.7.

#### Scenario: Detail view header
- GIVEN the admin opens the detail view for "Omgevingsvergunning"
- THEN the page MUST display:
  - Breadcrumb: "Administration > Procest > Omgevingsvergunning"
  - A "Save" button in the header area
  - The tabbed interface as defined in REQ-ADMIN-004

#### Scenario: Statuses tab layout
- GIVEN the admin is on the Statuses tab
- THEN the layout MUST show:
  - Section header "STATUSES (drag to reorder)" with an "+ Add" button
  - A list of status types with drag handles on the left
  - Each status type row showing: drag handle, order number, name, notification toggle, "Final" checkbox
  - Status types with notification enabled showing the notification text field below the row

#### Scenario: Back navigation
- GIVEN the admin is on the case type detail view
- WHEN they click the breadcrumb link "Procest"
- THEN the system MUST navigate back to the case type list
- AND if there are unsaved changes, the system SHOULD prompt: "You have unsaved changes. Discard?"

## Non-Functional Requirements

- **Performance**: Case type list MUST load within 1 second for up to 50 case types. Case type detail view (including all linked type definitions) MUST load within 2 seconds.
- **Accessibility**: All form fields MUST have associated labels. Drag-and-drop reordering MUST have a keyboard alternative (e.g., up/down arrow buttons). Error messages MUST be associated with their fields via `aria-describedby`. All content MUST meet WCAG AA standards.
- **Localization**: All labels, error messages, validation messages, and placeholder text MUST support English and Dutch localization.
- **Data integrity**: Deleting a case type or sub-entity MUST use soft-delete or referential integrity checks. The system MUST prevent orphaning active cases.
- **Responsiveness**: The admin settings page MUST be usable on desktop viewports (minimum 1024px width). Mobile responsiveness is not required for admin settings.
