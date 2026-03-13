# Proposal: case-types

## Summary

Implement the MVP tier of the case type system for Procest. Case types are configurable definitions that control case behavior — allowed statuses, processing deadlines, extension rules, and validation. This is the admin-facing configuration backbone that all case management features build on.

## Motivation

Procest currently has hardcoded case statuses and no configurable case type system. Without case types, every case behaves identically — same statuses, no deadline enforcement, no validation rules. The case-types spec (REQ-CT-01 through REQ-CT-16) defines a full type system modeled after CMMN 1.1 `CaseDefinition` and ZGW `ZaakType`. Implementing the MVP tier unlocks configurable case lifecycles, deadline calculation, and publish/draft workflows.

## Affected Projects

- [ ] Project: `procest` — New admin settings UI for case type management, new utility modules for case type logic, enhanced App.vue routing for admin views

## Scope

### In Scope (MVP)

- **Case Type CRUD** (REQ-CT-01): Create, read, update, delete case types as OpenRegister objects
- **Draft/Published lifecycle** (REQ-CT-02): Case types default to draft, must meet requirements before publishing
- **Validity periods** (REQ-CT-03): ValidFrom/ValidUntil windows controlling when types are usable
- **Status Type management** (REQ-CT-04): Ordered status types per case type with drag reordering, final status enforcement
- **Processing deadline config** (REQ-CT-05): ISO 8601 duration fields with human-readable display
- **Extension configuration** (REQ-CT-06 MVP): Extension allowed/period fields with conditional validation
- **Default case type** (REQ-CT-13): Admin can set one published type as default
- **Validation rules** (REQ-CT-14): Required fields, ISO 8601 format, date ordering
- **Admin UI tabs** (REQ-CT-15 MVP): General and Statuses tabs in case type editor
- **Error scenarios** (REQ-CT-16): Graceful handling of publish blockers, delete blockers, duplicate orders

### Out of Scope (V1)

- Result Type management (REQ-CT-07)
- Role Type management (REQ-CT-08)
- Property Definition management (REQ-CT-09)
- Document Type management (REQ-CT-10)
- Decision Type management (REQ-CT-11)
- Confidentiality defaults (REQ-CT-12)
- Suspension configuration (REQ-CT-06d/e)
- V1 tabs: Results, Roles, Properties, Docs (REQ-CT-15d-g)

## Approach

Frontend-only implementation using the existing `useObjectStore` Pinia store to CRUD case types and status types via the OpenRegister API. New admin settings components will be registered in the Nextcloud admin panel. The existing `caseType` and `statusType` object types are already registered in the store initialization (`store.js`), so the data layer is ready.

Key architectural decisions:
1. **Admin UI in Nextcloud settings panel** — Separate Vue entry point (`settings.js`) rendering into the admin settings template
2. **Tab-based editor** — Case type detail page with General + Statuses tabs (V1 adds more tabs)
3. **ISO 8601 duration helpers** — New utility module for parsing/formatting durations
4. **Publish validation** — Frontend-side validation before setting `isDraft = false`

## Cross-Project Dependencies

- **OpenRegister**: Must have `caseType` and `statusType` schemas registered in the `procest` register
- **Case Management**: Will consume case types once available (future change — not blocked by this)

## Rollback Strategy

All changes are frontend-only (Vue components + utilities). Rollback by reverting the source files. No database migrations or schema changes required.

## Open Questions

None — the spec is comprehensive and the existing codebase patterns are well-established.
