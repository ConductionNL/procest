# Case Management Specification

## Purpose

Case management is the core capability of Procest. A case represents a coherent body of work with a defined lifecycle, initiation, and result. Cases are governed by configurable **case types** that control behavior: allowed statuses, required fields, processing deadlines, retention rules, and more. Cases follow CMMN 1.1 concepts (CasePlanModel) and are semantically typed as `schema:Project`.

**Standards**: CMMN 1.1 (CasePlanModel), Schema.org (`Project`), ZGW (`Zaak`)
**Feature tier**: MVP (core case CRUD, list, detail, status, deadline), V1 (sub-cases, confidentiality, result types, document checklist, suspension)

## Data Model

### Case Entity

| Property | Type | CMMN/Schema.org | ZGW Mapping | Required |
|----------|------|----------------|-------------|----------|
| `title` | string | `schema:name` | `omschrijving` | Yes |
| `description` | string | `schema:description` | `toelichting` | No |
| `identifier` | string | `schema:identifier` | `identificatie` | Auto |
| `caseType` | reference | CMMN CaseDefinition | `zaaktype` | Yes |
| `status` | reference | CMMN PlanItem lifecycle | `status` | Yes |
| `result` | reference | CMMN case outcome | `resultaat` | No |
| `startDate` | date | `schema:startDate` | `startdatum` | Yes |
| `endDate` | date | `schema:endDate` | `einddatum` | No |
| `plannedEndDate` | date | -- | `einddatumGepland` | No |
| `deadline` | date | -- | `uiterlijkeEinddatumAfdoening` | Auto (from caseType) |
| `confidentiality` | enum | -- | `vertrouwelijkheidaanduiding` | No (default from caseType) |
| `assignee` | string | CMMN HumanTask.assignee | -- | No |
| `priority` | enum | `schema:priority` | -- | No |
| `parentCase` | reference | CMMN CaseTask | `hoofdzaak` | No |
| `relatedCases` | array | -- | `relevanteAndereZaken` | No |
| `geometry` | GeoJSON | `schema:geo` | `zaakgeometrie` | No |

### Case Type Behavioral Controls on Cases

- `deadline` is auto-calculated: `startDate` + `caseType.processingDeadline`
- `confidentiality` defaults from `caseType.confidentiality`
- `status` MUST reference a status type linked to the case's case type
- Only role types linked to the case type are allowed for participant assignment
- Property definitions linked to the case type MUST be satisfied before reaching required statuses
- Document types linked to the case type define which documents are expected at each status

### Confidentiality Levels

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

## Requirements

---

### REQ-CM-01: Case Creation

**Feature tier**: MVP

The system MUST support creating new cases. Each case MUST be linked to a published, valid case type. The case type controls initial defaults and behavioral constraints.

#### Scenario CM-01a: Create a case with case type selection

- GIVEN a user with case management access
- AND a published case type "Omgevingsvergunning" with `processingDeadline = "P56D"`, `confidentiality = "internal"`, and status types ["Ontvangen", "In behandeling", "Besluitvorming", "Afgehandeld"]
- WHEN the user opens the "New Case" form and selects case type "Omgevingsvergunning"
- AND enters title "Bouwvergunning Keizersgracht 100"
- AND submits the form
- THEN the system MUST create an OpenRegister object in the `procest` register with the `case` schema
- AND the `identifier` MUST be auto-generated (format: `YYYY-NNN`, e.g., "2026-042")
- AND the `startDate` MUST default to the current date
- AND the `deadline` MUST be auto-calculated as `startDate + P56D` (e.g., 2026-01-15 + 56 days = 2026-03-12)
- AND the `confidentiality` MUST default to "internal" (inherited from case type)
- AND the `status` MUST be set to "Ontvangen" (the first status type by `order`)
- AND a unique `identifier` MUST be auto-generated

#### Scenario CM-01b: Case type is required at creation

- GIVEN a user opening the "New Case" form
- WHEN the user attempts to submit without selecting a case type
- THEN the system MUST reject the submission
- AND the system MUST display a validation error: "Case type is required"

#### Scenario CM-01c: Title is required at creation

- GIVEN a user opening the "New Case" form with case type "Klacht behandeling" selected
- WHEN the user attempts to submit without entering a title
- THEN the system MUST reject the submission
- AND the system MUST display a validation error: "Title is required"

#### Scenario CM-01d: Cannot create case with draft case type

- GIVEN a case type "Bezwaarschrift" with `isDraft = true`
- WHEN a user attempts to create a case of type "Bezwaarschrift"
- THEN the system MUST reject the creation
- AND the system MUST display an error: "Cannot create a case with a draft case type. The case type must be published first."

