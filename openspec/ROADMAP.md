# Procest Roadmap

## Implemented (MVP Complete)

All MVP specs have been implemented and archived.

| Spec | Archived | Summary |
|------|----------|---------|
| openregister-integration | 2026-02-26 | Register config, repair step, 12 schemas, Pinia store |
| case-types | 2026-02-26 | Case type system with status types, deadlines, extensions |
| case-management | 2026-02-26 | Full case CRUD, status lifecycle, timeline, activity |
| task-management | 2026-02-26 | CMMN task lifecycle, assignment, priority, due dates |
| dashboard | 2026-02-26 | KPI cards, overdue alerts, workload preview, activity feed |
| my-work | 2026-02-26 | Personal workload view with grouped urgency, filters |
| roles-decisions (MVP) | 2026-02-26 | Role assignment, handler reassign, result recording |
| admin-settings | — | Already implemented (AdminSettings.php, Settings.vue, CaseTypeAdmin.vue, status types) |

## V1 Features (Roadmap)

### roles-decisions V1

The MVP implemented roles (assignment, handler shortcut, display, validation) and results (type selector, object creation). V1 adds the remaining 8 requirements:

| Requirement | Description | Complexity |
|-------------|-------------|------------|
| REQ-ROLE-002 | Role type enforcement from case type (filter available role types by case type) | Medium |
| REQ-ROLE-004 | Role-based case access / RBAC (restrict case visibility by role) | High |
| REQ-RESULT-002 | Result type admin configuration (CRUD in admin settings Results tab) | Medium |
| REQ-DECISION-001 | Decision CRUD (create, read, update, delete formal decisions on cases) | High |
| REQ-DECISION-002 | Decision validity periods (start/end dates, legal effect tracking) | Medium |
| REQ-DECISION-003 | Decision types from case type (admin config for allowed decision types) | Medium |
| REQ-DECISION-004 | Decision validation (required fields, type enforcement) | Low |
| REQ-DECISION-005 | Decisions section on case detail (display decisions with timeline) | Medium |

### admin-settings V1

The admin settings MVP is implemented (panel registration, case type CRUD, general tab, status types with reorder, publish, default). V1 adds type management tabs:

| Requirement | Description | Complexity |
|-------------|-------------|------------|
| REQ-ADMIN-009 | Result type management tab (CRUD with archival rules, retention periods) | Medium |
| REQ-ADMIN-010 | Role type management tab (CRUD with generic role mapping) | Medium |
| REQ-ADMIN-011 | Property definition management tab (custom fields per case type) | Medium |
| REQ-ADMIN-012 | Document type management tab (document requirements per case type) | Medium |

### Potential Future Features

These are not yet specified but may be needed:

- **Document management**: Upload/attach documents to cases, enforce document type requirements
- **Notifications**: Notify participants on status changes, task assignments, deadlines
- **Bulk operations**: Bulk status change, bulk assignment across cases
- **Reporting/export**: Case statistics, processing time reports, CSV/PDF export
- **External contacts**: Support non-Nextcloud participants (citizens, organizations)
- **Case templates**: Pre-fill case data from templates
