# Tasks: create-procest-app

## 1. Procest App Scaffold

### Task 1: Create Procest app directory structure and info.xml
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-app-scaffold/spec.md#requirement-app-must-be-a-valid-nextcloud-app`
- **files**: `procest/appinfo/info.xml`, `procest/composer.json`, `procest/img/app.svg`
- **acceptance_criteria**:
  - GIVEN the procest directory exists in apps-extra WHEN Nextcloud scans for apps THEN it MUST appear with id `procest`, name "Procest", namespace `Procest`
  - AND it MUST declare compatibility with Nextcloud 28-33 and PHP 8.1+
- [x] Create `procest/` directory with `appinfo/info.xml`, `composer.json`, `img/app.svg`

### Task 2: Create Procest PHP backend (Application, Controllers, Services)
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-app-scaffold/spec.md#requirement-app-must-provide-a-single-page-application-entry-point`
- **files**: `procest/lib/AppInfo/Application.php`, `procest/lib/Controller/DashboardController.php`, `procest/lib/Controller/SettingsController.php`, `procest/lib/Service/SettingsService.php`, `procest/appinfo/routes.php`, `procest/templates/index.php`
- **acceptance_criteria**:
  - GIVEN the app is enabled WHEN a user navigates to `/apps/procest/` THEN the server MUST return an HTML page with a `#content` mount point
  - GIVEN a GET request to `/api/settings` THEN it MUST return register and schema IDs
  - GIVEN an admin POST to `/api/settings` THEN it MUST persist config
- [x] Create Application.php with IBootstrap registration
- [x] Create DashboardController with index action
- [x] Create SettingsController with get/save endpoints
- [x] Create SettingsService for IAppConfig read/write
- [x] Create routes.php and templates/index.php

### Task 3: Create Procest admin settings page
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-app-scaffold/spec.md#requirement-app-must-provide-admin-settings-page`
- **files**: `procest/lib/Settings/AdminSettings.php`, `procest/lib/Settings/AdminSection.php`, `procest/lib/Sections/SettingsSection.php`
- **acceptance_criteria**:
  - GIVEN an admin user WHEN navigating to `/settings/admin/procest` THEN the admin settings page MUST load with the `procest-settings.js` bundle
- [x] Create AdminSettings and AdminSection classes
- [x] Register settings section in info.xml

### Task 4: Create Procest repair step for auto-configuration
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-case-management/spec.md#requirement-case-management-register-must-be-auto-configured-on-install`
- **files**: `procest/lib/Repair/InitializeSettings.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister is active and no `case-management` register exists WHEN Procest is enabled THEN a repair step MUST create the register with schemas for case, task, status, role, result, decision
  - GIVEN a `case-management` register already exists WHEN Procest is enabled THEN it MUST detect and use the existing register
- [x] Create InitializeSettings repair step
- [x] Register repair step in info.xml

### Task 5: Create Procest webpack and frontend entry points
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-app-scaffold/spec.md#requirement-app-must-use-webpack-build-system-extending-nextcloud-base-config`
- **files**: `procest/webpack.config.js`, `procest/package.json`, `procest/src/main.js`, `procest/src/settings.js`, `procest/src/pinia.js`, `procest/src/App.vue`
- **acceptance_criteria**:
  - GIVEN source files exist in `src/` WHEN `npm run build` is executed THEN it MUST produce `js/procest-main.js` and `js/procest-settings.js`
  - AND the Vue app MUST initialize with Pinia state management
- [x] Create package.json with Nextcloud dependencies
- [x] Create webpack.config.js extending @nextcloud/webpack-vue-config
- [x] Create main.js, settings.js, pinia.js, and App.vue entry points

### Task 6: Create Procest l10n translations
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-app-scaffold/spec.md#requirement-app-must-support-multilingual-translations`
- **files**: `procest/l10n/en.json`, `procest/l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a user with English locale WHEN viewing Procest THEN all text MUST be in English
  - GIVEN a user with Dutch locale WHEN viewing Procest THEN all text MUST be in Dutch
- [x] Create l10n/en.json and l10n/nl.json with all UI strings

## 2. Procest Object Store

### Task 7: Create Procest Pinia object store with type registration
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-object-store/spec.md#requirement-object-store-must-use-pinia-with-dynamic-type-registration`
- **files**: `procest/src/store/modules/object.js`
- **acceptance_criteria**:
  - GIVEN app settings are loaded WHEN `registerObjectType('case', schemaId, registerId)` is called THEN the store MUST record the mapping
  - GIVEN an object type is registered WHEN `unregisterObjectType('case')` is called THEN the type MUST be removed and cached data cleared
