# My Work (Werkvoorraad)

Personal productivity hub aggregating all work items assigned to the current user — cases where they are the handler plus tasks assigned to them — into a single prioritized view. Answers: "What do I need to work on next?"

## Specs

- `openspec/specs/my-work/spec.md`

## Features

### Personal Workload View (MVP)

Unified list of cases (where user is handler) and tasks (assigned to user), combining both entity types into a single sorted view.

- Item cards showing title, type badge (case/task), priority, and deadline/due date
- Click-through navigation to case or task detail views

### Filter Tabs (MVP)

Filter tabs to focus on specific entity types:

- **All**: Cases + tasks combined
- **Cases**: Only cases where user is handler
- **Tasks**: Only tasks assigned to user

### Temporal Grouping (MVP)

Items organized into urgency-based groups:

- **Overdue**: Past deadline/due date, highlighted with red indicators
- **Due This Week**: Due within the current week
- **Upcoming**: Due in the future beyond this week
- **No Deadline**: Items without a deadline/due date

### Sorting (MVP)

Items sorted by priority first (urgent → high → normal → low), then by deadline/due date within each priority level.

### Overdue Highlighting (MVP)

Items past their deadline are visually distinct with red color indicators and overdue badges.

### Default Filter (MVP)

By default, only non-final items are shown (excludes completed/terminated cases and tasks). Users can toggle to include completed items.

### Item Navigation (MVP)

Clicking any item navigates to its detail view (case detail or task detail) for full context and actions.

### Empty State (MVP)

When no items are assigned, shows a friendly empty state indicating no current work items.

### Planned (V1)

- Cross-app workload: include Pipelinq leads/requests alongside Procest items
- Concurrent state change handling (real-time updates)

### Planned (Enterprise)

- Workload analytics (items per user for management visibility)
