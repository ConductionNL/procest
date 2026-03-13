# Task Management Specification

## Purpose

Tasks represent work items within a case. They follow CMMN 1.1 HumanTask concepts and are semantically typed as `schema:Action`. Tasks can be assigned to Nextcloud users, have due dates and priorities, and follow an independent lifecycle within the parent case. Tasks are the primary mechanism for distributing and tracking work across case handlers, advisors, and other participants.

**Standards**: CMMN 1.1 (HumanTask, PlanItem lifecycle), Schema.org (`Action`, `actionStatus`), BPMN 2.0 (task patterns)
**Primary feature tier**: MVP
**Extended features**: V1 (kanban, checklists, dependencies, templates), Enterprise (automation)

---

## Data Model

### Task Entity

Stored as an OpenRegister object in the `procest` register under the `task` schema.

| Property | Type | CMMN/Schema.org | Required | Default |
|----------|------|----------------|----------|---------|
| `title` | string (max 255) | `schema:name` | Yes | — |
| `description` | string | `schema:description` | No | — |
| `status` | enum | CMMN PlanItem states | Yes | `available` |
| `assignee` | string (Nextcloud user UID) | CMMN assignee | No | — |
| `case` | reference (UUID) | CMMN parent case | Yes | — |
| `dueDate` | datetime (ISO 8601) | `schema:endTime` | No | — |
| `priority` | enum: `low`, `normal`, `high`, `urgent` | `schema:priority` | No | `normal` |
| `completedDate` | datetime (ISO 8601) | `schema:endTime` | Auto (set on completion) | — |

### Task Status Lifecycle (CMMN PlanItem)

```
                ┌──────────┐
                │ available│
                └────┬─────┘
                     │ start
                     v
                ┌──────────┐
           ┌────│  active  │────┐
           │    └──────────┘    │
     complete                terminate
           │                    │
           v                    v
    ┌───────────┐       ┌────────────┐
    │ completed │       │ terminated │
    └───────────┘       └────────────┘

    ┌──────────┐
    │ disabled │  (set from available only)
    └──────────┘
```

| Status | CMMN State | Description | Allowed Transitions From |
|--------|-----------|-------------|--------------------------|
| `available` | Available | Task can be started | (initial state) |
| `active` | Active | Task is being worked on | `available` |
| `completed` | Completed | Task finished successfully | `active` |
| `terminated` | Terminated | Task stopped before completion | `available`, `active` |
| `disabled` | Disabled | Task not applicable | `available` |

### Priority Levels

| Priority | Sort Weight | Visual Indicator |
|----------|------------|------------------|
| `urgent` | 1 (highest) | Red badge |
| `high` | 2 | Orange badge |
| `normal` | 3 | No badge (default) |
| `low` | 4 (lowest) | Grey badge |

---

## Requirements

### REQ-TASK-001: Task CRUD

**Tier**: MVP

The system MUST support creating, reading, updating, and deleting tasks linked to cases. All task objects are stored in OpenRegister under the `procest` register, `task` schema.

#### Scenario: Create a task linked to a case

- GIVEN a case #2024-042 "Bouwvergunning Keizersgracht" exists with status "In behandeling"
- AND the current user "jan.devries" has access to the case
- WHEN the user creates a task with title "Controleer bouwtekeningen" and assigns it to case #2024-042
- THEN the system MUST create an OpenRegister object in the `task` schema
- AND the task `case` property MUST contain the UUID of case #2024-042
- AND the task `status` MUST default to `available`
- AND the task `priority` MUST default to `normal`
- AND the `completedDate` MUST be null
- AND the audit trail MUST record the creation event with the creating user

#### Scenario: Create a task with all optional fields

- GIVEN a case #2024-048 "Subsidie verduurzaming" exists
- WHEN the user creates a task with:
  - title: "Beoordeel begroting"
  - description: "Controleer of de ingediende begroting voldoet aan de subsidievoorwaarden"
  - assignee: "maria.bakker"
  - dueDate: "2026-03-01T17:00:00Z"
  - priority: "high"
- THEN all properties MUST be stored correctly on the task object
- AND the task `status` MUST still default to `available`

