# Procest App - Comprehensive Browser Test Results

**Date:** 2026-02-27
**Environment:** http://localhost:8080/index.php/apps/procest
**Browser:** Playwright Chromium (headless), 1920x1080
**Auth:** admin / admin
**Nextcloud Language:** Dutch (nl)

---

## Navigation Structure

The app sidebar contains:
- **Main list:** Dashboard, My Work, Cases, Tasks, Documentation
- **Footer:** "Instellingen" (Settings) button

The hash-based routing maps to:
- `#/dashboard` -- Dashboard view
- `#/my-work` -- My Work view
- `#/cases` -- Cases list
- `#/tasks` -- Tasks list
- `#/case-types` -- Case Type management (accessed via settings footer)
- `#/settings` -- Configuration page (accessed via settings footer)

**Note:** The Documentation link is intended to open an external link to procest.app.

---

## Page-by-Page Results

### 1. Dashboard (`/#/dashboard`)

- **Status:** PARTIAL
- **Screenshot:** `screenshots/dashboard.png`
- **What renders:**
  - Page heading "Dashboard"
  - Action buttons: "New Case", "New Task", "Refresh dashboard"
  - 4 summary cards: Open Cases (0), Overdue (0), Completed This Month (0), My Tasks (0)
  - Two sections: "Cases by Status" (shows "No open cases") and "My Work" (shows "No items assigned to you")
  - Welcome message: "Welcome to Procest! Get started by creating your first case type in Settings."
- **Issues:**
  - All 4 summary cards show "0" even though My Work page shows 3 assigned cases -- the dashboard data fetch fails before it can populate
  - 4 console errors: Object types "case", "caseType", "statusType", "task" are not registered in the store
  - 2 `@nextcloud/vue` warnings: appName and appVersion not set/replaced
- **Notes:** The dashboard renders its layout correctly but cannot load data due to missing object type registrations in the Pinia store. The store's `initializeStores()` is not registering the required types, causing `_getTypeConfig` to throw.

---

### 2. My Work (`/#/my-work`)

- **Status:** PASS
- **Screenshot:** `screenshots/my-work.png`
- **What renders:**
  - Page heading "My Work (3)"
  - Tab filters: All (3), Cases (3), Tasks (0)
  - "Show completed" checkbox
  - Section "NO DEADLINE (3)" with 3 case items:
    - CASE: DeepLinkTestCase Omgevingsvergunning Kerkstraat
    - CASE: DeepLinkTestCase Omgevingsvergunning Kerkstraat
    - CASE: Test Zaak Deep Link
  - Each case has a dash/status indicator on the right
- **Issues:**
  - 3x HTTP 500 errors fetching tasks for individual case objects (`/api/objects/{register}/{schema}/{uuid}/tasks`)
  - Same 4 "Object type not registered" errors on page load
- **Notes:** The page successfully loads and displays assigned cases. Task sub-fetching fails (500 from OpenRegister task relation endpoint), but cases display correctly.

---

### 3. Cases (`/#/cases`)

- **Status:** PARTIAL
- **Screenshot:** `screenshots/cases.png`
- **What renders:**
  - Page heading "Cases"
  - "New case" button (top-right)
  - Search field: "Search cases..."
  - Filter row: Case type (dropdown), Status (dropdown), Priority (dropdown), Handler (text input), Overdue only (checkbox)
  - Empty state: folder icon, "No cases found", "Create a new case to get started"