#### Scenario CM-01e: Cannot create case with expired case type

- GIVEN a case type "Bouwvergunning Oud" with `validUntil = "2025-12-31"`
- AND today is "2026-02-25"
- WHEN a user attempts to create a case of this type
- THEN the system MUST reject the creation
- AND the system MUST display an error: "Cannot create a case with an expired case type. The case type was valid until 2025-12-31."

#### Scenario CM-01f: Cannot create case with case type not yet valid

- GIVEN a case type "Nieuwe Subsidie" with `validFrom = "2027-01-01"`
- AND today is "2026-02-25"
- WHEN a user attempts to create a case of this type
- THEN the system MUST reject the creation
- AND the system MUST display an error: "Cannot create a case with a case type that is not yet valid. The case type is valid from 2027-01-01."

#### Scenario CM-01g: Default case type pre-selected

- GIVEN a case type "Omgevingsvergunning" is marked as the default case type in admin settings
- WHEN a user opens the "New Case" form
- THEN the case type dropdown MUST pre-select "Omgevingsvergunning"
- AND the user MAY change the selection to another published, valid case type

---

### REQ-CM-02: Case Update

**Feature tier**: MVP

The system MUST support updating case properties. Changes MUST be recorded in the audit trail.

#### Scenario CM-02a: Update case description

- GIVEN an existing case "Bouwvergunning Keizersgracht 100" with identifier "2026-042"
- WHEN the user updates the description to "Verbouwing woonhuis, 3 bouwlagen, 180 m2"
- THEN the system MUST update the OpenRegister object
- AND the audit trail MUST record: user, timestamp, field changed, old value, new value

#### Scenario CM-02b: Update case priority

- GIVEN an existing case with priority "normal"
- WHEN the handler changes the priority to "high"
- THEN the system MUST update the `priority` field
- AND the audit trail MUST record the change

#### Scenario CM-02c: Reassign case handler

- GIVEN a case assigned to "Jan de Vries"
- WHEN an authorized user reassigns the case to "Maria van den Berg"
- THEN the `assignee` field MUST be updated to "Maria van den Berg"
- AND the audit trail MUST record: "Handler changed from Jan de Vries to Maria van den Berg"

---

### REQ-CM-03: Case Deletion

**Feature tier**: MVP

The system MUST support deleting cases. Deletion SHOULD be restricted to cases without a final status.

#### Scenario CM-03a: Delete a case in initial status

- GIVEN a case "Testmelding" with status "Ontvangen" and no linked tasks, decisions, or sub-cases
- WHEN an authorized user deletes the case
- THEN the system MUST remove the OpenRegister object
- AND the system MUST display a confirmation dialog before deletion

#### Scenario CM-03b: Warn before deleting case with linked objects

- GIVEN a case with 3 linked tasks and 1 linked decision
- WHEN an authorized user attempts to delete the case
- THEN the system MUST display a warning: "This case has 3 tasks and 1 decision. Deleting the case will also remove these linked objects."
- AND the user MUST confirm before proceeding

---

### REQ-CM-04: Case List View

**Feature tier**: MVP

The system MUST provide a list view of all cases with search, sort, filter, and pagination capabilities. See wireframe 3.2 (Case List View) in DESIGN-REFERENCES.md.

#### Scenario CM-04a: Default case list

- GIVEN 24 open cases in the system
- WHEN the user navigates to the Cases page
- THEN the system MUST display a table with columns: ID, Title, Type, Status, Deadline, Handler
- AND the list MUST be paginated at 20 items per page by default
- AND overdue cases MUST be visually highlighted (red indicator)

#### Scenario CM-04b: Filter by case type

- GIVEN cases of types "Omgevingsvergunning" (10), "Subsidieaanvraag" (7), "Klacht" (4), "Melding" (3)
- WHEN the user selects filter "Type: Omgevingsvergunning"
- THEN only the 10 cases of type "Omgevingsvergunning" MUST be shown

#### Scenario CM-04c: Filter by status

- GIVEN cases in statuses "Ontvangen" (8), "In behandeling" (6), "Besluitvorming" (5), "Afgehandeld" (5)
- WHEN the user selects filter "Status: In behandeling"
- THEN only the 6 cases with status "In behandeling" MUST be shown

#### Scenario CM-04d: Filter by handler

- GIVEN cases assigned to "Jan de Vries" (8), "Maria van den Berg" (6), unassigned (10)
- WHEN the user selects filter "Handler: Jan de Vries"
- THEN only Jan's 8 cases MUST be shown

#### Scenario CM-04e: Filter by priority

