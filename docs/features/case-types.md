# Case Types

Configuration system that controls case behavior. Case types define the allowed statuses, results, roles, custom fields, document requirements, and processing rules for cases.

## Specs

- `openspec/specs/case-types/spec.md`

## Features

### Case Type CRUD (MVP)

Full create, read, update, and delete for case type configurations.

- Fields: title, description, processingDeadline (ISO 8601 duration), defaultConfidentiality, validFrom, validUntil
- Case types contain sub-collections: statusTypes, resultTypes, roleTypes, propertyDefinitions, documentTypes, decisionTypes

### Draft/Published Lifecycle (MVP)

Case types follow a two-phase lifecycle:

- **Draft**: Can be freely edited. Cannot be used to create cases.
- **Published**: Active and available for case creation. Changes require careful consideration.

### Validity Periods (MVP)

Case types have optional `validFrom` and `validUntil` dates, enabling version management. Only valid case types are available for new case creation.

### Status Type Management (MVP)

Ordered list of allowed statuses for the case type. Each status type has a name and order. Statuses define the lifecycle phases a case can progress through.

- Add, reorder, edit, and delete status types
- Order determines the expected progression sequence

### Processing Deadline Configuration (MVP)

ISO 8601 duration (e.g., `P30D` for 30 days, `P6W` for 6 weeks) that auto-calculates case deadlines from creation date.

### Default Case Type Selection (MVP)

One case type can be marked as the default, pre-selected when creating new cases.

### Case Type Validation Rules (MVP)

- Title is required
- At least one status type required before publishing
- Processing deadline must be valid ISO 8601 duration
- validFrom must be before validUntil when both set

### Planned (V1)

- Extension and suspension configuration (rules for deadline modification)
- Result type management (with archival rules: `bewaren` / `vernietigen`)
- Role type management (with generic role mapping)
- Property definition management (custom fields with type/validation)
- Document type management (with direction: incoming/internal/outgoing)
- Decision type management
- Confidentiality default settings

### Planned (Enterprise)

- Case type versioning chains (auditable type evolution)
- Case type import/export
- Sub-case type restrictions
