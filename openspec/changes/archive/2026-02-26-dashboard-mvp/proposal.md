# Proposal: dashboard-mvp

## Summary

Implement the MVP tier of the Procest dashboard — the landing page providing at-a-glance case management metrics, overdue alerts, personal workload preview, recent activity, and quick actions. Replaces the current placeholder Dashboard.vue with a full data-driven dashboard.

## Motivation

The current dashboard is a static placeholder with a welcome message and a button to navigate to cases. Users have no visibility into their case management workload without clicking through to the case list. The dashboard spec (REQ-DASH-001 through REQ-DASH-013) defines a comprehensive overview page with KPI cards, status distribution chart, overdue panel, my work preview, activity feed, and quick actions. Implementing the MVP tier gives case handlers immediate situational awareness on login.

## Affected Projects

- [ ] Project: `procest` — Rewrite Dashboard.vue with KPI cards, chart, overdue panel, my work preview, activity feed, quick actions; add dashboard helper utilities

## Scope

### In Scope (MVP)

- **KPI Cards** (REQ-DASH-001): Open Cases, Overdue, Completed This Month (with avg processing days), My Tasks — all with sub-labels
- **Cases by Status Chart** (REQ-DASH-002): Horizontal bar chart showing case count per status type
- **Overdue Cases Panel** (REQ-DASH-004): List of overdue cases with days overdue, severity indicators, "View all" link
- **My Work Preview** (REQ-DASH-005): Top 5 items (cases + tasks) sorted by priority/deadline, link to full My Work view
- **Recent Activity Feed** (REQ-DASH-006): Last 10 activity events from case activity arrays
- **Quick Actions** (REQ-DASH-007): "+ New Case" button opening CaseCreateDialog
- **Data Scope / RBAC** (REQ-DASH-008): All metrics respect user's visible cases
- **Empty State** (REQ-DASH-009): Welcome message for fresh installs, guidance for no-access users
- **Refresh** (REQ-DASH-010): Load on mount, manual refresh button, loading skeletons, error handling
- **Error Handling** (REQ-DASH-012): Graceful partial failures, deleted case type fallback
- **Layout** (REQ-DASH-013): KPI row + two-column layout, responsive single column on narrow viewports

### Out of Scope (V1)

- Cases by Type chart (REQ-DASH-003)
- Average Processing Time as separate KPI (REQ-DASH-011) — basic avg is included in "Completed This Month" sub-label
- Auto-refresh on timer interval
- Dashboard widget for Nextcloud Dashboard app

## Approach

Frontend-only implementation. Rewrite the existing `Dashboard.vue` from a placeholder into a full component that fetches data via `useObjectStore`. New utility module `dashboardHelpers.js` for KPI calculations, date grouping, and data aggregation. The horizontal bar chart uses pure CSS (no charting library) — proportional-width divs with labels.

Key decisions:
1. **No charting library** — CSS bar chart keeps bundle small and avoids a new dependency
2. **Activity from case objects** — Aggregate `activity` arrays from recent cases (no Nextcloud Activity API for MVP)
3. **Parallel data fetching** — KPI queries, overdue list, my work items, and activity all fetched concurrently on mount
4. **Loading skeletons** — Use Nextcloud's `NcLoadingIcon` or shimmer placeholders per section

## Cross-Project Dependencies

- **case-management** (in progress) — Dashboard consumes case data with case type integration, status history, activity arrays, deadlines. The dashboard can be implemented in parallel but will need case-management's enhanced data model to be meaningful.
- **task-management** (archived) — My Tasks KPI and My Work preview query tasks
- **OpenRegister** — All data queries via `fetchCollection`

## Rollback Strategy

All changes are frontend-only (Dashboard.vue rewrite + new utility file). Rollback by reverting to the placeholder Dashboard.vue. No database migrations or schema changes.

## Open Questions

None — the spec is comprehensive and the data model is established by the case-management and task-management changes.
