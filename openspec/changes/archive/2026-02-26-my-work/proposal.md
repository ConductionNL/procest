# Proposal: my-work

## Why

The Procest dashboard already has a `MyWorkPreview` widget showing the top 5 assigned items, powered by `getMyWorkItems()` in dashboardHelpers.js. However, there is no dedicated full-page "My Work" view. The dashboard widget emits a `@view-all` event that navigates to `'my-work'`, but no such route or view exists — clicking "View all my work" does nothing.

Case handlers need a personal productivity hub that shows *all* their assigned cases and tasks, grouped by urgency, with the ability to filter by entity type and navigate to any item. This is the daily "what should I work on next?" view.

## Architecture Change: CalDAV Tasks

**Important**: This change uses **Nextcloud CalDAV tasks** instead of Procest's OpenRegister task objects. OpenRegister provides a convenience API (`/api/objects/{register}/{schema}/{id}/tasks`) that wraps CalDAV VTODO items with `X-OPENREGISTER-*` properties. Procest's `task` schema in OpenRegister is no longer the source for My Work tasks — CalDAV is.

**Dependency**: Requires OpenRegister's `object-interactions` change to be implemented first (TaskService, TasksController, NoteService, NotesController).

My Work fetches:
- **Cases**: OpenRegister API (as before) — `assignee == currentUser`, non-final status
- **Tasks**: OpenRegister Tasks API — `GET /api/objects/{register}/{schema}/{id}/tasks` for each user case, OR query CalDAV directly for all VTODOs with `X-OPENREGISTER-REGISTER` matching Procest's register ID

## What Changes

- **Create `src/views/MyWork.vue`** — Full "My Work" view with grouped sections (Overdue, Due This Week, Upcoming, No Deadline), filter tabs (All/Cases/Tasks with counts), overdue highlighting, item navigation, empty state, and a "Show completed" toggle
- **Extend `src/utils/dashboardHelpers.js`** — Add a `getGroupedMyWorkItems()` function that returns items organized into urgency groups instead of a flat limited list
- **Add `src/services/taskApi.js`** — Thin wrapper around the OpenRegister tasks convenience API for fetching user's CalDAV tasks
- **Update `src/App.vue`** — Add `'my-work'` route mapping to the MyWork component
- **Update `src/navigation/MainMenu.vue`** — Add "My Work" navigation item between Dashboard and Cases

## Capabilities

### Modified Capabilities

- **my-work** — All MVP requirements (REQ-MYWORK-001 through REQ-MYWORK-007, REQ-MYWORK-009, REQ-MYWORK-010). REQ-MYWORK-008 (cross-app workload with Pipelinq, V1) is deferred.

## Impact

- **Frontend only**: No Procest backend changes — uses OpenRegister's task and case APIs
- **Reuses existing infrastructure**: `getMyWorkItems()`, `formatDeadlineCountdown()`, `isCaseOverdue()`, `prioritySortWeight()` all exist
- **Navigation**: Adds a 4th nav item; Dashboard's "View all my work" link will now work
- **Performance**: Cases from OpenRegister API + tasks from CalDAV via OpenRegister tasks API, client-side merge and grouping
- **Dependency**: OpenRegister `object-interactions` must be deployed first
