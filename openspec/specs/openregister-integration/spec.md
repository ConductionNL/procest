# OpenRegister Integration Specification

## Purpose

Procest owns **no database tables**. All data is stored as OpenRegister objects in a dedicated `procest` register containing 12 schemas. This spec defines how the register and schemas are configured, how the repair step initializes the data model, how the frontend interacts with the OpenRegister API, the Pinia store patterns, cross-entity reference semantics, error handling, pagination, RBAC, cascade behaviors, and performance considerations.

OpenRegister integration is the foundational layer upon which all other Procest features are built.

**Standards**: OpenAPI 3.0.0 (schema format), OpenRegister API conventions
**Feature tier**: MVP (foundation for all features)

---

## Architecture Overview

```
┌─────────────────────────────────────────────────┐
│  Procest Frontend (Vue 2 + Pinia)               │
│  - Pinia stores per entity type                 │
│  - API service layer with error handling        │
└──────────────┬──────────────────────────────────┘
               │ REST API calls
┌──────────────▼──────────────────────────────────┐
│  OpenRegister API                                │
│  /index.php/apps/openregister/api/objects/       │
│  {register}/{schema}/{id}                        │
│  - CRUD operations                              │
│  - Search, pagination, filtering                │
│  - Schema validation                            │
│  - RBAC enforcement                             │
└──────────────┬──────────────────────────────────┘
               │
┌──────────────▼──────────────────────────────────┐
│  OpenRegister Storage (PostgreSQL)               │
│  - JSON object storage                          │
│  - Schema validation                            │
│  - Audit trail                                  │
└─────────────────────────────────────────────────┘
```

---

## Register and Schema Definitions

### Register

| Field | Value |
|-------|-------|
| Name | `procest` |
| Slug | `procest` |
| Description | Case management register for Procest |

### Schema Inventory (12 schemas)

The register MUST define exactly 12 schemas, organized into two groups:

**Configuration schemas** (managed by admins, define case type behavior):

| # | Schema | Purpose | CMMN/Schema.org | ZGW Equivalent |
|---|--------|---------|-----------------|----------------|
| 1 | `caseType` | Case type definition | CaseDefinition / CasePlanModel | ZaakType |
| 2 | `statusType` | Status lifecycle phase per case type | Milestone | StatusType |
| 3 | `resultType` | Case outcome type with archival rules | Case outcome | ResultaatType |
| 4 | `roleType` | Participant role type per case type | schema:Role | RolType |
| 5 | `propertyDefinition` | Custom field definition per case type | schema:PropertyValueSpecification | Eigenschap |
| 6 | `documentType` | Document type requirement per case type | schema:DigitalDocument | InformatieObjectType |
| 7 | `decisionType` | Decision type definition | schema:ChooseAction definition | BesluitType |

**Instance schemas** (created by users during case operations):

| # | Schema | Purpose | CMMN/Schema.org | ZGW Equivalent |
|---|--------|---------|-----------------|----------------|
| 8 | `case` | Case instance | CasePlanModel / schema:Project | Zaak |
| 9 | `task` | Task within a case | HumanTask / schema:Action | (Taak) |
| 10 | `role` | Role assignment on a case | schema:Role instance | Rol |
| 11 | `result` | Case outcome record | Case result | Resultaat |
| 12 | `decision` | Formal decision on a case | schema:ChooseAction instance | Besluit |

---

## Requirements

### REQ-OREG-001: Configuration File

**Tier**: MVP

The system MUST define its register and all schemas in a JSON configuration file that follows the OpenAPI 3.0.0 format, consistent with the pattern used by `opencatalogi` and `softwarecatalog`.

#### Scenario: Configuration file exists and is valid

- GIVEN the Procest app source code
- THEN the file `lib/Settings/procest_register.json` MUST exist
- AND it MUST be valid JSON
- AND it MUST conform to OpenAPI 3.0.0 format
- AND it MUST define a register with slug `procest`
- AND it MUST define exactly 12 schemas as listed in the schema inventory

#### Scenario: Schema defines required properties for case

- GIVEN the `case` schema definition in `procest_register.json`
- THEN it MUST define the following required properties:
  - `title` (string, max 255)
  - `caseType` (string, format: uuid, reference to caseType)
  - `status` (string, format: uuid, reference to statusType)
  - `startDate` (string, format: date)
- AND it MUST define the following optional properties:
  - `description` (string)
  - `identifier` (string, auto-generated)
  - `result` (string, format: uuid, reference to result)
  - `endDate` (string, format: date)
  - `plannedEndDate` (string, format: date)
  - `deadline` (string, format: date)
  - `confidentiality` (string, enum)
  - `assignee` (string)
  - `priority` (string, enum: low, normal, high, urgent)
  - `parentCase` (string, format: uuid)
  - `relatedCases` (array of strings)
  - `geometry` (object, GeoJSON)

