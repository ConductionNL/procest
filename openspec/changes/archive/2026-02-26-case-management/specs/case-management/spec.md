# Delta Spec: Case Management — MVP

**Source**: `openspec/specs/case-management/spec.md`
**Scope**: MVP tier requirements only. V1 items (CM-08b, CM-09, CM-10, CM-12, CM-14c/d/e, CM-17, CM-18, CM-19) are deferred.

---

## MODIFIED — REQ-CM-01: Case Creation (MVP scope)

Scenarios included: CM-01a, CM-01b, CM-01c, CM-01d, CM-01e, CM-01f, CM-01g

All creation scenarios are in MVP scope. Key implementation points:

- Case type selection dropdown showing only published + currently valid case types
- Auto-generate `identifier` in format `YYYY-NNN` (year + sequential number)
- Auto-set `startDate` to current date
- Auto-calculate `deadline` from `startDate + caseType.processingDeadline`
- Inherit `confidentiality` from case type
- Set initial `status` to first status type (lowest `order`) of the case type
- Default case type pre-selected if configured in admin settings
- Validation: title required, case type required, case type must be published, case type must be within validity window

**MVP simplification**: Identifier generation uses `YYYY-{Date.now() % 10000}` format (frontend-generated, not sequential from backend). This is acceptable for MVP since true sequential numbering requires backend coordination.

---

## INCLUDED — REQ-CM-02: Case Update (MVP scope)

Scenarios included: CM-02a, CM-02b, CM-02c

All update scenarios are in MVP scope. Editable fields: title, description, assignee, priority.

**MVP simplification**: Audit trail entries are stored as an `activity` array property on the case object itself (not via Nextcloud Activity system). Each entry: `{ date, type, description, user }`.

---

## INCLUDED — REQ-CM-03: Case Deletion (MVP scope)

Scenarios included: CM-03a, CM-03b

Both deletion scenarios in scope. Show confirmation dialog; if case has linked tasks, show count in warning message.

**MVP simplification**: Linked tasks are NOT cascade-deleted. The warning informs the user, but deletion only removes the case object. Orphaned tasks remain accessible from the global task list.

---

## INCLUDED — REQ-CM-04: Case List View (MVP scope)

Scenarios included: CM-04a, CM-04b, CM-04c, CM-04d, CM-04e, CM-04f, CM-04g, CM-04h, CM-04i

All list scenarios in scope. Enhance existing CaseList.vue:

- Columns: Identifier, Title, Type, Status (badge), Deadline (countdown), Handler
- Filters: case type, status, handler (text), priority, overdue toggle
- Search against title/description (debounced 300ms)
- Sort by any column (server-side via `_order` param)
- Pagination at 20 items per page
- Overdue cases highlighted with red left border + red deadline text

---

## INCLUDED — REQ-CM-05: Quick Status Change from List (MVP scope, partial)

Scenarios included: CM-05a, CM-05b
Scenarios EXCLUDED: CM-05c (blocked by properties — V1)

Quick status change via clickable status cell in the case list. Opens a dropdown of statuses from the case's case type. Selecting a new status updates inline without page reload.

**MVP simplification**: No property/document validation on status change from list. That is V1 (CM-05c).

---

## INCLUDED — REQ-CM-06: Case Detail View (MVP scope, partial)

Scenarios included: CM-06a, CM-06b, CM-06c, CM-06d, CM-06e, CM-06f

Case detail view with:
- **Info panel**: title (editable), description (editable), type (read-only), identifier (read-only), priority (dropdown), confidentiality (read-only, inherited), assignee (editable), creation date
- **Status change dropdown**: shows statuses from case type, current status highlighted
- **Deadline panel**: start date, deadline, days remaining/overdue, processing deadline duration, days elapsed, extension button (if allowed)

---

## INCLUDED — REQ-CM-07: Status Timeline Visualization (MVP scope)

Scenarios included: CM-07a, CM-07b, CM-07c

Horizontal timeline of status dots:
- Each dot = a status type from the case type (ordered by `order`)
- Passed statuses: filled dot + date label below
- Current status: highlighted/active dot + date label
- Future statuses: greyed/empty dot, no date