- [x] Create object store with registerObjectType/unregisterObjectType actions
- [x] Implement objectTypeRegistry state and getters

### Task 8: Create Procest object store CRUD and collection actions
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-object-store/spec.md#requirement-object-store-must-fetch-collections-from-openregister`
- **files**: `procest/src/store/modules/object.js`
- **acceptance_criteria**:
  - GIVEN type `case` is registered WHEN `fetchCollection('case', { _limit: 20 })` is called THEN it MUST fetch from OpenRegister with correct URL
  - GIVEN type `case` is registered WHEN `fetchObject('case', 'uuid-123')` is called THEN it MUST fetch single object by ID
  - GIVEN `saveObject('case', { title: 'New' })` is called with no ID THEN it MUST POST to OpenRegister
  - GIVEN `saveObject('case', { id: 'uuid-123' })` is called THEN it MUST PUT to OpenRegister
  - GIVEN `deleteObject('case', 'uuid-123')` is called THEN it MUST DELETE from OpenRegister
- [x] Implement fetchCollection with pagination and search
- [x] Implement fetchObject for single objects
- [x] Implement saveObject (create/update) and deleteObject
- [x] All requests MUST include requesttoken and OCS-APIREQUEST headers

### Task 9: Create Procest settings store and initialization
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-object-store/spec.md#requirement-object-store-must-load-settings-before-data-operations`
- **files**: `procest/src/store/modules/settings.js`, `procest/src/store/store.js`
- **acceptance_criteria**:
  - GIVEN the app is loading WHEN the store initializes THEN it MUST fetch `/apps/procest/api/settings` first
  - AND it MUST register all object types using the returned IDs
  - AND data fetching MUST NOT proceed until settings are loaded
- [x] Create settings store module with fetchSettings action
- [x] Create main store.js that initializes settings then registers types
- [x] Implement loading and error state tracking per object type

## 3. Procest Case Management Views

### Task 10: Create Procest navigation and routing
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-case-management/spec.md#requirement-navigation-must-include-cases-and-tasks-menu-items`
- **files**: `procest/src/navigation/MainMenu.vue`, `procest/src/App.vue`
- **acceptance_criteria**:
  - GIVEN the user opens Procest WHEN the navigation loads THEN the menu MUST include "Dashboard" and "Cases" items
  - AND clicking each item MUST navigate to the corresponding view
- [x] Create MainMenu.vue with navigation items
- [x] Set up Vue Router in App.vue with routes for dashboard, cases list, case detail

### Task 11: Create Procest cases list and detail views
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-case-management/spec.md#requirement-app-must-provide-a-cases-list-view`
- **files**: `procest/src/views/Dashboard.vue`, `procest/src/views/cases/CaseList.vue`, `procest/src/views/cases/CaseDetail.vue`
- **acceptance_criteria**:
  - GIVEN the user navigates to cases WHEN the page loads THEN it MUST display case title, status, assignee, and created date with pagination
  - GIVEN the user clicks a case WHEN the detail loads THEN it MUST display all case fields and associated tasks
  - GIVEN the user searches THEN the list MUST query OpenRegister with `_search`
- [x] Create Dashboard.vue with summary/welcome content
- [x] Create CaseList.vue with paginated list, search, and "New case" button
- [x] Create CaseDetail.vue with full case fields and task list

### Task 12: Create Procest case and task CRUD forms
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-case-management/spec.md#requirement-app-must-support-case-crud-operations`
- **files**: `procest/src/views/cases/CaseDetail.vue`
- **acceptance_criteria**:
  - GIVEN the user creates a new case THEN the object store MUST POST to OpenRegister
  - GIVEN the user edits a case THEN the object store MUST PUT to OpenRegister
  - GIVEN the user deletes a case THEN the object store MUST DELETE and navigate back to list
  - GIVEN the user creates a task within a case THEN it MUST include a reference to the parent case