#### Scenario: Schema defines required properties for task

- GIVEN the `task` schema definition in `procest_register.json`
- THEN it MUST define:
  - `title` (string, required)
  - `status` (string, enum: available, active, completed, terminated, disabled, required, default: "available")
  - `case` (string, format: uuid, required)
  - `description` (string, optional)
  - `assignee` (string, optional)
  - `dueDate` (string, format: date-time, optional)
  - `priority` (string, enum: low, normal, high, urgent, optional, default: "normal")
  - `completedDate` (string, format: date-time, optional)

#### Scenario: Schema defines required properties for role

- GIVEN the `role` schema definition
- THEN it MUST define:
  - `name` (string, required)
  - `roleType` (string, format: uuid, required)
  - `case` (string, format: uuid, required)
  - `participant` (string, required)
  - `description` (string, optional)

#### Scenario: Schema defines required properties for result

- GIVEN the `result` schema definition
- THEN it MUST define:
  - `name` (string, required)
  - `case` (string, format: uuid, required)
  - `resultType` (string, format: uuid, required)
  - `description` (string, optional)

#### Scenario: Schema defines required properties for decision

- GIVEN the `decision` schema definition
- THEN it MUST define:
  - `title` (string, required)
  - `case` (string, format: uuid, required)
  - `description` (string, optional)
  - `decisionType` (string, format: uuid, optional)
  - `decidedBy` (string, optional)
  - `decidedAt` (string, format: date-time, optional)
  - `effectiveDate` (string, format: date, optional)
  - `expiryDate` (string, format: date, optional)

#### Scenario: Schema defines caseType with all behavioral fields

- GIVEN the `caseType` schema definition
- THEN it MUST define at minimum:
  - `title` (string, required)
  - `description` (string, optional)
  - `identifier` (string, auto)
  - `purpose` (string, required)
  - `trigger` (string, required)
  - `subject` (string, required)
  - `processingDeadline` (string, ISO 8601 duration, required)
  - `confidentiality` (string, enum, required)
  - `isDraft` (boolean, default: true)
  - `validFrom` (string, format: date, required)
  - `validUntil` (string, format: date, optional)
  - `origin` (string, enum: internal, external, required)
  - `suspensionAllowed` (boolean, required)
  - `extensionAllowed` (boolean, required)
  - `publicationRequired` (boolean, required)

#### Scenario: All schemas include type annotations

- GIVEN each schema definition
- THEN each MUST include a `@type` property or annotation referencing the appropriate standard:
  - `case`: `schema:Project`
  - `task`: `schema:Action`
  - `role`: `schema:Role`
  - `result`: (no standard type, app-specific)
  - `decision`: `schema:ChooseAction`
  - `caseType`: `schema:Project` definition
  - `statusType`: `schema:ActionStatusType`
  - `roleType`: `schema:Role` definition
  - `propertyDefinition`: `schema:PropertyValueSpecification`
  - `documentType`: `schema:DigitalDocument`
  - `decisionType`: `schema:ChooseAction` definition
  - `resultType`: (no standard type)

---

### REQ-OREG-002: Auto-Configuration on Install (Repair Step)

**Tier**: MVP

The system MUST import the register configuration during app installation and upgrades via the Nextcloud repair step mechanism.

#### Scenario: First install creates register and all schemas

- GIVEN Procest is being installed for the first time on a Nextcloud instance with OpenRegister
- WHEN the repair step `lib/Migration/ImportConfiguration.php` runs
- THEN it MUST call `ConfigurationService::importFromApp('procest')`
- AND the `procest` register MUST be created in OpenRegister
- AND all 12 schemas MUST be created with their property definitions
- AND the repair step MUST log success or failure

#### Scenario: Upgrade adds new schemas without data loss

- GIVEN Procest was previously installed with 10 schemas (before decisionType and propertyDefinition were added)
- AND existing cases, tasks, and roles exist in the register
- WHEN the repair step runs during upgrade
- THEN the 2 new schemas (`decisionType`, `propertyDefinition`) MUST be created
- AND existing schemas MUST be updated if their definitions changed (new properties added)
- AND existing objects in unchanged schemas MUST NOT be modified or deleted
- AND no data loss MUST occur

#### Scenario: Repair step is idempotent

- GIVEN the repair step has already run successfully
- WHEN the repair step runs again (e.g., during `occ maintenance:repair`)
- THEN it MUST NOT create duplicate registers or schemas
- AND existing data MUST remain intact
- AND the operation MUST complete without errors

