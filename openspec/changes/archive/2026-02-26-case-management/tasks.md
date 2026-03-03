# Tasks: Case Management — MVP

## Implementation Tasks

### Utility Modules

- [x] **T01**: Create `src/utils/caseHelpers.js` — Export functions: `calculateDeadline(startDate, durationString)` (adds ISO 8601 duration to date), `generateIdentifier()` (returns `YYYY-{timestamp_suffix}`), `isCaseOverdue(caseObj)` (deadline past + not at final status), `isCaseDueToday(caseObj, isFinal)`, `getCaseOverdueText(caseObj)`, `formatDeadlineCountdown(caseObj, isFinal)` (returns `{ text, style }` — "X days remaining"/"Due today"/"X days overdue"), `getDaysElapsed(startDate)`, `getDaysRemaining(deadline)`. Import `parseDuration` from `durationHelpers.js`. Use `isFinal` flag from status type (not CMMN terminal status).

- [x] **T02**: Create `src/utils/caseValidation.js` — Export functions: `validateCaseCreate(form, caseTypes)` (returns `{ valid, errors }` — checks title required, case type required, case type published, case type within validity window), `validateCaseUpdate(form)` (title required), `isCaseTypeUsable(caseType)` (published + validFrom <= today + validUntil >= today or null). Case type validity checks: `isDraft === false`, `validFrom <= today`, `validUntil === null || validUntil >= today`.

### New Components

- [x] **T03**: Create `src/views/cases/CaseCreateDialog.vue` — Modal dialog with: case type dropdown (fetches published + valid case types), title text field, description textarea (optional). On case type selection, show preview panel: processing deadline (formatted duration), confidentiality level, initial status name, calculated deadline from today. On submit: validate via `caseValidation.js`, construct case object with auto-fields (identifier, startDate, deadline, status = first status type by order, confidentiality from case type, `statusHistory: [{ status, date, changedBy }]`, `activity: [{ date, type: "created", description, user }]`, extensionCount: 0), call `saveObject('case', data)`, emit `@created` with new case ID. Props: none. Events: `@created(caseId)`, `@close`.

- [x] **T04**: Create `src/views/cases/components/StatusTimeline.vue` — Horizontal timeline of status dots. Props: `statusTypes` (array, ordered by `order`), `currentStatusId` (string), `statusHistory` (array of `{ status, date }`). Render: one dot per status type. Passed statuses (in statusHistory before current): filled dot, date label below. Current status: highlighted/larger dot, date label. Future statuses: greyed dot, no date. Use CSS flexbox with connecting lines between dots. Color: passed = `--color-success`, current = `--color-primary`, future = `--color-text-maxcontrast`.

- [x] **T05**: Create `src/views/cases/components/DeadlinePanel.vue` — Info panel showing deadline data. Props: `startDate`, `deadline`, `processingDeadline` (duration string), `extensionAllowed` (bool), `extensionPeriod` (duration string), `extensionCount` (number), `isFinal` (bool). Display: "Started: {date}", "Deadline: {date}", countdown text with color coding (green/amber/red), "Processing time: {formatted duration}", "Days elapsed: {N}", "Extension: allowed (+{period})" or "not allowed". Show "Extend" button when `extensionAllowed && extensionCount === 0 && !isFinal`. Emit `@extend` when clicked.

- [x] **T06**: Create `src/views/cases/components/ActivityTimeline.vue` — Reverse-chronological event list. Props: `activity` (array), `isReadOnly` (bool). Each entry shows: date (formatted), type icon, description, user. Entry types with icons: `created` (plus), `status_change` (arrow), `update` (pencil), `extension` (clock), `note` (comment). At the top: "Add note" text area + submit button (hidden when read-only). Emit `@add-note(text)`.

- [x] **T07**: Create `src/views/cases/components/QuickStatusDropdown.vue` — Inline dropdown for status change in case list. Props: `caseObj` (the case object), `statusTypes` (array). Shows NcSelect/dropdown with status type names. Current status pre-selected. On selection change: update case status, append to statusHistory and activity, emit `@changed`. Uses `@click.stop` on root element to prevent row click.

### Enhanced Views

