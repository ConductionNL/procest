# Tasks: task-management

## 1. Utility Functions

- [x] 1.1 Create task lifecycle utility (`src/utils/taskLifecycle.js`)
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-002`
  - **files**: `src/utils/taskLifecycle.js`
  - **acceptance_criteria**:
    - GIVEN a task status WHEN calling `getAllowedTransitions(status)` THEN it returns only valid CMMN transitions (e.g., `available` → `[active, terminated, disabled]`)
    - GIVEN a transition from `available` to `completed` WHEN calling `validateTransition(from, to)` THEN it returns `false`
    - GIVEN a transition from `active` to `completed` WHEN calling `validateTransition(from, to)` THEN it returns `true`
    - Export `TASK_STATUSES` constant with all five statuses
    - Export `getStatusLabel(status)` returning human-readable Dutch/English labels
    - Export `isTerminalStatus(status)` returning `true` for completed/terminated/disabled

- [x] 1.2 Create task helper utilities (`src/utils/taskHelpers.js`)
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-005`, `#REQ-TASK-013`
  - **files**: `src/utils/taskHelpers.js`
  - **acceptance_criteria**:
    - GIVEN a task with dueDate in the past and status `active` WHEN calling `isOverdue(task)` THEN it returns `true`
    - GIVEN a task with status `completed` and dueDate in the past WHEN calling `isOverdue(task)` THEN it returns `false`
    - GIVEN a task due today WHEN calling `isDueToday(task)` THEN it returns `true`
    - GIVEN a task 5 days overdue WHEN calling `getOverdueText(task)` THEN it returns "5 days overdue"
    - Export `formatDueDate(dateString)` returning formatted date (e.g., "Feb 26")
    - Export `prioritySortWeight(priority)` returning sort weight (urgent=1, high=2, normal=3, low=4)
    - Export `PRIORITY_LEVELS` constant with all four levels and their display config (label, color CSS variable)
    - Export `sortTasks(tasks)` that sorts by status group (active first), then priority, then due date

## 2. Task List View

- [x] 2.1 Create `TaskList.vue` component
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-004`
  - **files**: `src/views/tasks/TaskList.vue`
  - **acceptance_criteria**:
    - GIVEN the user navigates to `#/tasks` WHEN the component mounts THEN it fetches tasks via `fetchCollection('task')` and displays a table
    - Table columns: Title (clickable → task detail), Case (clickable → case detail), Status, Assignee, Due Date, Priority
    - GIVEN 23 tasks WHEN the list renders THEN pagination controls appear (page size 20)
    - GIVEN an overdue task WHEN it renders in the table THEN the due date cell shows red overdue text
    - GIVEN a task with priority `urgent` WHEN it renders THEN a red "Urgent" badge is shown
    - Empty state with NcEmptyContent when no tasks found

- [x] 2.2 Add search functionality to TaskList
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-004`
  - **files**: `src/views/tasks/TaskList.vue`
  - **acceptance_criteria**:
    - GIVEN the search input WHEN the user types "bouwtekeningen" THEN after 300ms debounce the list re-fetches with `_search=bouwtekeningen`
    - GIVEN an active search WHEN the user clears the input THEN all tasks are shown again

- [x] 2.3 Add filter controls to TaskList (status, assignee, priority)
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-004`
  - **files**: `src/views/tasks/TaskList.vue`
  - **acceptance_criteria**:
    - GIVEN filter dropdowns for status, assignee, and priority WHEN the user selects status "active" THEN the list re-fetches with `_filters[status]=active`
    - GIVEN multiple active filters WHEN the user clears one THEN remaining filters stay applied
    - Status filter options: all, available, active, completed, terminated, disabled

- [x] 2.4 Add column sorting to TaskList
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-004`
  - **files**: `src/views/tasks/TaskList.vue`
  - **acceptance_criteria**:
    - GIVEN the user clicks the "Due Date" column header THEN tasks are re-fetched with `_order[dueDate]=asc`
    - GIVEN the user clicks the same column again THEN sort order toggles to `desc`
    - Sortable columns: title, status, assignee, dueDate, priority

## 3. Task Detail View

- [x] 3.1 Create `TaskDetail.vue` component (view/edit mode)
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-001`
  - **files**: `src/views/tasks/TaskDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a task UUID in the URL hash WHEN the component mounts THEN it fetches the task via `fetchObject('task', id)` and populates the form
    - Form fields: title (required), description (textarea), assignee (text input), dueDate (date input), priority (select), status (read-only display)
    - GIVEN a completed/terminated task WHEN the form renders THEN all fields are disabled
    - "Back to list" button navigates to `#/tasks`
    - Parent case link navigates to `#/cases/{caseId}`

- [x] 3.2 Add status transition actions to TaskDetail
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-002`, `#REQ-TASK-008`
  - **files**: `src/views/tasks/TaskDetail.vue`, `src/utils/taskLifecycle.js`
  - **acceptance_criteria**:
    - GIVEN a task with status `available` WHEN the detail renders THEN action buttons show: "Start", "Terminate", "Disable"
    - GIVEN a task with status `active` WHEN the user clicks "Complete" THEN status is set to `completed` and `completedDate` to current ISO timestamp
    - GIVEN a task with status `completed` WHEN the detail renders THEN no action buttons are shown
    - Action buttons use `getAllowedTransitions()` from taskLifecycle.js