#### Scenario: Repair step handles missing OpenRegister gracefully

- GIVEN Procest is installed but OpenRegister is NOT installed
- WHEN the repair step runs
- THEN it MUST log a clear error message indicating that OpenRegister is required
- AND the repair step MUST NOT crash or throw an unhandled exception
- AND Procest MUST indicate to the admin that OpenRegister needs to be installed

#### Scenario: Schema property additions are non-destructive

- GIVEN the `task` schema previously had 6 properties
- AND the upgrade adds 2 new optional properties (e.g., `checklist`, `blockedBy`)
- WHEN the repair step updates the schema
- THEN the 2 new properties MUST be added to the schema
- AND existing task objects MUST remain valid (new properties are optional)
- AND existing task objects MUST NOT have the new properties set to any default

---

### REQ-OREG-003: Frontend API Interaction Patterns

**Tier**: MVP

The frontend MUST interact with OpenRegister's REST API for all CRUD operations. All API calls MUST follow consistent URL patterns and error handling.

#### Scenario: Base URL pattern

- GIVEN the Procest frontend needs to access OpenRegister
- THEN all API calls MUST use the base URL pattern: `/index.php/apps/openregister/api/objects/procest/{schema}`
- AND for single objects: `/index.php/apps/openregister/api/objects/procest/{schema}/{uuid}`

#### Scenario: List all cases (GET collection)

- GIVEN the `case` schema exists in the `procest` register with 24 case objects
- WHEN the frontend requests the case list
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/procest/case`
- AND the response MUST include:
  - An array of case objects
  - Pagination metadata (`total`, `page`, `limit`, `pages`)
- AND the default page size MUST be configurable (e.g., 20)

#### Scenario: Get a single case (GET object)

- GIVEN a case with UUID "abc-123-def" exists
- WHEN the frontend requests the case detail
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/procest/case/abc-123-def`
- AND the response MUST include all case properties

#### Scenario: Create a new case (POST)

- GIVEN the user fills in the new case form with:
  - title: "Bouwvergunning Prinsengracht 200"
  - caseType: "casetype-uuid-omgevings"
  - startDate: "2026-03-01"
- WHEN the user submits the form
- THEN the frontend MUST call `POST /index.php/apps/openregister/api/objects/procest/case`
- AND the request body MUST contain the case properties as JSON
- AND the response MUST include the created object with its generated UUID

#### Scenario: Update an existing case (PUT)

- GIVEN an existing case with UUID "abc-123-def"
- WHEN the user updates the description
- THEN the frontend MUST call `PUT /index.php/apps/openregister/api/objects/procest/case/abc-123-def`
- AND the request body MUST contain the full updated object
- AND the response MUST include the updated object

#### Scenario: Delete a case (DELETE)

- GIVEN an existing case with UUID "abc-123-def"
- WHEN the user deletes the case
- THEN the frontend MUST call `DELETE /index.php/apps/openregister/api/objects/procest/case/abc-123-def`
- AND the response MUST confirm deletion (HTTP 200 or 204)

#### Scenario: API call with authentication

- GIVEN a logged-in Nextcloud user
- THEN all OpenRegister API calls MUST include the Nextcloud session cookie or authorization header
- AND unauthenticated requests MUST be rejected with HTTP 401

---

### REQ-OREG-004: Pagination and Filtering

**Tier**: MVP

The frontend MUST support paginated access to object lists and use OpenRegister query parameters for filtering, searching, and sorting.

#### Scenario: Paginate case list

- GIVEN 24 cases exist in the register
- WHEN the frontend requests page 2 with limit 10
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/procest/case?_page=2&_limit=10`
- AND the response MUST contain cases 11-20
- AND the pagination metadata MUST show: `total: 24`, `page: 2`, `limit: 10`, `pages: 3`

#### Scenario: Filter cases by status

- GIVEN cases with various status references
- WHEN the frontend filters by a specific status type UUID
- THEN it MUST include the filter as a query parameter: `?status=statustype-uuid-inbehandeling`
- AND only cases matching that status MUST be returned

#### Scenario: Filter tasks by case

- GIVEN 23 tasks across 8 cases
- WHEN the frontend requests tasks for case #2024-042 (UUID: "case-uuid-042")
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/procest/task?case=case-uuid-042`
- AND only tasks linked to that case MUST be returned

#### Scenario: Filter tasks by assignee

- GIVEN tasks assigned to various users
- WHEN the frontend filters by assignee "jan.devries"
- THEN it MUST include `?assignee=jan.devries` in the query
- AND only tasks assigned to Jan MUST be returned

