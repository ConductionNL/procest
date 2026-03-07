# procest-case-management Specification

## Purpose
Define the case management domain for Procest: cases, tasks, statuses, roles, results, and decisions. All entities are stored in OpenRegister under the `case-management` register. The frontend provides list and detail views for cases and tasks.

## ADDED Requirements

### Requirement: Case-management register MUST be auto-configured on install
The app MUST create or detect the `case-management` register and its schemas in OpenRegister during app initialization.

#### Scenario: First install with no existing register
- GIVEN OpenRegister is active and no `case-management` register exists
- WHEN the Procest app is enabled for the first time
- THEN a repair step MUST create the `case-management` register
- AND it MUST create schemas for: case, task, status, role, result, decision
- AND it MUST store the register and schema IDs in app configuration

#### Scenario: Install with existing register
- GIVEN OpenRegister has a `case-management` register already configured
- WHEN the Procest app is enabled
- THEN the repair step MUST detect and use the existing register
- AND it MUST store the found register/schema IDs in app configuration

### Requirement: Settings endpoint MUST return register/schema configuration
The backend MUST provide an API endpoint that returns the configured register and schema IDs.

#### Scenario: Get configuration
- GIVEN the app is configured with register and schema IDs
- WHEN a GET request is made to `/api/settings`
- THEN the response MUST include `register`, `case_schema`, `task_schema`, `status_schema`, `role_schema`, `result_schema`, `decision_schema`
- AND the response status MUST be 200

#### Scenario: Save configuration
- GIVEN an admin user
- WHEN a POST request is made to `/api/settings` with register/schema IDs
- THEN the configuration MUST be persisted in app config
- AND the response MUST confirm success

### Requirement: App MUST provide a cases list view
The frontend MUST display a paginated, searchable list of cases.

#### Scenario: Cases list page
- GIVEN the user navigates to the cases section
- WHEN the page loads
- THEN the object store MUST fetch cases from OpenRegister using the configured register/schema
- AND the list MUST display case title, status, assignee, and created date
- AND the list MUST support pagination

#### Scenario: Cases search
- GIVEN the cases list is displayed
- WHEN the user enters a search term
- THEN the object store MUST query OpenRegister with the `_search` parameter
- AND the list MUST update to show matching results

### Requirement: App MUST provide a case detail view
The frontend MUST display case details with related tasks.

#### Scenario: Case detail page
- GIVEN the user clicks a case in the list
- WHEN the detail view loads
- THEN the object store MUST fetch the full case object by ID
- AND the view MUST display all case fields (title, description, status, assignee, priority, dates)
- AND the view MUST list tasks associated with this case

### Requirement: App MUST support case CRUD operations
The frontend MUST allow creating, editing, and deleting cases via OpenRegister.

#### Scenario: Create case
- GIVEN the user is on the cases list
- WHEN the user clicks "New case" and fills in the form
- THEN the object store MUST POST to OpenRegister with the case data
- AND the new case MUST appear in the list

#### Scenario: Edit case
- GIVEN the user is viewing a case detail
- WHEN the user modifies fields and saves
- THEN the object store MUST PUT to OpenRegister with the updated data
- AND the detail view MUST reflect the changes

#### Scenario: Delete case
- GIVEN the user is viewing a case detail
- WHEN the user confirms deletion
- THEN the object store MUST DELETE the case from OpenRegister
- AND the user MUST be navigated back to the list

### Requirement: App MUST provide task management within cases
The frontend MUST support creating, editing, and completing tasks linked to a case.

#### Scenario: Task list within case
- GIVEN the user is viewing a case detail
- WHEN the tasks section loads
- THEN tasks MUST be fetched from OpenRegister filtered by the case ID
- AND each task MUST show title, status, assignee, and due date

#### Scenario: Create task
- GIVEN the user is viewing a case detail
- WHEN the user creates a new task
- THEN the task MUST be saved to OpenRegister with a reference to the parent case
- AND it MUST appear in the case's task list

### Requirement: Navigation MUST include cases and tasks menu items
The app navigation MUST show menu items for Cases and optionally Tasks.

#### Scenario: Navigation rendering
- GIVEN the user opens the Procest app
- WHEN the navigation loads
- THEN the menu MUST include at minimum a "Dashboard" item and a "Cases" item
- AND clicking "Cases" MUST navigate to the cases list view
