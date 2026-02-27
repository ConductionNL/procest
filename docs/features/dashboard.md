# Dashboard

Landing page providing an at-a-glance overview of case activity and personal workload with KPI cards, charts, and quick actions.

## Specs

- `openspec/specs/dashboard/spec.md`

## Features

### KPI Cards (MVP)

Top row of metric cards showing key case management numbers:

- Open Cases (count of non-terminal cases)
- Overdue Cases (cases past their deadline)
- Completed This Month (cases closed in current month)
- My Tasks (tasks assigned to current user)

### Cases by Status Chart (MVP)

Visual chart showing the distribution of cases across status values, providing a quick overview of workflow bottleneck areas.

### Overdue Cases Panel (MVP)

Dedicated panel listing cases past their processing deadline, sorted by urgency. Direct click-through to case detail.

### My Work Preview (MVP)

Compact preview of the user's top 5 assigned items (cases + tasks), linking to the full My Work view.

### Quick Actions (MVP)

Shortcut buttons for common operations — primarily "+ New Case" for fast case creation.

### Dashboard Data Scope (MVP)

All dashboard data respects RBAC permissions. Users only see metrics and cases they have access to.

### Empty State (MVP)

Fresh installations show a welcoming empty state with getting-started guidance instead of empty charts and zero-count cards.

### Dashboard Refresh (MVP)

Data refreshes on mount and supports manual refresh for up-to-date metrics.

### Dashboard Layout (MVP)

Responsive grid layout adapting to screen size, with KPI cards on top, charts in the middle, and activity/work previews below.

### Planned (V1)

- Cases by type chart (distribution across case types)
- Recent activity feed (last 10 case events)
- Average processing time KPI per case type

### Planned (Enterprise)

- Custom dashboards
- SLA compliance meter
- Handler workload heatmap
- Trend analysis (case volume over time)
