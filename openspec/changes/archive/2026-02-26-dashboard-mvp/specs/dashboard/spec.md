# Dashboard MVP — Delta Specification

## Purpose

Implement the MVP tier of the dashboard spec (`openspec/specs/dashboard/spec.md`). This change implements all requirements tagged [MVP] from the main spec without modifications.

## ADDED Requirements

All requirements below reference the main spec verbatim. No behavioral changes — this delta confirms the MVP scope being implemented.

### Requirement: KPI Cards Row

The system MUST display four KPI cards: Open Cases (+N today), Overdue (action needed), Completed This Month (avg N days), My Tasks (N due today). Per REQ-DASH-001.

#### Scenario: KPI cards display correct counts
- GIVEN the dashboard loads with cases and tasks in OpenRegister
- WHEN the user views the dashboard
- THEN four KPI cards MUST display with correct counts and sub-labels
- AND counts MUST only include cases visible to the current user (RBAC)

#### Scenario: Zero state for all KPI cards
- GIVEN no cases or tasks exist
- WHEN the user views the dashboard
- THEN all KPI cards MUST show "0" with graceful sub-labels
- AND no errors or broken layouts MUST appear

### Requirement: Cases by Status Chart

The system MUST display a horizontal bar chart showing open case distribution by status type. Per REQ-DASH-002.

#### Scenario: Status bars render proportionally
- GIVEN open cases distributed across multiple statuses
- WHEN the user views the dashboard
- THEN a horizontal bar chart MUST show each status with name and count
- AND bar widths MUST be proportional to count relative to maximum

#### Scenario: Same-named statuses aggregate
- GIVEN multiple case types with identically named statuses
- WHEN the user views the status chart
- THEN counts MUST be aggregated by status name across all case types

### Requirement: Overdue Cases Panel

The system MUST display a panel listing overdue cases sorted by severity. Per REQ-DASH-004.

#### Scenario: Overdue cases listed with details
- GIVEN cases with deadline before today and non-final status
- WHEN the user views the dashboard
- THEN the overdue panel MUST list each case with identifier, title, type, days overdue, and handler
- AND cases MUST be sorted by days overdue descending

#### Scenario: No overdue cases
- GIVEN all open cases have deadline >= today
- WHEN the user views the dashboard
- THEN the overdue panel MUST show a positive message or be hidden

### Requirement: My Work Preview

The system MUST display the top 5 most urgent items assigned to the current user. Per REQ-DASH-005.

#### Scenario: Top 5 items shown
- GIVEN the user has assigned cases and tasks
- WHEN the user views the dashboard
- THEN a "My Work" panel MUST show the top 5 items sorted by priority then deadline
- AND each item MUST show entity type badge, title, deadline, and overdue status

#### Scenario: View all link
- GIVEN the My Work preview is displayed
- WHEN the user clicks "View all my work"
- THEN the system MUST navigate to the full My Work view

### Requirement: Recent Activity Feed

The system MUST display the last 10 case management events. Per REQ-DASH-006.

#### Scenario: Activity feed from case objects
- GIVEN cases with activity arrays containing status changes, updates, notes
- WHEN the user views the dashboard
- THEN the activity feed MUST show the 10 most recent entries across all visible cases
- AND each entry MUST show description, user, and relative timestamp

### Requirement: Quick Actions

The system MUST provide a "+ New Case" button. Per REQ-DASH-007.

#### Scenario: New case button opens dialog
- GIVEN the user is on the dashboard
- WHEN they click "+ New Case"
- THEN the CaseCreateDialog MUST open

### Requirement: Dashboard Data Scope

All dashboard metrics MUST respect RBAC — only cases visible to the current user. Per REQ-DASH-008.

#### Scenario: User sees only their permitted data
- GIVEN user A has access to 20 cases and user B has access to 15
- WHEN user A views the dashboard
- THEN all counts and panels MUST reflect only user A's 20 cases

### Requirement: Empty State

The dashboard MUST show a helpful setup message when no cases exist. Per REQ-DASH-009.

#### Scenario: Fresh install empty state
- GIVEN no cases or case types exist
- WHEN the user views the dashboard
- THEN a welcome message with setup guidance MUST be displayed
- AND KPI cards MUST show "0" without errors

### Requirement: Dashboard Refresh

The dashboard MUST load on mount and support manual refresh. Per REQ-DASH-010.

#### Scenario: Load with skeletons
- GIVEN the user navigates to the dashboard
- WHEN data is loading
- THEN loading skeletons MUST be shown per section
- AND stale data from previous sessions MUST NOT be displayed

#### Scenario: Manual refresh
- GIVEN the user clicks the refresh button
- WHEN the API is available
- THEN all dashboard data MUST be re-fetched and displayed fresh

### Requirement: Error Handling

The dashboard MUST handle errors gracefully. Per REQ-DASH-012.

#### Scenario: Partial section failure
- GIVEN cases load successfully but activity fails
- WHEN the user views the dashboard
- THEN available data MUST be displayed
- AND the failed section MUST show an error with retry option

### Requirement: Dashboard Layout

The dashboard MUST follow the defined layout structure. Per REQ-DASH-013.

#### Scenario: Two-column responsive layout
- GIVEN the user views the dashboard on a wide viewport
- THEN the layout MUST show KPI row at top, then two columns (left: chart + my work; right: overdue + activity)
- AND on narrow viewports the layout MUST collapse to single column

## MODIFIED Requirements

None — the main spec is being implemented as-is.

## REMOVED Requirements

None.
