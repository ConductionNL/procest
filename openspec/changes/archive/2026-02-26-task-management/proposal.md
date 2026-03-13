# Proposal: task-management

## Summary

Implement the MVP task management feature for Procest, enabling users to create, assign, track, and complete tasks within cases. Tasks follow the CMMN 1.1 HumanTask lifecycle and are stored as OpenRegister objects. This is a core building block — cases without tasks have no way to distribute and track work.

## Motivation

The current Procest app has a basic stub for tasks in the case detail page (a simple table and inline form) but lacks proper task CRUD, status lifecycle enforcement, a dedicated task list view, filtering, sorting, overdue handling, and priority management. Without a functional task system, case handlers cannot break work into trackable units, assign tasks to colleagues, or monitor progress and deadlines.

## Affected Projects

- [x] Project: `procest` — New task views (list + detail), Pinia store actions for task-specific logic, enhanced case detail task section, navigation updates

## Scope

### In Scope

- Task CRUD via OpenRegister API (create, read, update, delete)
- CMMN PlanItem status lifecycle (available → active → completed/terminated, available → disabled)
- Task assignment to Nextcloud users
- Global task list view with search, filtering (status, assignee, priority), and sorting
- Case-scoped task list in case detail with completion progress (e.g., "3/5")
- Task due dates with overdue highlighting (red for overdue, amber for due today)
- Priority levels (urgent, high, normal, low) with visual badges
- Task detail/edit view
- Navigation menu item for Tasks
- Frontend validation (title required, case required)

### Out of Scope

- Kanban board view (V1 — REQ-TASK-007)
- Task checklists/sub-items (V1 — REQ-TASK-009)
- Task dependencies (V1 — REQ-TASK-010)
- Task templates per case type (V1 — REQ-TASK-011)
- Automated task creation on status change (Enterprise — REQ-TASK-012)
- Nextcloud notifications on assignment (deferred — requires backend work)
- User existence validation on assignment (deferred — requires backend endpoint)

## Approach

This is a **frontend-only** change. All data operations go through the existing OpenRegister API via the generic `useObjectStore`. The `task` object type is already registered in `store.js`. The implementation adds:

1. A dedicated `TaskList.vue` view with filtering/sorting
2. A `TaskDetail.vue` view for creating/editing tasks
3. Enhanced task section in `CaseDetail.vue` with progress tracking
4. Task-specific helper functions for status lifecycle validation, overdue calculation, and priority sorting
5. Navigation menu entry for "Tasks"
6. Updated hash-based routing in `App.vue`

No new backend endpoints or database changes required — OpenRegister handles all persistence.

## Capabilities

### New Capabilities

- None (task-management spec already exists)

### Modified Capabilities

- `task-management` — Implementing MVP requirements (REQ-TASK-001 through REQ-TASK-006, REQ-TASK-008, REQ-TASK-013) from the existing spec. No requirement changes, only implementation.

## Cross-Project Dependencies

- **OpenRegister**: Task objects stored in the `procest` register under the `task` schema. The schema must be registered (handled by `InitializeSettings` repair step). No OpenRegister changes needed.

## Rollback Strategy

- All changes are frontend-only Vue components and Pinia store helpers
- Revert the git commits to restore previous stub behavior
- No data migration needed — task objects created during use remain in OpenRegister regardless

## Open Questions

- None — the spec is comprehensive and the architecture is straightforward
