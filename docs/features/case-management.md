# Case Management

Core entity and lifecycle for tracking structured work. A case is a coherent body of work with defined status phases, deadlines, and outcomes, following CMMN 1.1 concepts.

## Specs

- `openspec/specs/case-management/spec.md`

## Features

### Case CRUD (MVP)

Full create, read, update, and delete for case records. Cases are stored as `schema:Project` in OpenRegister.

- Case list view with search, sort, filter, and pagination
- Case detail view with panels for status, deadline, participants, properties, documents, tasks, decisions, and activity
- Fields: title, description, status, deadline, handler, caseType, result, confidentiality, parentCase
- Case type validation on creation (inherits allowed statuses, deadline rules, etc.)

### Case List View (MVP)

Data-dense table view for browsing and managing cases:

- Search across title and description
- Filter by status, case type, handler
- Sort by deadline, status, created date
- Quick status change directly from list rows

### Case Detail View (MVP)

Comprehensive detail layout with multiple information panels:

- Status timeline visualization showing passed/current/future statuses
- Deadline countdown (days remaining or days overdue)
- Participants panel showing assigned roles
- Custom properties panel (from case type definitions)
- Required documents checklist
- Tasks section with linked task list
- Decisions section
- Activity timeline

### Status Lifecycle (MVP)

Cases progress through statuses defined by their case type. Status changes are constrained by the case type configuration and recorded in the audit trail.

- Status timeline visualization on detail view
- Quick status change from list view
- Status history tracking

### Case Result Recording (MVP)

When a case reaches a terminal status, a result is recorded documenting the outcome. Results link to result types defined in the case type.

### Case Deadline (MVP)

Processing deadlines are auto-calculated from the case type's duration configuration (ISO 8601). The detail view shows a countdown with days remaining or days overdue indicator.

### Case Validation Rules (MVP)

- Title is required
- Case type must be valid and published
- Status must be an allowed status for the case type
- Deadline auto-calculated from case type duration

### Audit Trail (MVP)

Full audit trail tracking who changed what and when, including status transitions, field changes, and participant modifications.

### Planned (V1)

- Sub-cases (parent/child hierarchy)
- Document completion checklist (required vs present)
- Property completion indicator (% required fields filled)
- Days in current status indicator
- Case suspension and extension
- Confidentiality levels
- Case templates and cloning

### Planned (Enterprise)

- CMMN runtime (sentries, entry/exit criteria)
- Bulk case operations
- Configurable status workflows per type
