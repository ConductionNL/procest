# Design: dashboard-mvp

## Architecture Overview

This change rewrites the placeholder `Dashboard.vue` into a full dashboard with data-driven sections. All data flows through the existing `useObjectStore` Pinia store and OpenRegister API. No new backend code is needed — the dashboard is entirely frontend.

```
Dashboard.vue
├── KpiCards.vue (4 metric cards in a flex row)
├── StatusChart.vue (horizontal CSS bar chart)
├── OverduePanel.vue (list of overdue cases)
├── MyWorkPreview.vue (top 5 items: cases + tasks)
├── ActivityFeed.vue (last 10 events from case activity arrays)
└── CaseCreateDialog.vue (already exists from case-management)
```

## File Map

### New Files

| File | Purpose |
|------|---------|
| `src/utils/dashboardHelpers.js` | KPI calculation, activity aggregation, overdue sorting, status aggregation |
| `src/views/dashboard/KpiCards.vue` | Row of 4 KPI metric cards with counts and sub-labels |
| `src/views/dashboard/StatusChart.vue` | Horizontal bar chart of cases by status (pure CSS) |
| `src/views/dashboard/OverduePanel.vue` | Scrollable list of overdue cases with severity |
| `src/views/dashboard/MyWorkPreview.vue` | Top 5 most urgent assigned items |
| `src/views/dashboard/ActivityFeed.vue` | Last 10 activity entries across all visible cases |

### Modified Files

| File | Changes |
|------|---------|
| `src/views/Dashboard.vue` | Complete rewrite: fetch data on mount, compose sub-components, loading/error/empty states, refresh button, quick actions |
| `src/App.vue` | No changes needed — `Dashboard` component already referenced, default route is `dashboard` |

### Unchanged Files

| File | Reason |
|------|--------|
| `src/store/modules/object.js` | Generic CRUD store already supports `fetchCollection` with filters |
| `src/store/store.js` | Object types (case, task, caseType, statusType) already registered |
| `src/views/cases/CaseCreateDialog.vue` | Already exists from case-management change, reused as-is |

## Design Decisions

### DD-01: CSS Bar Chart (No Charting Library)

**Decision**: Implement the status distribution chart using pure CSS — `div` bars with proportional `width` percentages.

**Rationale**: Adding Chart.js or similar is ~200KB gzipped and overkill for a single horizontal bar chart. The chart only needs labeled bars proportional to counts. CSS flex/grid achieves this with zero dependencies.

**Implementation**: Each bar is a `div` with `width: (count / maxCount * 100)%`, a background color from a 6-color palette, and labels showing status name (left) and count (right).

### DD-02: Activity Aggregation from Case Objects

**Decision**: Aggregate activity entries by iterating over all visible cases' `activity` arrays, merge into a single list, sort by date descending, take the top 10.

**Rationale**: MVP stores activity as embedded arrays on case objects (design decision DD-03 from case-management). No separate activity API exists. Fetching all cases (which are already loaded for KPIs) and extracting their activity arrays is efficient for MVP scale (< 1000 cases).

**Trade-off**: For large installations, this means loading all case objects to get activity. Acceptable for MVP; V1 can add a dedicated activity query endpoint.

### DD-03: Parallel Data Fetching

**Decision**: On mount, fire all data queries concurrently using `Promise.allSettled()`:
1. Fetch all cases (open + completed) in a single query, split client-side using statusType `isFinal`
2. Fetch case types for name resolution
3. Fetch status types for status name resolution, chart labels, and open/completed splitting
4. Fetch tasks assigned to current user for "My Tasks" KPI and My Work

**Rationale**: Sequential fetching would multiply the load time. `Promise.allSettled` ensures partial failures don't block the entire dashboard (REQ-DASH-012). Fetching all cases in one query (instead of separate open/completed queries) reduces API calls from 5 to 4 while keeping the same data available.

**Implementation**: Dashboard.vue calls a `loadDashboardData()` method that awaits `Promise.allSettled([fetchCases(), fetchCaseTypes(), fetchStatusTypes(), fetchTasks()])`. Cases are split into open vs completed client-side by checking each case's status against the statusType `isFinal` flag. Each section checks its own loading/error state independently.

### DD-04: Section-Level Loading and Error States

**Decision**: Each dashboard section (KPIs, chart, overdue, my work, activity) has independent loading/error state.

**Rationale**: REQ-DASH-012 requires that "the system MUST NOT block the entire dashboard due to a single section failure." If activity loading fails, KPI cards and overdue panel still show.

**Implementation**: Each section component receives `loading` and `error` props. When `loading`, show `NcLoadingIcon` or skeleton. When `error`, show inline error message with retry callback.

### DD-05: KPI Sub-Label Calculations

**Decision**: Calculate sub-labels client-side from fetched data:
- Open Cases: count cases with `startDate == today` → "+N today"
- Overdue: static text "action needed" when count > 0
- Completed: average `(endDate - startDate)` in days → "avg N days"
- My Tasks: count tasks with `dueDate == today` → "N due today"

**Rationale**: All data needed for sub-labels is already fetched for the main counts. No additional API calls.

### DD-06: My Work Preview Reuses My Work Sorting Logic

**Decision**: Import sorting/grouping utilities from the existing `taskHelpers.js` and new `caseHelpers.js` to sort the top-5 items by priority then deadline.

**Rationale**: The My Work preview (REQ-DASH-005) uses the same sort order as the full My Work view. Reusing the helpers ensures consistency.

### DD-07: Overdue Panel Shows All (Scrollable)