Status dates are tracked via `statusHistory` array on the case object: `[{ status: "uuid", date: "ISO", changedBy: "user" }]`.

---

## INCLUDED — REQ-CM-11: Tasks Section (MVP scope)

Scenarios included: CM-11a, CM-11b

Already partially implemented in CaseDetail.vue from task-management change. Enhancements:
- Keep existing task list with completion counter
- Ensure "Add Task" button navigates to `#/tasks/new/{caseId}`
- Status icons for each task state

---

## INCLUDED — REQ-CM-13: Activity Timeline (MVP scope, simplified)

Scenarios included: CM-13a, CM-13b

**MVP simplification**: Activity is stored as an `activity` array on the case object. NOT integrated with Nextcloud Activity system (that is V1/CM-22).

Activity entries automatically recorded for:
- Case creation
- Status changes
- Field updates (title, description, assignee, priority changes)
- Deadline extension

Manual notes: user can add a text note via the activity panel. Stored in same `activity` array.

Each entry: `{ date: ISO, type: "created"|"status_change"|"update"|"extension"|"note", description: string, user: string }`.

---

## INCLUDED — REQ-CM-14: Status Change (MVP scope, partial)

Scenarios included: CM-14a, CM-14b, CM-14f
Scenarios EXCLUDED: CM-14c (properties block — V1), CM-14d (documents block — V1), CM-14e (notification — V1)

- Only statuses from the case type are allowed
- Invalid status rejected with error message
- Final status (`isFinal = true`) sets `endDate` to current date and marks case as closed
- Closed cases become read-only (no further edits without reopening)

---

## INCLUDED — REQ-CM-15: Case Result Recording (MVP scope, simplified)

Scenarios included: CM-15b (simplified)
Scenarios EXCLUDED: CM-15a (result types from case type — V1), CM-15c (archival rules — V1)

**MVP simplification**: When transitioning to a final status, a text input for "Result" is shown. The result is stored as a `result` string property on the case object. No result type entities, no archival rules.

---

## INCLUDED — REQ-CM-16: Case Deadline Extension (MVP scope)

Scenarios included: CM-16a, CM-16b, CM-16c

- Extension button visible when case type `extensionAllowed = true`
- Clicking extends deadline by `caseType.extensionPeriod`
- Track `extensionCount` on case object; reject if already extended once
- Reason captured as text input in extension dialog
- Activity entry recorded for extension

---

## INCLUDED — REQ-CM-20: Case Validation Rules (MVP scope)

Scenarios included: CM-20a, CM-20b, CM-20c, CM-20d
Scenario CM-20e: noted but not enforced (future date warning only)

Client-side validation:
- Title required
- Case type required + must be published + must be within validity window
- Status must be from case type's status types

---

## INCLUDED — REQ-CM-21: Case Deadline Countdown Display (MVP scope)

Scenarios included: CM-21a, CM-21b, CM-21c, CM-21d

Deadline countdown logic (reuse overdue pattern from taskHelpers):
- `X days remaining` — neutral style (green)
- `Due tomorrow` — warning style (amber)
- `Due today` — warning style (amber)
- `X days overdue` — error style (red)

Applied in both CaseList (deadline column) and CaseDetail (deadline panel).

---

## EXCLUDED — V1 Requirements

The following are explicitly excluded from this MVP change:

| Req | Description | Reason |
|-----|-------------|--------|
| CM-08b | Full participant/role management | Needs role types infrastructure |
| CM-09 | Custom properties panel | Needs property definition types |
| CM-10 | Document checklist | Needs document type infrastructure |
| CM-12 | Decisions section | Needs decision entity types |
| CM-14c | Status blocked by properties | Depends on CM-09 |
| CM-14d | Status blocked by documents | Depends on CM-10 |
| CM-14e | Notification on status change | Backend notification system |
| CM-17 | Case suspension | Complex deadline recalculation |
| CM-18 | Sub-cases | Parent/child hierarchy |
| CM-19 | Confidentiality override | Read-only from case type for MVP |
| CM-22 | Nextcloud Activity audit trail | Backend Activity provider needed |
