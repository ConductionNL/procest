# Task Management

Work items within cases following CMMN 1.1 HumanTask concepts. Tasks are the primary mechanism for distributing and tracking work within a case.

## Specs

- `openspec/specs/task-management/spec.md`

## Features

### Task CRUD (MVP)

Full create, read, update, and delete for tasks linked to cases.

- Fields: title, description, status, assignedTo, dueDate, priority, case (parent case reference)
- Tasks inherit context from their parent case

### Task Status Lifecycle (MVP)

Tasks follow CMMN PlanItem states:

- `available` → `active` → `completed` / `terminated`
- Status transitions are recorded for audit

### Task Assignment (MVP)

Tasks can be assigned to Nextcloud users. Assigned tasks appear in the user's My Work view.

### Task List View (MVP)

Browsable task list with search, sort, and filter:

- Search across title and description
- Filter by status, assignee, priority, parent case
- Sort by due date, priority, status

### Task Due Dates and Priorities (MVP)

- Four priority levels: low, normal, high, urgent
- Due date tracking with overdue highlighting
- Priority + due date drive sorting in My Work view

### Task Card Display (MVP)

Compact card layout showing task title, status badge, priority indicator, due date, and assignee. Used in both list views and case detail task sections.

### Task Completion (MVP)

Completing a task transitions it to `completed` status and updates the parent case's task progress.

### Overdue Task Management (MVP)

Tasks past their due date are visually highlighted with red indicators. Overdue tasks surface prominently in My Work and dashboard views.

### Planned (V1)

- Kanban board view with drag-and-drop between statuses
- Task checklist/sub-items
- Task dependencies (blocked by)
- Task templates per case type

### Planned (Enterprise)

- Automated task creation on case status change
- Workload dashboard (tasks per user)
