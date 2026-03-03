# Procest Features

Feature documentation organized by functional group. Each file describes implemented and planned features based on the OpenSpec specifications.

## Feature Groups

| Feature | File | Specs |
|---------|------|-------|
| Case Management | [case-management.md](case-management.md) | case-management |
| Case Types | [case-types.md](case-types.md) | case-types |
| Task Management | [task-management.md](task-management.md) | task-management |
| Roles & Decisions | [roles-decisions.md](roles-decisions.md) | roles-decisions |
| Dashboard | [dashboard.md](dashboard.md) | dashboard |
| My Work | [my-work.md](my-work.md) | my-work |
| Administration | [administration.md](administration.md) | admin-settings, openregister-integration |

## Spec-to-Feature Mapping

Used by the `/opsx:archive` skill to update the correct feature doc when archiving a change.

```
case-management → case-management.md
case-types → case-types.md
task-management → task-management.md
roles-decisions → roles-decisions.md
dashboard → dashboard.md
my-work → my-work.md
admin-settings → administration.md
openregister-integration → administration.md
```