#### Scenario: Read a single task

- GIVEN a task with UUID "task-uuid-001" exists in case #2024-042
- WHEN the frontend requests `GET /index.php/apps/openregister/api/objects/procest/task/task-uuid-001`
- THEN the response MUST include all task properties
- AND the `case` reference MUST be resolvable to the parent case object

#### Scenario: Update a task's description

- GIVEN an existing task "Controleer bouwtekeningen" with status `available`
- WHEN the user updates the description to "Controleer bouwtekeningen inclusief constructieberekening"
- THEN the system MUST update the task object via `PUT` to the OpenRegister API
- AND the audit trail MUST record the update with the changed fields

#### Scenario: Delete a task

- GIVEN a task "Verouderde controle" with status `available` in case #2024-042
- WHEN the user deletes the task
- THEN the system MUST call `DELETE` on the OpenRegister API
- AND the task MUST no longer appear in the case's task list
- AND the audit trail MUST record the deletion

#### Scenario: Attempt to create a task without a title (validation error)

- GIVEN the user is creating a new task for case #2024-042
- WHEN the user submits the form with an empty title
- THEN the system MUST reject the request with a validation error
- AND the error message MUST indicate that `title` is required
- AND no task object MUST be created

#### Scenario: Attempt to create a task without a case reference (validation error)

- GIVEN the user is creating a new task
- WHEN the user submits the form without selecting a parent case
- THEN the system MUST reject the request with a validation error
- AND the error message MUST indicate that `case` is required

#### Scenario: Attempt to create a task referencing a non-existent case

- GIVEN no case exists with UUID "non-existent-uuid"
- WHEN the user submits a task creation with `case` set to "non-existent-uuid"
- THEN the system MUST reject the request
- AND the error message MUST indicate that the referenced case does not exist

---

### REQ-TASK-002: Task Status Lifecycle

**Tier**: MVP

The system MUST enforce the CMMN PlanItem lifecycle for task status transitions. Invalid transitions MUST be rejected.

#### Scenario: Start a task (available to active)

- GIVEN a task "Controleer bouwtekeningen" with status `available`
- AND the task is assigned to "jan.devries"
- WHEN the user changes the status to `active`
- THEN the status MUST change to `active`
- AND the audit trail MUST record the status transition with timestamp and user

#### Scenario: Complete a task (active to completed)

- GIVEN a task "Controleer bouwtekeningen" with status `active` assigned to "jan.devries"
- WHEN "jan.devries" marks the task as completed
- THEN the status MUST change to `completed`
- AND the `completedDate` MUST be set to the current timestamp (ISO 8601)
- AND the audit trail MUST record the completion

#### Scenario: Terminate an active task

- GIVEN a task "Locatiebezoek plannen" with status `active`
- WHEN the user terminates the task with reason "Niet meer nodig na telefonisch contact"
- THEN the status MUST change to `terminated`
- AND the task MUST remain visible in the case timeline (not deleted)
- AND the audit trail MUST record the termination

#### Scenario: Terminate an available task

- GIVEN a task "Extra advies inwinnen" with status `available`
- WHEN the user terminates the task
- THEN the status MUST change to `terminated`

#### Scenario: Disable an available task

- GIVEN a task "Welstandstoets uitvoeren" with status `available`
- WHEN the user disables the task (not applicable for this case)
- THEN the status MUST change to `disabled`

#### Scenario: Reject invalid transition - complete an available task

- GIVEN a task "Controleer bouwtekeningen" with status `available`
- WHEN the user attempts to change the status directly to `completed`
- THEN the system MUST reject the transition
- AND the error message MUST indicate that a task must be `active` before it can be `completed`
- AND the task status MUST remain `available`

#### Scenario: Reject invalid transition - reactivate a completed task

- GIVEN a task "Intake controle" with status `completed` and `completedDate` "2026-01-20T14:30:00Z"
- WHEN the user attempts to change the status back to `active`
- THEN the system MUST reject the transition
- AND the error message MUST indicate that completed tasks cannot be reactivated
- AND the `completedDate` MUST remain unchanged

#### Scenario: Reject invalid transition - disable an active task

