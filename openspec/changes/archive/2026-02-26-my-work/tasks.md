# Tasks: my-work

**Dependency**: OpenRegister `object-interactions` change must be implemented first (TaskService, TasksController API).

## 1. Task API Wrapper

- [x] 1.1 Create `src/services/taskApi.js` — Thin module that wraps OpenRegister's tasks convenience API. Exports: `fetchTasksForRegister(registerId)` → fetches all CalDAV tasks linked to the Procest register for the current user; `normalizeCalDavTask(task)` → maps CalDAV task JSON (`{ uid, summary, due, status, priority, objectUuid, ... }`) to the work item shape (`{ type: 'task', id, title, reference, deadline, daysText, isOverdue, priority }`). Priority mapping: iCalendar 1-3→'urgent', 4→'high', 5-6→'normal', 7-9→'low', 0→'normal'. Uses `fetch()` with requesttoken header to call `GET /apps/openregister/api/objects/{register}/tasks`.

## 2. Helper — Grouped My Work Items

- [x] 2.1 Add `getGroupedMyWorkItems(cases, normalizedTasks)` to `src/utils/dashboardHelpers.js` — Accepts OpenRegister case objects and already-normalized CalDAV task items. Returns `{ overdue, dueThisWeek, upcoming, noDeadline, totalCount }`. Each group is an array of work items with shape `{ type, id, title, reference, deadline, daysText, isOverdue, priority }`. Build case items using existing logic from `getMyWorkItems()`. Classify all items by comparing deadline to today and end-of-week (Sunday). Sort within each group by priority then deadline. Skip the limit (return all items).

## 3. View — MyWork.vue

- [x] 3.1 Create `src/views/MyWork.vue` — Full "My Work" view with: (a) header showing title "My Work" and total item count, (b) filter tabs (All/Cases/Tasks) with counts in parentheses, (c) "Show completed" toggle checkbox, (d) grouped sections (OVERDUE with red section header, DUE THIS WEEK, UPCOMING, NO DEADLINE) — each section has a header with count and hides when empty, (e) each item row with type badge (CASE blue / TASK green), title, reference/linked case link, deadline info, priority indicator, (f) overdue items highlighted with red left border and red days text, (g) click handler navigating to case-detail (for cases) or the linked case detail (for tasks), (h) loading state with NcLoadingIcon, (i) empty state with NcEmptyContent ("No items assigned to you"), (j) "all caught up" message when all items are completed but toggle is off. Fetch data in `mounted()`: call `objectStore.fetchCollection('case', { '_filters[assignee]': currentUser })` for cases AND `fetchTasksForRegister(registerId)` for CalDAV tasks in parallel. Use `getGroupedMyWorkItems()` for grouping. Filter tabs filter the grouped data client-side. "Show completed" toggle fetches additional completed cases (final status) and completed CalDAV tasks (STATUS=COMPLETED) and shows them in a muted COMPLETED section at the bottom. Follow CaseList.vue patterns for styling.

## 4. Routing — App.vue

- [x] 4.1 Update `src/App.vue` — Import MyWork component, add `'my-work'` case to `currentView` computed (returns `'MyWork'`), register MyWork in components.

## 5. Navigation — MainMenu.vue

- [x] 5.1 Update `src/navigation/MainMenu.vue` — Add a "My Work" navigation item between Dashboard and Cases. Use `AccountCheck` icon from vue-material-design-icons. Set active state for `currentRoute === 'my-work'`.

## 6. Verification

- [ ] 6.1 Verify "My Work" nav item appears and is clickable
- [ ] 6.2 Verify Dashboard "View all my work" link navigates to My Work view
- [ ] 6.3 Verify cases from OpenRegister and CalDAV tasks both appear in the list
- [ ] 6.4 Verify items are grouped into correct urgency sections (overdue/this week/upcoming/no deadline)
- [ ] 6.5 Verify filter tabs (All/Cases/Tasks) filter items and show correct counts
- [ ] 6.6 Verify clicking a case navigates to case-detail
- [ ] 6.7 Verify clicking a task navigates to the linked case detail
- [ ] 6.8 Verify empty state shows when user has no assigned items
- [ ] 6.9 Verify overdue items have red highlighting
- [ ] 6.10 Verify "Show completed" toggle shows completed items in muted section