- GIVEN cases with priorities "high" (4), "normal" (16), "low" (4)
- WHEN the user selects filter "Priority: high"
- THEN only the 4 high-priority cases MUST be shown

#### Scenario CM-04f: Filter overdue cases

- GIVEN 3 cases past their deadline
- WHEN the user selects filter "Overdue: Yes"
- THEN only the 3 overdue cases MUST be shown

#### Scenario CM-04g: Search cases by keyword

- GIVEN cases with titles "Bouwvergunning Keizersgracht 100", "Bouwvergunning Prinsengracht 50", "Subsidie innovatie"
- WHEN the user searches for "Keizersgracht"
- THEN only "Bouwvergunning Keizersgracht 100" MUST be shown
- AND search MUST match against `title` and `description` fields

#### Scenario CM-04h: Sort by deadline ascending

- GIVEN multiple cases with different deadlines
- WHEN the user sorts by "Deadline" ascending
- THEN cases MUST be ordered with the nearest deadline first

#### Scenario CM-04i: Paginate case list

- GIVEN 24 cases matching the current filters
- AND page size is 20
- WHEN the user views the case list
- THEN page 1 MUST show cases 1-20
- AND the system MUST display "Showing 20 of 24 cases -- Page 1 of 2"
- AND a "Next" button MUST navigate to page 2 (cases 21-24)

---

### REQ-CM-05: Quick Status Change from List

**Feature tier**: MVP

The system MUST support changing a case's status directly from the case list view without opening the detail page. See wireframe 3.2 in DESIGN-REFERENCES.md.

#### Scenario CM-05a: Quick status change via dropdown

- GIVEN a case "Bouwvergunning Keizersgracht 100" with status "Ontvangen" in the case list
- AND the case type defines statuses ["Ontvangen", "In behandeling", "Besluitvorming", "Afgehandeld"]
- WHEN the user clicks the status cell/dropdown for this case
- THEN a dropdown MUST appear showing only the statuses defined by the case type
- AND the current status MUST be visually indicated (e.g., checked or highlighted)

#### Scenario CM-05b: Quick status change succeeds

- GIVEN the status dropdown is open for case "2026-042"
- WHEN the user selects "In behandeling"
- THEN the case status MUST be updated to "In behandeling"
- AND the list row MUST update without a full page reload
- AND the audit trail MUST record the status change

#### Scenario CM-05c: Quick status change blocked by missing properties

- GIVEN a case type "Omgevingsvergunning" with property "Kadastraal nummer" required at status "In behandeling"
- AND the case has not filled "Kadastraal nummer"
- WHEN the user attempts a quick status change to "In behandeling"
- THEN the system MUST reject the change
- AND display a message: "Cannot advance to 'In behandeling': required property 'Kadastraal nummer' is missing. Open the case to complete the required fields."

---

### REQ-CM-06: Case Detail View

**Feature tier**: MVP

The system MUST provide a comprehensive detail view for each case. See wireframe 3.3 (Case Detail View) in DESIGN-REFERENCES.md. The detail view MUST include: status timeline, case info panel, deadline and timing panel, participants panel, custom properties panel, required documents checklist, tasks section, decisions section, activity timeline, and sub-cases section.

#### Scenario CM-06a: Case info panel

- GIVEN a case "Bouwvergunning Keizersgracht 100" of type "Omgevingsvergunning"
- WHEN the user navigates to the case detail view
- THEN the case info panel MUST display: title, type, priority, confidentiality level, identifier, and creation date
- AND a "Change Status" dropdown MUST be available

#### Scenario CM-06b: Deadline and timing panel

- GIVEN a case with `startDate = "2026-01-15"`, `deadline = "2026-03-12"`, `processingDeadline = "P56D"` (from case type)
- AND today is "2026-02-25" (15 days remaining)
- WHEN the user views the case detail
- THEN the deadline panel MUST display: "Started: Jan 15, 2026", "Deadline: Mar 12, 2026"
- AND the system MUST display "15 days remaining"
- AND the processing deadline MUST show "56 days"
- AND the days elapsed MUST show "41"

#### Scenario CM-06c: Deadline countdown -- overdue

- GIVEN a case with `deadline = "2026-02-20"`
- AND today is "2026-02-25"
- THEN the system MUST display "5 DAYS OVERDUE" with a red visual indicator
- AND the deadline text MUST be styled in red/error state

#### Scenario CM-06d: Deadline countdown -- on track

- GIVEN a case with `deadline = "2026-03-15"`
- AND today is "2026-02-25"
- THEN the system MUST display "18 days remaining" with a neutral/green indicator

#### Scenario CM-06e: Extension button visibility