#### Scenario: Search by text field

- GIVEN cases with various titles
- WHEN the user searches for "bouwvergunning"
- THEN the frontend MUST pass the search term via the appropriate OpenRegister search parameter
- AND results MUST include cases whose title contains "bouwvergunning" (case-insensitive)

#### Scenario: Sort by field

- GIVEN the task list is displayed
- WHEN the user sorts by due date ascending
- THEN the frontend MUST include `?_sort=dueDate&_order=asc` in the query
- AND the API response MUST return tasks ordered by due date ascending

#### Scenario: Combined filters

- GIVEN the user applies multiple filters: assignee "jan.devries", status "active", sorted by priority
- THEN the frontend MUST combine all filters: `?assignee=jan.devries&status=active&_sort=priority&_order=desc`
- AND the API MUST apply all filters conjunctively (AND logic)

---

### REQ-OREG-005: Pinia Store Patterns

**Tier**: MVP

The frontend MUST use Pinia stores for state management, with one store per entity type. Stores MUST follow a consistent pattern for CRUD actions, loading states, error handling, and pagination.

#### Scenario: Case store provides standard CRUD actions

- GIVEN the `useCaseStore()` Pinia store
- THEN it MUST provide the following actions:
  - `fetchCases(params?)` -- list with optional filter/pagination params
  - `fetchCase(id)` -- get single case by UUID
  - `createCase(data)` -- create new case
  - `updateCase(id, data)` -- update existing case
  - `deleteCase(id)` -- delete case
- AND each action MUST construct the correct OpenRegister API URL
- AND each action MUST handle loading states and errors

#### Scenario: Store tracks loading state

- GIVEN the case store
- WHEN `fetchCases()` is called
- THEN `store.loading` MUST be set to `true` before the API call
- AND `store.loading` MUST be set to `false` after the API call completes (success or failure)
- AND the UI MUST show a loading indicator while `store.loading` is `true`

#### Scenario: Store tracks error state

- GIVEN the case store
- WHEN an API call fails with HTTP 500
- THEN `store.error` MUST be set to an error object containing the status code and message
- AND the UI MUST display a user-friendly error message
- AND `store.loading` MUST be set to `false`

#### Scenario: Store handles pagination state

- GIVEN the case store fetches a paginated list
- THEN the store state MUST include:
  - `items` -- array of case objects for the current page
  - `total` -- total number of matching cases
  - `page` -- current page number
  - `limit` -- items per page
  - `pages` -- total number of pages
- AND the store MUST provide a `fetchPage(page)` action that fetches a specific page

#### Scenario: Task store follows the same pattern

- GIVEN the `useTaskStore()` Pinia store
- THEN it MUST provide: `fetchTasks(params?)`, `fetchTask(id)`, `createTask(data)`, `updateTask(id, data)`, `deleteTask(id)`
- AND it MUST follow the same loading/error/pagination pattern as the case store

#### Scenario: All entity types have stores

- GIVEN the Procest frontend
- THEN Pinia stores MUST exist for all 12 entity types:
  - `useCaseStore()`, `useTaskStore()`, `useRoleStore()`, `useResultStore()`, `useDecisionStore()`
  - `useCaseTypeStore()`, `useStatusTypeStore()`, `useResultTypeStore()`, `useRoleTypeStore()`
  - `usePropertyDefinitionStore()`, `useDocumentTypeStore()`, `useDecisionTypeStore()`
- AND each store MUST follow the same CRUD + loading + error + pagination pattern

#### Scenario: Store caches fetched data

- GIVEN the case store has already fetched case "abc-123-def"
- WHEN `fetchCase("abc-123-def")` is called again within the same session
- THEN the store SHOULD return the cached version immediately
- AND the store MAY optionally refetch in the background (stale-while-revalidate)

---

### REQ-OREG-006: Cross-Entity References

**Tier**: MVP

Entities in Procest reference each other via UUID. The frontend MUST resolve these references to display meaningful data (titles, names) rather than raw UUIDs.

#### Scenario: Task references a case

- GIVEN a task object with `case: "case-uuid-042"`
- WHEN the task is displayed in a list or card
- THEN the frontend MUST resolve "case-uuid-042" to display the case identifier and title (e.g., "Case #2024-042 Bouwvergunning Keizersgracht")
- AND the resolved case reference MUST be clickable, navigating to the case detail

#### Scenario: Case references a case type

- GIVEN a case object with `caseType: "casetype-uuid-omgevings"`
- WHEN the case is displayed in the case list
- THEN the frontend MUST resolve the case type to display its title (e.g., "Omgevingsvergunning")

#### Scenario: Role references both case and role type

