# Tasks: openregister-integration

## 1. Backend — Configuration File

- [x] 1.1 Create `lib/Settings/procest_register.json` — OpenAPI 3.0.0 JSON file defining the `procest` register with all 12 schemas. Each schema must include: all required/optional properties from the main spec (REQ-OREG-001), correct types/formats/enums/defaults, `x-schema-org-type` annotations, and `x-openregister` metadata block. Reference `softwarecatalog/lib/Settings/softwarecatalogus_register.json` for format conventions.

## 2. Backend — Service and Repair Step

- [x] 2.1 Create `lib/Service/SettingsService.php` — Thin service class that: gets ConfigurationService from the container, reads the JSON config file, calls `ConfigurationService::importFromApp('procest')`. Follow the softwarecatalog SettingsService pattern. Include a `loadConfiguration()` method that returns the import result.

- [x] 2.2 Rewrite `lib/Repair/InitializeSettings.php` — Replace the custom `initializeRegisterAndSchemas()` method with: (a) check OpenRegister is enabled, (b) get SettingsService from container, (c) call `settingsService->loadConfiguration()`, (d) store register/schema IDs in app config for frontend consumption. Remove the hard-coded SCHEMAS constant. Keep the OpenRegister availability check and graceful error handling.

## 3. Frontend — Store Registration

- [x] 3.1 Update `src/store/store.js` — Add registrations for the 4 missing entity types: `resultType` (config key: `result_type_schema`), `roleType` (`role_type_schema`), `propertyDefinition` (`property_definition_schema`), `documentType` (`document_type_schema`), `decisionType` (`decision_type_schema`). Follow the existing pattern of checking `config.register && config.{key}` before calling `registerObjectType()`.

## 4. Frontend — Error Handling

- [x] 4.1 Add `_parseError(response, type)` method to `src/store/modules/object.js` — Parse HTTP response into structured error object `{ status, message, details, isValidation }`. Map status codes: 400/422 → parse body for field-level errors + `isValidation: true`; 401 → "Session expired, please log in again"; 403 → "You do not have permission to perform this action"; 404 → "The requested {type} could not be found"; 409 → "This {type} was modified by another user. Please reload."; 500+ → "An unexpected error occurred. Please try again later.". Log full details to console for all error types.

- [x] 4.2 Update all CRUD methods in `object.js` to use `_parseError()` — Replace the current `throw new Error(...)` patterns in `fetchCollection`, `fetchObject`, `saveObject`, `deleteObject` with calls to `_parseError(response, type)`. Set `this.errors[type]` to the structured error object instead of a plain string. Ensure backward compatibility: components that read `errors[type]` as a string should still work (add a `toString()` or check for `.message` property).

## 5. Verification

- [ ] 5.1 Verify `procest_register.json` is valid JSON and contains all 12 schemas with correct property definitions
- [ ] 5.2 Verify repair step runs without error on a clean environment (`occ maintenance:repair`)
- [ ] 5.3 Verify all 12 entity types appear in `objectStore.objectTypes` after app load
- [ ] 5.4 Verify error handling: trigger a 404 by fetching a non-existent object, confirm structured error object
- [ ] 5.5 Verify SettingsService returns register and schema IDs to the settings endpoint