- **Issues:**
  - API error [500] for case: Internal Server Error when fetching cases from backend
  - 3x NcSelect `inputLabel` accessibility warnings (Case type, Status, Priority dropdowns missing `inputLabel` or `ariaLabelCombobox`)
  - "caseType" and "statusType" object type registration errors (filter dropdowns can't populate)
- **Notes:** The UI renders correctly with all filter controls. The 500 error from the backend prevents any cases from loading. The NcSelect accessibility warnings should be fixed by adding `inputLabel` props to the filter dropdowns.

---

### 4. Tasks (`/#/tasks`)

- **Status:** PARTIAL
- **Screenshot:** `screenshots/tasks.png`
- **What renders:**
  - Page heading "Tasks"
  - Search field: "Search tasks..."
  - Filter row: Status (dropdown), Priority (dropdown), Assignee (text input)
  - Empty state: clipboard icon, "No tasks found", "Tasks will appear here when created from a case"
- **Issues:**
  - 2x NcSelect `inputLabel` accessibility warnings (Status, Priority dropdowns)
  - "task" object type not registered errors
- **Notes:** The UI renders correctly. No "New task" button on this page (tasks are created from within cases). The empty state message is appropriate.

---

### 5. Case Types (`/#/case-types`)

- **Status:** PARTIAL
- **Screenshot:** `screenshots/case-types.png`
- **What renders:**
  - "Add Case Type" button (top-right, blue)
  - Empty state: document+gear icon, "No case types configured", "Create a case type to define case behavior, statuses, and deadlines"
- **Issues:**
  - "caseType" object type not registered error when fetching existing case types
  - No page heading visible (h2 is missing or not rendered)
- **Notes:** The page renders its empty state correctly. The "Add Case Type" button is present and should allow creating new case types. The missing h2 heading is a minor UI inconsistency compared to other pages.

---

### 6. Configuration / Settings (`/#/settings`)

- **Status:** PASS
- **Screenshot:** `screenshots/settings.png`
- **What renders:**
  - Page heading "Procest" with "Settings" subtitle
  - "Documentation" link (top-right)
  - Configuration form with labeled fields:
    - Register: 6
    - Case schema: 33
    - Task schema: 34
    - Status schema: 35
    - Role schema: 36
    - Result schema: 37
    - Decision schema: 38
    - Case type schema: (empty)
    - Status type schema: (empty)
  - "Save" button
- **Issues:**
  - Case type schema and Status type schema fields are empty -- this is the likely root cause of all "Object type not registered" errors across the app
  - The heading is partially obscured by the sidebar toggle button ("Procest" appears as "rocest" in some viewport widths)
- **Notes:** The settings page is fully functional with all form fields populated (except the two empty ones). The empty "Case type schema" and "Status type schema" fields explain why the store cannot register these types.

---

### 7. Admin Settings (`/settings/admin/procest`)

- **Status:** PASS
- **Screenshot:** `screenshots/admin-settings.png`
- **What renders:**
  - Nextcloud admin settings page with "Procest" selected in left sidebar under "Beheer"
  - Page heading "Procest"
  - "Configuration" section (collapsed, expandable with arrow)
  - "Case Type Management" section with "Add Case Type" button
  - Empty state: "No case types configured", "Create a case type to define case behavior, statuses, and deadlines"
- **Issues:**
  - "caseType" object type not registered error (same root cause)
  - `@nextcloud/vue` appName/appVersion warnings from procest-settings.js
- **Notes:** The admin settings page renders correctly within the Nextcloud settings framework. It provides both Configuration and Case Type Management sections.

---

## Console Error Summary

### Procest-specific errors (unique):

| Error | Severity | Count | Affected Pages |
|-------|----------|-------|----------------|
| Object type "case" is not registered | HIGH | Every page load | Dashboard, Cases, My Work |
| Object type "caseType" is not registered | HIGH | Every page load | Dashboard, Cases, Case Types, Admin Settings |
| Object type "statusType" is not registered | HIGH | Every page load | Dashboard, Cases |
| Object type "task" is not registered | HIGH | Every page load | Dashboard, Tasks |
| API error [500] for case: Internal Server Error | HIGH | Cases page | Cases |
| HTTP 500 fetching tasks for case objects | MEDIUM | My Work | My Work (3 cases) |
| @nextcloud/vue appName not set | LOW | Every page load | All pages |
| @nextcloud/vue appVersion not set | LOW | Every page load | All pages |
| NcSelect missing inputLabel/ariaLabelCombobox | LOW | Filter pages | Cases (3x), Tasks (2x) |
| Error fetching Procest settings | MEDIUM | Cases page | Cases (TypeError in settings fetch) |

### Root Cause Analysis

The **primary root cause** of most errors is that the **Case type schema** and **Status type schema** fields in Settings (`/#/settings`) are empty. The store initialization (`initializeStores()`) uses these schema IDs to register object types. Without them, `_getTypeConfig()` throws "Object type X is not registered" for `case`, `caseType`, `statusType`, and `task`.

**Fix:** Configure the Case type schema and Status type schema IDs in the Procest settings page.

---

## Summary Table

| Page | Route | Status | Screenshot | Key Finding |
|------|-------|--------|------------|-------------|
| Login/Dashboard | `/apps/procest` | PARTIAL | `login-complete.png`, `dashboard.png` | Renders correctly but all counters show 0 due to store errors |
| My Work | `#/my-work` | PASS | `my-work.png` | Shows 3 assigned cases correctly; task sub-fetch fails |
| Cases | `#/cases` | PARTIAL | `cases.png` | UI renders with filters; API 500 prevents data loading |
| Tasks | `#/tasks` | PARTIAL | `tasks.png` | UI renders with filters; empty state (no tasks exist) |
| Case Types | `#/case-types` | PARTIAL | `case-types.png` | Empty state renders; caseType fetch fails |
| Configuration | `#/settings` | PASS | `settings.png` | All fields present; Case type/Status type schemas empty |
| Admin Settings | `/settings/admin/procest` | PASS | `admin-settings.png` | Config + Case Type Management sections render correctly |

### Overall Assessment

The Procest app **UI renders correctly** across all pages. The layout, navigation, components, buttons, filters, and empty states all display as expected. The primary issues are **backend/configuration related**:

1. **Missing schema configuration** -- Case type schema and Status type schema are not configured in Settings, causing store initialization failures
2. **API 500 errors** -- The backend returns Internal Server Error for case/task collection queries, likely because the OpenRegister schemas/register are not fully set up
3. **Accessibility warnings** -- NcSelect components in filter dropdowns are missing `inputLabel` props
4. **Library config warnings** -- @nextcloud/vue `appName` and `appVersion` are not being set during build

The app is in a **functional but unconfigured state** -- once the missing schema IDs are configured and the backend register is properly set up, the data-fetching errors should resolve.