- GIVEN a case type with `extensionAllowed = true` and `extensionPeriod = "P28D"`
- WHEN the user views the deadline panel
- THEN a "Request Extension" button MUST be visible
- AND the panel MUST show "Extension: allowed (+28 days)"

#### Scenario CM-06f: Extension button hidden when not allowed

- GIVEN a case type with `extensionAllowed = false`
- WHEN the user views the deadline panel
- THEN no "Request Extension" button MUST be displayed
- AND the panel MUST show "Extension: not allowed"

---

### REQ-CM-07: Status Timeline Visualization

**Feature tier**: MVP

The case detail view MUST display a visual status timeline showing all statuses defined by the case type. Passed statuses are filled, the current status is highlighted, and future statuses are greyed out. See wireframe 3.3 in DESIGN-REFERENCES.md.

#### Scenario CM-07a: Status timeline with current status

- GIVEN a case of type "Omgevingsvergunning" with ordered statuses ["Ontvangen", "In behandeling", "Besluitvorming", "Afgehandeld"]
- AND the case is currently at "In behandeling"
- WHEN the user views the case detail
- THEN the status timeline MUST display 4 dots/nodes in order
- AND "Ontvangen" MUST appear as passed (filled dot with date)
- AND "In behandeling" MUST appear as current (highlighted/active dot)
- AND "Besluitvorming" and "Afgehandeld" MUST appear as future (greyed dots)

#### Scenario CM-07b: Status timeline with dates

- GIVEN a case that transitioned from "Ontvangen" (Jan 15) to "In behandeling" (Feb 1)
- WHEN the user views the status timeline
- THEN the date "Jan 15" MUST appear beneath the "Ontvangen" node
- AND the date "Feb 1" MUST appear beneath the "In behandeling" node
- AND future statuses MUST NOT show dates

#### Scenario CM-07c: Status timeline at final status

- GIVEN a case at status "Afgehandeld" (which has `isFinal = true`)
- WHEN the user views the status timeline
- THEN all dots MUST appear as passed/completed (filled)
- AND the timeline MUST visually indicate the case is complete

---

### REQ-CM-08: Participants Panel

**Feature tier**: MVP (handler assignment), V1 (full role types)

The case detail view MUST display assigned participants with their roles. See wireframe 3.3 in DESIGN-REFERENCES.md.

#### Scenario CM-08a: Display participants

- GIVEN a case with roles: Handler = "Jan de Vries", Initiator = "Petra Jansen (Acme Corp)", Advisor = "Dr. K. Bakker"
- WHEN the user views the participants panel
- THEN each participant MUST be shown with their role label and name
- AND the handler MUST have a "Reassign" action
- AND an "Add Participant" button MUST be displayed

#### Scenario CM-08b: Add participant with role type restriction (V1)

- GIVEN a case of type "Omgevingsvergunning" with allowed role types ["Aanvrager", "Behandelaar", "Technisch adviseur", "Beslisser"]
- WHEN the user clicks "Add Participant"
- THEN the role selection MUST only show roles defined by the case type
- AND the user MUST NOT be able to assign a role type not in the case type's list

---

### REQ-CM-09: Custom Properties Panel

**Feature tier**: V1

The case detail view MUST display custom properties defined by the case type. See wireframe 3.3 in DESIGN-REFERENCES.md.

#### Scenario CM-09a: Display custom properties

- GIVEN a case of type "Omgevingsvergunning" with property definitions ["Kadastraal nummer" (text), "Bouwkosten" (number), "Oppervlakte" (number), "Bouwlagen" (number)]
- AND the case has values: Kadastraal nummer = "AMS04-A-1234", Bouwkosten = 250000, Oppervlakte = 180, Bouwlagen = 3
- WHEN the user views the custom properties panel
- THEN all 4 properties MUST be displayed with their values
- AND an "Edit Properties" button MUST be available

#### Scenario CM-09b: Empty custom properties

- GIVEN a case of type "Omgevingsvergunning" with 4 property definitions
- AND no property values have been filled
- WHEN the user views the custom properties panel
- THEN all 4 properties MUST be displayed with empty/placeholder values
- AND the panel SHOULD indicate "0 of 4 properties filled"

---

### REQ-CM-10: Required Documents Checklist

**Feature tier**: V1

The case detail view MUST display a checklist of required documents defined by the case type, showing which are present and which are missing. See wireframe 3.3 in DESIGN-REFERENCES.md.

#### Scenario CM-10a: Document checklist with mixed completion