- [x] **T08**: Rewrite `src/views/cases/CaseList.vue` — Replace current minimal list with full case management list. Changes: (a) Add columns: Identifier, Title, Type (case type name), Status (badge with QuickStatusDropdown), Deadline (countdown with color), Handler. Remove "Created" column. (b) Add filters: case type dropdown (fetch all case types), status dropdown (dynamically populated from selected case type's status types, or all statuses if no type filter), handler text field (debounced), priority dropdown, overdue toggle checkbox. (c) Search: existing search works, ensure it uses `_search` param. (d) Sort: all column headers sortable via `_order` param. (e) "New case" button opens CaseCreateDialog instead of navigating to `#/cases/new`. (f) Overdue rows: red left border + red deadline text. (g) Load case types on mount for type column display and type filter. (h) Load status types for status badge display.

- [x] **T09**: Rewrite `src/views/cases/CaseDetail.vue` — Replace current minimal detail with full case management detail. Changes: (a) Load case type + status types on mount. (b) Info panel section: title (editable), description (editable textarea), identifier (read-only), case type name (read-only link), priority dropdown (low/normal/high/urgent), confidentiality (read-only text), assignee (editable), start date (read-only). (c) Status bar: current status badge + status change dropdown (only statuses from case type) + result input (shown when transitioning to final status). (d) Integrate StatusTimeline component below status bar. (e) Integrate DeadlinePanel component. (f) Keep existing tasks section (from task-management). (g) Integrate ActivityTimeline component. (h) Read-only mode: when current status isFinal, disable all editable fields, hide status dropdown, hide save/delete buttons. (i) On status change: update status, append to statusHistory, append to activity, if final status set endDate + prompt for result. (j) On save: validate via caseValidation.js, append update activity entry, saveObject. (k) On delete: confirmation dialog with linked task count warning.

### Extension Support

- [x] **T10**: Add extension dialog in CaseDetail.vue — When "Extend" is emitted from DeadlinePanel: show confirmation dialog with reason text input. On confirm: calculate new deadline (current deadline + extensionPeriod via `calculateDeadline`), update case object (`deadline`, `extensionCount++`, append to activity), saveObject. If extensionCount >= 1 already, the Extend button is hidden (handled by DeadlinePanel props).

### Case Type Integration Helpers

- [x] **T11**: Add helper functions for case type data in CaseDetail.vue — Methods: `getStatusTypesForCase()` (fetch and cache status types for the case's case type, ordered by `order`), `getCurrentStatusType()` (find the status type matching current case status), `isAtFinalStatus()` (check if current status type has `isFinal === true`), `getInitialStatusType(statusTypes)` (return the one with lowest `order`). These feed into StatusTimeline, status dropdown, and read-only detection.

### Result Recording

- [x] **T12**: Add result prompt on final status transition in CaseDetail.vue — When user selects a final status from the dropdown: show inline text input "Result" below the dropdown. Text is required before confirming. On confirm: set `result` on case object, set `endDate` to today, update status + statusHistory + activity, saveObject.

### Validation Integration

- [x] **T13**: Wire validation into CaseCreateDialog and CaseDetail — CaseCreateDialog: call `validateCaseCreate()` before saving, display field-level errors. CaseDetail: call `validateCaseUpdate()` before saving, display title error. Both: show error messages below respective fields using `.form-error` styling.

### Case List — Case Type Data Loading

- [x] **T14**: Add case type and status type caching to CaseList.vue — On mount, fetch all case types via `fetchCollection('caseType', { _limit: 100 })`. Cache in local data for: type column display (resolve caseType ID to name), type filter dropdown options, status filter options (grouped by type). For each case in the list, resolve its `caseType` to a name using the cache. Fetch status types once to populate filter options.

### Deadline Display in List

- [x] **T15**: Add deadline countdown column to CaseList.vue — For each case row, compute deadline countdown using `formatDeadlineCountdown()` from `caseHelpers.js`. Need to know if case is at final status: check case's status against its case type's status types to find `isFinal`. Apply CSS classes: `deadline--overdue` (red), `deadline--today` (amber), `deadline--ok` (green/neutral).

## Verification Tasks

- [x] **V01**: All 7 new files created and syntactically valid
- [ ] **V02**: CaseList renders with all 6 columns and 5+ filters
- [ ] **V03**: CaseCreateDialog validates and auto-fills all required fields
- [ ] **V04**: StatusTimeline shows correct passed/current/future states
- [ ] **V05**: DeadlinePanel shows countdown and extension button correctly
- [ ] **V06**: ActivityTimeline displays entries and supports adding notes
- [ ] **V07**: Status change to final status prompts for result and sets endDate
- [ ] **V08**: Quick status change in list updates inline
- [ ] **V09**: Extension dialog calculates new deadline correctly
- [x] **V10**: All tasks checked off
