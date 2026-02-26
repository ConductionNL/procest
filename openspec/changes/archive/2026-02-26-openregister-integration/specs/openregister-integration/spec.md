# Delta Spec: openregister-integration

This delta spec confirms the MVP requirements from the main openregister-integration spec and narrows the scope to what this change implements.

## Scope

**In scope (MVP)**: REQ-OREG-001, REQ-OREG-002, REQ-OREG-003, REQ-OREG-004, REQ-OREG-005, REQ-OREG-006, REQ-OREG-007, REQ-OREG-008, REQ-OREG-010, REQ-OREG-011, REQ-OREG-012, REQ-OREG-013

**Deferred (V1)**: REQ-OREG-009 (cascade behaviors)

---

## MODIFIED Requirements

### Requirement: REQ-OREG-001 — Configuration File

The system MUST define its register and all schemas in a JSON configuration file at `lib/Settings/procest_register.json` that follows the OpenAPI 3.0.0 format.

**Current state**: No config file exists. Schemas are hard-coded in PHP with only 6 of 12 schemas and minimal properties.

**Change**: Create the full OpenAPI 3.0.0 JSON config with all 12 schemas and complete property definitions as specified in the main spec.

#### Scenario: Configuration file exists with all 12 schemas

- GIVEN the Procest app source code
- WHEN a developer inspects `lib/Settings/procest_register.json`
- THEN the file MUST be valid JSON conforming to OpenAPI 3.0.0
- AND it MUST define a register with slug `procest`
- AND it MUST define exactly 12 schemas: `caseType`, `statusType`, `resultType`, `roleType`, `propertyDefinition`, `documentType`, `decisionType`, `case`, `task`, `role`, `result`, `decision`
- AND each schema MUST include all required and optional properties as listed in the main spec

#### Scenario: Schema type annotations

- GIVEN each schema definition in the config file
- THEN each MUST include a `@type` or `x-schema-org-type` annotation referencing the appropriate standard (e.g., `case` → `schema:Project`, `task` → `schema:Action`)

---

### Requirement: REQ-OREG-002 — Auto-Configuration on Install

The system MUST import the register configuration via `ConfigurationService::importFromApp('procest')` during the repair step, replacing the current custom register/schema creation logic.

**Current state**: `InitializeSettings.php` uses custom code with RegisterService and SchemaMapper directly. Register slug is `case-management` instead of `procest`.

**Change**: Rewrite to use `ConfigurationService::importFromApp()` pattern. Register slug becomes `procest`.

#### Scenario: Repair step uses ConfigurationService

- GIVEN Procest is installed on a Nextcloud instance with OpenRegister
- WHEN the repair step `lib/Repair/InitializeSettings.php` runs
- THEN it MUST call `ConfigurationService::importFromApp('procest')`
- AND the `procest` register MUST be created or updated in OpenRegister
- AND all 12 schemas MUST be created with their full property definitions

#### Scenario: Repair step handles missing OpenRegister

- GIVEN Procest is installed but OpenRegister is NOT installed
- WHEN the repair step runs
- THEN it MUST log a clear warning message
- AND it MUST NOT crash or throw an unhandled exception

#### Scenario: Repair step is idempotent

- GIVEN the repair step has already run successfully
- WHEN it runs again
- THEN it MUST NOT create duplicate registers or schemas
- AND existing data MUST remain intact

---

### Requirement: REQ-OREG-005 — Pinia Store: All 12 Entity Types Registered

The frontend MUST register all 12 entity types in the Pinia store on initialization.

**Current state**: Only 8 types are registered (case, task, status, role, result, decision, caseType, statusType).

**Change**: Add `resultType`, `roleType`, `propertyDefinition`, `documentType`, `decisionType` to store initialization.

#### Scenario: All entity types registered on boot

- GIVEN the Procest app loads in the browser
- WHEN `initializeStores()` completes
- THEN the object store MUST have all 12 entity types registered
- AND each type MUST be usable for `fetchCollection`, `fetchObject`, `saveObject`, `deleteObject`

---

### Requirement: REQ-OREG-008 — HTTP Status-Specific Error Handling

The frontend store MUST parse HTTP response status codes and provide user-friendly error messages instead of generic "Failed to fetch" strings.

**Current state**: All errors produce `Failed to fetch {type}: {statusText}` — no distinction between 400, 403, 404, 409, 500.

**Change**: Add response status parsing with categorized error messages.

#### Scenario: Validation error (HTTP 400/422)

- GIVEN the user submits invalid data
- WHEN the API returns HTTP 400 or 422
- THEN the store error MUST include the validation details from the response body
- AND the error MUST be structured so the UI can map errors to specific fields

#### Scenario: Authorization error (HTTP 403)

- GIVEN a user without sufficient permissions
- WHEN the API returns HTTP 403
- THEN the store error MUST include a message like "You do not have permission to perform this action"

#### Scenario: Not found error (HTTP 404)

- GIVEN a deleted or non-existent object
- WHEN the API returns HTTP 404
- THEN the store error MUST include a message like "The requested {type} could not be found"

#### Scenario: Server error (HTTP 500)

- GIVEN an unexpected server error
- WHEN the API returns HTTP 500
- THEN the store error MUST include "An unexpected error occurred. Please try again later."
- AND the full error details MUST be logged to the browser console