- GIVEN a case of type "Omgevingsvergunning" with required document types:
  - "Bouwtekening" (incoming, required at "In behandeling")
  - "Constructieberekening" (incoming, required at "In behandeling")
  - "Situatietekening" (incoming, required at "In behandeling")
  - "Welstandsadvies" (internal, required at "Besluitvorming")
  - "Vergunningsbesluit" (outgoing, required at "Afgehandeld")
- AND files uploaded: Bouwtekening (Jan 16), Constructieberekening (Jan 20), Situatietekening (Jan 22)
- WHEN the user views the documents panel
- THEN the header MUST show "3/5 complete"
- AND Bouwtekening, Constructieberekening, Situatietekening MUST show a checkmark with upload date
- AND Welstandsadvies MUST show a missing indicator with "required at: Besluitvorming"
- AND Vergunningsbesluit MUST show a missing indicator with "required at: Afgehandeld"

#### Scenario CM-10b: All documents present

- GIVEN a case where all 5 required documents have been uploaded
- WHEN the user views the documents panel
- THEN the header MUST show "5/5 complete"
- AND all items MUST show a checkmark

#### Scenario CM-10c: No required documents defined

- GIVEN a case type "Melding" with no document types defined
- WHEN the user views the case detail
- THEN the documents panel SHOULD either be hidden or show "No required documents for this case type"

---

### REQ-CM-11: Tasks Section

**Feature tier**: MVP

The case detail view MUST display tasks linked to the case. See wireframe 3.3 in DESIGN-REFERENCES.md.

#### Scenario CM-11a: Display tasks with completion count

- GIVEN a case with 5 tasks: 2 completed, 1 active, 2 available
- WHEN the user views the tasks section
- THEN the header MUST show "TASKS 3/5" (or similar completion indicator)
- AND each task MUST show: title, status icon, due date (if set), assignee (if set)
- AND completed tasks MUST show a checkmark
- AND the active task MUST be visually distinct (e.g., spinner icon)
- AND an "Add Task" button MUST be available

#### Scenario CM-11b: No tasks

- GIVEN a case with no linked tasks
- WHEN the user views the tasks section
- THEN the section MUST show "No tasks" or an empty state
- AND the "Add Task" button MUST still be available

---

### REQ-CM-12: Decisions Section

**Feature tier**: V1

The case detail view MUST display decisions linked to the case.

#### Scenario CM-12a: Display decisions

- GIVEN a case with 1 decision: "Vergunning verleend" decided on Feb 20 by "Jan de Vries"
- WHEN the user views the decisions section
- THEN the decision MUST show: title, decided date, decided by
- AND an "Add Decision" button MUST be available

#### Scenario CM-12b: No decisions

- GIVEN a case with no decisions
- WHEN the user views the decisions section
- THEN the section MUST show "(no decisions yet)"
- AND an "Add Decision" button MUST be available

---

### REQ-CM-13: Activity Timeline

**Feature tier**: MVP

The case detail view MUST display an activity timeline showing all events related to the case in chronological order (newest first). See wireframe 3.3 in DESIGN-REFERENCES.md.

#### Scenario CM-13a: Activity timeline entries

- GIVEN a case "2026-042" with the following events:
  - Feb 25: Task "Review docs" assigned to Jan de Vries
  - Feb 20: Deadline passed (case is now overdue)
  - Feb 1: Status changed to "In behandeling" by Jan de Vries
  - Jan 22: Document "Situatietekening" uploaded by Petra Jansen
  - Jan 15: Case created
- WHEN the user views the activity timeline
- THEN all events MUST be displayed in reverse chronological order
- AND each entry MUST show: date, event description, actor (if applicable)
- AND deadline-passed events MUST be visually distinct (warning style)

#### Scenario CM-13b: Add note to activity

- GIVEN a case detail view with an activity timeline
- WHEN the user clicks "Add note" and enters "Wachten op welstandsadvies van externe partij"
- THEN the note MUST appear in the timeline with the current date and the user's name
- AND the note MUST be stored via Nextcloud's ICommentsManager

---

### REQ-CM-14: Status Change

**Feature tier**: MVP

The system MUST support changing a case's status. Status changes MUST respect case type constraints: only statuses defined by the case type are allowed, required properties MUST be satisfied, and required documents MUST be present.

#### Scenario CM-14a: Valid status change

- GIVEN a case of type "Omgevingsvergunning" currently at "Ontvangen"
- AND the case type defines statuses ["Ontvangen", "In behandeling", "Besluitvorming", "Afgehandeld"]
- WHEN the handler changes the status to "In behandeling"
- THEN the status MUST be updated
- AND the audit trail MUST record: who (handler name), when (timestamp), from "Ontvangen" to "In behandeling"

