# pipelinq-object-store Specification

## Purpose
Define the Pinia-based object store that provides the data layer for Pipelinq. Identical pattern to the Procest object store — queries OpenRegister directly from the frontend for all CRUD, search, and pagination operations.

## ADDED Requirements

### Requirement: Object store MUST use Pinia with dynamic type registration
The store MUST support registering object types at runtime, each mapped to an OpenRegister register/schema pair.

#### Scenario: Register object type
- GIVEN the app settings have been loaded with register/schema IDs
- WHEN `registerObjectType('client', schemaId, registerId)` is called
- THEN the store MUST record the mapping in `objectTypeRegistry`
- AND subsequent CRUD actions for type `client` MUST use the correct register/schema

#### Scenario: Unregister object type
- GIVEN an object type is registered
- WHEN `unregisterObjectType('client')` is called
- THEN the type MUST be removed from the registry
- AND its cached data MUST be cleared

### Requirement: Object store MUST fetch collections from OpenRegister
The store MUST provide a `fetchCollection` action that queries OpenRegister's list endpoint with pagination and search support.

#### Scenario: Fetch paginated collection
- GIVEN object type `client` is registered with register=6, schema=40
- WHEN `fetchCollection('client', { _limit: 20, _offset: 0 })` is called
- THEN the store MUST fetch `GET /apps/openregister/api/objects/6/40?_limit=20&_offset=0`
- AND the response results MUST be stored in `collections.client`
- AND pagination metadata MUST be stored in `pagination.client`

#### Scenario: Fetch with search
- GIVEN the user searches for "Gemeente Amsterdam"
- WHEN `fetchCollection('client', { _search: 'Gemeente Amsterdam' })` is called
- THEN the store MUST include `_search=Gemeente+Amsterdam` in the query
- AND results MUST reflect the search filter

### Requirement: Object store MUST fetch individual objects
The store MUST provide a `fetchObject` action that retrieves a single object by ID.

#### Scenario: Fetch single object
- GIVEN object type `client` is registered
- WHEN `fetchObject('client', 'uuid-456')` is called
- THEN the store MUST fetch `GET /apps/openregister/api/objects/6/40/uuid-456`
- AND the object MUST be stored in `objects.client['uuid-456']`

### Requirement: Object store MUST support create, update, and delete
The store MUST provide actions for full CRUD operations against OpenRegister.

#### Scenario: Create object
- GIVEN object type `request` is registered
- WHEN `saveObject('request', { title: 'New request', client: 'uuid-456' })` is called with no existing ID
- THEN the store MUST POST to OpenRegister
- AND the created object MUST be added to the store

#### Scenario: Update object
- GIVEN a client object exists with ID `uuid-456`
- WHEN `saveObject('client', { id: 'uuid-456', name: 'Updated' })` is called
- THEN the store MUST PUT to OpenRegister
- AND the store MUST update `objects.client['uuid-456']`

#### Scenario: Delete object
- GIVEN a request object exists with ID `uuid-789`
- WHEN `deleteObject('request', 'uuid-789')` is called
- THEN the store MUST DELETE from OpenRegister
- AND the object MUST be removed from the store

### Requirement: Object store MUST track loading and error states
The store MUST provide reactive loading and error states per object type.

#### Scenario: Loading state during fetch
- GIVEN a collection fetch is in progress for type `client`
- WHEN a component checks `isLoading('client')`
- THEN it MUST return `true`
- AND when the fetch completes, it MUST return `false`

#### Scenario: Error state on failure
- GIVEN an API call fails with a network error
- WHEN the store processes the error
- THEN `errors.client` MUST contain the error message
- AND the loading state MUST be set to `false`

### Requirement: Object store MUST load settings before data operations
The store MUST fetch app settings on initialization before any object type can be registered.

#### Scenario: Settings initialization
- GIVEN the app is loading for the first time
- WHEN the store initializes
- THEN it MUST fetch `/apps/pipelinq/api/settings` to get register/schema configuration
- AND it MUST register all object types using the returned IDs
- AND data fetching MUST NOT proceed until settings are loaded

### Requirement: All API calls MUST include Nextcloud authentication headers
Every fetch request to OpenRegister MUST include the CSRF token and OCS header.

#### Scenario: Authenticated request
- GIVEN a store action makes a fetch call
- WHEN the request is constructed
- THEN it MUST include `requesttoken: OC.requestToken` header
- AND it MUST include `OCS-APIREQUEST: true` header