- GIVEN a role object with:
  - `case: "case-uuid-042"`
  - `roleType: "roletype-uuid-handler"`
  - `participant: "jan.devries"`
- WHEN the role is displayed on the case detail page
- THEN the frontend MUST resolve:
  - The role type to its name (e.g., "Behandelaar")
  - The participant to the Nextcloud user display name (e.g., "Jan de Vries")
  - The case reference to the case title (if displayed outside case context)

#### Scenario: Result references a result type

- GIVEN a result object with `resultType: "resulttype-uuid-granted"`
- WHEN the result is displayed
- THEN the frontend MUST resolve the result type to its name (e.g., "Vergunning verleend")
- AND the archival information from the result type SHOULD be accessible

#### Scenario: Case type hierarchy resolution

- GIVEN a case detail view that needs to display:
  - The case type name
  - The current status name (from status type)
  - The handler name (from role)
  - Task list (from tasks referencing this case)
- WHEN the case detail page loads
- THEN the frontend MUST fetch and resolve all related entities
- AND cross-references MUST be resolved in parallel where possible

#### Scenario: Dangling reference (referenced object deleted)

- GIVEN a task with `case: "case-uuid-deleted"` where the referenced case has been deleted
- WHEN the task is displayed
- THEN the frontend MUST handle the missing reference gracefully
- AND it SHOULD display a "Case not found" or "[Deleted]" placeholder
- AND the task MUST still be viewable and manageable

---

### REQ-OREG-007: Schema Validation Rules

**Tier**: MVP

OpenRegister MUST validate objects against their schema definitions before storage. Procest schemas MUST define appropriate validation constraints.

#### Scenario: Required field validation

- GIVEN the `task` schema requires `title` and `case`
- WHEN the frontend submits a task without a title
- THEN the OpenRegister API MUST return HTTP 400/422 with a validation error
- AND the error response MUST identify the missing field (`title`)
- AND the frontend MUST display the validation error to the user

#### Scenario: Enum validation for task status

- GIVEN the `task` schema defines `status` as enum: `available`, `active`, `completed`, `terminated`, `disabled`
- WHEN the frontend submits a task with `status: "pending"`
- THEN the OpenRegister API MUST reject the request
- AND the error MUST indicate that "pending" is not a valid value for `status`

#### Scenario: Enum validation for priority

- GIVEN the `task` schema defines `priority` as enum: `low`, `normal`, `high`, `urgent`
- WHEN the frontend submits a task with `priority: "critical"`
- THEN the API MUST reject with a validation error

#### Scenario: Date format validation

- GIVEN the `case` schema defines `startDate` as format: date
- WHEN the frontend submits a case with `startDate: "not-a-date"`
- THEN the API MUST reject with a format validation error

#### Scenario: UUID reference format validation

- GIVEN the `task` schema defines `case` as format: uuid
- WHEN the frontend submits a task with `case: "not-a-uuid"`
- THEN the API MUST reject with a format validation error

#### Scenario: String length validation

- GIVEN the `case` schema defines `title` with maxLength: 255
- WHEN the frontend submits a case with a title of 300 characters
- THEN the API MUST reject with a length validation error

---

### REQ-OREG-008: Error Handling

**Tier**: MVP

The frontend MUST handle all categories of API errors gracefully and present user-friendly messages.

#### Scenario: Network error (offline/timeout)

- GIVEN the user is creating a case
- WHEN the API call fails due to a network timeout
- THEN the frontend MUST display a message like "Unable to reach the server. Please check your connection and try again."
- AND the form data MUST be preserved (not cleared)
- AND a retry option SHOULD be available

#### Scenario: Validation error (HTTP 400/422)

- GIVEN the user submits a case with missing required fields
- WHEN the API returns HTTP 422 with field-level errors
- THEN the frontend MUST map errors to specific form fields
- AND invalid fields MUST be highlighted with their error messages
- AND the form MUST remain editable for correction

#### Scenario: Authorization error (HTTP 403)

- GIVEN a user without admin privileges
- WHEN they attempt to create a case type via the API
- THEN the API MUST return HTTP 403
- AND the frontend MUST display "You do not have permission to perform this action"

#### Scenario: Not found error (HTTP 404)

- GIVEN a case with UUID "abc-123-def" has been deleted
- WHEN the frontend attempts to fetch it
- THEN the API MUST return HTTP 404
- AND the frontend MUST display "The requested case could not be found"
- AND the frontend SHOULD redirect to the case list

#### Scenario: Server error (HTTP 500)

- GIVEN an unexpected error occurs on the server
- WHEN the API returns HTTP 500
- THEN the frontend MUST display a generic error message: "An unexpected error occurred. Please try again later."
- AND the error SHOULD be logged to the browser console with details for debugging

