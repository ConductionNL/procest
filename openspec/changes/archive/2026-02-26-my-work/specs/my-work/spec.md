# Delta Spec: my-work

This delta spec scopes the MVP implementation of the My Work view using CalDAV tasks via OpenRegister's convenience API.

## Scope

**In scope (MVP)**: REQ-MYWORK-001, REQ-MYWORK-002, REQ-MYWORK-003, REQ-MYWORK-004, REQ-MYWORK-005, REQ-MYWORK-006, REQ-MYWORK-007, REQ-MYWORK-009, REQ-MYWORK-010

**Deferred (V1)**: REQ-MYWORK-008 (cross-app workload with Pipelinq)

**Dependency**: OpenRegister `object-interactions` change (TaskService, TasksController API)

---

## Architecture Change: Task Data Source

**Previous assumption**: Tasks come from Procest's OpenRegister `task` schema.
**New approach**: Tasks come from **Nextcloud CalDAV VTODO** items via OpenRegister's tasks convenience API.

The OpenRegister tasks API (`GET /api/objects/{register}/{schema}/{id}/tasks`) returns JSON-friendly task objects. For My Work, we need all tasks linked to the Procest register — not per-object, but per-user across all objects.

**Task query strategy for My Work**:
1. Fetch user's assigned cases (OpenRegister case objects with `assignee == currentUser`)
2. For each case, fetch linked CalDAV tasks via OpenRegister tasks API
3. OR: Direct CalDAV query for all user's VTODOs with `X-OPENREGISTER-REGISTER` matching Procest's register ID (more efficient, single query)

For MVP, option 2 is preferred — a single API call or CalDAV query returns all Procest tasks for the user.

---

## Current State

### Existing Code

- **`src/utils/dashboardHelpers.js::getMyWorkItems()`** — Merges cases + tasks into flat sorted list, limited to N items. Currently expects OpenRegister task objects. Needs updating for CalDAV task shape.
- **`src/views/dashboard/MyWorkPreview.vue`** — Dashboard preview widget showing top 5 items. Emits `@view-all` but no route handles it.
- **`src/App.vue`** — Hash-based routing. No `my-work` route.
- **`src/navigation/MainMenu.vue`** — 3 nav items: Dashboard, Cases, Tasks. No My Work entry.

### What's Missing

1. No `MyWork.vue` full-page view
2. No grouped sections (Overdue, Due This Week, Upcoming, No Deadline)
3. No filter tabs (All/Cases/Tasks)
4. No "Show completed" toggle
5. No CalDAV task fetching — `getMyWorkItems()` expects OpenRegister objects
6. No `my-work` route in App.vue
7. No My Work navigation menu item

---

## MODIFIED Requirements

### Requirement: REQ-MYWORK-001 — Personal Workload View

**Current state**: `getMyWorkItems()` exists but uses OpenRegister task objects and is limited to 5 items.

**Change**: Create full MyWork.vue that fetches assigned cases (OpenRegister) and CalDAV tasks (via OpenRegister tasks API), displays in a unified list.

#### Scenario: Full workload with CalDAV tasks

- GIVEN the user has N assigned cases and M CalDAV tasks linked to Procest objects
- WHEN they navigate to My Work
- THEN the view MUST display all N+M items
- AND CalDAV tasks MUST show: summary, due date, priority, status, linked case reference
- AND cases MUST show: title, identifier, deadline, priority, status

---

### Requirement: REQ-MYWORK-004 — Grouped Sections

**Current state**: No grouping exists.

**Change**: Add urgency-based grouping for both cases and CalDAV tasks.

#### Scenario: CalDAV tasks grouped by due date

- GIVEN CalDAV tasks with varying DUE properties
- WHEN the user views My Work
- THEN tasks MUST be grouped using their DUE date into: OVERDUE, DUE THIS WEEK, UPCOMING, NO DEADLINE
- AND the grouping logic MUST handle both case deadlines and task DUE dates uniformly

---

### Requirement: REQ-MYWORK-006 — Show Completed Toggle

**Current state**: No toggle.

**Change**: Toggle fetches completed cases (final status) and completed CalDAV tasks (STATUS=COMPLETED).

#### Scenario: Toggle shows completed CalDAV tasks

- GIVEN the user enables "Show completed"
- THEN completed CalDAV tasks (STATUS=COMPLETED) MUST be fetched
- AND they MUST appear in a COMPLETED section at the bottom, visually muted
