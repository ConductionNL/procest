# Design: task-management

## Architecture Overview

This is a **frontend-only** change. Tasks are already modeled in OpenRegister (the `task` schema exists and the object type is registered in the Pinia store). The implementation adds Vue components for task management and helper utilities for lifecycle enforcement and date calculations.

```
┌──────────────────────────────────────────────────┐
│  New / Modified Vue Components                    │
│                                                   │
│  TaskList.vue      — Global task list with filters│
│  TaskDetail.vue    — Task create/edit/view form   │
│  CaseDetail.vue    — Enhanced tasks section       │
│  MainMenu.vue      — "Tasks" nav item             │
│  App.vue           — Route: #/tasks, #/tasks/:id  │
└──────────────┬───────────────────────────────────┘
               │
┌──────────────▼───────────────────────────────────┐
│  Helpers (src/utils/)                             │
│                                                   │
│  taskLifecycle.js  — Status transitions, allowed  │
│                      actions, validation           │
│  taskHelpers.js    — Overdue calc, priority sort, │
│                      date formatting               │
└──────────────┬───────────────────────────────────┘
               │
┌──────────────▼───────────────────────────────────┐
│  useObjectStore (existing — no changes)           │
│  fetchCollection / fetchObject / saveObject /     │
│  deleteObject — all CRUD via OpenRegister API     │
└──────────────────────────────────────────────────┘
```

## API Design

No new backend API endpoints. All operations use the existing OpenRegister API:

### `GET /apps/openregister/api/objects/procest/task`

**Query Parameters:**
- `_limit` — Page size (default 20)
- `_offset` — Pagination offset
- `_search` — Full-text search on title
- `_order` — Sort order, e.g. `{"dueDate":"asc"}`
- `_filters[status]` — Filter by status
- `_filters[assignee]` — Filter by assignee UID
- `_filters[case]` — Filter by parent case UUID
- `_filters[priority]` — Filter by priority

**Response:**
```json
{
  "results": [
    {
      "id": "uuid",
      "title": "Controleer bouwtekeningen",
      "description": "",
      "status": "available",
      "assignee": "jan.devries",
      "case": "case-uuid",
      "dueDate": "2026-03-01T17:00:00Z",
      "priority": "normal",
      "completedDate": null
    }
  ],
  "total": 23,
  "page": 1,
  "pages": 2
}
```

### `POST /apps/openregister/api/objects/procest/task`

**Request:**
```json
{
  "title": "Controleer bouwtekeningen",
  "case": "case-uuid",
  "status": "available",
  "priority": "normal",
  "assignee": "jan.devries",
  "dueDate": "2026-03-01T17:00:00Z"
}
```

### `PUT /apps/openregister/api/objects/procest/task/{id}`

Same body as POST, with `id` in URL path.

### `DELETE /apps/openregister/api/objects/procest/task/{id}`

No body. Returns 200 on success.

## Database Changes

None — OpenRegister handles all storage. The `task` schema is already defined in the repair step.

## Nextcloud Integration

- **Components used**: `NcAppNavigation`, `NcAppNavigationItem`, `NcContent`, `NcAppContent`, `NcButton`, `NcTextField`, `NcSelect`, `NcLoadingIcon`, `NcEmptyContent`, `NcNoteCard` (from `@nextcloud/vue`)
- **Icons**: Material Design Icons via `vue-material-design-icons` (ClipboardCheck, CalendarAlert, etc.)
- **No backend controllers** — all frontend
- **No events/hooks** — no server-side logic

## File Structure

```
src/
  utils/
    taskLifecycle.js          (NEW) — Status transition map, getAllowedTransitions(), validateTransition()
    taskHelpers.js            (NEW) — isOverdue(), getOverdueText(), prioritySortWeight(), formatDueDate()
  views/
    tasks/
      TaskList.vue            (NEW) — Global task list with filters/search/sort
      TaskDetail.vue          (NEW) — Task create/edit/view form with lifecycle actions
    cases/
      CaseDetail.vue          (MODIFIED) — Enhanced task section with progress, better sorting, links to task detail
  navigation/
    MainMenu.vue              (MODIFIED) — Add "Tasks" navigation item
  App.vue                     (MODIFIED) — Add routes for #/tasks and #/tasks/:id
```

## Decisions

### 1. Utility functions vs. Pinia store extension

**Decision**: Separate utility files (`taskLifecycle.js`, `taskHelpers.js`) rather than extending the generic `useObjectStore`.

**Rationale**: The object store is intentionally generic (works for any OpenRegister object type). Task-specific logic like lifecycle validation and overdue calculation belongs in utility functions that components import directly. This keeps the store clean and reusable.

**Alternative considered**: A dedicated `useTaskStore` wrapping `useObjectStore` — rejected because it adds a layer of indirection for simple utility functions.

### 2. Client-side filtering vs. API filtering

**Decision**: Use OpenRegister API `_filters` parameter for all filtering and sorting.

**Rationale**: Server-side filtering is more scalable and consistent with pagination. Client-side filtering would require fetching all tasks upfront, which doesn't scale.

### 3. Status lifecycle enforcement location

**Decision**: Enforce lifecycle transitions in the frontend (disable invalid action buttons) rather than relying on backend validation alone.

**Rationale**: Better UX — users never see confusing error messages from the API. The lifecycle rules are simple and deterministic. The backend schema validation provides a safety net if the frontend is bypassed.

### 4. Hash routing for tasks

**Decision**: Add `#/tasks` and `#/tasks/:id` routes to the existing hash-based router in `App.vue`.

**Rationale**: Consistent with existing pattern (`#/cases`, `#/cases/:id`). No need for vue-router — the app is simple enough for hash-based routing.

## Security Considerations

- All API calls include `requesttoken` (CSRF protection) via existing `_getHeaders()`
- `OCS-APIREQUEST` header is set, ensuring Nextcloud middleware is active
- No user-generated HTML is rendered (XSS safe)
- OpenRegister handles RBAC — frontend doesn't need to implement access control

## NL Design System

- Uses standard Nextcloud Vue components which inherit NL Design System tokens when the `nldesign` app is active
- Priority badges use CSS variables (no hardcoded colors)
- Overdue indicators use semantic color variables (`--color-error`, `--color-warning`)
- All interactive elements have visible focus states (via Nextcloud component defaults)

## Trade-offs

- **No Nextcloud notifications on task assignment**: Would require a backend controller endpoint. Deferred to a future change to keep this frontend-only.
- **No user existence validation**: Assignee field accepts any string. Validating against Nextcloud users requires a backend API call. Accepted for MVP — users typically know their colleagues' UIDs.
- **No real-time updates**: If another user modifies a task, the current user won't see it until they refresh or navigate. Acceptable for MVP.
