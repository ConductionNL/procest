# Roles & Decisions Specification

## Purpose

Roles define the relationship between participants (Nextcloud users or external contacts) and cases -- who is involved and in what capacity. Results record the formal outcome of a completed case, linking to a predefined result type that controls archival rules. Decisions are formal administrative choices made on cases, with legal validity periods and publication requirements.

Together, these three entities govern participation, outcomes, and formal decision-making within the case lifecycle.

**Standards**: Schema.org (`Role`, `ChooseAction`), CMMN (case outcomes, case participants), ZGW (`Rol`, `Resultaat`, `Besluit`, `RolType`, `ResultaatType`, `BesluitType`)
**Primary feature tier**: MVP (roles, results), V1 (decisions, role types, result types, decision types)

---

## Data Model

### Role Entity

Stored as an OpenRegister object in the `procest` register under the `role` schema.

| Property | Type | Schema.org/ZGW | Required | Default |
|----------|------|----------------|----------|---------|
| `name` | string (max 255) | `schema:roleName` / `omschrijving` | Yes | — |
| `description` | string | `schema:description` / `roltoelichting` | No | — |
| `roleType` | reference (UUID to RoleType) | — / `omschrijvingGeneriek` (via RoleType) | Yes | — |
| `case` | reference (UUID to Case) | — / `zaak` | Yes | — |
| `participant` | string (Nextcloud user UID or contact reference) | `schema:agent` / `betrokkene` | Yes | — |

### Role Type Entity

Stored as an OpenRegister object in the `procest` register under the `roleType` schema.

| Property | Type | ZGW Mapping | Required |
|----------|------|-------------|----------|
| `name` | string (max 255) | `omschrijving` | Yes |
| `caseType` | reference (UUID to CaseType) | `zaaktype` | Yes |
| `genericRole` | enum | `omschrijvingGeneriek` | Yes |

### Standard Generic Roles

These are the fixed set of generic role categories, derived from ZGW but internationally applicable.

| Generic Role | ZGW Dutch | Description | Typical Use |
|-------------|-----------|-------------|-------------|
| `initiator` | Initiator | Started the case | Citizen/applicant who submitted the request |
| `handler` | Behandelaar | Processes the case | Civil servant assigned to handle the case |
| `advisor` | Adviseur | Provides advice | Technical or legal advisor consulted |
| `decision_maker` | Beslisser | Makes decisions | Authority who signs off on decisions |
| `stakeholder` | Belanghebbende | Has interest in outcome | Neighbor, affected party |
| `coordinator` | Zaakcoordinator | Coordinates the case | Team lead overseeing case progress |
| `contact` | Klantcontacter | Contact person | Front-desk agent, customer contact |
| `co_initiator` | Mede-initiator | Co-initiator | Joint applicant or co-requester |

### Result Entity

Stored as an OpenRegister object in the `procest` register under the `result` schema.

| Property | Type | Source | Required |
|----------|------|--------|----------|
| `name` | string (max 255) | `schema:name` | Yes |
| `description` | string | `schema:description` | No |
| `case` | reference (UUID to Case) | Parent case | Yes |
| `resultType` | reference (UUID to ResultType) | ResultType definition | Yes |

### Result Type Entity

Stored as an OpenRegister object in the `procest` register under the `resultType` schema.

| Property | Type | ZGW Mapping | Required |
|----------|------|-------------|----------|
| `name` | string (max 255) | `omschrijving` | Yes |
| `description` | string | `toelichting` | No |
| `caseType` | reference (UUID to CaseType) | `zaaktype` | Yes |
| `archiveAction` | enum: `retain`, `destroy` | `archiefnominatie` | No |
| `retentionPeriod` | duration (ISO 8601, e.g., "P20Y") | `archiefactietermijn` | No |
| `retentionDateSource` | enum | `afleidingswijze` | No |

### Decision Entity

Stored as an OpenRegister object in the `procest` register under the `decision` schema.

