# Proposal: roles-decisions-mvp

## Summary

Implement the MVP tier of the Roles & Decisions spec for Procest: role assignment on cases (participants section with add/remove/reassign), handler assignment shortcut, participant display on case detail, role validation, and case result recording on completion.

## Problem

Cases currently only have a flat `assignee` field — there's no structured way to track multiple participants (handler, initiator, advisor, coordinator) or record formal case outcomes. Handlers are assigned directly on the case object without role type semantics.

## Scope

**In scope (MVP — 5 requirements):**
- REQ-ROLE-001: Role assignment on cases (CRUD for role objects linked to case)
- REQ-ROLE-003: Handler assignment shortcut (assigns handler role + updates case assignee)
- REQ-ROLE-005: Participant display on case detail (grouped by role type, with avatars)
- REQ-ROLE-006: Role validation (required fields, case existence check)
- REQ-RESULT-001: Case result recording (select result type on completion, set endDate)

**Out of scope (V1):**
- REQ-ROLE-002: Role type enforcement from case type
- REQ-ROLE-004: Role-based case access / RBAC
- REQ-RESULT-002: Result type admin configuration UI
- REQ-DECISION-*: Full decisions system (CRUD, validity periods, decision types)

## Approach

1. Create a `ParticipantsSection.vue` component for CaseDetail — fetches roles filtered by case UUID, displays grouped by role type, supports add/remove/reassign handler
2. Create a `ResultSection.vue` component for CaseDetail — shows result if exists, provides result type selection during case completion flow
3. Create an `AddParticipantDialog.vue` for role assignment — role type dropdown + user picker
4. Extend the existing status change flow in CaseDetail to prompt for result selection when transitioning to a final status
5. All data uses existing store patterns (role, result, roleType, resultType are already registered)

## Dependencies

- OpenRegister backend (role/result/roleType/resultType schemas must exist in the register)
- Store object types already registered: `role`, `result`, `roleType`, `resultType`
