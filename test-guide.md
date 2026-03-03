# Procest — Test Guide

> **Agentic testing (experimental)**: This guide is used by automated browser testing agents. Results are approximate and should be verified manually for critical findings.

## App Access

- **App URL**: `http://localhost:8080/index.php/apps/procest`
- **Admin Settings**: `http://localhost:8080/settings/admin/procest`
- **Login**: admin / admin

## What to Test

Read the feature documentation for the complete feature map:

- **Feature index**: [docs/features/README.md](docs/features/README.md) — lists all feature groups with links
- **Feature docs**: [docs/features/](docs/features/) — detailed description of each feature group

### Feature Groups

| Feature Group | Doc File | Key Pages to Visit |
|---------------|----------|-------------------|
| Dashboard | [dashboard.md](docs/features/dashboard.md) | `/#/dashboard` — KPI cards, status chart, activity feed |
| My Work | [my-work.md](docs/features/my-work.md) | `/#/my-work` — assigned cases/tasks grouped by deadline |
| Case Management | [case-management.md](docs/features/case-management.md) | `/#/cases` — list, filters, detail view, status changes |
| Task Management | [task-management.md](docs/features/task-management.md) | `/#/tasks` — list, detail view, linked cases |
| Case Types | [case-types.md](docs/features/case-types.md) | Admin settings — create/edit case types with statuses |
| Roles & Decisions | [roles-decisions.md](docs/features/roles-decisions.md) | Case detail — participants, results, decisions sections |
| Administration | [administration.md](docs/features/administration.md) | `/settings/admin/procest` — schema config, case type mgmt |

### Navigation Structure

The app uses hash-based routing. The sidebar has these menu items:
1. **Dashboard** → `/#/dashboard`
2. **My Work** → `/#/my-work`
3. **Cases** → `/#/cases` (detail: `/#/cases/{id}`)
4. **Tasks** → `/#/tasks` (detail: `/#/tasks/{id}`)
5. **Documentation** → opens external link (procest.app)

### Key Interactions to Test

- **New Case**: Click "New case" button on Cases page → dialog opens → fill form → submit
- **Case Detail**: Click a case row → detail page with status bar, info panel, deadline panel
- **Status Change**: On case detail, use status dropdown → confirm (result prompt on final status)
- **Case Type Admin**: In admin settings, create a new case type with general info + statuses

## What NOT to Test

Check [openspec/ROADMAP.md](openspec/ROADMAP.md) for features that are planned but NOT yet implemented. Skip these during testing.

## Test Data

The app needs at least one published case type to function. If the dashboard shows an empty state asking to configure case types, go to admin settings first and create one.
