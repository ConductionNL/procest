# Dashboard Specification

## Purpose

The dashboard is the landing page of the Procest app. It provides an at-a-glance overview of case management activity: KPI cards with headline metrics, status and type distribution charts, an overdue cases panel, a personal workload preview, a recent activity feed, and quick actions. The dashboard aggregates data across all cases visible to the current user (respecting RBAC via OpenRegister).

**Feature tiers**: MVP (KPI cards, status chart, overdue panel, my work preview, activity feed, quick actions, empty state, refresh); V1 (average processing time KPI, case type breakdown chart)

## Data Sources

All dashboard data comes from OpenRegister queries against the `procest` register:
- **Cases**: schema `case` — filtered by non-final status for "open", by `deadline < today` for "overdue", by `endDate` within current month for "completed this month"
- **Tasks**: schema `task` — filtered by `assignee == currentUser` and status `available` or `active`
- **Activity**: Nextcloud Activity API (`OCP\Activity\IManager`) — filtered by app `procest`, last 10 events

## Requirements

### REQ-DASH-001: KPI Cards Row [MVP]

The dashboard MUST display a row of four KPI cards at the top, providing headline metrics for the current user's case management workload.

#### Scenario: Open cases count with today indicator
- GIVEN there are 24 cases with non-final status visible to the current user
- AND 3 of those cases were created today (startDate == today)
- WHEN the user views the dashboard
- THEN the system MUST display a KPI card titled "Open Cases"
- AND the card MUST show the count "24"
- AND the card MUST show a sub-label "+3 today"
- AND the count MUST only include cases whose current status is not marked `isFinal`

#### Scenario: Overdue cases count with action indicator
- GIVEN there are 3 cases where `deadline < today` and status is not final
- WHEN the user views the dashboard
- THEN the system MUST display a KPI card titled "Overdue"
- AND the card MUST show the count "3"
- AND the card MUST show a warning sub-label (e.g., "action needed") to indicate urgency
- AND clicking the card SHOULD navigate to a filtered view showing only overdue cases

#### Scenario: Completed this month with average processing days
- GIVEN 12 cases reached a final status during the current calendar month
- AND those 12 cases had an average duration of 18 days (from `startDate` to `endDate`)
- WHEN the user views the dashboard
- THEN the system MUST display a KPI card titled "Completed This Month"
- AND the card MUST show the count "12"
- AND the card MUST show a sub-label "avg 18 days"

#### Scenario: My tasks count with due-today indicator
- GIVEN the current user has 7 tasks assigned with status `available` or `active`
- AND 2 of those tasks have `dueDate == today`
- WHEN the user views the dashboard
- THEN the system MUST display a KPI card titled "My Tasks"
- AND the card MUST show the count "7"
- AND the card MUST show a sub-label "2 due today"

#### Scenario: Zero values in KPI cards
- GIVEN no cases exist in the system
- WHEN the user views the dashboard
- THEN each KPI card MUST show "0" as the count
- AND sub-labels MUST either show "0 today" / "none" or be omitted gracefully
- AND the cards MUST NOT show errors or broken layouts

### REQ-DASH-002: Cases by Status Chart [MVP]

The dashboard MUST display a horizontal bar chart showing the distribution of open cases across status types.

#### Scenario: Status distribution with multiple statuses
- GIVEN open cases distributed as: Ontvangen (8), In behandeling (6), Besluitvorming (5), Bezwaar (3), Afgehandeld today (2)
- WHEN the user views the dashboard
- THEN the system MUST display a horizontal bar chart titled "Cases by Status"
- AND each bar MUST show the status name on the left and the count on the right
- AND bars MUST be ordered by count (descending) or by status order (ascending) -- the implementation SHOULD use status order from case types for consistency
- AND each bar's length MUST be proportional to its count relative to the maximum

#### Scenario: Statuses with zero cases
- GIVEN a status type "Bezwaar" exists but no cases currently have that status
- WHEN the user views the status chart
- THEN the system MAY omit statuses with zero cases from the chart
- OR the system MAY show them with an empty bar and count "0"

