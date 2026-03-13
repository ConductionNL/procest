# Design: case-types

## Architecture Overview

Case type management lives in the Nextcloud **admin settings** panel, rendered by a separate webpack entry point (`settings.js`). The current Settings.vue (register/schema config) will be preserved and the case type management UI will be added alongside it. All data flows through the existing `useObjectStore` Pinia store to OpenRegister.

```
Admin Settings Page (settings.js entry point)
├── Settings.vue (existing config — register/schema IDs)
└── CaseTypeAdmin.vue (NEW — case type management)
    ├── CaseTypeList.vue (list with search, badges, default star)
    └── CaseTypeDetail.vue (tabbed editor)
        ├── GeneralTab.vue (all case type fields)
        └── StatusesTab.vue (ordered status type list + CRUD)
```

Data flow:
```
Vue Components → useObjectStore → OpenRegister API
                                   ├── /api/objects/{register}/caseType/{id}
                                   └── /api/objects/{register}/statusType/{id}
```

## API Design

No new backend APIs. All CRUD uses the existing OpenRegister API through `useObjectStore`:

- `fetchCollection('caseType', params)` — List case types
- `fetchObject('caseType', id)` — Get single case type
- `saveObject('caseType', data)` — Create (POST) or update (PUT)
- `deleteObject('caseType', id)` — Delete case type
- Same pattern for `statusType` with `_filters[caseType]={id}` for scoped queries

## Database Changes

None. Case types and status types are stored as OpenRegister objects.

**New app config keys** (added to `SettingsService.php`):
- `case_type_schema` — Schema ID for the `caseType` OpenRegister schema
- `status_type_schema` — Schema ID for the `statusType` OpenRegister schema

## Nextcloud Integration

- **Settings**: Existing `AdminSettings.php` + `SettingsSection.php` — no changes needed, already renders `templates/settings/admin.php`
- **Entry point**: `settings.js` already exists as webpack `adminSettings` entry — will be enhanced to mount the new admin root component

## File Structure

```
src/
  settings.js                          (MODIFIED — mount AdminRoot instead of bare Settings)
  views/
    settings/
      Settings.vue                     (EXISTING — register/schema config, no changes)
      AdminRoot.vue                    (NEW — root component, renders Settings + CaseTypeAdmin)
      CaseTypeAdmin.vue                (NEW — list/detail router for case types)
      CaseTypeList.vue                 (NEW — case type list with badges)
      CaseTypeDetail.vue               (NEW — tabbed editor)
      tabs/
        GeneralTab.vue                 (NEW — all case type fields)
        StatusesTab.vue                (NEW — ordered status type CRUD)
  utils/
    durationHelpers.js                 (NEW — ISO 8601 parse/format/validate)
    caseTypeValidation.js              (NEW — publish validation, field validation)
  store/
    store.js                           (MODIFIED — register caseType + statusType)
lib/
  Service/
    SettingsService.php                (MODIFIED — add case_type_schema, status_type_schema keys)
```

## Decisions

### 1. Admin UI architecture: Single root component with inline routing

**Decision**: Create an `AdminRoot.vue` that manages view state (list vs detail) internally, similar to how `App.vue` handles the main app routing. The settings.js entry point mounts AdminRoot, which renders both the existing Settings config and the CaseTypeAdmin.

**Why not vue-router**: The admin settings page is a small surface. Hash routing would conflict with Nextcloud's own admin panel. A simple `currentView`/`currentId` data-driven approach (same as App.vue) keeps it simple.

### 2. Status type reordering: Save on drop

**Decision**: When a status type is dragged to a new position, immediately recalculate all `order` values and save each affected status type via `saveObject`. No separate "save order" button.

**Why**: Matches user expectation from drag-and-drop UIs. The order must be persisted immediately so refreshing the page preserves the new order. Saving only changed items keeps API calls minimal.

**Implementation**: Use HTML5 drag-and-drop (no external library) since we only need simple vertical reordering within a small list.

### 3. Publish validation: Frontend-only

**Decision**: Validate publish prerequisites (required fields, >=1 status type, >=1 final status, validFrom set) in the frontend before calling `saveObject`. No backend validation endpoint.

**Why**: OpenRegister doesn't enforce business rules — it's a generic object store. All validation logic lives in `caseTypeValidation.js`. This is consistent with how task management does validation (frontend-side in TaskDetail.vue).

### 4. Default case type: Stored in app config

**Decision**: Store the default case type ID in Nextcloud's `IAppConfig` via the existing settings API (`default_case_type` key), not as a field on the case type object.

**Why**: Only one type can be default at a time. Storing it as app config avoids having to query all case types to find which one has `isDefault=true`. Simple read from settings.

### 5. Identifier auto-generation: Frontend timestamp

**Decision**: Generate `identifier` as `CT-{Date.now()}` in the frontend when creating a new case type.

**Why**: Simple, unique enough for this context. OpenRegister assigns its own UUID as the object ID. The `identifier` is a human-readable reference, not the primary key.

### 6. Delete with cascade: Sequential deletes

**Decision**: When deleting a case type, first fetch and delete all linked status types (`_filters[caseType]={id}`), then delete the case type itself.

**Why**: OpenRegister doesn't support cascade deletes. The frontend must handle this explicitly. Status types are the only child entity in MVP scope.

## Risks / Trade-offs

- **[No server-side validation]** → All validation is in the frontend. A direct API call could create invalid case types. Acceptable for MVP since only admins manage case types.
- **[Drag-and-drop without library]** → HTML5 drag-and-drop can be finicky on touch devices. Status type lists are typically small (5-10 items), so this is acceptable. Could add a library in V1 if needed.
- **[Sequential cascade deletes]** → If a case type has many status types, deletion involves N+1 API calls. Acceptable for MVP since status type counts are small.

## Migration Plan

No migration needed. This is a new feature. The admin must configure `case_type_schema` and `status_type_schema` in settings before case types can be managed.

## Open Questions

None.