| Property | Type | Schema.org/ZGW | Required | Default |
|----------|------|----------------|----------|---------|
| `title` | string (max 255) | `schema:name` | Yes | — |
| `description` | string | `schema:description` / `toelichting` | No | — |
| `case` | reference (UUID to Case) | — / `zaak` | Yes | — |
| `decisionType` | reference (UUID to DecisionType) | — / `besluittype` | No | — |
| `decidedBy` | string (Nextcloud user UID) | `schema:agent` | No | — |
| `decidedAt` | datetime (ISO 8601) | `schema:endTime` / `datum` | No | current timestamp |
| `effectiveDate` | date (ISO 8601) | `schema:startTime` / `ingangsdatum` | No | — |
| `expiryDate` | date (ISO 8601) | `schema:endTime` / `vervaldatum` | No | — |

### Decision Type Entity

Stored as an OpenRegister object in the `procest` register under the `decisionType` schema.

| Property | Type | ZGW Mapping | Required |
|----------|------|-------------|----------|
| `name` | string (max 255) | `omschrijving` | Yes |
| `description` | string | `toelichting` | No |
| `category` | string | `besluitcategorie` | No |
| `objectionPeriod` | duration (ISO 8601) | `reactietermijn` | No |
| `publicationRequired` | boolean | `publicatie_indicatie` | Yes |
| `publicationPeriod` | duration (ISO 8601) | `publicatietermijn` | No |

---

## Requirements

### REQ-ROLE-001: Role Assignment on Cases

**Tier**: MVP

The system MUST support assigning roles to participants on cases. A role links a participant (Nextcloud user or contact reference) to a case with a specific role type.

#### Scenario: Assign a handler to a case

- GIVEN a case #2024-042 "Bouwvergunning Keizersgracht" exists
- AND a role type "Behandelaar" (genericRole: `handler`) exists for the case's type "Omgevingsvergunning"
- WHEN the coordinator assigns Nextcloud user "jan.devries" as handler
- THEN the system MUST create a role object in the `role` schema with:
  - `name`: "Behandelaar"
  - `roleType`: UUID of the "Behandelaar" role type
  - `case`: UUID of case #2024-042
  - `participant`: "jan.devries"
- AND the handler MUST appear in the Participants section of the case detail view
- AND the case's `assignee` field SHOULD also be set to "jan.devries" (handler shortcut)
- AND the audit trail MUST record the role assignment

#### Scenario: Assign initiator from Pipelinq request-to-case conversion

- GIVEN a Pipelinq request #REQ-2024-089 is being converted to a case
- AND the requesting contact is "Petra Jansen" (contact ref: "contact-uuid-petra")
- AND the case type has a role type "Aanvrager" (genericRole: `initiator`)
- WHEN the case is created from the request
- THEN the system SHOULD automatically create a role with:
  - `roleType`: UUID of the "Aanvrager" role type
  - `participant`: "contact-uuid-petra"
  - `case`: UUID of the new case
- AND the initiator MUST appear in the Participants section under "Initiator"

#### Scenario: Assign multiple participants with different roles

- GIVEN case #2024-042 already has:
  - Handler: "jan.devries" (Jan de Vries)
  - Initiator: "contact-uuid-petra" (Petra Jansen)
- WHEN the coordinator adds an advisor with participant "dr.k.bakker"
- THEN the system MUST create a new role object for the advisor
- AND all three participants MUST be visible in the case detail:
  ```
  Handler:    Jan de Vries     [Reassign]
  Initiator:  Petra Jansen (Acme Corp)
  Advisor:    Dr. K. Bakker
  ```
- AND each role MUST show the participant display name and role type label

#### Scenario: Assign the same participant with multiple roles

- GIVEN "jan.devries" is already the handler on case #2024-042
- WHEN the coordinator also assigns "jan.devries" as the coordinator role
- THEN the system MUST create a second role object for the coordinator assignment
- AND the Participants section MUST show Jan de Vries listed under both roles

#### Scenario: Reassign a handler

