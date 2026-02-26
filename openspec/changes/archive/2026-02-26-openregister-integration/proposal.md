# Proposal: openregister-integration

## Why

Procest's data layer currently uses a hard-coded PHP repair step with only 6 minimal schemas (case, task, status, role, result, decision) registered under a `case-management` slug. The spec requires 12 schemas under the `procest` slug, imported via `ConfigurationService::importFromApp()` from an OpenAPI 3.0.0 JSON file — the same pattern used by opencatalogi and softwarecatalog. The frontend store only registers 8 of 12 entity types and lacks HTTP status-specific error handling.

This change brings the backend configuration and frontend store layer in line with the openregister-integration spec (MVP tier only; V1 cascade behaviors are excluded).

## What Changes

- **Create `lib/Settings/procest_register.json`** — OpenAPI 3.0.0 config file defining the `procest` register with all 12 schemas and their full property definitions
- **Rewrite `lib/Repair/InitializeSettings.php`** — Replace custom register/schema creation with `ConfigurationService::importFromApp('procest')` call, matching the opencatalogi/softwarecatalog pattern
- **Register all 12 entity types in the frontend store** — Add `resultType`, `roleType`, `propertyDefinition`, `documentType`, `decisionType` to `store.js`
- **Add HTTP status-specific error handling** in `object.js` — Parse 400/401/403/404/409/500 responses with user-friendly messages
- **Add `SettingsService.php`** — Thin service to load configuration via ConfigurationService, matching the sister-app pattern

## Capabilities

### Modified Capabilities

- **openregister-integration** — All MVP requirements (REQ-OREG-001 through REQ-OREG-008, REQ-OREG-010 through REQ-OREG-013) are being implemented or corrected. REQ-OREG-009 (cascade behaviors, V1) is deferred.

## Impact

- **Backend**: New JSON config file, rewritten repair step, new SettingsService
- **Frontend**: Updated store.js (4 new entity types), improved error handling in object.js
- **Data migration**: Register slug changes from `case-management` to `procest`. The repair step re-import is idempotent — existing data is preserved because ConfigurationService handles upsert logic.
- **Dependencies**: OpenRegister `ConfigurationService` must be available (already a runtime dependency)
