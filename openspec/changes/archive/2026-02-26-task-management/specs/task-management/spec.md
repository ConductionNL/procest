# Task Management Delta Specification

## Purpose

Implements the MVP tier of the task management capability as defined in `procest/openspec/specs/task-management/spec.md`. This delta spec captures the subset of requirements being implemented in this change and any implementation-specific clarifications.

## ADDED Requirements

### Requirement: MVP Task CRUD Implementation

The system MUST implement full CRUD operations for tasks using the existing `useObjectStore` Pinia store and OpenRegister API. The frontend MUST validate required fields before submission.

#### Scenario: Create task with frontend validation

- GIVEN the user is on the case detail page for case #2024-042
- WHEN the user clicks "New task" and submits a form with title "Controleer bouwtekeningen"
- THEN the system MUST call `saveObject('task', { title, case: caseId, status: 'available', priority: 'normal' })`
- AND the new task MUST appear in the case's task list without a full page reload
- AND the task form MUST reset after successful creation

#### Scenario: Reject task creation without title

- GIVEN the user is creating a new task
- WHEN the user submits the form with an empty title
- THEN the frontend MUST show an inline validation error "Title is required"
- AND the form MUST NOT submit to the API

#### Scenario: Edit task inline fields

- GIVEN an existing task "Controleer bouwtekeningen" with status `available`
- WHEN the user opens the task detail view and changes the description
- THEN the system MUST call `saveObject('task', updatedData)` with the task's existing ID
- AND the updated data MUST be reflected in both the task detail and any list views

#### Scenario: Delete task with confirmation

- GIVEN an existing task "Verouderde controle" in case #2024-042
- WHEN the user clicks delete and confirms the dialog
- THEN the system MUST call `deleteObject('task', taskId)`
- AND the task MUST be removed from the case's task list

### Requirement: CMMN Status Lifecycle Enforcement

The frontend MUST enforce the CMMN PlanItem lifecycle transitions. Invalid transitions MUST be prevented in the UI before reaching the API.

#### Scenario: Status transition buttons reflect allowed transitions

- GIVEN a task with status `available`
- WHEN the task detail view renders
- THEN the available actions MUST be: "Start" (→ active), "Terminate" (→ terminated), "Disable" (→ disabled)
- AND "Complete" MUST NOT be available (requires active status first)

#### Scenario: Complete sets completedDate automatically

- GIVEN a task with status `active`
- WHEN the user clicks "Complete"
- THEN the system MUST set `status: 'completed'` AND `completedDate` to the current ISO 8601 timestamp
- AND both fields MUST be sent in a single `saveObject` call

#### Scenario: Completed and terminated tasks are read-only

- GIVEN a task with status `completed` or `terminated`
- WHEN the task detail view renders
- THEN all form fields MUST be disabled/read-only
- AND no status transition buttons MUST be shown

### Requirement: Global Task List View

The system MUST provide a dedicated task list view accessible from the main navigation, with filtering, sorting, and search.

#### Scenario: Navigate to global task list

- GIVEN the user clicks "Tasks" in the navigation menu
- WHEN the task list view loads
- THEN the system MUST fetch all tasks via `fetchCollection('task', params)`
- AND display them in a table with columns: Title, Case, Status, Assignee, Due Date, Priority

#### Scenario: Filter by status

- GIVEN 23 tasks across multiple cases
- WHEN the user selects status filter "active"
- THEN the system MUST re-fetch with `_filters[status]=active`
- AND only active tasks MUST be shown

#### Scenario: Filter by assignee

- GIVEN tasks assigned to multiple users
- WHEN the user selects assignee filter "jan.devries"
- THEN the system MUST re-fetch with `_filters[assignee]=jan.devries`
- AND only Jan's tasks MUST be shown

#### Scenario: Sort by due date

- GIVEN tasks with various due dates
- WHEN the user clicks the "Due Date" column header
- THEN tasks MUST be re-fetched with `_order[dueDate]=asc`
- AND tasks without a due date MUST appear at the end

#### Scenario: Search by title

- GIVEN the user types "bouwtekeningen" in the search field
- WHEN the search term is applied (debounced 300ms)
- THEN the system MUST re-fetch with `_search=bouwtekeningen`

### Requirement: Case-Scoped Task List with Progress

The case detail page MUST show tasks for that case with completion progress tracking.

#### Scenario: Task progress indicator

- GIVEN case #2024-042 has 5 tasks, 2 completed
- WHEN the case detail page renders the tasks section
- THEN the header MUST show "Tasks (2/5)"
- AND the progress fraction MUST update when a task is completed

#### Scenario: Default sort order in case task list

- GIVEN case tasks with mixed statuses and priorities
- WHEN the task list renders
- THEN tasks MUST be sorted: available/active first (by priority descending, then due date ascending), then completed, then terminated/disabled

### Requirement: Overdue Highlighting

Active/available tasks past their due date MUST be visually highlighted. Completed/terminated/disabled tasks MUST NOT show overdue indicators.

#### Scenario: Overdue task in list view

- GIVEN a task with dueDate "2026-02-20" and status `active`, and today is 2026-02-25
- WHEN the task renders in any list view
- THEN the due date cell MUST show "5 days overdue" in red text
- AND the row MUST have a visual overdue indicator (red left border or background tint)

#### Scenario: Due today indicator

- GIVEN a task with dueDate set to today and status `active`
- WHEN the task renders
- THEN the due date cell MUST show "Due today" in amber/orange text

#### Scenario: Completed task not marked overdue

- GIVEN a task with dueDate in the past and status `completed`
- WHEN the task renders
- THEN no overdue indicator MUST be shown

### Requirement: Priority Visual Indicators

Tasks MUST display priority badges with consistent color coding across all views.

#### Scenario: Priority badge rendering

- GIVEN a task with priority `urgent`
- WHEN the task renders in any list view
- THEN the priority cell MUST show a red "Urgent" badge
- AND `high` MUST show orange, `normal` no badge, `low` grey

## MODIFIED Requirements

_No existing requirements are being changed — this is an initial implementation of the existing spec._

## REMOVED Requirements

_None._
