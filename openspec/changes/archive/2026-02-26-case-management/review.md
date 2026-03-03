# Review: case-management

## Summary
- Tasks completed: 15/15
- GitHub issues closed: N/A (used `/opsx:ff`, no `plan.json` or GitHub issues created)
- Spec compliance: **PASS** (with warnings)

## Verification

### Task Completion
All 15 implementation tasks (T01-T15) are marked complete in `tasks.md`. Verification tasks V01 and V10 are marked complete (file existence + task checklist). V02-V09 (manual browser testing) remain unchecked — these require live testing.

### Files Created/Modified
All 7 new files exist and are syntactically valid:
- `src/utils/caseHelpers.js`
- `src/utils/caseValidation.js`
- `src/views/cases/CaseCreateDialog.vue`
- `src/views/cases/components/StatusTimeline.vue`
- `src/views/cases/components/DeadlinePanel.vue`
- `src/views/cases/components/ActivityTimeline.vue`
- `src/views/cases/components/QuickStatusDropdown.vue`

Both modified files have been rewritten:
- `src/views/cases/CaseList.vue`
- `src/views/cases/CaseDetail.vue`

---

## Requirement-by-Requirement Verification

| Req | Status | Notes |
|-----|--------|-------|
| CM-01 (Case Creation) | PASS (with warning) | All auto-fields, validation, preview panel implemented. Default case type pre-selection from admin settings not implemented. |
| CM-02 (Case Update) | PASS | All 4 editable fields + activity tracking for changes |
| CM-03 (Case Deletion) | PASS | Confirmation dialog with linked task count warning |
| CM-04 (Case List View) | PASS (with warning) | 6 columns, 5 filters, search, sort, pagination. Overdue filter checkbox is non-functional (see W02). |
| CM-05 (Quick Status Change) | PASS | QuickStatusDropdown inline with @click.stop, updates + refreshes list |
| CM-06 (Case Detail View) | PASS | Full info panel, status change dropdown, deadline panel |
| CM-07 (Status Timeline) | PASS | Horizontal dots with passed/current/future states and date labels |
| CM-11 (Tasks Section) | PASS | Task list with completion counter, "New task" button, status/priority badges |
| CM-13 (Activity Timeline) | PASS | All 4 auto-recorded types + manual notes |
| CM-14 (Status Change) | PASS | Statuses from case type only, final status sets endDate, read-only mode |
| CM-15 (Result Recording) | PASS | Result text prompt on final status, required before confirm |
| CM-16 (Deadline Extension) | PASS | Extension dialog with reason, deadline calculation, extensionCount tracking |
| CM-20 (Case Validation) | PASS | Title required, case type required + published + valid window |
| CM-21 (Deadline Countdown) | PASS | 4 states (remaining/today/tomorrow/overdue) with correct styles in both list and detail |

---

## Findings

### CRITICAL
None.

### WARNING
- [ ] **W01**: CM-01 — Default case type pre-selection from admin settings is not implemented. The delta spec lists "Default case type pre-selected if configured in admin settings" as a key implementation point. `CaseCreateDialog.vue` does not read `default_case_type` from settings. (spec_ref: CM-01, "Default case type pre-selected if configured in admin settings")

- [ ] **W02**: CM-04 — Overdue filter checkbox is non-functional. `CaseList.vue` has a `filters.overdue` checkbox (line 43-49) that triggers `onFilterChange()` → `fetchCases()`, but `fetchCases()` never sends the overdue value to the API. The checkbox appears functional but has no effect on results. Either implement client-side filtering post-fetch, add a backend `_filters[overdue]` param, or remove the checkbox for MVP. (spec_ref: CM-04, filter list includes "overdue toggle")

- [ ] **W03**: Accessibility — `CaseCreateDialog.vue` and extension dialog overlays lack focus traps, `aria-modal` attributes, and ESC key handling. These are custom overlay implementations. The NL Design System shared spec recommends WCAG AA compliance which includes proper modal dialog accessibility. (spec_ref: shared nl-design spec, accessibility)

### SUGGESTION
- The `confirm()` native dialog for deletion (`CaseDetail.vue:546`) could be replaced with a proper NcDialog component for better UX and consistency with the custom dialogs used elsewhere.
- `generateIdentifier()` uses `Date.now() % 10000` which could produce collisions in rapid creation. The delta spec acknowledges this as acceptable for MVP; V1 should add backend sequential numbering.
- `CaseCreateDialog.vue` fetches all case types and filters client-side via `isCaseTypeUsable()`. For large case type counts, a server-side `_filters[isDraft]=false` filter would be more efficient. Fine for MVP.
- `QuickStatusDropdown.vue:86` saves the entire case object spread (`{ ...this.caseObj, status, statusHistory, activity }`). If `caseObj` is stale (another user changed it), this could overwrite their changes. Consider a PATCH-style update in V1.

---

## Cross-Reference with Shared Specs

| Shared Spec | Status | Notes |
|-------------|--------|-------|
| nextcloud-app | PASS | No PHP/routing changes needed; existing routes sufficient |
| api-patterns | N/A | No new API endpoints created; uses existing objectStore |
| nl-design | WARNING | Colors use CSS variables (good), but custom modals lack accessibility features (W03) |
| docker | PASS | No Docker changes needed; all frontend-only |

---

## Recommendation
**APPROVE** — All 13 MVP requirements are implemented with correct behavior. The 3 warnings are minor:
- W01 (default case type) is a SHOULD-level feature, not blocking
- W02 (overdue filter) is a UI bug that should be fixed before merge
- W03 (accessibility) is a cross-cutting concern applicable to the entire app, not specific to this change

**Suggested pre-merge fix**: Address W02 by either implementing the overdue filter or removing the non-functional checkbox.
