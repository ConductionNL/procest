# Design: roles-decisions-mvp

## Context

CaseDetail.vue currently has 6 sections: header, status bar, status timeline, info+deadline panels, tasks, and activity. There is no participants section. The "result" flow uses a free-text `NcTextField` that stores a string directly on the case object's `result` field. The store already registers `role`, `result`, `roleType`, and `resultType` object types.

## Goals / Non-Goals

**Goals:**
- Add a Participants section to CaseDetail showing roles grouped by type
- Support add/remove participant with role type selection
- Handler reassignment that updates both role and case assignee
- Replace free-text result prompt with result type selector
- Create proper result objects linked to case on completion

**Non-Goals:**
- Role type enforcement per case type (V1)
- Role-based access control (V1)
- Result type admin configuration (V1)
- Decisions (V1)
- External contact resolution (contacts not in Nextcloud users)

## Decisions

### DD-01: ParticipantsSection as Standalone Component

**Decision**: Create `src/views/cases/components/ParticipantsSection.vue` that receives the case UUID as a prop and manages its own role fetching/CRUD.

**Rationale**: Follows the pattern of other case detail sections (DeadlinePanel, ActivityTimeline, StatusTimeline) — each is a self-contained component. The section fetches roles on mount via `objectStore.fetchCollection('role', { '_filters[case]': caseUuid })`.

### DD-02: Role Type Loading Strategy

**Decision**: Load all role types once in CaseDetail (or ParticipantsSection on mount) and pass them down. For MVP, load ALL role types regardless of case type (V1 will filter by case type).

**Rationale**: Role types are a small set (typically <20). Filtering by case type requires REQ-ROLE-002 (V1). For MVP, showing all role types is acceptable.

### DD-03: User Picker for Participant Selection

**Decision**: Use a simple `NcSelect` with Nextcloud user list fetched via OCS API (`/ocs/v2.php/cloud/users/details`) or filter the user list available from the current session.

**Rationale**: Full user picker components from @nextcloud/vue are complex. A simple select with user display names is sufficient for MVP. The participant field stores the Nextcloud UID.

**Fallback**: If fetching users is complex, allow free-text UID input with validation.

### DD-04: Handler Shortcut via Generic Role Match

**Decision**: Identify the handler role by checking `genericRole === 'handler'` on the role type. When reassigning, update both the role's `participant` and the case's `assignee` field.

**Rationale**: The spec defines 8 standard generic roles. The handler generic role has special semantics — it syncs with the case `assignee` field. This avoids hardcoding a specific role type UUID.

**Implementation**: When saving a handler reassignment, make two API calls: (1) save the role object with new participant, (2) save the case with new assignee. Both via `objectStore.saveObject()`.

### DD-05: Result Type Selector Replaces Free-Text

**Decision**: Replace the existing `NcTextField` result prompt in CaseDetail's status change flow with an `NcSelect` dropdown of result types. The selected result type creates a result object. For backward compatibility, keep the case `result` field updated with the result type name string.

**Rationale**: The current free-text result is stored as `caseData.result` (string). The new flow creates a proper `result` object AND sets `caseData.result` to the result type name for display compatibility. If no result types exist for the case type, fall back to the existing free-text field.

**Flow change in `onStatusSelected()`:
1. User selects final status → result prompt shows
2. Prompt now shows `NcSelect` with result types (fetched for the case's caseType)
3. User selects a result type → confirm
4. `confirmStatusChange()` creates result object + updates case with status, endDate, result name

### DD-06: AddParticipantDialog Component

**Decision**: Create `src/views/cases/components/AddParticipantDialog.vue` as a modal dialog with two fields: role type selector (NcSelect) and participant selector (NcSelect with user list).

**Rationale**: Keeps the participants section clean. The dialog is responsible for validation (both fields required) and calls `objectStore.saveObject('role', ...)` on confirm.

## File Map

### New Files

| File | Purpose |
|------|---------|
| `src/views/cases/components/ParticipantsSection.vue` | Participants section for case detail — lists roles, add/remove, handler reassign |
| `src/views/cases/components/AddParticipantDialog.vue` | Modal dialog for adding a participant with role type + user selection |
| `src/views/cases/components/ResultSection.vue` | Result display section for case detail — shows recorded result |

### Modified Files

| File | Changes |
|------|---------|
| `src/views/cases/CaseDetail.vue` | Add ParticipantsSection + ResultSection components, replace free-text result prompt with result type NcSelect |

## Risks / Trade-offs

- **[Trade-off] All role types shown in MVP** — Without case type filtering (V1), users see all role types even if irrelevant. Acceptable for MVP since the role type list is small.
- **[Trade-off] User list fetching** — Fetching all Nextcloud users may be slow on large instances. For MVP, limit to first 100 users. V1 can add search-as-you-type.
- **[Risk] Backward compat for `caseData.result`** — The existing `result` field is a string. The new flow writes both a result object AND the string. Old cases with string-only results still display correctly.
- **[Risk] Role types may not exist** — If the OpenRegister schemas don't have role type seed data, the participants section has no role types to offer. The AddParticipantDialog should handle empty role types gracefully.