- [x] Implement create/edit form in CaseDetail (inline editing mode)
- [x] Implement delete with confirmation
- [x] Implement task creation within case detail

### Task 13: Create Procest admin settings Vue component
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-app-scaffold/spec.md#requirement-app-must-provide-admin-settings-page`
- **files**: `procest/src/views/settings/Settings.vue`
- **acceptance_criteria**:
  - GIVEN an admin WHEN on the settings page THEN they MUST see configuration options for register and schema mappings
- [x] Create Settings.vue with register/schema ID configuration fields

## 4. Pipelinq App Scaffold

### Task 14: Create Pipelinq app directory structure and info.xml
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-app-scaffold/spec.md#requirement-app-must-be-a-valid-nextcloud-app`
- **files**: `pipelinq/appinfo/info.xml`, `pipelinq/composer.json`, `pipelinq/img/app.svg`
- **acceptance_criteria**:
  - GIVEN the pipelinq directory exists in apps-extra WHEN Nextcloud scans for apps THEN it MUST appear with id `pipelinq`, name "Pipelinq", namespace `Pipelinq`
  - AND it MUST declare compatibility with Nextcloud 28-33 and PHP 8.1+
- [x] Create `pipelinq/` directory with `appinfo/info.xml`, `composer.json`, `img/app.svg`

### Task 15: Create Pipelinq PHP backend (Application, Controllers, Services)
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-app-scaffold/spec.md#requirement-app-must-provide-a-single-page-application-entry-point`
- **files**: `pipelinq/lib/AppInfo/Application.php`, `pipelinq/lib/Controller/DashboardController.php`, `pipelinq/lib/Controller/SettingsController.php`, `pipelinq/lib/Service/SettingsService.php`, `pipelinq/appinfo/routes.php`, `pipelinq/templates/index.php`
- **acceptance_criteria**:
  - GIVEN the app is enabled WHEN a user navigates to `/apps/pipelinq/` THEN the server MUST return an HTML page with a `#content` mount point
  - GIVEN a GET request to `/api/settings` THEN it MUST return register and schema IDs (register, client_schema, request_schema, contact_schema)
  - GIVEN an admin POST to `/api/settings` THEN it MUST persist config
- [x] Create Application.php with IBootstrap registration
- [x] Create DashboardController with index action
- [x] Create SettingsController with get/save endpoints
- [x] Create SettingsService for IAppConfig read/write
- [x] Create routes.php and templates/index.php

### Task 16: Create Pipelinq admin settings page
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-app-scaffold/spec.md#requirement-app-must-provide-admin-settings-page`
- **files**: `pipelinq/lib/Settings/AdminSettings.php`, `pipelinq/lib/Settings/AdminSection.php`, `pipelinq/lib/Sections/SettingsSection.php`
- **acceptance_criteria**:
  - GIVEN an admin user WHEN navigating to `/settings/admin/pipelinq` THEN the admin settings page MUST load with the `pipelinq-settings.js` bundle
- [x] Create AdminSettings and AdminSection classes
- [x] Register settings section in info.xml

### Task 17: Create Pipelinq repair step for auto-configuration
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-client-management/spec.md#requirement-client-management-register-must-be-auto-configured-on-install`
- **files**: `pipelinq/lib/Repair/InitializeSettings.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister is active and no `client-management` register exists WHEN Pipelinq is enabled THEN a repair step MUST create the register with schemas for client, request, contact
  - GIVEN a `client-management` register already exists WHEN Pipelinq is enabled THEN it MUST detect and use the existing register
- [x] Create InitializeSettings repair step
- [x] Register repair step in info.xml

### Task 18: Create Pipelinq webpack and frontend entry points
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-app-scaffold/spec.md#requirement-app-must-use-webpack-build-system-extending-nextcloud-base-config`
- **files**: `pipelinq/webpack.config.js`, `pipelinq/package.json`, `pipelinq/src/main.js`, `pipelinq/src/settings.js`, `pipelinq/src/pinia.js`, `pipelinq/src/App.vue`
- **acceptance_criteria**:
  - GIVEN source files exist in `src/` WHEN `npm run build` is executed THEN it MUST produce `js/pipelinq-main.js` and `js/pipelinq-settings.js`
  - AND the Vue app MUST initialize with Pinia state management