- [x] 3.3 Add create mode to TaskDetail (new task)
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-001`
  - **files**: `src/views/tasks/TaskDetail.vue`
  - **acceptance_criteria**:
    - GIVEN URL `#/tasks/new?case=caseUuid` WHEN the component mounts THEN it renders an empty form with the case pre-filled
    - GIVEN the user submits with title "Controleer bouwtekeningen" THEN `saveObject('task', { title, case, status: 'available', priority: 'normal' })` is called
    - GIVEN successful creation THEN the user is navigated to the new task's detail page
    - GIVEN empty title WHEN user clicks Save THEN inline validation error "Title is required" appears

- [x] 3.4 Add delete functionality to TaskDetail
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-001`
  - **files**: `src/views/tasks/TaskDetail.vue`
  - **acceptance_criteria**:
    - GIVEN an existing task that is not in a terminal status WHEN the user clicks "Delete" THEN a confirmation dialog appears
    - GIVEN the user confirms deletion THEN `deleteObject('task', id)` is called and user is navigated to task list

## 4. Case Detail Enhancement

- [x] 4.1 Enhance CaseDetail.vue task section with progress tracking
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-008`
  - **files**: `src/views/cases/CaseDetail.vue`
  - **acceptance_criteria**:
    - GIVEN case #2024-042 has 5 tasks, 2 completed WHEN the section header renders THEN it shows "Tasks (2/5)"
    - Task table shows: title (clickable → `#/tasks/{id}`), status badge, assignee, due date (with overdue formatting), priority badge
    - Default sort: available/active first (by priority desc, then due date asc), then completed, then terminated/disabled
    - "New task" button navigates to `#/tasks/new?case={caseId}` instead of inline form

- [x] 4.2 Replace inline task form in CaseDetail with navigation to TaskDetail
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-001`
  - **files**: `src/views/cases/CaseDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the "New task" button in the case detail WHEN clicked THEN navigate to `#/tasks/new?case={caseId}`
    - Remove the inline `showNewTask` / `newTask` form data and `createTask` method
    - Task rows are clickable and navigate to `#/tasks/{taskId}`

## 5. Navigation and Routing

- [x] 5.1 Add "Tasks" item to MainMenu.vue
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-004`
  - **files**: `src/navigation/MainMenu.vue`
  - **acceptance_criteria**:
    - GIVEN the navigation menu WHEN it renders THEN a "Tasks" item appears between "Cases" and the end of the list
    - The item uses `ClipboardCheckOutline` (or similar) icon from vue-material-design-icons
    - GIVEN the user is on `#/tasks` WHEN the menu renders THEN the Tasks item is highlighted as active

- [x] 5.2 Add task routes to App.vue
  - **spec_ref**: design.md (routing section)
  - **files**: `src/App.vue`
  - **acceptance_criteria**:
    - GIVEN URL `#/tasks` WHEN the app renders THEN `TaskList` component is shown
    - GIVEN URL `#/tasks/new` WHEN the app renders THEN `TaskDetail` in create mode is shown
    - GIVEN URL `#/tasks/{uuid}` WHEN the app renders THEN `TaskDetail` with that task loaded is shown
    - Import `TaskList` and `TaskDetail` components

## 6. Styling and Polish

- [x] 6.1 Add priority badge and overdue indicator styles
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-005`, `#REQ-TASK-006`
  - **files**: `src/views/tasks/TaskList.vue`, `src/views/tasks/TaskDetail.vue`
  - **acceptance_criteria**:
    - Priority badges: urgent (red bg), high (orange bg), normal (no badge), low (grey bg)
    - All badge colors use CSS variables (`--color-error` for red, `--color-warning` for orange, `--color-text-maxcontrast` for grey)
    - Overdue text styled in `--color-error`, "Due today" in `--color-warning`
    - Priority and overdue indicators include text labels (not color-only — WCAG AA)

- [x] 6.2 Add empty states and loading indicators
  - **spec_ref**: `procest/openspec/specs/task-management/spec.md#REQ-TASK-004`
  - **files**: `src/views/tasks/TaskList.vue`, `src/views/tasks/TaskDetail.vue`
  - **acceptance_criteria**:
    - GIVEN no tasks exist WHEN the task list renders THEN NcEmptyContent with "No tasks found" and a clipboard icon is shown
    - GIVEN tasks are loading WHEN the list renders THEN NcLoadingIcon is shown
    - GIVEN a task is being saved WHEN the save button is clicked THEN the button shows a loading state

## Verification

- [x] All tasks checked off
- [ ] Manual testing: create, edit, complete, delete tasks in a case
- [ ] Manual testing: global task list with filters, search, sort
- [ ] Manual testing: overdue and priority indicators display correctly
- [ ] Manual testing: status lifecycle transitions are enforced (invalid buttons disabled)
- [ ] Manual testing: case detail shows task progress "X/Y"