#### Scenario CM-14b: Reject status not in case type

- GIVEN a case of type "Omgevingsvergunning" with statuses ["Ontvangen", "In behandeling", "Besluitvorming", "Afgehandeld"]
- WHEN an API request attempts to set status to "Bezwaar" (not in this case type's list)
- THEN the system MUST reject the change
- AND return an error: "Status 'Bezwaar' is not defined for case type 'Omgevingsvergunning'"

#### Scenario CM-14c: Status change blocked by required properties (V1)

- GIVEN a case of type "Omgevingsvergunning"
- AND property "Kadastraal nummer" has `requiredAtStatus` pointing to "In behandeling"
- AND the case has not filled "Kadastraal nummer"
- WHEN the user attempts to change status to "In behandeling"
- THEN the system MUST reject the change
- AND display: "Cannot advance to 'In behandeling': required properties missing: Kadastraal nummer"

#### Scenario CM-14d: Status change blocked by required documents (V1)

- GIVEN a case of type "Omgevingsvergunning"
- AND document type "Welstandsadvies" has `requiredAtStatus` pointing to "Besluitvorming"
- AND no file of type "Welstandsadvies" has been uploaded
- WHEN the user attempts to change status to "Besluitvorming"
- THEN the system MUST reject the change
- AND display: "Cannot advance to 'Besluitvorming': required documents missing: Welstandsadvies"

#### Scenario CM-14e: Status change triggers initiator notification

- GIVEN a case with an initiator "Petra Jansen"
- AND the target status type "In behandeling" has `notifyInitiator = true` and `notificationText = "Uw zaak is in behandeling genomen"`
- WHEN the handler changes the case to "In behandeling"
- THEN the system MUST send a notification to the initiator
- AND the notification MUST contain the text "Uw zaak is in behandeling genomen"

#### Scenario CM-14f: Status change to final status sets endDate

- GIVEN a case currently at "Besluitvorming"
- AND "Afgehandeld" is the final status (`isFinal = true`)
- WHEN the handler changes the status to "Afgehandeld"
- THEN the case `endDate` MUST be set to the current date
- AND the case MUST be marked as closed
- AND no further status changes SHOULD be allowed without explicit reopening

---

### REQ-CM-15: Case Result Recording

**Feature tier**: MVP (basic result), V1 (result types from case type)

The system MUST support recording a result when closing a case.

#### Scenario CM-15a: Record result from case type's allowed results (V1)

- GIVEN a case of type "Omgevingsvergunning" with result types ["Vergunning verleend", "Vergunning geweigerd", "Ingetrokken"]
- WHEN the handler closes the case and selects result "Vergunning verleend"
- THEN a Result object MUST be created and linked to the case
- AND the result MUST reference the "Vergunning verleend" result type
- AND the result type's archival rules MUST be recorded: `archiveAction = "retain"`, `retentionPeriod = "P20Y"`

#### Scenario CM-15b: Result required at final status

- GIVEN a case type "Omgevingsvergunning" where the final status "Afgehandeld" requires a result
- WHEN the handler attempts to set status to "Afgehandeld" without selecting a result
- THEN the system MUST prompt for a result selection
- AND the result dropdown MUST only show result types defined by the case type

#### Scenario CM-15c: Result triggers archival rules (V1)

- GIVEN a result type "Vergunning geweigerd" with `archiveAction = "destroy"` and `retentionPeriod = "P10Y"` and `retentionDateSource = "case_completed"`
- WHEN a case is closed with this result
- THEN the system MUST record: archive action = destroy, retention until = endDate + 10 years
- AND the audit trail MUST record the archival determination

---

### REQ-CM-16: Case Deadline Extension

**Feature tier**: MVP

The system MUST support extending a case's deadline when the case type allows it.

#### Scenario CM-16a: Extend deadline when allowed

- GIVEN a case of type "Omgevingsvergunning" with `extensionAllowed = true` and `extensionPeriod = "P28D"`
- AND the case has `deadline = "2026-03-12"`
- WHEN the handler requests an extension
- THEN the deadline MUST be extended to "2026-04-09" (original + 28 days)
- AND the audit trail MUST record: "Deadline extended from 2026-03-12 to 2026-04-09 by [handler name]"
- AND the extension reason SHOULD be captured

#### Scenario CM-16b: Reject extension when not allowed

- GIVEN a case of type "Klacht behandeling" with `extensionAllowed = false`
- WHEN the handler attempts to extend the deadline
- THEN the system MUST reject the request
- AND display: "Deadline extension is not allowed for case type 'Klacht behandeling'"

#### Scenario CM-16c: Extension limit (single extension)

- GIVEN a case that has already been extended once
- WHEN the handler attempts a second extension
- THEN the system SHOULD reject the request (default: one extension allowed)
- AND display: "This case has already been extended"

---

### REQ-CM-17: Case Suspension

**Feature tier**: V1

The system SHOULD support suspending a case when the case type allows it. Suspension pauses the deadline countdown.

#### Scenario CM-17a: Suspend a case

- GIVEN a case of type "Omgevingsvergunning" with `suspensionAllowed = true`
- AND the case has `deadline = "2026-03-12"` and 15 days remaining
- WHEN the handler suspends the case with reason "Wachten op aanvullende gegevens van aanvrager"
- THEN the case MUST enter a suspended state
- AND the deadline countdown MUST pause (remaining days frozen at 15)
- AND the audit trail MUST record: suspension start, reason, who suspended

#### Scenario CM-17b: Resume a suspended case

- GIVEN a case suspended for 10 days with 15 days remaining at suspension
- WHEN the handler resumes the case
- THEN the deadline MUST be recalculated: new deadline = today + 15 remaining days
- AND the audit trail MUST record: suspension end, total suspended duration (10 days), who resumed

#### Scenario CM-17c: Reject suspension when not allowed

- GIVEN a case of type "Melding" with `suspensionAllowed = false`
- WHEN the handler attempts to suspend the case
- THEN the system MUST reject the request
- AND display: "Suspension is not allowed for case type 'Melding'"

---

### REQ-CM-18: Sub-Cases

**Feature tier**: V1

The system SHOULD support parent/child case hierarchies. A sub-case is a full case linked to a parent case.

#### Scenario CM-18a: Create a sub-case

- GIVEN an existing case "Bouwproject Centrum" (identifier "2026-042")
- WHEN the user clicks "Create Sub-case" and selects case type "Omgevingsvergunning" with title "Vergunning fundering"
- THEN a new case MUST be created with `parentCase` referencing "2026-042"
- AND the sub-case MUST have its own lifecycle, deadline, and status independent of the parent

#### Scenario CM-18b: Sub-cases displayed on parent

- GIVEN a parent case "2026-042" with 2 sub-cases: "Vergunning fundering" (active) and "Vergunning gevel" (completed)
- WHEN the user views the parent case detail
- THEN the sub-cases section MUST list both sub-cases with their status and deadline
- AND each sub-case MUST be clickable to navigate to its detail view

#### Scenario CM-18c: Navigate from sub-case to parent

- GIVEN a sub-case "Vergunning fundering" with parent "Bouwproject Centrum"
- WHEN the user views the sub-case detail
- THEN a breadcrumb or link MUST be displayed: "Parent case: Bouwproject Centrum (2026-042)"
- AND clicking it MUST navigate to the parent case detail

#### Scenario CM-18d: Sub-case type restrictions (V1)

- GIVEN a parent case type "Bouwproject" with `subCaseTypes` referencing ["Omgevingsvergunning", "Sloopvergunning"]
- WHEN the user creates a sub-case
- THEN the case type selection MUST only show "Omgevingsvergunning" and "Sloopvergunning"
- AND the user MUST NOT be able to select a case type not in the parent's `subCaseTypes` list

---

### REQ-CM-19: Confidentiality Levels

**Feature tier**: V1

The system SHOULD support confidentiality levels on cases, defaulting from the case type.

#### Scenario CM-19a: Inherit confidentiality from case type

- GIVEN a case type "Omgevingsvergunning" with `confidentiality = "internal"`
- WHEN a new case is created
- THEN the case `confidentiality` MUST default to "internal"

#### Scenario CM-19b: Override confidentiality on case

- GIVEN a case with default confidentiality "internal"
- WHEN the handler changes the confidentiality to "confidential"
- THEN the case `confidentiality` MUST be updated to "confidential"
- AND the audit trail MUST record the change

#### Scenario CM-19c: Confidentiality level options

- GIVEN the confidentiality enum with 8 levels (public through top_secret)
- WHEN the user views the confidentiality dropdown on a case
- THEN all 8 levels MUST be available for selection
- AND the levels MUST be ordered from least to most restrictive

---

### REQ-CM-20: Case Validation Rules

**Feature tier**: MVP

The system MUST enforce validation rules when creating or modifying cases.

#### Scenario CM-20a: Title is required

- GIVEN a case creation or update form
- WHEN the user submits with an empty title
- THEN the system MUST reject the submission with error: "Title is required"

#### Scenario CM-20b: Case type is required

- GIVEN a case creation form
- WHEN the user submits without selecting a case type
- THEN the system MUST reject the submission with error: "Case type is required"

#### Scenario CM-20c: Case type must be published

- GIVEN a case type "Bezwaarschrift" with `isDraft = true`
- WHEN a user submits a case creation with this type
- THEN the system MUST reject with error: "Case type 'Bezwaarschrift' is a draft and cannot be used to create cases"

#### Scenario CM-20d: Case type must be within validity window

- GIVEN a case type with `validFrom = "2026-06-01"` and today is "2026-02-25"
- WHEN a user submits a case creation with this type
- THEN the system MUST reject with error: "Case type is not yet valid (valid from 2026-06-01)"

#### Scenario CM-20e: Start date must not be in the future

- GIVEN a case creation form
- WHEN the user sets startDate to a date in the future
- THEN the system SHOULD warn but MAY allow (some jurisdictions allow future-dated cases)

---

### REQ-CM-21: Case Deadline Countdown Display

**Feature tier**: MVP

The system MUST display deadline countdowns on cases across all views (list, detail, My Work). See wireframes 3.2 and 3.3 in DESIGN-REFERENCES.md.

#### Scenario CM-21a: Days remaining display

- GIVEN a case with `deadline = "2026-03-15"` and today is "2026-02-25"
- WHEN displayed in the case list or detail view
- THEN the system MUST show "18 days" (or "18 days remaining")
- AND the indicator MUST use a neutral/positive style (e.g., no color or green)

#### Scenario CM-21b: Due tomorrow

- GIVEN a case with deadline = tomorrow
- WHEN displayed in any view
- THEN the system MUST show "1 day" (or "Due tomorrow")
- AND the indicator MUST use a warning style (e.g., yellow/amber)

#### Scenario CM-21c: Overdue display

- GIVEN a case with `deadline = "2026-02-20"` and today is "2026-02-25"
- WHEN displayed in any view
- THEN the system MUST show "5 days overdue" (or "5d overdue")
- AND the indicator MUST use an error/danger style (e.g., red)

#### Scenario CM-21d: Due today

- GIVEN a case with deadline = today
- WHEN displayed in any view
- THEN the system MUST show "Due today"
- AND the indicator MUST use a warning style

---

### REQ-CM-22: Audit Trail

**Feature tier**: MVP

The system MUST maintain a complete audit trail for all case modifications. The audit trail is published via Nextcloud's Activity system (`OCP\Activity\IManager`).

#### Scenario CM-22a: Status change audit entry

- GIVEN a case "2026-042"
- WHEN the handler changes status from "Ontvangen" to "In behandeling"
- THEN the audit trail MUST record: event type "case_status_change", user "Jan de Vries", timestamp, from status "Ontvangen", to status "In behandeling"

#### Scenario CM-22b: Property change audit entry

- GIVEN a case "2026-042"
- WHEN the user changes description from "Verbouwing" to "Verbouwing woonhuis, 3 bouwlagen"
- THEN the audit trail MUST record: event type "case_update", user, timestamp, field "description", old value, new value

#### Scenario CM-22c: Deadline extension audit entry

- GIVEN a case "2026-042"
- WHEN the handler extends the deadline
- THEN the audit trail MUST record: event type "case_extension", user, timestamp, old deadline, new deadline, reason

#### Scenario CM-22d: Case creation audit entry

- GIVEN a user creating a new case
- WHEN the case is successfully created
- THEN the audit trail MUST record: event type "case_created", user, timestamp, case type, initial status, calculated deadline

---

## UI References

- **Case List View**: See wireframe 3.2 in DESIGN-REFERENCES.md
- **Case Detail View**: See wireframe 3.3 in DESIGN-REFERENCES.md (status timeline, info panel, deadline panel, participants, custom properties, document checklist, tasks, decisions, activity timeline, sub-cases)
- **My Work View**: See wireframe 3.5 in DESIGN-REFERENCES.md (overdue / due this week / upcoming sections)
- **Dashboard**: See wireframe 3.1 in DESIGN-REFERENCES.md (case count widgets, status distribution, overdue list)

## Dependencies

- **Case Types spec** (`../case-types/spec.md`): Case type MUST be published and valid to create cases. Case type controls statuses, deadlines, confidentiality defaults, document types, property definitions, result types, and role types.
- **OpenRegister**: All case data is stored as OpenRegister objects in the `procest` register under the `case` schema.
- **Nextcloud Activity**: Audit trail events are published via `OCP\Activity\IManager`.
- **Nextcloud Comments**: Case notes use `OCP\Comments\ICommentsManager`.
- **Nextcloud Files**: Document uploads reference Nextcloud file IDs via `OCP\Files\IRootFolder`.
