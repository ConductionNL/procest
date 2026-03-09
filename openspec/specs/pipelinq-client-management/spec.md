# pipelinq-client-management Specification

## Purpose
Define the client and request management domain for Pipelinq: clients, requests (verzoeken), and contacts. All entities are stored in OpenRegister under the `client-management` register. Requests represent the pre-state of a case — a yet-to-be-determined or incoming case before it enters formal case management in Procest.

## ADDED Requirements

### Requirement: Client-management register MUST be auto-configured on install
The app MUST create or detect the `client-management` register and its schemas in OpenRegister during app initialization.

#### Scenario: First install with no existing register
- GIVEN OpenRegister is active and no `client-management` register exists
- WHEN the Pipelinq app is enabled for the first time
- THEN a repair step MUST create the `client-management` register
- AND it MUST create schemas for: client, request, contact
- AND it MUST store the register and schema IDs in app configuration

#### Scenario: Install with existing register
- GIVEN OpenRegister has a `client-management` register already configured
- WHEN the Pipelinq app is enabled
- THEN the repair step MUST detect and use the existing register
- AND it MUST store the found register/schema IDs in app configuration

### Requirement: Settings endpoint MUST return register/schema configuration
The backend MUST provide an API endpoint that returns the configured register and schema IDs.

#### Scenario: Get configuration
- GIVEN the app is configured with register and schema IDs
- WHEN a GET request is made to `/api/settings`
- THEN the response MUST include `register`, `client_schema`, `request_schema`, `contact_schema`
- AND the response status MUST be 200

#### Scenario: Save configuration
- GIVEN an admin user
- WHEN a POST request is made to `/api/settings` with register/schema IDs
- THEN the configuration MUST be persisted in app config
- AND the response MUST confirm success

### Requirement: App MUST provide a clients list view
The frontend MUST display a paginated, searchable list of clients.

#### Scenario: Clients list page
- GIVEN the user navigates to the clients section
- WHEN the page loads
- THEN the object store MUST fetch clients from OpenRegister using the configured register/schema
- AND the list MUST display client name, type (person/organization), email, and phone
- AND the list MUST support pagination

#### Scenario: Clients search
- GIVEN the clients list is displayed
- WHEN the user enters a search term
- THEN the object store MUST query OpenRegister with the `_search` parameter
- AND the list MUST update to show matching results

### Requirement: App MUST provide a client detail view
The frontend MUST display client details with related requests and contacts.

#### Scenario: Client detail page
- GIVEN the user clicks a client in the list
- WHEN the detail view loads
- THEN the object store MUST fetch the full client object by ID
- AND the view MUST display all client fields (name, type, email, phone, address, notes)
- AND the view MUST list requests associated with this client
- AND the view MUST list contacts associated with this client

### Requirement: App MUST support client CRUD operations
The frontend MUST allow creating, editing, and deleting clients via OpenRegister.

#### Scenario: Create client
- GIVEN the user is on the clients list
- WHEN the user clicks "New client" and fills in the form
- THEN the object store MUST POST to OpenRegister with the client data
- AND the new client MUST appear in the list

#### Scenario: Edit client
- GIVEN the user is viewing a client detail
- WHEN the user modifies fields and saves
- THEN the object store MUST PUT to OpenRegister with the updated data
- AND the detail view MUST reflect the changes

#### Scenario: Delete client
- GIVEN the user is viewing a client detail
- WHEN the user confirms deletion
- THEN the object store MUST DELETE the client from OpenRegister
- AND the user MUST be navigated back to the list

### Requirement: App MUST provide a requests list view
The frontend MUST display a paginated, searchable list of requests (verzoeken).

#### Scenario: Requests list page
- GIVEN the user navigates to the requests section
- WHEN the page loads
- THEN the object store MUST fetch requests from OpenRegister
- AND the list MUST display request title, client name, status, priority, and requested date
- AND the list MUST support pagination

### Requirement: App MUST provide a request detail view
The frontend MUST display request details with the linked client.

#### Scenario: Request detail page
- GIVEN the user clicks a request in the list
- WHEN the detail view loads
- THEN the object store MUST fetch the full request object by ID
- AND the view MUST display all request fields (title, description, client, status, priority, category, requestedAt)
- AND the view MUST show a link to the associated client

### Requirement: App MUST support request CRUD operations
The frontend MUST allow creating, editing, and deleting requests via OpenRegister.

#### Scenario: Create request
- GIVEN the user is on the requests list or a client detail
- WHEN the user creates a new request
- THEN the request MUST be saved to OpenRegister
- AND if created from a client detail, it MUST include a reference to that client

#### Scenario: Edit request
- GIVEN the user is viewing a request detail
- WHEN the user modifies fields and saves
- THEN the object store MUST PUT to OpenRegister with the updated data

#### Scenario: Delete request
- GIVEN the user is viewing a request detail
- WHEN the user confirms deletion
- THEN the object store MUST DELETE the request from OpenRegister

### Requirement: Navigation MUST include clients and requests menu items
The app navigation MUST show menu items for the primary entity types.

#### Scenario: Navigation rendering
- GIVEN the user opens the Pipelinq app
- WHEN the navigation loads
- THEN the menu MUST include at minimum "Dashboard", "Clients", and "Requests" items
- AND clicking each item MUST navigate to the corresponding list view