#### Scenario: Concurrent modification conflict (HTTP 409)

- GIVEN two users are editing the same case simultaneously
- WHEN user A saves after user B has already saved
- THEN the API SHOULD return HTTP 409 (conflict)
- AND the frontend MUST inform user A that the case was modified by another user
- AND the frontend SHOULD offer to reload the latest version

---

### REQ-OREG-009: Cascade Behaviors

**Tier**: V1

The system MUST define what happens to dependent entities when a parent entity is deleted or modified.

#### Scenario: Delete a case with linked tasks, roles, results, and decisions

- GIVEN case #2024-042 has:
  - 5 tasks
  - 3 roles
  - 1 result
  - 2 decisions
- WHEN the user deletes case #2024-042
- THEN the system MUST either:
  - (a) Cascade delete all linked tasks, roles, results, and decisions, OR
  - (b) Prevent deletion and warn the user that dependent entities exist
- AND the system MUST NOT leave orphaned task/role/result/decision objects
- AND the chosen behavior MUST be consistent

#### Scenario: Delete a case type that is in use

- GIVEN case type "Omgevingsvergunning" is referenced by 10 active cases
- WHEN an admin attempts to delete the case type
- THEN the system MUST prevent the deletion
- AND the error message MUST indicate that the case type is in use by 10 cases
- AND the admin SHOULD be advised to set the case type as draft or set a `validUntil` date instead

#### Scenario: Delete a case type that is not in use

- GIVEN case type "Bezwaarschrift" (draft, no cases reference it)
- WHEN an admin deletes the case type
- THEN the case type MUST be deleted
- AND all linked status types, result types, role types, property definitions, document types, and decision types MUST also be deleted (cascade)

#### Scenario: Remove a status type from a case type

- GIVEN case type "Omgevingsvergunning" has 4 status types
- AND status type "Besluitvorming" (order: 3) is being removed
- AND 3 cases currently have status "Besluitvorming"
- THEN the system MUST prevent removal
- AND the error message MUST indicate that 3 cases are currently in this status

#### Scenario: Remove an unused status type

- GIVEN status type "Verouderde status" is linked to case type "Omgevingsvergunning"
- AND no cases currently reference this status type
- WHEN the admin removes it
- THEN the status type MUST be deleted
- AND the remaining status types MUST maintain their order (reorder if needed)

---

### REQ-OREG-010: Audit Trail Integration

**Tier**: MVP

All create, update, and delete operations on Procest objects MUST be captured in the audit trail.

#### Scenario: Case creation is logged

- GIVEN user "jan.devries" creates case #2024-053
- THEN the audit trail MUST record:
  - Action: "created"
  - Entity type: "case"
  - Entity UUID
  - User: "jan.devries"
  - Timestamp
  - Key field values (title, caseType)

#### Scenario: Task status change is logged

- GIVEN user "jan.devries" changes task "Review documenten" from `active` to `completed`
- THEN the audit trail MUST record:
  - Action: "status_changed"
  - Entity type: "task"
  - Entity UUID
  - User: "jan.devries"
  - Old value: "active"
  - New value: "completed"
  - Timestamp

#### Scenario: Role assignment is logged

- GIVEN a coordinator assigns "maria.bakker" as advisor on case #2024-042
- THEN the audit trail MUST record:
  - Action: "role_assigned"
  - Entity type: "role"
  - Case reference
  - Participant: "maria.bakker"
  - Role type: "Advisor"
  - Timestamp

#### Scenario: Decision creation is logged

- GIVEN "dr.k.bakker" records a decision on case #2024-042
- THEN the audit trail MUST record the decision creation with all key fields

#### Scenario: Audit trail is displayed on case detail

- GIVEN case #2024-042 has 15 audit events
- WHEN the user views the Activity Timeline section on the case detail
- THEN the events MUST be displayed in reverse chronological order
- AND each event MUST show: description, user, timestamp
- AND the timeline MUST be paginated or have a "Load more" option

---

### REQ-OREG-011: RBAC (Role-Based Access Control)

**Tier**: MVP

The system MUST enforce access control via OpenRegister's RBAC system. Configuration entities (case types, status types, etc.) MUST be admin-only. Instance entities (cases, tasks, roles, results, decisions) MUST be accessible to authorized users.

#### Scenario: Admin-only access to case type management

- GIVEN a non-admin user "jan.devries"
- WHEN Jan attempts to create, update, or delete a case type via the API
- THEN the system MUST return HTTP 403
- AND the operation MUST NOT be performed

#### Scenario: Admin can manage all configuration entities