- GIVEN case #2024-042 has handler "jan.devries" (Jan de Vries)
- WHEN the coordinator clicks "Reassign" and selects "maria.bakker" (Maria Bakker)
- THEN the existing handler role MUST be updated with `participant`: "maria.bakker"
- AND the case `assignee` field SHOULD be updated to "maria.bakker"
- AND "maria.bakker" SHOULD receive a notification about the assignment
- AND the audit trail MUST record the reassignment from "jan.devries" to "maria.bakker"

#### Scenario: Remove a role from a case

- GIVEN case #2024-042 has an advisor role for "dr.k.bakker"
- WHEN the coordinator removes the advisor role
- THEN the role object MUST be deleted from OpenRegister
- AND "Dr. K. Bakker" MUST no longer appear in the Participants section
- AND the audit trail MUST record the removal

---

### REQ-ROLE-002: Role Type Enforcement from Case Type

**Tier**: V1

The system SHOULD enforce that only role types linked to the case's case type can be assigned. This prevents assigning roles that are not applicable to the case type.

#### Scenario: Only allowed role types are available for assignment

- GIVEN case type "Omgevingsvergunning" has role types:
  - "Aanvrager" (genericRole: `initiator`)
  - "Behandelaar" (genericRole: `handler`)
  - "Technisch adviseur" (genericRole: `advisor`)
  - "Beslisser" (genericRole: `decision_maker`)
- WHEN the user opens the "Add Participant" dialog on a case of this type
- THEN only these four role types MUST be available for selection
- AND role types from other case types MUST NOT appear

#### Scenario: Reject assignment of a role type not linked to the case type

- GIVEN case type "Klacht behandeling" has only role types: "Klager" (initiator), "Behandelaar" (handler)
- WHEN the user attempts to assign a role with genericRole `advisor` to a case of this type
- THEN the system MUST reject the assignment
- AND the error message MUST indicate that the role type is not allowed for this case type

#### Scenario: Case type with no role types defined

- GIVEN case type "Melding" has no role types configured (V1 feature not yet configured)
- WHEN the user attempts to add a participant to a case of this type
- THEN the system SHOULD allow assignment with any generic role as fallback
- OR the system SHOULD display a message that role types need to be configured by an admin

---

### REQ-ROLE-003: Handler Assignment Shortcut

**Tier**: MVP

The system MUST provide a convenient handler assignment mechanism that creates the handler role and updates the case's `assignee` field in a single action.

#### Scenario: Quick handler assignment from case list

- GIVEN the case list shows case #2024-050 "Bouwvergunning Prinsengracht" with handler "---"
- WHEN the user clicks the handler cell and selects "Jan de Vries"
- THEN the system MUST create a handler role for "jan.devries" on the case
- AND the case `assignee` MUST be set to "jan.devries"
- AND the case list MUST immediately reflect the new handler

#### Scenario: Handler assignment from case detail

- GIVEN case #2024-050 has no handler assigned
- WHEN the user clicks "Assign Handler" in the Participants section
- THEN a user picker MUST appear showing Nextcloud users
- AND selecting "jan.devries" MUST create both the role and update the case assignee

---

### REQ-ROLE-004: Role-Based Case Access

**Tier**: V1

The system SHOULD support controlling who can see and edit a case based on their assigned role.

#### Scenario: Handler has full edit access

- GIVEN "jan.devries" is assigned as handler on case #2024-042
- WHEN Jan views the case
- THEN Jan MUST have full edit access: update case fields, change status, manage tasks, manage roles

#### Scenario: Advisor has read access plus task assignment

- GIVEN "dr.k.bakker" is assigned as advisor on case #2024-042
- WHEN Dr. Bakker views the case
- THEN Dr. Bakker MUST have read access to all case details
- AND Dr. Bakker SHOULD be able to complete tasks assigned to them
- AND Dr. Bakker MUST NOT be able to change the case status or manage other roles

#### Scenario: Unassigned user cannot access a restricted case

- GIVEN case #2024-042 has confidentiality `case_sensitive`
- AND "pieter.smit" has no role on the case
- WHEN "pieter.smit" attempts to view the case
- THEN the system SHOULD deny access based on RBAC rules
- AND the case MUST NOT appear in Pieter's case list

---