- [x] Create package.json with Nextcloud dependencies
- [x] Create webpack.config.js extending @nextcloud/webpack-vue-config
- [x] Create main.js, settings.js, pinia.js, and App.vue entry points

### Task 19: Create Pipelinq l10n translations
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-app-scaffold/spec.md#requirement-app-must-support-multilingual-translations`
- **files**: `pipelinq/l10n/en.json`, `pipelinq/l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a user with English locale WHEN viewing Pipelinq THEN all text MUST be in English
  - GIVEN a user with Dutch locale WHEN viewing Pipelinq THEN all text MUST be in Dutch
- [x] Create l10n/en.json and l10n/nl.json with all UI strings

## 5. Pipelinq Object Store

### Task 20: Create Pipelinq Pinia object store with type registration
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-object-store/spec.md#requirement-object-store-must-use-pinia-with-dynamic-type-registration`
- **files**: `pipelinq/src/store/modules/object.js`
- **acceptance_criteria**:
  - GIVEN app settings are loaded WHEN `registerObjectType('client', schemaId, registerId)` is called THEN the store MUST record the mapping
  - GIVEN an object type is registered WHEN `unregisterObjectType('client')` is called THEN the type MUST be removed and cached data cleared
- [x] Create object store with registerObjectType/unregisterObjectType actions
- [x] Implement objectTypeRegistry state and getters

### Task 21: Create Pipelinq object store CRUD and collection actions
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-object-store/spec.md#requirement-object-store-must-fetch-collections-from-openregister`
- **files**: `pipelinq/src/store/modules/object.js`
- **acceptance_criteria**:
  - GIVEN type `client` is registered WHEN `fetchCollection('client', { _limit: 20 })` is called THEN it MUST fetch from OpenRegister with correct URL
  - GIVEN type `client` is registered WHEN `fetchObject('client', 'uuid-456')` is called THEN it MUST fetch single object by ID
  - GIVEN `saveObject('request', { title: 'New' })` is called with no ID THEN it MUST POST to OpenRegister
  - GIVEN `deleteObject('request', 'uuid-789')` is called THEN it MUST DELETE from OpenRegister
- [x] Implement fetchCollection with pagination and search
- [x] Implement fetchObject for single objects
- [x] Implement saveObject (create/update) and deleteObject
- [x] All requests MUST include requesttoken and OCS-APIREQUEST headers

### Task 22: Create Pipelinq settings store and initialization
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-object-store/spec.md#requirement-object-store-must-load-settings-before-data-operations`
- **files**: `pipelinq/src/store/modules/settings.js`, `pipelinq/src/store/store.js`
- **acceptance_criteria**:
  - GIVEN the app is loading WHEN the store initializes THEN it MUST fetch `/apps/pipelinq/api/settings` first
  - AND it MUST register all object types using the returned IDs
  - AND data fetching MUST NOT proceed until settings are loaded
- [x] Create settings store module with fetchSettings action
- [x] Create main store.js that initializes settings then registers types
- [x] Implement loading and error state tracking per object type

## 6. Pipelinq Client & Request Management Views

### Task 23: Create Pipelinq navigation and routing
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-client-management/spec.md#requirement-navigation-must-include-clients-and-requests-menu-items`
- **files**: `pipelinq/src/navigation/MainMenu.vue`, `pipelinq/src/App.vue`
- **acceptance_criteria**:
  - GIVEN the user opens Pipelinq WHEN the navigation loads THEN the menu MUST include "Dashboard", "Clients", and "Requests" items
  - AND clicking each item MUST navigate to the corresponding view
- [x] Create MainMenu.vue with navigation items
- [x] Set up Vue Router in App.vue with routes for dashboard, clients, requests

### Task 24: Create Pipelinq clients list and detail views
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-client-management/spec.md#requirement-app-must-provide-a-clients-list-view`
- **files**: `pipelinq/src/views/Dashboard.vue`, `pipelinq/src/views/clients/ClientList.vue`, `pipelinq/src/views/clients/ClientDetail.vue`
- **acceptance_criteria**:
  - GIVEN the user navigates to clients WHEN the page loads THEN it MUST display client name, type, email, and phone with pagination
  - GIVEN the user clicks a client WHEN the detail loads THEN it MUST display all client fields, related requests, and contacts
  - GIVEN the user searches THEN the list MUST query OpenRegister with `_search`