#### Scenario: Multiple case types with same-named statuses
- GIVEN case type "Omgevingsvergunning" has status "In behandeling" (3 cases)
- AND case type "Subsidieaanvraag" also has status "In behandeling" (4 cases)
- WHEN the user views the status chart
- THEN the system MUST aggregate cases by status name across case types
- AND the chart MUST show "In behandeling" with count 7

### REQ-DASH-003: Cases by Type Chart [V1]

The dashboard SHOULD display a bar chart showing the distribution of open cases by case type.

#### Scenario: Case type distribution
- GIVEN open cases distributed as: Omgevingsvergunning (10), Subsidieaanvraag (7), Klacht (4), Melding (3)
- WHEN the user views the dashboard
- THEN the system MUST display a bar chart titled "Cases by Type"
- AND each bar MUST show the case type title and the count
- AND bars MUST be ordered by count descending

#### Scenario: Case type with no open cases
- GIVEN a published case type "Bezwaarschrift" exists but has no open cases
- WHEN the user views the case type chart
- THEN the system MAY omit types with zero open cases
- OR the system MAY show them with a zero-count bar

### REQ-DASH-004: Overdue Cases Panel [MVP]

The dashboard MUST display a panel listing cases that have exceeded their processing deadline.

#### Scenario: Overdue cases list with details
- GIVEN the following overdue cases:
  | identifier | title                    | caseType             | daysOverdue | assignee |
  |------------|--------------------------|----------------------|-------------|----------|
  | 2024-042   | Bouwvergunning Keizersgr | Omgevingsvergunning  | 5           | Jan      |
  | 2024-038   | Subsidie innovatie       | Subsidieaanvraag     | 2           | Maria    |
- AND case #2024-045 "Klacht behandeling" is due tomorrow (not yet overdue)
- WHEN the user views the dashboard
- THEN the system MUST display an "Overdue Cases" panel
- AND the panel MUST list each overdue case showing: identifier, title, case type, days overdue, and handler name
- AND cases MUST be sorted by days overdue descending (most overdue first)
- AND case #2024-045 MUST NOT appear in this panel (it is not yet overdue)

#### Scenario: Overdue case visual severity
- GIVEN a case that is 5 days overdue
- AND a case that is due tomorrow (1 day remaining)
- WHEN the user views the overdue panel
- THEN overdue cases MUST be displayed with a red indicator
- AND cases due within 1 day MAY be displayed with a yellow/warning indicator in a separate "at risk" section or alongside overdue cases

#### Scenario: Overdue panel with "view all" link
- GIVEN there are 8 overdue cases
- WHEN the user views the dashboard
- THEN the panel MUST show all overdue cases (or a scrollable list if many)
- AND the panel MUST include a "View all overdue" link that navigates to the case list filtered by overdue status

#### Scenario: No overdue cases
- GIVEN all open cases have `deadline >= today`
- WHEN the user views the dashboard
- THEN the overdue panel MUST display a positive message (e.g., "No overdue cases") or be hidden
- AND the KPI card for overdue MUST show "0"

### REQ-DASH-005: My Work Preview [MVP]

The dashboard MUST display a preview of the current user's personal workload, showing the top 5 most urgent items.

#### Scenario: My Work preview shows top 5 items
- GIVEN the current user is handler on 3 cases and has 4 tasks assigned
- WHEN the user views the dashboard
- THEN the system MUST display a "My Work" preview panel showing the top 5 items
- AND items MUST be sorted by priority (urgent first), then deadline/dueDate (soonest first)
- AND each item MUST show: entity type badge ([CASE] or [TASK]), title, case type or parent case reference, deadline/dueDate, and overdue status if applicable

#### Scenario: My Work preview link to full view
- GIVEN the My Work preview is displayed
- WHEN the user clicks "View all my work"
- THEN the system MUST navigate to the full My Work view

#### Scenario: My Work preview with no items
- GIVEN the current user has no assigned cases or tasks
- WHEN the user views the dashboard
- THEN the My Work preview MUST display a message such as "No items assigned to you"