### REQ-RESULT-001: Case Result Recording

**Tier**: MVP

The system MUST support recording a result when a case is being completed. Each case MUST have at most one result. The result links to a predefined result type from the case type.

#### Scenario: Record a result on case completion

- GIVEN case #2024-042 "Bouwvergunning Keizersgracht" has status "Besluitvorming" (the status before final)
- AND the case type "Omgevingsvergunning" has result types: "Vergunning verleend", "Vergunning geweigerd", "Ingetrokken"
- WHEN the handler Jan de Vries records the result "Vergunning verleend"
- THEN the system MUST create a result object with:
  - `name`: "Vergunning verleend"
  - `case`: UUID of case #2024-042
  - `resultType`: UUID of the "Vergunning verleend" result type
- AND the case `result` reference MUST point to this result object
- AND the case `endDate` MUST be set to the current date
- AND the case status MUST transition to "Afgehandeld" (the final status)
- AND the audit trail MUST record the result and case closure

#### Scenario: Result type determines archival rules

- GIVEN the result type "Vergunning verleend" has:
  - archiveAction: `retain`
  - retentionPeriod: "P20Y" (20 years)
  - retentionDateSource: `case_completed`
- WHEN this result is recorded on case #2024-042
- THEN the system MUST store the archival metadata linked to the case
- AND the retention end date MUST be calculated as endDate + 20 years

#### Scenario: Result with "Denied" outcome

- GIVEN case #2024-038 "Subsidie innovatie" is being closed
- WHEN the handler Maria Bakker records result "Subsidie afgewezen" (archiveAction: `destroy`, retentionPeriod: "P10Y")
- THEN the result MUST be created and linked to the case
- AND the case MUST be marked as completed with endDate set

#### Scenario: Choose from predefined result types

- GIVEN case type "Omgevingsvergunning" has 3 result types configured
- WHEN the user initiates case closure on case #2024-042
- THEN the system MUST present the 3 result types as a selectable list
- AND the user MUST select one before completing the case
- AND free-text result entry MUST NOT be allowed (the result must match a defined result type)

#### Scenario: Attempt to record a result with an invalid result type

- GIVEN case type "Omgevingsvergunning" has result types: "Vergunning verleend", "Vergunning geweigerd", "Ingetrokken"
- WHEN the user attempts to record a result with a result type UUID belonging to case type "Klacht behandeling"
- THEN the system MUST reject the result
- AND the error message MUST indicate that the result type does not belong to this case type

#### Scenario: Attempt to record a second result on a case

- GIVEN case #2024-042 already has a result "Vergunning verleend"
- WHEN the user attempts to record another result
- THEN the system MUST reject the operation
- AND the error message MUST indicate that a case can have at most one result

#### Scenario: Case without result types configured

- GIVEN case type "Melding" has no result types defined (MVP without V1 type configuration)
- WHEN the handler closes the case
- THEN the system MUST allow case closure without selecting a result type
- AND a generic result with the case closure information MUST be recorded

---

### REQ-RESULT-002: Result Type Configuration

**Tier**: V1

Admin users MUST be able to configure result types per case type, including archival rules.

#### Scenario: Create a result type with archival rules

- GIVEN the admin is editing case type "Omgevingsvergunning"
- WHEN the admin creates a result type:
  - name: "Vergunning verleend"
  - archiveAction: `retain`
  - retentionPeriod: "P20Y"
  - retentionDateSource: `case_completed`
- THEN the result type MUST be created and linked to the case type
- AND the result type MUST appear in the Result Types section of the case type admin page

#### Scenario: Edit a result type's archival rules

- GIVEN result type "Vergunning geweigerd" for case type "Omgevingsvergunning" has retentionPeriod "P10Y"
- WHEN the admin changes retentionPeriod to "P7Y"
- THEN the result type MUST be updated
- AND existing cases that used this result type MUST NOT be retroactively affected

#### Scenario: Delete a result type that is not in use

- GIVEN result type "Ingetrokken" for case type "Omgevingsvergunning" is not referenced by any case result
- WHEN the admin deletes the result type
- THEN the result type MUST be removed from the case type
- AND it MUST no longer appear as an option during case closure