**Decision**: Show all overdue cases in a scrollable panel with `max-height: 300px` and `overflow-y: auto`, rather than limiting to N items.

**Rationale**: REQ-DASH-004 says "show all overdue cases (or a scrollable list if many)." The "View all overdue" link navigates to case list filtered by overdue. The panel itself is a scrollable summary.

## Component Props

### KpiCards.vue
```
Props:
  openCases: Number
  newToday: Number
  overdueCases: Number
  completedThisMonth: Number
  avgProcessingDays: Number | null
  myTasks: Number
  tasksDueToday: Number
  loading: Boolean
Events:
  @click-card(cardId: String) — navigates to filtered view
```

### StatusChart.vue
```
Props:
  statusData: Array<{ name: String, count: Number }>
  loading: Boolean
  error: String | null
```

### OverduePanel.vue
```
Props:
  cases: Array<{ identifier, title, caseTypeName, daysOverdue, handler }>
  loading: Boolean
  error: String | null
Events:
  @view-all — navigates to cases filtered by overdue
  @click-case(caseId) — navigates to case detail
```

### MyWorkPreview.vue
```
Props:
  items: Array<{ type: 'case'|'task', title, reference, deadline, daysText, isOverdue, priority }>
  loading: Boolean
  error: String | null
Events:
  @view-all — navigates to My Work view
  @click-item(type, id) — navigates to detail view
```

### ActivityFeed.vue
```
Props:
  entries: Array<{ date, type, description, user, caseIdentifier }>
  loading: Boolean
  error: String | null
Events:
  @view-all — navigates to activity view
```

## Data Flow

### Dashboard Mount Sequence
1. Dashboard.vue `mounted()` calls `loadDashboardData()`
2. Fire 4 parallel queries via `Promise.allSettled()`:
   - `fetchCollection('case', { '_limit': 1000 })` — all cases (split into open/completed client-side)
   - `fetchCollection('caseType', { '_limit': 100 })` — for case type name resolution
   - `fetchCollection('statusType', { '_limit': 500 })` — for status name resolution, chart, and open/completed splitting
   - `fetchCollection('task', { '_filters[assignee]': currentUser, '_limit': 100 })` — my tasks
3. Split cases into open vs completed by checking each case's status against statusType `isFinal` flag
4. On each resolved: compute derived data, pass to sub-components
5. On rejection: set section-level error, show retry

### KPI Computation (in dashboardHelpers.js)
```javascript
computeKpis(openCases, completedCases, myTasks) → {
  openCount, newToday,
  overdueCount,
  completedCount, avgDays,
  taskCount, tasksDueToday
}
```

### Status Aggregation (in dashboardHelpers.js)
```javascript
aggregateByStatus(openCases, statusTypes) → [{ name, count }]
// Groups by status name across case types, orders by status type order
```

### Overdue Extraction (in dashboardHelpers.js)
```javascript
getOverdueCases(openCases, caseTypes) → [{ identifier, title, caseTypeName, daysOverdue, handler }]
// Filters deadline < today, sorts by daysOverdue desc
```

### Activity Aggregation (in dashboardHelpers.js)
```javascript
getRecentActivity(cases, limit = 10) → [{ date, type, description, user, caseIdentifier }]
// Flattens all cases' activity arrays, sorts by date desc, takes top N
```

## Layout Structure

```
┌──────────────────────────────────────────────────────────┐
│  [+ New Case]                                [Refresh]   │
├──────────┬──────────┬──────────┬──────────────────────────┤
│ Open (24)│Overdue(3)│Done (12) │ My Tasks (7)            │
│ +3 today │ action!  │ avg 18d  │ 2 due today             │
├──────────┴──────────┴──────────┴──────────────────────────┤
│                    │                                      │
│  Cases by Status   │  Overdue Cases                       │
│  ██████████ 8      │  #042 Bouwverg.. 5 days overdue      │
│  ███████ 6         │  #038 Subsidie.. 2 days overdue      │
│  █████ 5           │  [View all overdue →]                │
│  ███ 3             │                                      │
│                    ├──────────────────────────────────────│
│  My Work (top 5)   │  Recent Activity                     │
│  [CASE] #042...    │  Status changed on #042 - 10m ago    │
│  [TASK] Review..   │  Decision on #036 - 1h ago           │
│  [View all →]      │  Task completed - 2h ago             │
│                    │  [View all activity →]               │
└────────────────────┴──────────────────────────────────────┘
```

Responsive: below 768px, two columns collapse to single column with all sections stacked vertically.

## Security Considerations

- All data queries go through OpenRegister which enforces RBAC — no additional auth needed
- No new API endpoints — frontend-only
- No CSRF concerns — read-only data display

## NL Design System

- KPI cards: Use Nextcloud `NcNoteCard` or custom card styling with `--color-primary`, `--color-warning`, `--color-success` CSS variables
- Text: Use `--font-face` and Nextcloud typography classes for consistent sizing
- Colors: All chart colors from CSS custom properties, no hardcoded hex values
- Responsive: Use CSS Grid with `grid-template-columns: 3fr 2fr` collapsing to `1fr` via media query (left column wider for charts and work preview)

## Trade-offs

1. **No charting library** → Simple CSS bars. Pro: zero dependency. Con: no animations, no hover tooltips. Acceptable for MVP.
2. **Activity from case objects** → Must load all cases. Pro: no new API. Con: scales poorly past ~1000 cases. V1 can add dedicated endpoint.
3. **Client-side KPI computation** → All aggregation in JavaScript. Pro: no backend changes. Con: CPU cost for large datasets. Acceptable for MVP volume.
