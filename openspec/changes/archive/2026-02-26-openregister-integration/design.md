# Design: openregister-integration

## Context

Procest is a thin-client Nextcloud app that stores all data in OpenRegister. The current backend uses a custom repair step that manually creates registers and schemas via RegisterService/SchemaMapper. Sister apps (opencatalogi, softwarecatalog) use a JSON config file + `ConfigurationService::importFromApp()` pattern. This change aligns Procest with that pattern and completes the data model.

## Goals / Non-Goals

**Goals:**
- Create `procest_register.json` with all 12 schemas in OpenAPI 3.0.0 format
- Rewrite repair step to use `ConfigurationService::importFromApp()`
- Register all 12 entity types in the frontend store
- Add HTTP status-specific error handling to the Pinia store

**Non-Goals:**
- Cascade delete logic (V1, REQ-OREG-009)
- Per-entity Pinia stores (`useCaseStore()`, etc.) — the generic `useObjectStore()` pattern works well
- Server-side audit trail integration (OpenRegister handles this)

## Decisions

### DD-01: OpenAPI 3.0.0 Config File at `lib/Settings/procest_register.json`

**Decision**: Create a single JSON file following OpenAPI 3.0.0 format with `x-openregister` metadata, matching the softwarecatalog pattern.

**Rationale**: `ConfigurationService::importFromApp()` reads from `lib/Settings/{slug}_register.json` by convention. The OpenAPI format provides schema validation tooling and is the standard across all Conduction apps.

**Alternatives considered**: Keep the hard-coded PHP approach — rejected because it diverges from the ecosystem pattern and makes schema changes harder to review.

### DD-02: ConfigurationService::importFromApp() in Repair Step

**Decision**: Replace the custom `initializeRegisterAndSchemas()` method with a single call to `ConfigurationService::importFromApp('procest')`.

**Rationale**: The ConfigurationService handles all the complexity: register creation/update, schema creation/update, idempotency, and version tracking. The custom code duplicates this logic poorly.

**Implementation**: The repair step becomes ~30 lines: check OpenRegister exists, get ConfigurationService from container, call `importFromApp()`.

### DD-03: Register Slug Migration from `case-management` to `procest`

**Decision**: The new config file uses slug `procest`. Since ConfigurationService uses the app ID to find configurations, this effectively creates a new register alongside the old one.

**Risk**: Existing data is in the old `case-management` register.

**Mitigation**: The repair step will first check if a `case-management` register exists with Procest data. If found, it can be migrated by updating the register slug. However, for MVP (where no production data exists yet), we simply create the new `procest` register and let the old one be.

### DD-04: Error Object Structure

**Decision**: Expand the store error from a string to a structured object: `{ status, message, details, isValidation }`.

**Rationale**: UI components need to distinguish validation errors (show field-level messages) from auth errors (show permission message) from server errors (show generic message + retry).

**Implementation**:
```javascript
// Error structure
{
  status: 422,                    // HTTP status code
  message: 'Validation failed',  // User-friendly message
  details: { title: 'Required' }, // Field-level errors (if 400/422)
  isValidation: true              // Quick check for form components
}
```

### DD-05: SettingsService.php Pattern

**Decision**: Create a `SettingsService.php` following the softwarecatalog pattern for configuration loading, rather than doing everything in the repair step.

**Rationale**: The SettingsService provides a reusable entry point for loading configuration. The repair step delegates to it.

## File Map

### New Files

| File | Purpose |
|------|---------|
| `lib/Settings/procest_register.json` | OpenAPI 3.0.0 register config with 12 schemas |
| `lib/Service/SettingsService.php` | Configuration loading via ConfigurationService |

### Modified Files

| File | Changes |
|------|---------|
| `lib/Repair/InitializeSettings.php` | Rewrite: use SettingsService → ConfigurationService::importFromApp() |
| `src/store/store.js` | Add 4 missing entity type registrations |
| `src/store/modules/object.js` | Add HTTP status-specific error parsing |

## Risks / Trade-offs

- **[Risk] Register slug change** → Existing dev data in `case-management` register may become orphaned. Mitigation: No production deployments exist yet. Dev environments use `clean-env.sh`.
- **[Risk] ConfigurationService API changes** → The import method signature may differ from what softwarecatalog uses. Mitigation: Read the actual ConfigurationService source before implementing.
- **[Trade-off] Generic store vs per-entity stores** → The spec suggests per-entity stores (`useCaseStore()`, etc.) but the generic `useObjectStore()` is simpler and already works. We keep the generic pattern.

## Open Questions

None — the pattern is well-established in sister apps.
