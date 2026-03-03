# Design: my-work

## Context

Procest's dashboard already has a My Work preview widget (`MyWorkPreview.vue`) powered by `getMyWorkItems()` which merges cases and tasks into a flat sorted list limited to 5 items. The full My Work view needs to expand this into a complete productivity hub with urgency grouping, filter tabs, and completed item toggling.

**Key architecture change**: Tasks now come from Nextcloud CalDAV (VTODO items) via OpenRegister's tasks convenience API, not from Procest's OpenRegister `task` schema. This means the task data shape is different (CalDAV fields like `summary`, `due`, `status` vs OpenRegister fields like `title`, `dueDate`).

## Goals / Non-Goals

**Goals:**
- Create a full-page My Work view with urgency-grouped sections
- Add filter tabs (All, Cases, Tasks) with live counts
- Add "Show completed" toggle
- Wire up routing and navigation
- Fetch CalDAV tasks via OpenRegister tasks API
- Reuse existing helpers where possible

**Non-Goals:**
- Cross-app Pipelinq integration (V1, REQ-MYWORK-008)
- Server-side aggregation endpoint
- Task creation/editing from My Work (users create tasks from case detail)
- Infinite scroll (pagination sufficient for MVP)

## Decisions

### DD-01: Task Fetching Strategy

**Decision**: Fetch all user's CalDAV tasks linked to the Procest register in a single API call, rather than per-case fetching.

**Rationale**: Per-case fetching (one API call per case) would be N+1 requests. Instead, we call the OpenRegister tasks API once to get all tasks where `X-OPENREGISTER-REGISTER` matches Procest's register ID. This returns all Procest-linked tasks for the user in one response.

**API call**: `GET /apps/openregister/api/objects/{register}/tasks?assignee={currentUser}` (if a register-level endpoint exists) or fall back to fetching from CalDAV directly.

**Fallback**: If no register-level task endpoint exists in the MVP API, fetch user's cases first, then batch-fetch tasks per case. Limit to 20 most recent cases.

### DD-02: New `getGroupedMyWorkItems()` Helper

**Decision**: Add a new function to `dashboardHelpers.js` that accepts both OpenRegister case objects and CalDAV task objects (different shapes), normalizes them, and groups by urgency.

**Rationale**: `getMyWorkItems()` currently expects OpenRegister task objects with `title`, `dueDate`, etc. CalDAV tasks have `summary`, `due`, `status` (NEEDS-ACTION/IN-PROCESS/COMPLETED/CANCELLED). The new function handles both shapes.

**Normalization**: Map CalDAV fields to the existing work item shape:
- `summary` → `title`
- `due` → `deadline`
- CalDAV `priority` (1-9, iCalendar scale) → app priority (urgent/high/normal/low)
- CalDAV `status` (needs-action/in-process) → "active" tasks
- CalDAV `status` (completed) → completed tasks (for toggle)

**Signature**:
```javascript
getGroupedMyWorkItems(cases, calDavTasks) → {
  overdue: WorkItem[],
  dueThisWeek: WorkItem[],
  upcoming: WorkItem[],
  noDeadline: WorkItem[],
  totalCount: number
}
```

### DD-03: Client-Side Filtering via Tabs

**Decision**: Fetch all cases + tasks upfront, merge client-side, then filter by tab selection.

**Rationale**: Total item count per user is typically under 50. Tab filtering is instant on the loaded data.

### DD-04: Show Completed Toggle

**Decision**: When toggled, make additional fetch for completed cases (final status) and completed CalDAV tasks (STATUS=COMPLETED). Append to a "COMPLETED" section.

**Rationale**: Completed items excluded from default queries. Supplemental requests only when toggle is on.

### DD-05: Single Component + Task API Wrapper

**Decision**: `MyWork.vue` is a single component. A thin `src/services/taskApi.js` module wraps the OpenRegister tasks API calls.

**Rationale**: Separating the API calls from the view keeps MyWork.vue focused on display logic. The taskApi module handles the fetch + CalDAV-to-app-format normalization.

### DD-06: Route and Navigation Placement

**Decision**: Add `my-work` route to App.vue. Nav item between Dashboard and Cases with `AccountCheck` icon.

## File Map

### New Files

| File | Purpose |
|------|---------|
| `src/views/MyWork.vue` | Full "My Work" view with grouped sections, filter tabs, completed toggle |
| `src/services/taskApi.js` | Thin wrapper around OpenRegister tasks API + CalDAV field normalization |

### Modified Files

| File | Changes |
|------|---------|
| `src/utils/dashboardHelpers.js` | Add `getGroupedMyWorkItems()` function accepting normalized items |
| `src/App.vue` | Add `my-work` route, import MyWork component |
| `src/navigation/MainMenu.vue` | Add "My Work" nav item between Dashboard and Cases |

## Risks / Trade-offs

- **[Dependency] OpenRegister object-interactions** — Must be deployed first. If not available, My Work can fall back to showing only cases (no tasks). The view should handle missing task API gracefully.
- **[Risk] CalDAV priority mapping** — iCalendar priority (1=highest, 9=lowest) differs from Procest's (urgent/high/normal/low). Mapping: 1-3→urgent, 4→high, 5-6→normal, 7-9→low, 0→normal.
- **[Trade-off] Per-user tasks only** — CalDAV tasks are in the user's personal calendar. A task created by user A on a case is not visible to user B in My Work. This matches Nextcloud Tasks behavior.
- **[Trade-off] Client-side grouping** — Grouping by week boundary depends on local timezone. Use Sunday as end of week.