### REQ-DASH-006: Recent Activity Feed [MVP]

The dashboard MUST display a feed of the last 10 case management events.

#### Scenario: Activity feed shows recent events
- GIVEN the following recent events occurred:
  1. Case #042 status changed to "In behandeling" by Jan (10 min ago)
  2. Decision recorded on Case #036 "Vergunning verleend" by Maria (1 hour ago)
  3. Task "Review docs" completed by Pieter (2 hours ago)
  4. Document "Situatietekening" uploaded on Case #042 (yesterday)
- WHEN the user views the dashboard
- THEN the system MUST display a "Recent Activity" feed
- AND the feed MUST show the last 10 events ordered by timestamp descending (most recent first)
- AND each event MUST show: event description, actor name, and relative timestamp
- AND the event types displayed MUST include: status changes, task completions, decisions, document uploads

#### Scenario: Activity feed "view all" link
- GIVEN the activity feed is displayed
- WHEN the user clicks "View all activity"
- THEN the system MUST navigate to a full activity view or the Nextcloud activity app filtered to Procest events

#### Scenario: Activity feed with no events
- GIVEN no Procest activity events have been recorded
- WHEN the user views the dashboard
- THEN the activity feed MUST display a message such as "No recent activity"

### REQ-DASH-007: Quick Actions [MVP]

The dashboard MUST provide quick action buttons for common case management tasks.

#### Scenario: New Case button
- GIVEN the user is on the dashboard
- WHEN they click the "+ New Case" button
- THEN the system MUST navigate to the case creation form
- AND the case creation form MUST pre-select the default case type (if one is configured)

#### Scenario: Quick action visibility
- GIVEN the user is on the dashboard
- THEN the "+ New Case" button MUST be prominently visible, placed in the top-right area of the dashboard or header bar

### REQ-DASH-008: Dashboard Data Scope [MVP]

The dashboard MUST aggregate data across all cases visible to the current user, respecting RBAC.

#### Scenario: Dashboard respects user permissions
- GIVEN user "Jan" has access to 20 cases via RBAC
- AND user "Maria" has access to 15 cases (some overlapping with Jan's)
- WHEN Jan views the dashboard
- THEN all counts, charts, and panels MUST reflect only the 20 cases Jan can access
- AND the system MUST NOT expose data from cases Jan cannot access

#### Scenario: Admin sees all cases
- GIVEN an admin user has access to all 50 cases in the system
- WHEN the admin views the dashboard
- THEN all dashboard metrics MUST reflect all 50 cases

### REQ-DASH-009: Empty State [MVP]

The dashboard MUST display a helpful setup message when no cases exist.

#### Scenario: Fresh installation with no data
- GIVEN Procest was just installed and no cases or case types exist
- WHEN the user views the dashboard
- THEN the system MUST display an empty state with:
  - A friendly message explaining what Procest does (e.g., "Welcome to Procest - Case Management for Nextcloud")
  - A call-to-action to create the first case type (for admins) or inform non-admins that the app needs configuration
  - Helpful guidance or a link to documentation
- AND all KPI cards MUST show "0" without errors
- AND charts MUST either be hidden or show an empty state

#### Scenario: Cases exist but user has no access
- GIVEN cases exist but the current user has no RBAC access to any of them
- WHEN the user views the dashboard
- THEN the dashboard MUST show zero values and empty panels
- AND the system SHOULD display a message such as "You have no cases assigned yet"

### REQ-DASH-010: Dashboard Refresh Behavior [MVP]

The dashboard MUST load data on mount and support manual refresh.

#### Scenario: Dashboard loads data on mount
- GIVEN the user navigates to the dashboard
- WHEN the dashboard component mounts
- THEN the system MUST fetch all dashboard data (KPI metrics, chart data, overdue list, my work items, activity feed) from the API
- AND the system SHOULD show loading skeletons or spinners while data is being fetched
- AND the system MUST NOT display stale data from a previous session

#### Scenario: Manual refresh button
- GIVEN the user is viewing the dashboard
- WHEN they click the refresh button
- THEN the system MUST re-fetch all dashboard data from the API
- AND the system SHOULD show a brief loading indicator during refresh
- AND the data displayed MUST reflect the current state after refresh completes

#### Scenario: API error during dashboard load
- GIVEN the OpenRegister API is temporarily unavailable
- WHEN the user navigates to the dashboard
- THEN the system MUST display an error message (e.g., "Unable to load dashboard data")
- AND the system MUST provide a retry option
- AND the system MUST NOT display partial or misleading data

### REQ-DASH-011: Average Processing Time KPI [V1]

The dashboard SHOULD display the average processing time across completed cases.

#### Scenario: Average processing time calculation
- GIVEN 12 cases were completed this month with durations: 14, 16, 18, 20, 22, 15, 17, 19, 21, 13, 19, 22 days
- WHEN the user views the dashboard
- THEN the "Completed This Month" KPI card MUST show the average duration as "avg 18 days"
- AND the average MUST be calculated as the arithmetic mean of `endDate - startDate` for all cases completed in the current calendar month

#### Scenario: No completed cases this month
- GIVEN no cases have reached a final status in the current calendar month
- WHEN the user views the dashboard
- THEN the "Completed This Month" KPI card MUST show "0"
- AND the average sub-label MUST show "no data" or be omitted

### REQ-DASH-012: Error Scenarios [MVP]

The dashboard MUST handle error conditions gracefully.

#### Scenario: Dashboard for user with no permissions
- GIVEN a user who is authenticated but has no RBAC permissions for any cases
- WHEN they view the dashboard
- THEN the system MUST display zero values in all KPI cards
- AND the system MUST NOT show error messages related to permissions
- AND the system SHOULD display a helpful message (e.g., "No cases assigned to you yet")

#### Scenario: Partial data load failure
- GIVEN the cases API returns data but the activity API fails
- WHEN the user views the dashboard
- THEN the system MUST display the available data (KPI cards, charts)
- AND the failed section (activity feed) MUST show a localized error message with a retry option
- AND the system MUST NOT block the entire dashboard due to a single section failure

#### Scenario: Dashboard with deleted case type
- GIVEN a case references a case type that has been deleted or is no longer valid
- WHEN the user views the dashboard
- THEN the case MUST still be counted in KPI metrics and charts
- AND the case type name SHOULD fall back to "Unknown type" or the stored identifier
- AND the system MUST NOT crash or show an unhandled error

### REQ-DASH-013: Dashboard Layout [MVP]

The dashboard MUST follow the layout structure defined in the design reference (DESIGN-REFERENCES.md section 3.1).

#### Scenario: Layout structure
- GIVEN the user views the dashboard
- THEN the page MUST display the following sections in order:
  1. KPI cards row (4 cards: Open Cases, Overdue, Completed This Month, My Tasks)
  2. Two-column layout below the KPI row:
     - Left column: Cases by Status chart, Cases by Type chart (V1), My Work preview
     - Right column: Overdue Cases panel, Recent Activity feed
- AND the layout MUST be responsive, collapsing to a single column on narrow viewports

#### Scenario: Navigation header
- GIVEN the user is on the dashboard
- THEN the navigation MUST include tabs or links for: Dashboard, Cases, Tasks, Decisions, My Work, and Settings (admin only)
- AND the Dashboard tab MUST be visually marked as active

## Non-Functional Requirements

- **Performance**: Dashboard MUST load within 2 seconds for up to 1000 cases. Individual API calls SHOULD complete within 500ms.
- **Accessibility**: All KPI cards MUST have appropriate ARIA labels. Charts MUST have text alternatives. The dashboard MUST meet WCAG AA standards.
- **Localization**: All labels, messages, and date formatting MUST support English and Dutch localization.
- **Caching**: Dashboard data MAY be cached client-side for up to 60 seconds to reduce API load, but MUST be refreshable on demand.
