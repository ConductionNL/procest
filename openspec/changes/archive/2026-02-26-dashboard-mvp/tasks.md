# Tasks: Dashboard MVP

## Implementation Tasks

### Utility Module

- [x] **T01**: Create `src/utils/dashboardHelpers.js` — Export functions: `computeKpis(openCases, completedCases, myTasks)` (returns `{ openCount, newToday, overdueCount, completedCount, avgDays, taskCount, tasksDueToday }`), `aggregateByStatus(openCases, statusTypes)` (returns `[{ name, count }]` ordered by status type `order`; aggregates same-named statuses across case types), `getOverdueCases(openCases, caseTypes)` (returns `[{ id, identifier, title, caseTypeName, daysOverdue, handler }]` sorted by daysOverdue desc; uses `isCaseOverdue` from `caseHelpers.js`), `getRecentActivity(cases, limit = 10)` (flattens all cases' `activity` arrays, adds `caseIdentifier` to each entry, sorts by date desc, returns top `limit`), `getMyWorkItems(cases, tasks, limit = 5)` (merges cases + tasks into unified items with `{ type, id, title, reference, deadline, daysText, isOverdue, priority }`, sorts by priority then deadline asc, returns top `limit`). Import `isCaseOverdue`, `getDaysRemaining`, `formatDeadlineCountdown` from `caseHelpers.js`. Today checks: `new Date().toISOString().slice(0, 10)`.

### Sub-Components

- [x] **T02**: Create `src/views/dashboard/KpiCards.vue` — Row of 4 metric cards using CSS Grid (4 columns, collapses to 2x2 on narrow). Each card: title (h3), count (large number), sub-label (small text). Props: `openCases` (Number), `newToday` (Number), `overdueCases` (Number), `completedThisMonth` (Number), `avgProcessingDays` (Number|null), `myTasks` (Number), `tasksDueToday` (Number), `loading` (Boolean). When `loading`, show `NcLoadingIcon` inside each card. Card 1: "Open Cases" / count / "+N today". Card 2: "Overdue" / count / "action needed" (red) when > 0 or "all on track" when 0. Card 3: "Completed This Month" / count / "avg N days" or "no data". Card 4: "My Tasks" / count / "N due today". Emit `@click-card(cardId)` on card click (cardId = 'open', 'overdue', 'completed', 'tasks'). Overdue card: red border when count > 0. Use `--color-warning` for overdue, `--color-success` for completed, `--color-primary` for others.

- [x] **T03**: Create `src/views/dashboard/StatusChart.vue` — Horizontal bar chart, pure CSS. Props: `statusData` (Array of `{ name, count }`), `loading` (Boolean), `error` (String|null). Title: "Cases by Status". Each bar: `div` with `width: (count / maxCount * 100)%`, minimum 20px for visibility. Status name left-aligned, count right-aligned. 6-color palette from CSS variables (`--color-primary`, `--color-primary-element-light`, `--color-warning`, `--color-success`, `--color-error`, `--color-text-maxcontrast`), cycling. Empty state: "No open cases" message. Error state: show `error` with retry button (emit `@retry`). Loading state: 4 skeleton bars.

- [x] **T04**: Create `src/views/dashboard/OverduePanel.vue` — Scrollable list (max-height 300px). Props: `cases` (Array of `{ id, identifier, title, caseTypeName, daysOverdue, handler }`), `loading` (Boolean), `error` (String|null). Title: "Overdue Cases" with count badge. Each row: identifier (bold), title (truncated), case type (muted), "N days overdue" (red text), handler name. Click row → emit `@click-case(caseId)`. Footer: "View all overdue" link → emit `@view-all`. Empty state: green checkmark + "No overdue cases" when list is empty. Severity: > 7 days overdue gets red background tint, 3-7 gets orange, 1-2 gets yellow.

- [x] **T05**: Create `src/views/dashboard/MyWorkPreview.vue` — List of top 5 items. Props: `items` (Array of `{ type, id, title, reference, deadline, daysText, isOverdue, priority }`), `loading` (Boolean), `error` (String|null). Title: "My Work". Each row: type badge ([CASE] blue / [TASK] green), title, reference/case type (muted), deadline with `daysText`, overdue indicator (red text if overdue), priority icon for high/urgent. Click row → emit `@click-item(type, id)`. Footer: "View all my work" link → emit `@view-all`. Empty state: "No items assigned to you". Loading: 5 skeleton rows.

- [x] **T06**: Create `src/views/dashboard/ActivityFeed.vue` — Reverse-chronological event list (last 10). Props: `entries` (Array of `{ date, type, description, user, caseIdentifier }`), `loading` (Boolean), `error` (String|null). Title: "Recent Activity". Each entry: type icon (same icons as ActivityTimeline — plus/arrow/pencil/clock/comment), description text, "by {user}" (muted), relative timestamp (e.g., "10 min ago", "1 hour ago", "yesterday"), case reference "#{identifier}" (muted). Footer: "View all activity" link → emit `@view-all`. Empty state: "No recent activity". Loading: 5 skeleton entries.

### Main Dashboard Rewrite

- [x] **T07**: Rewrite `src/views/Dashboard.vue` — Replace placeholder with full dashboard. (a) Import all 5 sub-components + CaseCreateDialog + `dashboardHelpers.js`. (b) Data: `loading` (Boolean), `error` (String|null), `openCases` (Array), `completedCases` (Array), `myTasks` (Array), `caseTypes` (Array), `statusTypes` (Array), `showCreateDialog` (Boolean), section-level loading/error flags for each section. (c) On `mounted()`: call `loadDashboardData()` which fires 5 parallel `fetchCollection` queries via `Promise.allSettled()`: open cases, completed cases this month, my tasks, case types, status types. (d) On resolve: compute KPIs via `computeKpis()`, status data via `aggregateByStatus()`, overdue list via `getOverdueCases()`, my work items via `getMyWorkItems()`, activity via `getRecentActivity()`. Pass computed data as props to sub-components. (e) Quick actions: "+ New Case" button top-right opens CaseCreateDialog. On `@created`: navigate to case detail. (f) Refresh: button top-right triggers `loadDashboardData()` again. (g) Empty state: when no cases AND no case types exist, show welcome message with setup guidance (admin: "Create your first case type in Settings"; non-admin: "The app needs configuration by an administrator"). (h) Layout: CSS Grid — KPI row full width, then two columns (left 60% / right 40%), responsive to single column below 768px. (i) Section error handling: each section independently shows data or error with retry.

### Navigation Integration

- [x] **T08**: Wire dashboard card clicks and panel links — In Dashboard.vue: handle `@click-card` from KpiCards (overdue → `navigateTo('cases')` with overdue filter hash, tasks → `navigateTo('tasks')`), handle `@view-all` from OverduePanel (navigate to cases with `#/cases?overdue=true`), handle `@view-all` from MyWorkPreview (navigate to `#/my-work`), handle `@click-case` from OverduePanel (navigate to `#/cases/{id}`), handle `@click-item` from MyWorkPreview (navigate to `#/cases/{id}` or `#/tasks/{id}` based on type). All navigation via `this.$emit('navigate', route, id)` matching App.vue's handler.

## Verification Tasks

- [ ] **V01**: All 7 new/modified files created and syntactically valid (1 utility + 5 components + 1 rewrite)
- [ ] **V02**: KPI cards show correct counts for open cases, overdue, completed, my tasks
- [ ] **V03**: KPI sub-labels display correctly: "+N today", "action needed", "avg N days", "N due today"
- [ ] **V04**: Status chart renders horizontal bars proportional to case counts
- [ ] **V05**: Overdue panel lists cases sorted by days overdue, with severity colors
- [ ] **V06**: My Work preview shows top 5 items sorted by priority then deadline
- [ ] **V07**: Activity feed shows last 10 events with relative timestamps
- [ ] **V08**: Empty state displays welcome message when no data exists
- [ ] **V09**: Refresh button re-fetches all dashboard data
- [ ] **V10**: Partial API failure shows section-level error without blocking other sections
- [ ] **V11**: Layout is responsive — two columns on wide, single column on narrow
- [ ] **V12**: "+ New Case" opens CaseCreateDialog
- [ ] **V13**: All tasks checked off