- GIVEN a task "Beoordeel aanvraag" with status `active`
- WHEN the user attempts to change the status to `disabled`
- THEN the system MUST reject the transition
- AND the error message MUST indicate that only `available` tasks can be disabled

---

### REQ-TASK-003: Task Assignment

**Tier**: MVP

The system MUST support assigning tasks to Nextcloud users by their user UID. Unassigned tasks are allowed.

#### Scenario: Assign a task to a user

- GIVEN an available task "Controleer bouwtekeningen" in case #2024-042
- WHEN the user assigns it to Nextcloud user "jan.devries"
- THEN the task `assignee` MUST be set to "jan.devries"
- AND the task MUST appear in Jan de Vries's "My Work" view
- AND the audit trail MUST record the assignment

#### Scenario: Assign a task to a user with notification

- GIVEN an available task "Beoordeel constructieberekening"
- WHEN the user assigns it to "pieter.smit"
- THEN the assigned user SHOULD receive a Nextcloud notification
- AND the notification MUST include the task title and the parent case reference

#### Scenario: Reassign a task to a different user

- GIVEN a task "Review documenten" assigned to "jan.devries" with status `active`
- WHEN the coordinator reassigns it to "maria.bakker"
- THEN the `assignee` MUST be updated to "maria.bakker"
- AND "maria.bakker" SHOULD receive an assignment notification
- AND the audit trail MUST record the reassignment from "jan.devries" to "maria.bakker"
- AND the task MUST remain in its current status (`active`)

#### Scenario: Unassign a task

- GIVEN a task "Verzamel informatie" assigned to "jan.devries" with status `available`
- WHEN the user removes the assignee
- THEN the `assignee` MUST be set to null
- AND the task MUST no longer appear in Jan's "My Work" view

#### Scenario: Attempt to assign a task to a non-existent user

- GIVEN a task "Controleer bouwtekeningen"
- WHEN the user attempts to assign it to "nonexistent.user"
- THEN the system MUST reject the assignment
- AND the error message MUST indicate that the user does not exist in Nextcloud

#### Scenario: Create a task with immediate assignment

- GIVEN a case #2024-042 exists
- WHEN the user creates a task with title "Situatietekening controleren" and assignee "jan.devries" in a single operation
- THEN the task MUST be created with the assignee already set
- AND the task MUST appear immediately in Jan's "My Work" view

---

### REQ-TASK-004: Task List View

**Tier**: MVP

The system MUST provide a list view for tasks with search, sorting, and filtering capabilities. The list view MUST support both a global task list (all tasks) and a case-scoped task list (tasks for a specific case).

#### Scenario: View the global task list

- GIVEN 23 tasks exist across 8 cases
- WHEN the user navigates to the Tasks section
- THEN the system MUST display a paginated list of tasks
- AND each task row MUST show: title, parent case reference (ID + title), status, assignee, due date, and priority

#### Scenario: View tasks for a specific case

- GIVEN case #2024-042 "Bouwvergunning Keizersgracht" has 5 tasks
- WHEN the user views the case detail page
- THEN all 5 tasks MUST be displayed in the Tasks section
- AND tasks MUST be sorted by status (available/active first) then by priority (urgent first) then by due date (earliest first)
- AND the task count MUST show completion progress (e.g., "3/5")

#### Scenario: Filter tasks by status

- GIVEN 23 tasks exist with statuses: 4 available, 6 active, 12 completed, 1 terminated
- WHEN the user filters by status "active"
- THEN only the 6 active tasks MUST be shown
- AND the filter MUST be clearly indicated in the UI

#### Scenario: Filter tasks by assignee

- GIVEN tasks assigned to "jan.devries" (8 tasks), "maria.bakker" (6 tasks), and unassigned (9 tasks)
- WHEN the user filters by assignee "jan.devries"
- THEN only the 8 tasks assigned to Jan MUST be shown

#### Scenario: Filter tasks by case

- GIVEN the user is on the global task list
- WHEN the user selects case filter "Case #2024-042"
- THEN only tasks belonging to case #2024-042 MUST be shown

#### Scenario: Sort tasks by due date

