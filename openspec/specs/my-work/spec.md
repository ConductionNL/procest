# My Work (Werkvoorraad) Specification

## Purpose

My Work is the personal productivity hub for case handlers. It aggregates all work items assigned to the current user -- cases where they are the handler and tasks assigned to them -- into a single prioritized view. Items are grouped by urgency (Overdue, Due This Week, Upcoming, No Deadline) and sorted by priority then deadline within each group. This view answers the daily question: "What do I need to work on next?"

**Feature tiers**: MVP (cases + tasks, filter tabs, sorting, grouping, overdue highlighting, item navigation, empty state); V1 (cross-app workload with Pipelinq, show completed toggle)

## Data Sources

My Work queries two OpenRegister schemas in the `procest` register:
- **Cases**: schema `case` with filter `assignee == currentUser` AND status NOT `isFinal`
- **Tasks**: schema `task` with filter `assignee == currentUser` AND status IN (`available`, `active`)

For V1 cross-app workload:
- **Pipelinq leads**: filter `assignedTo == currentUser` with non-closed stage
- **Pipelinq requests**: filter `assignedTo == currentUser` with non-final status

## Requirements

### REQ-MYWORK-001: Personal Workload View [MVP]

The system MUST provide a "My Work" view showing all cases and tasks assigned to the current user in a unified list.

#### Scenario: View assigned cases and tasks
- GIVEN user "Jan" is handler on 3 cases:
  | identifier | title                     | caseType            | status           | deadline   | priority |
  |------------|---------------------------|---------------------|------------------|------------|----------|
  | 2024-042   | Bouwvergunning Keizersgr  | Omgevingsvergunning | In behandeling   | 2026-02-20 | high     |
  | 2024-038   | Subsidie innovatie        | Subsidieaanvraag    | Besluitvorming   | 2026-02-23 | normal   |
  | 2024-048   | Subsidie verduurzaming    | Subsidieaanvraag    | In behandeling   | 2026-02-28 | normal   |
- AND Jan has 4 tasks assigned:
  | title              | case       | dueDate    | priority | status    |
  |--------------------|-----------:|------------|----------|-----------|
  | Review documents   | 2024-042   | 2026-02-26 | high     | active    |
  | Collect information| 2024-048   | 2026-03-01 | normal   | available |
  | Contact applicant  | 2024-050   | 2026-03-03 | normal   | available |
  | Prepare decision   | 2024-042   | 2026-03-05 | normal   | available |
- WHEN Jan navigates to "My Work"
- THEN the system MUST display all 7 items in a unified list
- AND the total item count "7 items total" MUST be shown

#### Scenario: Case item display
- GIVEN a case item in the My Work list
- THEN the item MUST display:
  - A "[CASE]" badge to identify the entity type
  - The case identifier (e.g., "#2024-042")
  - The case title (e.g., "Bouwvergunning Keizersgracht")
  - The case type name (e.g., "Omgevingsvergunning")
  - The current status name (e.g., "In behandeling")
  - The deadline date
  - Days overdue (red, e.g., "5 days overdue") or days remaining (e.g., "3 days")
  - Priority indicator (if not normal)

#### Scenario: Task item display
- GIVEN a task item in the My Work list
- THEN the item MUST display:
  - A "[TASK]" badge to identify the entity type
  - The task title (e.g., "Review documents")
  - The parent case reference as a clickable link (e.g., "Case: #2024-042 Bouwvergunning Keizersgracht")
  - The due date
  - Days overdue or days remaining
  - Priority indicator (if not normal)

### REQ-MYWORK-002: Filter Tabs [MVP]

The system MUST provide filter tabs to narrow the My Work list by entity type.

#### Scenario: Filter tab layout
- GIVEN the user has 3 cases and 4 tasks
- WHEN they view My Work
- THEN the system MUST display three filter tabs: "All", "Cases", "Tasks"
- AND each tab MUST show the item count in parentheses: "All (7)", "Cases (3)", "Tasks (4)"
- AND the "All" tab MUST be selected by default

#### Scenario: Filter by Cases only
- GIVEN the user has 3 cases and 4 tasks
- WHEN they click the "Cases" tab
- THEN only the 3 case items MUST be shown
- AND the task items MUST be hidden
- AND the grouped sections MUST update to reflect only case items

#### Scenario: Filter by Tasks only
- GIVEN the user has 3 cases and 4 tasks
- WHEN they click the "Tasks" tab
- THEN only the 4 task items MUST be shown
- AND the case items MUST be hidden

#### Scenario: Filter tab with zero items
- GIVEN the user has 3 cases but 0 tasks
- WHEN they view My Work
- THEN the "Tasks" tab MUST show "Tasks (0)"
- AND clicking the "Tasks" tab MUST show an empty state message

### REQ-MYWORK-003: Sorting [MVP]

The system MUST sort My Work items by priority first, then by deadline/dueDate.

