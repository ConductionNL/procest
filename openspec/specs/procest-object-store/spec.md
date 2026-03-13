# procest-object-store Specification

## Purpose
Define the Pinia-based object store that provides the data layer for Procest. The store queries OpenRegister directly from the frontend for all CRUD, search, and pagination operations — following the softwarecatalog thin-client pattern.

## ADDED Requirements

### Requirement: Object store MUST use Pinia with dynamic type registration
The store MUST support registering object types at runtime, each mapped to an OpenRegister register/schema pair.

#### Scenario: Register object type
- GIVEN the app settings have been loaded with register/schema IDs
- WHEN `registerObjectType('case', schemaId, registerId)` is called
- THEN the store MUST record the mapping in `objectTypeRegistry`
- AND subsequent CRUD actions for type `case` MUST use the correct register/schema

#### Scenario: Unregister object type
- GIVEN an object type is registered
- WHEN `unregisterObjectType('case')` is called
- THEN the type MUST be removed from the registry
- AND its cached data MUST be cleared

### Requirement: Object store MUST fetch collections from OpenRegister
The store MUST provide a `fetchCollection` action that queries OpenRegister's list endpoint with pagination and search support.

#### Scenario: Fetch paginated collection
- GIVEN object type `case` is registered with register=5, schema=30
- WHEN `fetchCollection('case', { _limit: 20, _offset: 0 })` is called
- THEN the store MUST fetch `GET /apps/openregister/api/objects/5/30?_limit=20&_offset=0`
- AND the response results MUST be stored in `collections.case`
- AND pagination metadata MUST be stored in `pagination.case`

#### Scenario: Fetch with search
- GIVEN the user searches for "building permit"
- WHEN `fetchCollection('case', { _search: 'building permit' })` is called
- THEN the store MUST include `_search=building+permit` in the query
- AND results MUST reflect the search filter

### Requirement: Object store MUST fetch individual objects
The store MUST provide a `fetchObject` action that retrieves a single object by ID.

#### Scenario: Fetch single object
- GIVEN object type `case` is registered
- WHEN `fetchObject('case', 'uuid-123')` is called
- THEN the store MUST fetch `GET /apps/openregister/api/objects/5/30/uuid-123`
- AND the object MUST be stored in `objects.case['uuid-123']`

### Requirement: Object store MUST support create, update, and delete
The store MUST provide actions for full CRUD operations against OpenRegister.

#### Scenario: Create object
- GIVEN object type `case` is registered
- WHEN `saveObject('case', { title: 'New case', status: 'open' })` is called with no existing ID
- THEN the store MUST POST to `/apps/openregister/api/objects/5/30`
- AND the created object MUST be added to the store

#### Scenario: Update object
- GIVEN a case object exists with ID `uuid-123`
- WHEN `saveObject('case', { id: 'uuid-123', title: 'Updated' })` is called
- THEN the store MUST PUT to `/apps/openregister/api/objects/5/30/uuid-123`
- AND the store MUST update `objects.case['uuid-123']`

#### Scenario: Delete object
- GIVEN a case object exists with ID `uuid-123`
- WHEN `deleteObject('case', 'uuid-123')` is called
- THEN the store MUST DELETE `/apps/openregister/api/objects/5/30/uuid-123`
- AND `objects.case['uuid-123']` MUST be removed from the store

### Requirement: Object store MUST track loading and error states
The store MUST provide reactive loading and error states per object type.

#### Scenario: Loading state during fetch
- GIVEN a collection fetch is in progress for type `case`
- WHEN a component checks `isLoading('case')`
- THEN it MUST return `true`
- AND when the fetch completes, it MUST return `false`

#### Scenario: Error state on failure
- GIVEN an API call fails with a network error
- WHEN the store processes the error
- THEN `errors.case` MUST contain the error message
- AND the loading state MUST be set to `false`

### Requirement: Object store MUST load settings before data operations
The store MUST fetch app settings (register/schema IDs) on initialization before any object type can be registered.

#### Scenario: Settings initialization
- GIVEN the app is loading for the first time
- WHEN the store initializes
- THEN it MUST fetch `/apps/procest/api/settings` to get register/schema configuration
- AND it MUST register all object types using the returned IDs
- AND data fetching MUST NOT proceed until settings are loaded

### Requirement: All API calls MUST include Nextcloud authentication headers
Every fetch request to OpenRegister MUST include the CSRF token and OCS header.

#### Scenario: Authenticated request
- GIVEN a store action makes a fetch call
- WHEN the request is constructed
- THEN it MUST include `requesttoken: OC.requestToken` header
- AND it MUST include `OCS-APIREQUEST: true` header