- GIVEN tasks with various due dates
- WHEN the user sorts by due date ascending
- THEN tasks MUST be ordered with the earliest due date first
- AND tasks without a due date MUST appear at the end

#### Scenario: Sort tasks by priority

- GIVEN tasks with priorities: 2 urgent, 3 high, 10 normal, 2 low
- WHEN the user sorts by priority descending
- THEN tasks MUST be ordered: urgent, high, normal, low

#### Scenario: Search tasks by title

- GIVEN tasks with titles including "bouwtekeningen", "constructie", "situatie"
- WHEN the user searches for "bouwtekeningen"
- THEN only tasks whose title contains "bouwtekeningen" MUST be shown

#### Scenario: View "My Tasks" (personal task list)

- GIVEN "jan.devries" has 7 tasks assigned across cases #2024-042, #2024-048, and #2024-050
- WHEN Jan views the "My Work" section
- THEN all 7 of his tasks MUST be displayed
- AND each task MUST show which case it belongs to (case ID and title)
- AND tasks MUST be grouped by urgency: overdue first, then due this week, then upcoming

#### Scenario: Empty task list

- GIVEN a case #2024-051 with no tasks
- WHEN the user views the case detail page
- THEN the Tasks section MUST display an empty state message
- AND a prominent "Add Task" button MUST be visible

---

### REQ-TASK-005: Task Due Dates and Priorities

**Tier**: MVP

The system MUST support due dates and priority levels on tasks. Overdue tasks MUST be visually highlighted.

#### Scenario: Set a due date on a task

- GIVEN a task "Controleer bouwtekeningen" without a due date
- WHEN the user sets the due date to "2026-02-26T17:00:00Z"
- THEN the `dueDate` MUST be stored on the task object
- AND the due date MUST be displayed on the task card as "Feb 26"

#### Scenario: Overdue task highlighting in list view

- GIVEN a task "Review documenten" with dueDate "2026-02-20T17:00:00Z" and status `active`
- AND today is February 25, 2026
- THEN the task MUST be visually marked as overdue (red indicator)
- AND the overdue duration MUST be displayed (e.g., "5 days overdue")

#### Scenario: Overdue task highlighting on kanban card

- GIVEN a task card on the kanban board for "Review documenten" with dueDate in the past
- AND the task status is `active`
- THEN the card MUST display a red overdue warning (e.g., "1 day overdue")
- AND the due date text MUST be styled in red

#### Scenario: Completed task is not marked overdue

- GIVEN a task "Intake controle" with dueDate "2026-01-15" and status `completed` and completedDate "2026-01-14"
- THEN the task MUST NOT be marked as overdue, even though the due date is in the past
- AND the card MUST show the completion date with a green checkmark

#### Scenario: Task due today indicator

- GIVEN a task "Beoordeel constructie" with dueDate set to today
- AND the task status is `active`
- THEN the task MUST be highlighted with an amber/yellow "Due today" indicator

#### Scenario: Set priority on a task

- GIVEN a task "Controleer bouwtekeningen" with default priority `normal`
- WHEN the user changes the priority to `high`
- THEN the `priority` MUST be updated to `high`
- AND the task card MUST display a priority badge (orange "high" badge as per kanban card anatomy)

#### Scenario: Priority affects sort order

- GIVEN the following active tasks:
  - "Draft besluit" with priority `urgent`, dueDate Mar 5
  - "Review documenten" with priority `high`, dueDate Feb 26
  - "Verzamel info" with priority `normal`, dueDate Feb 28
- WHEN the user views the task list sorted by priority
- THEN the order MUST be: "Draft besluit" (urgent), "Review documenten" (high), "Verzamel info" (normal)

---

### REQ-TASK-006: Task Card Display

**Tier**: MVP (list), V1 (kanban cards)

Task cards MUST display key information following the card anatomy defined in the design wireframes.

#### Scenario: Task card anatomy in list view

- GIVEN a task with:
  - title: "Review documenten"
  - case: #2024-042 "Bouwvergunning Keizersgracht"
  - dueDate: "2026-02-26"
  - assignee: "jan.devries" (display name "Jan de Vries")
  - priority: `high`
  - status: `active`