#### Scenario: Default sort order
- GIVEN items with mixed priorities and deadlines:
  | item                      | priority | deadline/dueDate |
  |---------------------------|----------|------------------|
  | Case #042 Bouwvergunning  | high     | 2026-02-20       |
  | Task: Review documents    | high     | 2026-02-26       |
  | Case #038 Subsidie innov. | normal   | 2026-02-23       |
  | Case #048 Subsidie verduu.| normal   | 2026-02-28       |
  | Task: Collect information | normal   | 2026-03-01       |
  | Task: Contact applicant   | normal   | 2026-03-03       |
  | Task: Prepare decision    | normal   | 2026-03-05       |
- WHEN the user views My Work without changing sort
- THEN items MUST be sorted by priority (urgent > high > normal > low), then by deadline ascending (soonest first)
- AND the resulting order MUST be as listed above (high-priority items first, then normal sorted by date)

#### Scenario: Items without deadline appear last within priority group
- GIVEN two normal-priority items:
  - Case #048 with deadline 2026-02-28
  - Case #055 with no deadline set
- WHEN the user views My Work
- THEN Case #048 MUST appear before Case #055
- AND Case #055 MUST appear in the "No Deadline" grouped section

### REQ-MYWORK-004: Grouped Sections [MVP]

The system MUST group My Work items into urgency-based sections to provide visual structure.

#### Scenario: Overdue section (red)
- GIVEN cases/tasks where deadline/dueDate is before today
- WHEN the user views My Work
- THEN those items MUST appear in a section titled "OVERDUE"
- AND the section MUST have a red visual treatment (red background tint, red section header, or red left border)
- AND each item within MUST show "X days overdue" in red text
- AND the section MUST appear first (above all other sections)

#### Scenario: Due This Week section
- GIVEN today is Monday, 2026-02-23
- AND there are items with deadline/dueDate between today and Sunday 2026-03-01 (inclusive)
- WHEN the user views My Work
- THEN those items MUST appear in a section titled "DUE THIS WEEK"
- AND each item MUST show the number of days remaining (e.g., "1 day", "3 days")

#### Scenario: Upcoming section
- GIVEN items with deadline/dueDate after the current week
- WHEN the user views My Work
- THEN those items MUST appear in a section titled "UPCOMING"
- AND each item MUST show the due date

#### Scenario: No Deadline section
- GIVEN items with no deadline or dueDate set
- WHEN the user views My Work
- THEN those items MUST appear in a section titled "NO DEADLINE"
- AND this section MUST appear last (below all dated sections)

#### Scenario: Item count per section
- GIVEN 2 overdue items, 3 due this week, and 2 upcoming
- WHEN the user views My Work
- THEN each section header SHOULD display the count of items in that section (e.g., "OVERDUE (2)")

#### Scenario: Empty sections are hidden
- GIVEN no items are overdue
- WHEN the user views My Work
- THEN the "OVERDUE" section MUST NOT be displayed
- AND the first visible section MUST be whichever section has items

### REQ-MYWORK-005: Overdue Highlighting [MVP]

The system MUST visually distinguish overdue items from on-time items.

#### Scenario: Overdue case highlighting
- GIVEN case #2024-042 has deadline 2026-02-20 and today is 2026-02-25
- AND the case status is "In behandeling" (not final)
- WHEN the user views My Work
- THEN the case MUST be displayed with a red visual indicator (red background, red badge, or red left border)
- AND the text "5 days overdue" MUST be displayed in red
- AND the deadline date MUST be shown

#### Scenario: Overdue task highlighting
- GIVEN a task "Review documents" has dueDate 2026-02-24 and today is 2026-02-25
- AND the task status is "active"
- WHEN the user views My Work
- THEN the task MUST be displayed with a red visual indicator
- AND the text "1 day overdue" MUST be displayed in red

#### Scenario: Non-overdue item (normal display)
- GIVEN a case with deadline 2026-02-28 and today is 2026-02-25
- WHEN the user views My Work
- THEN the case MUST be displayed without red highlighting
- AND the text "3 days" MUST be displayed in a neutral color

### REQ-MYWORK-006: Default Filter -- Non-Final Items Only [MVP]

By default, My Work MUST only show open (non-completed) items.

#### Scenario: Only non-final cases shown by default
- GIVEN the user is handler on 5 cases: 3 with non-final status, 2 with final status ("Afgehandeld")
- WHEN they view My Work
- THEN only the 3 non-final cases MUST be shown
- AND the 2 completed cases MUST be hidden

#### Scenario: Only non-completed tasks shown by default
- GIVEN the user has 6 tasks: 4 with status `available` or `active`, 2 with status `completed`
- WHEN they view My Work
- THEN only the 4 open tasks MUST be shown

#### Scenario: Toggle to show completed items
- GIVEN the user is viewing My Work with 3 open items
- AND they have 2 completed items hidden
- WHEN they toggle the "Show completed" control
- THEN all 5 items MUST be displayed
- AND completed items MUST be visually distinguished (e.g., strikethrough, muted colors, or a "Completed" badge)
- AND completed items SHOULD appear at the bottom of the list, below all open items