- GIVEN an admin user "admin"
- THEN the admin MUST be able to CRUD all 7 configuration schemas:
  - caseType, statusType, resultType, roleType, propertyDefinition, documentType, decisionType
- AND the admin settings page in Nextcloud MUST provide the management UI

#### Scenario: Regular user can create cases and tasks

- GIVEN a regular Nextcloud user "jan.devries"
- THEN Jan MUST be able to:
  - Create cases (POST to case schema)
  - Create tasks on cases he has access to
  - Create roles on cases he has access to
  - Record results on cases he is handler for
  - Create decisions on cases he has access to

#### Scenario: User can only see cases they have access to

- GIVEN OpenRegister RBAC is configured
- WHEN "jan.devries" requests the case list
- THEN the API MUST return only cases that Jan has permission to view
- AND cases assigned to other users/organizations that Jan has no role in MUST NOT be returned

#### Scenario: Nextcloud admin settings page requires admin

- GIVEN a non-admin user navigates to the Procest admin settings URL
- THEN the Nextcloud admin settings system MUST prevent access
- AND the user MUST be redirected or shown an "access denied" page

---

### REQ-OREG-012: Performance and Eager Loading

**Tier**: MVP

The frontend MUST minimize API round-trips by fetching related entities efficiently.

#### Scenario: Case detail page loads all related data in parallel

- GIVEN the user opens case detail for case #2024-042
- THEN the frontend MUST fetch the following in parallel (not sequentially):
  - Case object (with case type, status references)
  - Tasks for the case (`?case=case-uuid-042`)
  - Roles for the case (`?case=case-uuid-042`)
  - Decisions for the case (`?case=case-uuid-042`)
  - Result for the case (if exists)
- AND the total load time MUST be under 3 seconds for a case with 10 tasks, 5 roles, 3 decisions

#### Scenario: Case list resolves case type names efficiently

- GIVEN the case list shows 20 cases referencing 4 different case types
- THEN the frontend MUST NOT make 20 individual API calls to resolve case type names
- AND instead MUST fetch all relevant case types in a single call (or use the cached case type store)
- AND the case type store SHOULD pre-fetch all case types on app initialization (small dataset, typically less than 20)

#### Scenario: Status type resolution is cached

- GIVEN case types have between 3-6 status types each
- WHEN the case list or detail page needs to display status names
- THEN status types MUST be fetched once per case type and cached in the Pinia store
- AND subsequent accesses MUST use the cached data

#### Scenario: My Work aggregation performance

- GIVEN the My Work view needs to display cases and tasks for the current user
- THEN the frontend MUST make exactly 2 API calls:
  - Cases with `?assignee=currentUser&status_ne=final` (non-final cases assigned to user)
  - Tasks with `?assignee=currentUser&status=available,active` (active/available tasks)
- AND the results MUST be merged and sorted client-side
- AND the total load time MUST be under 2 seconds

#### Scenario: Pagination prevents loading too many objects

- GIVEN the case list could contain hundreds of cases
- THEN the default page size MUST NOT exceed 50
- AND the frontend MUST use pagination (not load all objects at once)
- AND lazy loading or virtual scrolling SHOULD be used for long lists

---

### REQ-OREG-013: Cross-Entity Reference Map

**Tier**: MVP

For implementation clarity, this is the complete reference map showing how entities relate to each other.

```
CaseType ─────────────────────────────────────────────────────────────┐
│                                                                      │
├── StatusType[]        (statusType.caseType → caseType UUID)         │
├── ResultType[]        (resultType.caseType → caseType UUID)         │
├── RoleType[]          (roleType.caseType → caseType UUID)           │
├── PropertyDefinition[] (propertyDefinition.caseType → caseType UUID)│
├── DocumentType[]      (documentType.caseType → caseType UUID)       │
└── DecisionType[]      (decisionType.caseType → caseType UUID)       │
                                                                       │
Case ─────────────────────────────────────────────────────────────────┤
│  case.caseType → caseType UUID                                       │
│  case.status → statusType UUID                                       │
│  case.result → result UUID (optional)                                │
│  case.assignee → Nextcloud user UID (optional)                       │
│  case.parentCase → case UUID (optional, for sub-cases)              │
│                                                                      │
├── Task[]              (task.case → case UUID)                        │
│     task.assignee → Nextcloud user UID (optional)                    │
│                                                                      │
├── Role[]              (role.case → case UUID)                        │
│     role.roleType → roleType UUID                                    │
│     role.participant → Nextcloud user UID or contact ref             │
│                                                                      │
├── Result              (result.case → case UUID, at most 1)          │
│     result.resultType → resultType UUID                              │
│                                                                      │
└── Decision[]          (decision.case → case UUID)                    │
      decision.decisionType → decisionType UUID (optional)             │
      decision.decidedBy → Nextcloud user UID (optional)               │
```