- WHEN the task is rendered in the list view
- THEN the card MUST display:
  - The task title "Review documenten" (clickable, navigates to case detail)
  - The parent case reference "Case #2024-042" (clickable, navigates to case)
  - The due date formatted as "Feb 26"
  - The assignee name "Jan" or "Jan de Vries" with avatar
  - A priority badge "high" (orange)

#### Scenario: Task card on kanban board

- GIVEN the same task as above displayed on the kanban board
- THEN the card MUST be positioned in the "Active" column
- AND the card MUST follow the anatomy:
  ```
  ┌──────────────────┐
  │ Review documenten│  (title)
  │ Case #042        │  (parent case reference)
  │ Feb 26           │  (due date)
  │ Jan              │  (assignee)
  │ high             │  (priority badge)
  └──────────────────┘
  ```

#### Scenario: Unassigned task card

- GIVEN a task "Controleer regelgeving" with no assignee
- WHEN the card is rendered
- THEN the assignee field MUST show a dash "—" or "Unassigned" placeholder
- AND the card MUST still display all other fields normally

---

### REQ-TASK-007: Kanban Board View

**Tier**: V1

The system MUST provide a kanban board view for tasks, with columns corresponding to CMMN task statuses. The board MUST support drag-and-drop to change task status.

#### Scenario: View tasks as kanban board

- GIVEN tasks exist with statuses: 4 available, 6 active, 12 completed, 1 terminated
- WHEN the user switches to the board view via the "Board | List" toggle
- THEN the system MUST display four columns: "Available" (4 tasks), "Active" (6 tasks), "Completed" (12 tasks), "Terminated" (1 task)
- AND each column header MUST show the task count
- AND tasks within each column MUST be sorted by priority (urgent first) then due date (earliest first)

#### Scenario: Toggle between board and list view

- GIVEN the user is on the task list view
- WHEN the user clicks the "Board" toggle
- THEN the view MUST switch to the kanban board layout
- AND the current filters (case, assignee, priority) MUST be preserved across the toggle
- AND when switching back to "List", the filters MUST still be active

#### Scenario: Drag task from Available to Active

- GIVEN a task card "Controleer bouwtekeningen" in the "Available" column
- WHEN the user drags the card to the "Active" column
- THEN the system MUST update the task status to `active` via the OpenRegister API
- AND the card MUST move to the "Active" column
- AND the column counts MUST update (Available -1, Active +1)
- AND the audit trail MUST record the status change

#### Scenario: Drag task from Active to Completed

- GIVEN a task card "Site visit uitvoeren" in the "Active" column
- WHEN the user drags the card to the "Completed" column
- THEN the system MUST update the task status to `completed`
- AND `completedDate` MUST be set to the current timestamp
- AND the card MUST show a completion checkmark

#### Scenario: Prevent invalid drag (Completed to Active)

- GIVEN a task card "Intake controle" in the "Completed" column
- WHEN the user attempts to drag it to the "Active" column
- THEN the system MUST reject the drop (invalid transition per CMMN lifecycle)
- AND the card MUST snap back to the "Completed" column
- AND a brief error message SHOULD inform the user that completed tasks cannot be reactivated

#### Scenario: Filter kanban by case

- GIVEN the user selects case filter "Case #2024-042" on the kanban board
- THEN only tasks belonging to case #2024-042 MUST be shown in each column
- AND the column counts MUST reflect the filtered totals

#### Scenario: Filter kanban by assignee

- GIVEN the user selects assignee filter "Jan de Vries"
- THEN only tasks assigned to "jan.devries" MUST be shown across all columns

#### Scenario: Kanban board with no tasks

- GIVEN no tasks exist (or all are filtered out)
- THEN the board MUST display empty columns with a helpful message
- AND an "Add Task" button MUST be available

---

### REQ-TASK-008: Task Completion

**Tier**: MVP

When a task is completed, the system MUST automatically set the `completedDate` and enforce lifecycle rules.

#### Scenario: Complete a task and record completion date