#### Scenario: Attempt to delete a result type that is in use

- GIVEN result type "Vergunning verleend" is referenced by 5 existing case results
- WHEN the admin attempts to delete it
- THEN the system SHOULD warn the admin that 5 cases reference this result type
- AND the system SHOULD either prevent deletion or mark the result type as inactive (not available for new results but still resolves for existing ones)

---

### REQ-DECISION-001: Decision CRUD

**Tier**: V1

The system SHOULD support creating, reading, updating, and deleting formal decisions linked to cases. Decisions represent administrative determinations with potential legal effect.

#### Scenario: Create a decision on a case

- GIVEN case #2024-042 "Bouwvergunning Keizersgracht" is in status "Besluitvorming"
- AND the case type has a decision type "Omgevingsvergunning besluit"
- WHEN the decision maker "dr.k.bakker" records a decision:
  - title: "Omgevingsvergunning verleend Keizersgracht 100"
  - description: "Vergunning verleend voor de verbouwing van het pand op Keizersgracht 100 conform ingediende bouwtekeningen."
  - decisionType: UUID of "Omgevingsvergunning besluit"
  - effectiveDate: "2026-03-01"
  - expiryDate: "2031-03-01"
- THEN the system MUST create a decision object in the `decision` schema with:
  - `case`: UUID of case #2024-042
  - `decidedBy`: "dr.k.bakker"
  - `decidedAt`: current timestamp
  - All provided fields stored correctly
- AND the decision MUST appear in the Decisions section of the case detail view
- AND the audit trail MUST record the decision creation

#### Scenario: Create a decision with default decidedAt

- GIVEN the user records a decision without explicitly setting `decidedAt`
- THEN `decidedAt` MUST default to the current timestamp
- AND the decision date MUST be displayed in the case detail

#### Scenario: View decisions on case detail

- GIVEN case #2024-042 has 2 decisions:
  - "Omgevingsvergunning verleend" (decidedAt: 2026-02-25, effectiveDate: 2026-03-01, expiryDate: 2031-03-01)
  - "Voorwaardelijk gebruik terrein" (decidedAt: 2026-02-20, effectiveDate: 2026-02-20, expiryDate: 2027-02-20)
- WHEN the user views the case detail
- THEN both decisions MUST be displayed in the Decisions section
- AND each decision MUST show: title, decided date, decided by, validity period (effective to expiry)
- AND decisions MUST be sorted by decidedAt descending (most recent first)

#### Scenario: Update a decision's description

- GIVEN decision "Omgevingsvergunning verleend" exists on case #2024-042
- WHEN the decision maker updates the description to add additional conditions
- THEN the decision object MUST be updated via the OpenRegister API
- AND the audit trail MUST record the modification

#### Scenario: Delete a decision

- GIVEN decision "Voorwaardelijk gebruik terrein" exists on case #2024-042
- WHEN the user deletes the decision
- THEN the decision object MUST be removed from OpenRegister
- AND it MUST no longer appear in the case detail
- AND the audit trail MUST record the deletion

---

### REQ-DECISION-002: Decision Validity Periods

**Tier**: V1

The system SHOULD support tracking the validity period of decisions (effectiveDate to expiryDate) and provide indicators when decisions are nearing expiry or have expired.

#### Scenario: Decision with validity period display

- GIVEN a decision "Omgevingsvergunning verleend" with effectiveDate "2026-03-01" and expiryDate "2031-03-01"
- AND today is 2026-02-25
- WHEN the user views the decision
- THEN the validity period MUST be displayed as "Mar 1, 2026 -- Mar 1, 2031"
- AND the status MUST show "Not yet effective" (effective date is in the future)

#### Scenario: Active decision

- GIVEN a decision with effectiveDate "2026-01-01" and expiryDate "2031-01-01"
- AND today is 2026-06-15
- THEN the decision MUST be displayed as "Active"
- AND the remaining validity SHOULD be displayed (e.g., "4 years, 6 months remaining")

#### Scenario: Decision nearing expiry

- GIVEN a decision with expiryDate "2026-03-15"
- AND today is 2026-02-25 (18 days before expiry)
- THEN the decision SHOULD show an amber warning indicator
- AND the warning SHOULD indicate "Expires in 18 days"

#### Scenario: Expired decision

- GIVEN a decision with expiryDate "2025-12-31"
- AND today is 2026-02-25
- THEN the decision MUST be displayed as "Expired"
- AND an expired indicator MUST be shown in red

#### Scenario: Decision without expiry date

- GIVEN a decision with effectiveDate "2026-03-01" and no expiryDate
- THEN the validity MUST be displayed as "From Mar 1, 2026" (no end date)
- AND the decision MUST be treated as indefinitely valid once effective

#### Scenario: Decision without any dates

- GIVEN a decision with no effectiveDate and no expiryDate
- THEN no validity period MUST be displayed
- AND only the decidedAt date MUST be shown

---

### REQ-DECISION-003: Decision Types from Case Type

**Tier**: V1

The system SHOULD support linking decision types to case types. When creating a decision on a case, only decision types allowed by the case's case type SHOULD be offered.

#### Scenario: Only allowed decision types are available

- GIVEN case type "Omgevingsvergunning" has decision types:
  - "Omgevingsvergunning besluit" (publicationRequired: true, objectionPeriod: "P6W")
  - "Voorlopige voorziening" (publicationRequired: false)
- WHEN the user creates a decision on a case of this type
- THEN only these two decision types MUST be available for selection
- AND the user MAY also create a decision without a decision type (free-form decision)

#### Scenario: Decision type provides default publication rules

- GIVEN decision type "Omgevingsvergunning besluit" has publicationRequired: true and publicationPeriod: "P6W"
- WHEN a decision of this type is created
- THEN the system SHOULD indicate that the decision requires publication
- AND the publication deadline SHOULD be calculated from the decidedAt date

#### Scenario: Create a decision without a decision type

- GIVEN a case where the case type has decision types configured
- WHEN the user creates a decision and selects "No type" or leaves decision type empty
- THEN the system MUST allow the decision to be created without a decision type
- AND all other required fields (title) MUST still be validated

---

### REQ-DECISION-004: Decision Validation

**Tier**: V1

The system MUST validate decision data to ensure consistency and completeness.

#### Scenario: Decision title is required

- GIVEN the user is creating a new decision
- WHEN the user submits without a title
- THEN the system MUST reject the request with a validation error
- AND the error message MUST indicate that `title` is required

#### Scenario: Decision case reference is required

- GIVEN the user is creating a new decision
- WHEN the user submits without a case reference
- THEN the system MUST reject the request with a validation error
- AND the error message MUST indicate that `case` is required

#### Scenario: Expiry date must be after effective date

- GIVEN the user sets effectiveDate "2026-03-01" and expiryDate "2026-02-01"
- WHEN the user submits the decision
- THEN the system MUST reject the request
- AND the error message MUST indicate that expiryDate must be after effectiveDate

#### Scenario: DecidedBy must be a valid Nextcloud user

- GIVEN the user sets decidedBy to "nonexistent.user"
- WHEN the user submits the decision
- THEN the system SHOULD warn or reject that the user does not exist
- AND the system MAY allow the value if it is a free-text reference (external decision maker)

---

### REQ-ROLE-005: Participant Display on Case Detail

**Tier**: MVP

The case detail view MUST display all assigned participants grouped by role type, as shown in the design wireframes.

#### Scenario: Full participant section display

- GIVEN case #2024-042 has the following roles:
  - Handler: Jan de Vries ("jan.devries")
  - Initiator: Petra Jansen ("contact-uuid-petra", company "Acme Corp")
  - Advisor: Dr. K. Bakker ("dr.k.bakker")
- WHEN the user views the case detail page
- THEN the Participants section MUST display:
  ```
  PARTICIPANTS

  Handler:
  [avatar] Jan de Vries
           [Reassign]

  Initiator:
  [avatar] Petra Jansen (Acme Corp)

  Advisor:
  [avatar] Dr. K. Bakker

  [+ Add Participant]
  ```
- AND each participant MUST show their display name resolved from Nextcloud user or contact reference
- AND the handler role MUST have a "Reassign" action
- AND the "Add Participant" button MUST open a dialog to select role type and participant

#### Scenario: No participants assigned

- GIVEN a newly created case #2024-051 with no role assignments
- WHEN the user views the case detail
- THEN the Participants section MUST show an empty state
- AND a prominent "Assign Handler" action MUST be visible
- AND an "Add Participant" button MUST be available

#### Scenario: External contact as participant

- GIVEN "Petra Jansen" is a contact in Nextcloud Contacts (not a Nextcloud user)
- WHEN her role is displayed on the case
- THEN the system MUST resolve the contact reference to show her display name
- AND the system SHOULD show the organization ("Acme Corp") if available from the contact record
- AND the participant MUST be distinguished from Nextcloud users (e.g., different icon or label)

---

### REQ-ROLE-006: Role Validation

**Tier**: MVP

The system MUST validate role assignments to ensure data integrity.

#### Scenario: Participant is required

- GIVEN the user is creating a new role on a case
- WHEN the user submits without selecting a participant
- THEN the system MUST reject the request
- AND the error message MUST indicate that `participant` is required

#### Scenario: Role type is required

- GIVEN the user is creating a new role on a case
- WHEN the user submits without selecting a role type
- THEN the system MUST reject the request
- AND the error message MUST indicate that `roleType` is required

#### Scenario: Case reference is required

- GIVEN the user is creating a new role
- WHEN the user submits without a case reference
- THEN the system MUST reject the request
- AND the error message MUST indicate that `case` is required

#### Scenario: Validate that the referenced case exists

- GIVEN the user submits a role with `case` set to a non-existent UUID
- THEN the system MUST reject the request
- AND the error message MUST indicate that the referenced case does not exist

---

### REQ-DECISION-005: Decisions Section on Case Detail

**Tier**: V1

The case detail view MUST display all decisions linked to the case.

#### Scenario: Decisions section with no decisions

- GIVEN case #2024-042 has no decisions recorded
- WHEN the user views the case detail
- THEN the Decisions section MUST display "(no decisions yet)"
- AND an "Add Decision" button MUST be visible

#### Scenario: Decisions section with multiple decisions

- GIVEN case #2024-042 has 2 decisions
- WHEN the user views the case detail
- THEN both decisions MUST be listed with:
  - Title
  - Decided by (user display name)
  - Decided at (date)
  - Validity period (if set)
  - Decision type (if set)
- AND each decision MUST be clickable to view/edit details

---

## Error Scenarios Summary

| Error | Expected Behavior | Tier |
|-------|-------------------|------|
| Assign role type not linked to case type | Reject with "Role type not allowed for this case type" | V1 |
| Record result with invalid result type | Reject with "Result type does not belong to this case type" | V1 |
| Record second result on a case | Reject with "Case already has a result" | MVP |
| Create decision without title | Reject with validation error "title is required" | V1 |
| Create decision with expiryDate before effectiveDate | Reject with "expiryDate must be after effectiveDate" | V1 |
| Create role without participant | Reject with "participant is required" | MVP |
| Create role referencing non-existent case | Reject with "Referenced case does not exist" | MVP |
| Assign handler to non-existent user | Reject with "User does not exist" | MVP |

---

## Accessibility

All roles and decisions interfaces MUST comply with WCAG AA:

- Participant display names MUST have sufficient contrast
- Role type selection MUST be keyboard-accessible
- Decision validity indicators MUST NOT rely solely on color (use text labels alongside color)
- The "Add Participant" dialog MUST be focusable and navigable by keyboard
- Screen readers MUST announce role type and participant name for each entry

---

## Performance

- The Participants section MUST resolve user/contact display names within 1 second
- Decision validity calculations MUST be performed client-side (no extra API call)
- Role and result operations MUST complete within 2 seconds
- The case detail page MUST load participants, results, and decisions in parallel with other sections
