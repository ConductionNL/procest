# Roles & Decisions

Participation, outcomes, and formal decision-making on cases. Roles link participants to cases, results record outcomes, and decisions are formal administrative determinations.

## Specs

- `openspec/specs/roles-decisions/spec.md`

## Features

### Role Assignment (MVP)

Roles link participants (Nextcloud users or external contacts) to cases with specific role types.

- Handler assignment shortcut: quick assign the primary case handler
- Built-in generic roles: initiator, handler, advisor
- Participant display on case detail view
- Role validation: role type must be valid, participant must exist

### Case Result Recording (MVP)

When a case reaches a terminal status, a result records the outcome.

- Result links to the case and optionally to a result type
- Result description documents the outcome details
- Closing a case requires recording a result

### Planned (V1) — Role Types

- Role type enforcement from case type configuration
- Role-based case access (participants can only see cases they have a role on)
- Custom role types per case type

### Planned (V1) — Decisions

Formal administrative determinations within cases:

- Decision CRUD linked to cases
- Decision validity periods (effectiveDate, expiryDate)
- Decision types from case type configuration
- Decision validation rules
- Decisions section on case detail view

### Planned (V1) — Result Types

- Result type configuration per case type
- Archival rules on result types (`bewaren` = preserve, `vernietigen` = destroy)
- Result type enforcement on case closure

### Planned (Enterprise)

- DMN decision tables (automated decision logic)
- Decision templates
