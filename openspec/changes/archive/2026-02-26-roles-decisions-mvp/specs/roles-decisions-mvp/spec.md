# Delta Spec: roles-decisions-mvp

This delta spec scopes the MVP implementation of roles and results from the main `roles-decisions` spec.

## Scope

**In scope (MVP)**: REQ-ROLE-001, REQ-ROLE-003, REQ-ROLE-005, REQ-ROLE-006, REQ-RESULT-001

**Deferred (V1)**: REQ-ROLE-002, REQ-ROLE-004, REQ-RESULT-002, REQ-DECISION-001 through REQ-DECISION-005

---

## Current State

### Existing Code

- **`src/store/store.js`** — Already registers `role`, `result`, `roleType`, `resultType` object types with OpenRegister schema/register IDs
- **`src/views/cases/CaseDetail.vue`** — Has 6 sections (header, status bar, timeline, info+deadline, tasks, activity). No participants or results sections exist.
- **`src/store/modules/object.js`** — Full CRUD for any registered object type: `fetchCollection`, `fetchObject`, `saveObject`, `deleteObject`
- **Case object** — Has `assignee` field (flat string, Nextcloud UID) but no structured role references

### What's Missing

1. No `ParticipantsSection.vue` on case detail
2. No `ResultSection.vue` on case detail
3. No `AddParticipantDialog.vue` for role assignment
4. No result selection during case completion flow
5. No handler reassign UI
6. Roles not fetched or displayed anywhere

---

## MODIFIED Requirements

### Requirement: REQ-ROLE-001 — Role Assignment on Cases (MVP)

**Current state**: Cases only have a flat `assignee` field. No role objects exist in the UI.

**Change**: Add participants section to CaseDetail that creates/reads/deletes role objects filtered by case UUID.

#### Scenario: Assign a participant to a case

- GIVEN a case exists and role types are loaded
- WHEN the user clicks "Add Participant" and selects a role type and Nextcloud user
- THEN a role object MUST be created with: `name` (role type name), `roleType` (UUID), `case` (case UUID), `participant` (user UID)
- AND the participant MUST appear in the Participants section grouped under the role type

#### Scenario: Remove a participant role

- GIVEN a case has an advisor role assigned
- WHEN the user clicks the remove action on that role
- THEN the role object MUST be deleted via the store
- AND the participant MUST disappear from the section

---

### Requirement: REQ-ROLE-003 — Handler Assignment Shortcut (MVP)

**Current state**: Handler is set via the `assignee` field on the case info form.

**Change**: Add a "Reassign" action on the handler role that updates both the role participant and the case `assignee` field in one action.

#### Scenario: Reassign handler

- GIVEN a case has a handler role for user A
- WHEN the coordinator clicks "Reassign" and selects user B
- THEN the handler role's `participant` MUST be updated to user B
- AND the case's `assignee` field MUST be updated to user B
- AND both changes MUST be saved via the store

#### Scenario: Assign first handler

- GIVEN a case has no handler role
- WHEN the user clicks "Assign Handler" and selects a user
- THEN a new handler role MUST be created
- AND the case `assignee` MUST be set to the selected user

---

### Requirement: REQ-ROLE-005 — Participant Display on Case Detail (MVP)

**Current state**: No participants section exists.

**Change**: Add a ParticipantsSection component between the info/deadline panels and the tasks section.

#### Scenario: Display participants grouped by role type

- GIVEN a case has roles: handler (Jan), initiator (Petra), advisor (Dr. Bakker)
- WHEN the user views the case detail
- THEN the Participants section MUST display all three grouped by role type label
- AND each participant MUST show their display name (resolved from Nextcloud user)
- AND the handler MUST have a "Reassign" action
- AND an "Add Participant" button MUST be visible

#### Scenario: No participants

- GIVEN a case has no role assignments
- THEN an empty state MUST show with an "Assign Handler" prompt

---

### Requirement: REQ-ROLE-006 — Role Validation (MVP)

**Current state**: No validation — roles don't exist in UI yet.

**Change**: Validate role data before saving.

#### Scenario: Required fields

- GIVEN the user submits a role without participant or roleType
- THEN the store MUST reject with a validation error
- AND the dialog MUST show the error message

---

### Requirement: REQ-RESULT-001 — Case Result Recording (MVP)

**Current state**: CaseDetail has a result prompt overlay when transitioning to final status, but it uses a free-text field.

**Change**: Replace the free-text result input with a result type selector. When the user transitions to a final status, they must select a result type. A result object is created and linked to the case.

#### Scenario: Record result on case completion

- GIVEN a case is being transitioned to a final status
- AND result types exist for the case's case type
- WHEN the user selects a result type from the dropdown
- THEN a result object MUST be created with: `name` (result type name), `case` (case UUID), `resultType` (result type UUID)
- AND the case `endDate` MUST be set to today
- AND the case status MUST transition to the final status

#### Scenario: No result types configured

- GIVEN a case type has no result types
- WHEN the user transitions to a final status
- THEN the system MUST allow closure without result type selection
- AND a generic result MUST be recorded with the case closure info

#### Scenario: Attempt second result

- GIVEN a case already has a result
- WHEN another result creation is attempted
- THEN the system MUST reject with "Case already has a result"