- [x] Create Dashboard.vue with summary/welcome content
- [x] Create ClientList.vue with paginated list, search, and "New client" button
- [x] Create ClientDetail.vue with full client fields, requests list, and contacts list

### Task 25: Create Pipelinq client CRUD forms
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-client-management/spec.md#requirement-app-must-support-client-crud-operations`
- **files**: `pipelinq/src/views/clients/ClientDetail.vue`
- **acceptance_criteria**:
  - GIVEN the user creates a new client THEN the object store MUST POST to OpenRegister
  - GIVEN the user edits a client THEN the object store MUST PUT to OpenRegister
  - GIVEN the user deletes a client THEN the object store MUST DELETE and navigate back to list
- [x] Implement create/edit form in ClientDetail (inline editing mode)
- [x] Implement delete with confirmation

### Task 26: Create Pipelinq requests list and detail views
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-client-management/spec.md#requirement-app-must-provide-a-requests-list-view`
- **files**: `pipelinq/src/views/requests/RequestList.vue`, `pipelinq/src/views/requests/RequestDetail.vue`
- **acceptance_criteria**:
  - GIVEN the user navigates to requests WHEN the page loads THEN it MUST display request title, client name, status, priority, and requested date with pagination
  - GIVEN the user clicks a request WHEN the detail loads THEN it MUST display all fields and a link to the associated client
- [x] Create RequestList.vue with paginated list, search, and "New request" button
- [x] Create RequestDetail.vue with full request fields and client link

### Task 27: Create Pipelinq request CRUD forms
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-client-management/spec.md#requirement-app-must-support-request-crud-operations`
- **files**: `pipelinq/src/views/requests/RequestDetail.vue`
- **acceptance_criteria**:
  - GIVEN the user creates a request THEN it MUST be saved to OpenRegister
  - GIVEN the user creates a request from a client detail THEN it MUST include a reference to that client
  - GIVEN the user deletes a request THEN the object store MUST DELETE from OpenRegister
- [x] Implement create/edit form in RequestDetail
- [x] Implement delete with confirmation
- [x] Support creating request pre-linked to a client

### Task 28: Create Pipelinq admin settings Vue component
- **spec_ref**: `openspec/changes/create-procest-app/specs/pipelinq-app-scaffold/spec.md#requirement-app-must-provide-admin-settings-page`
- **files**: `pipelinq/src/views/settings/Settings.vue`
- **acceptance_criteria**:
  - GIVEN an admin WHEN on the settings page THEN they MUST see configuration options for register and schema mappings
- [x] Create Settings.vue with register/schema ID configuration fields

## 7. GitHub & Build

### Task 29: Push initial code to GitHub repositories
- **spec_ref**: `openspec/changes/create-procest-app/specs/procest-app-scaffold/spec.md#requirement-app-must-have-a-github-repository`
- **files**: N/A (git operations)
- **acceptance_criteria**:
  - GIVEN the ConductionNL GitHub org THEN `ConductionNL/procest` MUST exist and be public with the Procest source code
  - AND `ConductionNL/pipelinq` MUST exist and be public with the Pipelinq source code
- [x] Initialize git repos, push Procest code to ConductionNL/procest
- [x] Push Pipelinq code to ConductionNL/pipelinq

## 8. Integration Testing

### Task 30: Build, install, and verify both apps in Docker
- **spec_ref**: All specs
- **files**: N/A (testing)
- **acceptance_criteria**:
  - GIVEN both apps are built WHEN enabled in Nextcloud THEN they MUST activate without errors
  - GIVEN both apps are enabled WHEN browsing to `/apps/procest/` and `/apps/pipelinq/` THEN the SPAs MUST load
  - GIVEN admin settings pages THEN register/schema config MUST be saveable and retrievable
  - GIVEN configured registers THEN list/detail/CRUD operations MUST work for all entity types
- [x] Run `npm run build` in both app directories
- [x] Enable both apps via occ and verify no errors
- [x] Test Procest: dashboard, cases list, case detail, case CRUD
- [x] Test Pipelinq: dashboard, clients list, client detail, client CRUD, requests list, request CRUD
- [x] Test admin settings for both apps

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements
