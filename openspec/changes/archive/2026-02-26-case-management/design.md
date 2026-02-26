# Design: Case Management — MVP

## Architecture Overview

This change enhances the existing CaseList.vue and CaseDetail.vue with case-type-aware behavior, and adds new sub-components for status timeline, deadline panel, and activity timeline. All data flows through the existing `useObjectStore` Pinia store and OpenRegister API.

## File Map

### New Files

| File | Purpose |
|------|---------|
| `src/utils/caseHelpers.js` | Deadline calculation, countdown text, identifier generation, overdue/due-today logic for cases |
| `src/utils/caseValidation.js` | Case form validation: required fields, case type validity checks |
| `src/views/cases/CaseCreateDialog.vue` | Modal dialog for creating a new case with case type selection |
| `src/views/cases/components/StatusTimeline.vue` | Horizontal status timeline visualization |
| `src/views/cases/components/DeadlinePanel.vue` | Deadline info panel with countdown and extension button |
| `src/views/cases/components/ActivityTimeline.vue` | Chronological activity log with "Add note" input |
| `src/views/cases/components/QuickStatusDropdown.vue` | Inline status dropdown for the case list |

### Modified Files

| File | Changes |
|------|---------|
| `src/views/cases/CaseList.vue` | Replace hardcoded statuses with case-type-aware filters; add columns (identifier, type, deadline countdown); add quick status change; add overdue filter; add case type filter; add "New case" opens CaseCreateDialog |
| `src/views/cases/CaseDetail.vue` | Integrate case type data; replace hardcoded status/priority dropdowns with case-type-aware ones; add StatusTimeline, DeadlinePanel, ActivityTimeline components; add result input on final status; add read-only mode for closed cases; enhance info panel |
| `src/App.vue` | No routing changes needed — existing `#/cases` and `#/cases/{id}` routes are sufficient |
| `src/store/store.js` | No changes — `case`, `caseType`, `statusType` object types already registered |

### Unchanged Files

| File | Reason |
|------|--------|
| `lib/Service/SettingsService.php` | All needed config keys already exist |
| `src/store/modules/object.js` | Generic CRUD store already supports all needed operations |
| `src/navigation/MainMenu.vue` | Cases nav item already exists |
| `src/utils/taskLifecycle.js` | Task lifecycle unchanged |
| `src/utils/taskHelpers.js` | May import some helpers (isOverdue pattern) but file not modified |

## Design Decisions

### DD-01: Case Type Data Loading Strategy

**Decision**: Load case types and their status types eagerly on CaseDetail mount; cache in object store.

**Rationale**: Case detail needs case type data for status timeline, deadline panel, status dropdown, and validation. Loading on mount avoids repeated fetches. The object store's `getObject('caseType', id)` provides caching.

**Implementation**: In CaseDetail.mounted(), after fetching the case, fetch its case type. Then fetch status types filtered by `_filters[caseType]={caseTypeId}` and sort by `_order[order]=asc`.

### DD-02: Status History Tracking

**Decision**: Store status history as an array property on the case object: `statusHistory: [{ status, date, changedBy }]`.

**Rationale**: MVP avoids backend complexity. OpenRegister stores JSON objects, so an embedded array is natural. The status timeline component reads this array to show dates under passed statuses.

**Trade-off**: Status history is denormalized. Acceptable for MVP; V1 can migrate to separate status change entities if needed.

### DD-03: Activity Log Storage

**Decision**: Store activity as an array property on the case object: `activity: [{ date, type, description, user }]`.

**Rationale**: Avoids Nextcloud Activity system integration (V1 scope). Frontend appends entries on create/update/status-change/extension. Activity array grows with the case but is bounded in practice (tens of entries, not thousands).

**Trade-off**: No server-side activity filtering or cross-case activity feeds. Acceptable for MVP.

### DD-04: Identifier Generation

**Decision**: Generate identifiers client-side as `YYYY-{timestamp_suffix}` where suffix is `Date.now() % 10000`.

**Rationale**: True sequential numbering (YYYY-NNN) requires backend coordination (counter in app config). For MVP, a timestamp-based approach avoids collisions while being simple. Format: `2026-4281`.

**Trade-off**: Not strictly sequential. Acceptable for MVP; V1 can add a backend endpoint for sequential IDs.