### REQ-MYWORK-007: Item Navigation [MVP]

Clicking an item in My Work MUST navigate to the appropriate detail view.

#### Scenario: Click case item to navigate
- GIVEN case #2024-042 appears in My Work
- WHEN the user clicks on the case item
- THEN the system MUST navigate to the case detail view for case #2024-042

#### Scenario: Click task item to navigate
- GIVEN a task "Review documents" for case #2024-042 appears in My Work
- WHEN the user clicks on the task item
- THEN the system MUST navigate to the task detail or the parent case detail view with the task highlighted

#### Scenario: Click parent case reference on task
- GIVEN a task item shows "Case: #2024-042 Bouwvergunning Keizersgracht" as a clickable reference
- WHEN the user clicks on the parent case reference (not the task itself)
- THEN the system MUST navigate to the case detail view for case #2024-042

### REQ-MYWORK-008: Cross-App Workload [V1]

The My Work view SHOULD include items from Pipelinq (leads and requests) assigned to the current user.

#### Scenario: Include Pipelinq leads and requests
- GIVEN the current user has:
  - 2 cases in Procest
  - 3 tasks in Procest
  - 1 lead in Pipelinq (assigned to them)
  - 2 requests in Pipelinq (assigned to them)
- WHEN they view My Work with cross-app integration enabled
- THEN all 8 items MUST appear in a unified list
- AND each item MUST be labeled with its source: [CASE], [TASK], [LEAD], [REQUEST]
- AND Pipelinq items MUST follow the same sorting and grouping rules as Procest items

#### Scenario: Cross-app filter tabs
- GIVEN cross-app workload is enabled and the user has items from both Procest and Pipelinq
- WHEN they view My Work
- THEN the filter tabs MUST include: "All", "Cases", "Tasks", "Leads", "Requests"
- AND each tab MUST show its item count

#### Scenario: Pipelinq app not installed
- GIVEN the Pipelinq app is not installed on this Nextcloud instance
- WHEN the user views My Work
- THEN the system MUST show only Procest items (cases and tasks)
- AND no Pipelinq-related filter tabs MUST be shown
- AND no error messages MUST appear about Pipelinq being unavailable

### REQ-MYWORK-009: Empty State [MVP]

The system MUST display a helpful message when the user has no assigned items.

#### Scenario: No assigned items
- GIVEN the current user has no cases where they are handler and no tasks assigned to them
- WHEN they navigate to "My Work"
- THEN the system MUST display an empty state with:
  - A friendly message (e.g., "You have no cases or tasks assigned to you")
  - Guidance on how items appear here (e.g., "Cases and tasks assigned to you will appear in this view")
- AND the filter tabs MUST all show "(0)"

#### Scenario: All items completed (show-completed toggle off)
- GIVEN the user has 5 items but all have reached final/completed status
- AND the "Show completed" toggle is off
- WHEN they view My Work
- THEN the system MUST display a contextual empty state (e.g., "All caught up! No open items.")
- AND the system SHOULD indicate that completed items can be shown via the toggle

### REQ-MYWORK-010: Concurrent State Changes [MVP]

The system MUST handle cases where items change status while the user is viewing My Work.

#### Scenario: Case closed while viewing My Work
- GIVEN the user is viewing My Work with case #2024-042 listed
- AND another user changes case #2024-042 to a final status
- WHEN the user refreshes My Work (or the data auto-refreshes)
- THEN case #2024-042 MUST no longer appear in the list (unless "Show completed" is on)
- AND the item counts MUST update accordingly

#### Scenario: Case deleted while in My Work list
- GIVEN the user is viewing My Work with case #2024-042 listed
- AND case #2024-042 is deleted by an admin
- WHEN the user clicks on case #2024-042
- THEN the system MUST display a "Case not found" message or redirect to the case list
- AND the system MUST NOT show an unhandled error
- AND on next refresh, the deleted case MUST no longer appear in My Work

#### Scenario: Task reassigned away from user
- GIVEN the user is viewing My Work with task "Review documents" listed
- AND the task is reassigned to a different user
- WHEN the user refreshes My Work
- THEN the task MUST no longer appear in the list
- AND the item counts MUST update accordingly

## Non-Functional Requirements

- **Performance**: My Work MUST load within 1 second for users with up to 100 assigned items. The two queries (cases + tasks) SHOULD be executed in parallel.
- **Accessibility**: Each item MUST be keyboard-navigable. Screen readers MUST announce the entity type, title, urgency status, and deadline. Overdue visual indicators MUST NOT rely solely on color (use text "X days overdue" as well). All content MUST meet WCAG AA standards.
- **Localization**: All labels, section titles, date formatting, and relative time expressions (e.g., "5 days overdue", "3 days") MUST support English and Dutch localization.
- **Responsiveness**: The My Work view MUST adapt to narrow viewports, maintaining readability of all item fields on mobile screens.