- GIVEN a task "Locatiebezoek" with status `active` and no `completedDate`
- WHEN the user marks it as completed at 2026-02-25T14:30:00Z
- THEN the `status` MUST change to `completed`
- AND the `completedDate` MUST be set to "2026-02-25T14:30:00Z"
- AND the task MUST remain visible in the case timeline with a green checkmark

#### Scenario: Attempt to complete an already-completed task

- GIVEN a task "Intake controle" already has status `completed` and completedDate "2026-01-20T10:00:00Z"
- WHEN the user attempts to complete it again
- THEN the system MUST reject the operation (no-op or error)
- AND the `completedDate` MUST remain "2026-01-20T10:00:00Z"

#### Scenario: Task completion updates case progress

- GIVEN case #2024-042 has 5 tasks, 2 of which are completed
- WHEN the user completes a third task
- THEN the case detail Tasks section MUST show updated progress "3/5"

---

### REQ-TASK-009: Task Checklist (Sub-Items)

**Tier**: V1

The system SHOULD support checklists within tasks for detailed work breakdown. Checklist items are lightweight items stored as part of the task object (not separate OpenRegister objects).

#### Scenario: Add checklist items to a task

- GIVEN a task "Beoordeel aanvraag" with status `active`
- WHEN the user adds checklist items:
  - "Controleer volledigheid formulier"
  - "Verifieer bijlagen"
  - "Check regelgeving"
- THEN the task object MUST store these items as an ordered list
- AND each item MUST have a `checked` boolean (default: false) and a `label` string

#### Scenario: Check off a checklist item

- GIVEN a task with 3 checklist items, all unchecked
- WHEN the user checks "Controleer volledigheid formulier"
- THEN that item's `checked` MUST be set to true
- AND the task card SHOULD show checklist progress (e.g., "1/3")

#### Scenario: Reorder checklist items

- GIVEN a task with 3 checklist items
- WHEN the user drags "Check regelgeving" to the first position
- THEN the order MUST be updated in the stored list

#### Scenario: Checklist completion does not auto-complete the task

- GIVEN a task with 3 checklist items, all checked
- THEN the task status MUST NOT automatically change to `completed`
- AND the user MUST still explicitly complete the task

---

### REQ-TASK-010: Task Dependencies

**Tier**: V1

The system SHOULD support declaring dependencies between tasks ("blocked by" relationships). Dependencies are advisory: they provide visual indicators but do not strictly prevent work.

#### Scenario: Declare a task dependency

- GIVEN task A "Draft besluit" and task B "Review documenten" in case #2024-042
- WHEN the user sets task A as "blocked by" task B
- THEN task A MUST store a reference to task B's UUID as a dependency
- AND task A's card MUST show a "blocked" indicator while task B is not completed

#### Scenario: Dependency resolved when blocking task completes

- GIVEN task A "Draft besluit" is blocked by task B "Review documenten"
- WHEN task B is completed
- THEN task A's "blocked" indicator MUST be removed
- AND task A MUST remain in its current status (the indicator is visual only)

#### Scenario: View dependency chain

- GIVEN task A is blocked by task B, and task B is blocked by task C
- WHEN the user views task A's dependencies
- THEN the system SHOULD show the full dependency chain: A depends on B depends on C
- AND the system SHOULD warn if the chain creates a circular dependency

#### Scenario: Prevent circular dependencies

- GIVEN task A is blocked by task B
- WHEN the user attempts to set task B as "blocked by" task A
- THEN the system MUST reject the circular dependency
- AND the error message MUST indicate that a circular dependency was detected

---

### REQ-TASK-011: Task Templates per Case Type

**Tier**: V1

The system SHOULD support defining task templates on case types. When a case of that type is created, the user can choose to instantiate the template tasks.

#### Scenario: Define task template on case type

- GIVEN the admin is editing case type "Omgevingsvergunning"
- WHEN the admin defines a task template with:
  - "Intake controle" (priority: high, relative due: +3 days)
  - "Locatiebezoek plannen" (priority: normal, relative due: +14 days)
  - "Beoordeel aanvraag" (priority: high, relative due: +28 days)
  - "Draft besluit" (priority: urgent, relative due: +42 days)
  - "Verstuur resultaat" (priority: normal, relative due: +56 days)
- THEN the template MUST be saved on the case type configuration

