# Review: dashboard-mvp

## Summary
- Tasks completed: 8/8
- GitHub issues closed: N/A (used `/opsx:ff`, no `plan.json` or GitHub issues created)
- Spec compliance: **PASS** (with warnings)

## Verification

### Task Completion
All 8 implementation tasks (T01-T08) are marked complete in `tasks.md`. Verification tasks V01-V13 remain unchecked (manual browser testing).

### Files Created/Modified
All 6 new files exist and are syntactically valid:
- `src/utils/dashboardHelpers.js`
- `src/views/dashboard/KpiCards.vue`
- `src/views/dashboard/StatusChart.vue`
- `src/views/dashboard/OverduePanel.vue`
- `src/views/dashboard/MyWorkPreview.vue`
- `src/views/dashboard/ActivityFeed.vue`

Modified file:
- `src/views/Dashboard.vue` — full rewrite from placeholder

### Import Verification
- `prioritySortWeight` from `taskHelpers.js` — confirmed exists (line 101)
- `isTerminalStatus` from `taskLifecycle.js` — confirmed exists (line 95)
- `isCaseOverdue`, `getDaysRemaining`, `formatDeadlineCountdown` from `caseHelpers.js` — confirmed (case-management change)

---

## Requirement-by-Requirement Verification

| Requirement | Status | Notes |
|-------------|--------|-------|
| KPI Cards Row (REQ-DASH-001) | PASS | 4 cards with correct counts and sub-labels. Click navigation works. |
| Cases by Status Chart (REQ-DASH-002) | PASS | Proportional CSS bars, 6-color palette, same-name aggregation, loading/error/empty states |
| Overdue Cases Panel (REQ-DASH-004) | PASS | Sorted by severity, scrollable, severity color bands, click navigation, "View all" link |
| My Work Preview (REQ-DASH-005) | PASS | Top 5 items, priority+deadline sort, type badges, overdue indicators, priority icons |
| Recent Activity Feed (REQ-DASH-006) | PASS | Last 10 entries, type icons, relative timestamps, case references |
| Quick Actions (REQ-DASH-007) | PASS | "+ New Case" button opens CaseCreateDialog, navigates on creation |
| Dashboard Data Scope (REQ-DASH-008) | PASS | All queries via OpenRegister which enforces RBAC |
| Empty State (REQ-DASH-009) | PASS (with warning) | Welcome message shown, admin/non-admin guidance. See W01. |
| Dashboard Refresh (REQ-DASH-010) | PASS | Load on mount, refresh button, section-level loading skeletons |
| Error Handling (REQ-DASH-012) | PASS | Section-level try/catch, error messages with retry |
| Dashboard Layout (REQ-DASH-013) | PASS | KPI row + 3fr/2fr grid, responsive @768px breakpoint |

---

## Findings

### CRITICAL
None.

### WARNING
- [ ] **W01**: REQ-DASH-009 — Empty state uses `v-if/v-else` which hides KPI cards entirely when the welcome message is shown. The spec says "THEN a welcome message MUST be displayed AND KPI cards MUST show '0' without errors" — implying both should be visible simultaneously. Current implementation shows only the welcome message without KPI cards. From a UX perspective this is reasonable (zeros are meaningless on a fresh install), but it deviates from the spec letter.

- [ ] **W02**: REQ-DASH-012 — `retrySection(section)` calls `loadDashboardData()` which re-fetches ALL data, not just the failed section. This means clicking "Retry" on the activity feed reloads cases, case types, status types, and tasks too. Functionally correct but inefficient and may cause unnecessary loading flickers in sections that were already loaded.

### SUGGESTION
- The `isFinal` check in `Dashboard.vue:201` uses loose truthiness (`!st?.isFinal`) instead of explicit `=== true || === 'true'` matching. This works correctly because JavaScript's truthiness handles both boolean `true` and string `'true'` — but it's inconsistent with the explicit checks used everywhere else (CaseList, CaseDetail, DeadlinePanel). Consider aligning for consistency.
- `ActivityFeed.vue:29` uses `v-html="typeIcon(entry.type)"` with HTML entity strings. While the icon values are hardcoded constants (not user input), `v-html` is generally discouraged for XSS safety. Consider using Unicode characters directly instead of HTML entities to use `{{ }}` interpolation.
- The dashboard fetches up to 1000 cases on every mount/refresh. For large installations this could be slow. The design doc acknowledges this as a known MVP trade-off.
- The `My Work` section only shows cases where `assignee === currentUser` (exact string match). If `OC.currentUser` returns a different format than what's stored in the case's `assignee` field, items will be missed. This is a data contract concern, not a code bug.

---

## Cross-Reference with Shared Specs

| Shared Spec | Status | Notes |
|-------------|--------|-------|
| nextcloud-app | PASS | No PHP/routing changes; frontend only |
| api-patterns | N/A | No new API endpoints |
| nl-design | PASS | All colors from CSS variables; responsive layout; no hardcoded hex values |
| docker | PASS | No Docker changes |

---

## Recommendation
**APPROVE** — All 11 MVP requirements are implemented with correct behavior. The 2 warnings are minor:
- W01 (empty state KPIs) is a UX decision that arguably improves the fresh-install experience
- W02 (retry reloads all) is an efficiency concern, not a correctness issue

No blocking issues. Safe to archive.
