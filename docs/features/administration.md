# Administration

Nextcloud admin panel for case type management, plus the foundational OpenRegister integration that stores all case management data.

## Specs

- `openspec/specs/admin-settings/spec.md`
- `openspec/specs/openregister-integration/spec.md`

## Features

### Nextcloud Admin Panel (MVP)

Procest registers a settings section in Nextcloud's admin panel for case type configuration.

### Case Type List View (MVP)

Admin panel shows all case types in a list with:

- Title, status (draft/published), validity period
- Click-through to detail/edit view
- Create new case type action

### Case Type Detail/Edit View (MVP)

Tabbed interface for comprehensive case type editing:

- **General tab**: Title, description, processing deadline, validity dates
- **Statuses tab**: Status type management (add, reorder, edit, delete)

### Default Case Type Selection (MVP)

One case type can be marked as the default, pre-selected when creating new cases.

### Case Type Publish Action (MVP)

Transition a case type from draft to published state, making it available for case creation. Validates completeness before publishing.

### Planned (V1) — Admin Tabs

Additional case type configuration tabs:

- **Results tab**: Result type management (with archival rules)
- **Roles tab**: Role type management (with generic role mapping)
- **Properties tab**: Custom field definitions (with type and validation)
- **Documents tab**: Document type management (with direction)

### OpenRegister Integration (MVP)

Procest owns no database tables — all data is stored as OpenRegister objects in the `procest` register with 12 schemas:

**Configuration schemas (admin-managed):**
- `caseType` — Case behavior configuration
- `statusType` — Allowed statuses per case type
- `resultType` — Outcome types with archival rules
- `roleType` — Participant role definitions
- `propertyDefinition` — Custom field definitions
- `documentType` — Required document types
- `decisionType` — Decision type definitions

**Instance schemas (user-created):**
- `case` — Case records
- `task` — Task work items
- `role` — Participant assignments
- `result` — Case outcomes
- `decision` — Formal decisions

### Auto-Configuration on Install (MVP)

The repair step automatically imports the register configuration, checks OpenRegister availability (skips gracefully if not installed), and sets up default case types.

### Pinia Store Pattern (MVP)

Dedicated Pinia stores for each of the 12 entity types, providing typed CRUD operations, error handling, pagination, and caching.

### Error Handling (MVP)

Comprehensive error handling:

- Structured errors with HTTP status distinction (404, 403, 422, 500)
- Validation error parsing with field-level feedback
- Network error detection and retry support
- Error toasts and inline error displays

### Cross-Entity References (MVP)

UUID-based references between entities (e.g., case → caseType, task → case, role → case) with resolution and orphaned reference detection.

### RBAC Integration (MVP)

All data access respects OpenRegister's role-based access control.

### Audit Trail (MVP)

OpenRegister tracks all object modifications automatically.

### Planned (V1)

- Cascade behaviors (e.g., deleting a case type affects its sub-types)
- NL Design System theming support
- Eager loading for related entities

### Planned (Enterprise)

- Field-level access control
- Archival management (archiefwet)
- Data retention policies