#### Scenario: Apply task template on case creation

- GIVEN case type "Omgevingsvergunning" has 5 template tasks
- WHEN the user creates a new case of this type with start date "2026-03-01"
- THEN the system SHOULD offer to create the template tasks
- AND if accepted, 5 task objects MUST be created, each as an independent OpenRegister object
- AND relative due dates MUST be calculated from the case start date (e.g., "Intake controle" due date = 2026-03-04)
- AND all template tasks MUST be created with status `available`

#### Scenario: Skip task template on case creation

- GIVEN case type "Omgevingsvergunning" has 5 template tasks
- WHEN the user creates a new case and declines the template
- THEN no tasks MUST be created automatically
- AND the user can add tasks manually later

---

### REQ-TASK-012: Automated Task Creation on Case Status Change

**Tier**: Enterprise

The system MAY support automatically creating tasks when a case transitions to a specific status.

#### Scenario: Auto-create tasks on status change

- GIVEN case type "Omgevingsvergunning" has a rule: "When status changes to Besluitvorming, create task 'Draft besluit'"
- WHEN case #2024-042 transitions from "In behandeling" to "Besluitvorming"
- THEN the system MUST automatically create a task "Draft besluit"
- AND the task MUST be linked to case #2024-042
- AND the task MUST inherit default values from the automation rule (assignee, priority, relative due date)

#### Scenario: Auto-created task notification

- GIVEN an automated task creation rule exists
- WHEN the rule fires and creates a task assigned to "jan.devries"
- THEN "jan.devries" SHOULD receive a notification that a task was auto-created
- AND the notification MUST indicate it was system-generated

---

### REQ-TASK-013: Overdue Task Management

**Tier**: MVP

The system MUST provide clear visual indicators for overdue tasks and support filtering/sorting by overdue status.

#### Scenario: Overdue task in My Work view

- GIVEN "jan.devries" has an active task "Review documenten" with dueDate "2026-02-20"
- AND today is 2026-02-25
- THEN the task MUST appear in the "Overdue" section of Jan's My Work view
- AND the overdue indicator MUST show "5 days overdue" in red

#### Scenario: Multiple overdue tasks sorted by urgency

- GIVEN "jan.devries" has overdue tasks:
  - "Review documenten" (5 days overdue, priority: high)
  - "Verzamel informatie" (2 days overdue, priority: normal)
  - "Controleer bijlagen" (1 day overdue, priority: urgent)
- WHEN Jan views his My Work
- THEN overdue tasks MUST be sorted by priority first (urgent, high, normal), then by overdue duration (most overdue first within the same priority)
- AND the resulting order MUST be: "Controleer bijlagen", "Review documenten", "Verzamel informatie"

#### Scenario: Task becomes overdue

- GIVEN a task "Beoordeel begroting" with dueDate "2026-02-25T17:00:00Z" and status `active`
- WHEN the current time passes "2026-02-25T17:00:00Z"
- THEN on the next view render, the task MUST display an overdue indicator
- AND the task MUST move to the "Overdue" group in My Work

#### Scenario: Terminated or disabled tasks are not shown as overdue

- GIVEN a task "Verouderde controle" with dueDate in the past and status `terminated`
- THEN the task MUST NOT be marked as overdue
- AND the task MUST NOT appear in the overdue section of My Work

---

## Accessibility

All task management interfaces MUST comply with WCAG AA:

- Task cards MUST have sufficient color contrast for all text and indicators
- Overdue/priority indicators MUST NOT rely solely on color (use icons and text labels)
- Kanban drag-and-drop MUST have a keyboard-accessible alternative (e.g., dropdown to change status)
- Task list MUST be navigable by keyboard (Tab to move between rows, Enter to open)
- Screen readers MUST be able to identify task status, priority, and overdue state

---

## Performance

- The task list MUST load within 2 seconds for up to 100 tasks
- The kanban board MUST render within 2 seconds for up to 50 cards per column
- Drag-and-drop status changes MUST provide optimistic UI updates (move the card immediately, then confirm with the API)
- The My Work view MUST aggregate tasks and cases in a single page load (parallel API calls)