### DD-05: Deadline Calculation

**Decision**: Calculate deadline client-side: `startDate + parseDuration(caseType.processingDeadline)`.

**Rationale**: Duration parsing already exists in `durationHelpers.js`. The `parseDuration()` function returns `{ years, months, weeks, days }`. Apply these to the start date using JavaScript Date arithmetic.

**Implementation**: New function `calculateDeadline(startDate, durationString)` in `caseHelpers.js`. Handles P{n}D (add days), P{n}W (add weeks*7 days), P{n}M (add months), P{n}Y (add years).

### DD-06: Case Create Flow

**Decision**: Use a modal dialog (CaseCreateDialog) instead of navigating to `#/cases/new`.

**Rationale**: The create form needs case type selection with live preview of defaults (deadline, confidentiality, initial status). A dialog keeps the user in context (case list or dashboard). After creation, navigate to the new case's detail view.

**Implementation**: CaseCreateDialog receives `@created` event with the new case ID. Parent navigates to `#/cases/{newId}`.

### DD-07: Quick Status Change in List

**Decision**: Clicking the status badge in a case list row opens a QuickStatusDropdown inline.

**Rationale**: Matches CM-05a/b requirement. The dropdown shows only statuses from the case's case type. Selection triggers an immediate save and re-renders the row.

**Implementation**: QuickStatusDropdown is a lightweight component that fetches status types for the case's case type. Uses `@click.stop` to prevent row navigation. Emits `@changed` for the parent to refresh.

### DD-08: Read-Only Mode for Closed Cases

**Decision**: Cases at a final status (`isFinal = true` on current status type) become fully read-only.

**Rationale**: Matches CM-14f. Once a case reaches its final status, `endDate` is set and all form fields are disabled. The status dropdown is hidden (no further transitions).

**Implementation**: Computed `isReadOnly` checks if the current status type's `isFinal` flag is true.

### DD-09: Extension Flow

**Decision**: Extension is a single-click action with a confirmation dialog capturing reason.

**Rationale**: Matches CM-16a/b/c. The deadline panel shows an "Extend" button when the case type allows it and the case hasn't been extended yet. Clicking opens a small dialog for the reason. On confirm: `deadline += extensionPeriod`, `extensionCount++`, activity entry added.

### DD-10: Reuse Overdue/Countdown Pattern

**Decision**: Create case-specific overdue helpers in `caseHelpers.js` mirroring the pattern from `taskHelpers.js`.

**Rationale**: Cases use `deadline` (not `dueDate`) and don't have CMMN terminal statuses — they use `isFinal` from their status type. The logic is similar but the field names and final-status check differ, so separate functions are cleaner than parameterizing the task helpers.

## Component Hierarchy

```
CaseList.vue
├── CaseCreateDialog.vue (modal, shown on "New case" click)
└── QuickStatusDropdown.vue (inline per row)

CaseDetail.vue
├── StatusTimeline.vue
├── DeadlinePanel.vue
├── [existing task section]
└── ActivityTimeline.vue
```

## Data Flow

### Case Creation
1. User clicks "New case" → CaseCreateDialog opens
2. Dialog fetches published + valid case types via `fetchCollection('caseType', { '_filters[isDraft]': false })`
3. User selects type → dialog shows defaults preview (deadline, confidentiality, initial status)
4. User enters title → submits
5. Dialog validates (caseValidation.js), constructs case object with auto-fields
6. `saveObject('case', caseData)` → on success, emit `@created` with new ID
7. Parent navigates to `#/cases/{newId}`

### Status Change
1. User selects new status from dropdown (detail or list)
2. Frontend validates: status must be in case type's status types
3. If target is final status: prompt for result text
4. Update case: `status = newStatusTypeId`, append to `statusHistory`, append to `activity`
5. If final: set `endDate = today`, set `result` text
6. `saveObject('case', updatedCaseData)`

### Deadline Extension
1. User clicks "Extend" on deadline panel
2. Confirmation dialog: enter reason text
3. Calculate new deadline: `currentDeadline + parseDuration(caseType.extensionPeriod)`
4. Update case: `deadline = newDeadline`, `extensionCount++`, append to `activity`
5. `saveObject('case', updatedCaseData)`