#### Scenario: Verify reference integrity on task creation

- GIVEN a user creates a task with `case: "case-uuid-042"`
- THEN the system SHOULD verify that case "case-uuid-042" exists in the register
- AND if the referenced case does not exist, the creation SHOULD be rejected

#### Scenario: Verify role type belongs to the correct case type

- GIVEN a user creates a role on case #2024-042 (caseType: "Omgevingsvergunning")
- AND the user specifies roleType UUID for "Klager" which belongs to case type "Klacht"
- THEN the system SHOULD reject the role creation
- AND the error MUST indicate that the role type does not belong to the case's case type

#### Scenario: Case type deletion cascades to child types

- GIVEN case type "Bezwaarschrift" has 3 status types, 2 result types, and 2 role types
- AND no cases reference this case type
- WHEN the admin deletes the case type
- THEN all 3 status types, 2 result types, and 2 role types MUST also be deleted

---

## Summary: API Endpoint Patterns

| Entity | List | Get | Create | Update | Delete |
|--------|------|-----|--------|--------|--------|
| Case | `GET .../procest/case` | `GET .../procest/case/{id}` | `POST .../procest/case` | `PUT .../procest/case/{id}` | `DELETE .../procest/case/{id}` |
| Task | `GET .../procest/task` | `GET .../procest/task/{id}` | `POST .../procest/task` | `PUT .../procest/task/{id}` | `DELETE .../procest/task/{id}` |
| Role | `GET .../procest/role` | `GET .../procest/role/{id}` | `POST .../procest/role` | `PUT .../procest/role/{id}` | `DELETE .../procest/role/{id}` |
| Result | `GET .../procest/result` | `GET .../procest/result/{id}` | `POST .../procest/result` | `PUT .../procest/result/{id}` | `DELETE .../procest/result/{id}` |
| Decision | `GET .../procest/decision` | `GET .../procest/decision/{id}` | `POST .../procest/decision` | `PUT .../procest/decision/{id}` | `DELETE .../procest/decision/{id}` |
| CaseType | `GET .../procest/caseType` | `GET .../procest/caseType/{id}` | `POST .../procest/caseType` | `PUT .../procest/caseType/{id}` | `DELETE .../procest/caseType/{id}` |
| StatusType | `GET .../procest/statusType` | `GET .../procest/statusType/{id}` | `POST .../procest/statusType` | `PUT .../procest/statusType/{id}` | `DELETE .../procest/statusType/{id}` |
| ResultType | `GET .../procest/resultType` | `GET .../procest/resultType/{id}` | `POST .../procest/resultType` | `PUT .../procest/resultType/{id}` | `DELETE .../procest/resultType/{id}` |
| RoleType | `GET .../procest/roleType` | `GET .../procest/roleType/{id}` | `POST .../procest/roleType` | `PUT .../procest/roleType/{id}` | `DELETE .../procest/roleType/{id}` |
| PropDef | `GET .../procest/propertyDefinition` | `GET .../procest/propertyDefinition/{id}` | `POST ...` | `PUT ...` | `DELETE ...` |
| DocType | `GET .../procest/documentType` | `GET .../procest/documentType/{id}` | `POST ...` | `PUT ...` | `DELETE ...` |
| DecisionType | `GET .../procest/decisionType` | `GET .../procest/decisionType/{id}` | `POST ...` | `PUT ...` | `DELETE ...` |

Base URL: `/index.php/apps/openregister/api/objects`

---

## Pinia Store Inventory

| Store | Entity | Key Extra Features |
|-------|--------|-------------------|
| `useCaseStore()` | case | Resolves caseType and status names; My Work filtering |
| `useTaskStore()` | task | Kanban grouping by status; overdue calculation |
| `useRoleStore()` | role | Resolves participant display names from Nextcloud |
| `useResultStore()` | result | Links to resultType for archival info |
| `useDecisionStore()` | decision | Validity period calculations |
| `useCaseTypeStore()` | caseType | Cached on app init; used by all case views |
| `useStatusTypeStore()` | statusType | Ordered by `order`; cached per case type |
| `useResultTypeStore()` | resultType | Filtered by caseType |
| `useRoleTypeStore()` | roleType | Filtered by caseType |
| `usePropertyDefinitionStore()` | propertyDefinition | Filtered by caseType |
| `useDocumentTypeStore()` | documentType | Filtered by caseType |
| `useDecisionTypeStore()` | decisionType | Filtered by caseType (V1) |
