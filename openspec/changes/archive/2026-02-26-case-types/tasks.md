# Tasks: case-types

## 1. Backend & Store Setup

- [x] 1.1 Add `case_type_schema` and `status_type_schema` to SettingsService
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-01`
  - **files**: `lib/Service/SettingsService.php`
  - **acceptance_criteria**:
    - GIVEN the SettingsService WHEN `getSettings()` is called THEN it returns `case_type_schema` and `status_type_schema` along with existing keys
    - GIVEN a POST to `/api/settings` with `case_type_schema` and `status_type_schema` THEN both are persisted in IAppConfig

- [x] 1.2 Register `caseType` and `statusType` object types in store initialization
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-01`
  - **files**: `src/store/store.js`
  - **acceptance_criteria**:
    - GIVEN settings contain `case_type_schema` and `status_type_schema` WHEN `initializeStores()` runs THEN `caseType` and `statusType` are registered with `useObjectStore`
    - GIVEN `case_type_schema` is not configured THEN `caseType` is NOT registered (no error)

- [x] 1.3 Add `default_case_type` to SettingsService
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-13`
  - **files**: `lib/Service/SettingsService.php`
  - **acceptance_criteria**:
    - GIVEN the settings API WHEN `getSettings()` is called THEN `default_case_type` is included (default empty string)
    - GIVEN a POST with `default_case_type` set to a UUID THEN it is persisted

- [x] 1.4 Update Settings.vue to include new schema config fields
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-01`
  - **files**: `src/views/settings/Settings.vue`
  - **acceptance_criteria**:
    - GIVEN the admin settings page WHEN the config section renders THEN fields for `case_type_schema` and `status_type_schema` are visible and editable

## 2. Utility Functions

- [x] 2.1 Create ISO 8601 duration helpers (`durationHelpers.js`)
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-05`
  - **files**: `src/utils/durationHelpers.js`
  - **acceptance_criteria**:
    - GIVEN `"P56D"` WHEN calling `formatDuration("P56D")` THEN it returns "56 days"
    - GIVEN `"P8W"` WHEN calling `formatDuration("P8W")` THEN it returns "8 weeks"
    - GIVEN `"P2M"` WHEN calling `formatDuration("P2M")` THEN it returns "2 months"
    - GIVEN `"P1Y"` WHEN calling `formatDuration("P1Y")` THEN it returns "1 year"
    - GIVEN `"56 days"` WHEN calling `isValidDuration("56 days")` THEN it returns `false`
    - GIVEN `"P56D"` WHEN calling `isValidDuration("P56D")` THEN it returns `true`
    - Export `parseDuration(iso)` returning `{ years, months, weeks, days }` object
    - Export `formatDuration(iso)` returning human-readable string
    - Export `isValidDuration(value)` returning boolean

- [x] 2.2 Create case type validation helpers (`caseTypeValidation.js`)
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-14`, `#REQ-CT-02`
  - **files**: `src/utils/caseTypeValidation.js`
  - **acceptance_criteria**:
    - GIVEN a case type with empty title WHEN calling `validateCaseType(data)` THEN it returns `{ valid: false, errors: { title: "Title is required" } }`
    - GIVEN a case type with all required fields filled WHEN calling `validateCaseType(data)` THEN it returns `{ valid: true, errors: {} }`
    - GIVEN `extensionAllowed = true` and empty `extensionPeriod` WHEN validating THEN error: "Extension period is required when extension is allowed"
    - GIVEN `validFrom = "2026-01-01"` and `validUntil = "2025-12-31"` WHEN validating THEN error: "'Valid until' must be after 'Valid from'"
    - Export `validateForPublish(caseType, statusTypes)` returning `{ valid, errors[] }` checking: required fields, >=1 status type, >=1 final status, validFrom set
    - Export `REQUIRED_FIELDS` constant listing all required case type fields
    - Export `ORIGIN_OPTIONS`, `CONFIDENTIALITY_OPTIONS` as arrays for select dropdowns

## 3. Admin Root & Navigation

- [x] 3.1 Create `AdminRoot.vue` and update `settings.js` entry point
  - **spec_ref**: `procest/openspec/changes/case-types/design.md`
  - **files**: `src/views/settings/AdminRoot.vue`, `src/settings.js`
  - **acceptance_criteria**:
    - GIVEN the admin navigates to Procest settings WHEN the page loads THEN AdminRoot renders with two sections: Configuration (existing Settings.vue) and Case Type Management (CaseTypeAdmin)
    - GIVEN AdminRoot WHEN store initialization completes THEN `caseType` and `statusType` object types are available

- [x] 3.2 Create `CaseTypeAdmin.vue` with list/detail routing
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-01`
  - **files**: `src/views/settings/CaseTypeAdmin.vue`
  - **acceptance_criteria**:
    - GIVEN the admin section WHEN no case type is selected THEN `CaseTypeList` is shown
    - GIVEN the admin clicks a case type THEN `CaseTypeDetail` is shown for that type
    - GIVEN the admin clicks "Add Case Type" THEN `CaseTypeDetail` in create mode is shown
    - GIVEN the admin clicks "Back to list" in detail view THEN the list is shown again

## 4. Case Type List

- [x] 4.1 Create `CaseTypeList.vue`
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-01`, `#REQ-CT-02`, `#REQ-CT-03`, `#REQ-CT-13`
  - **files**: `src/views/settings/CaseTypeList.vue`
  - **acceptance_criteria**:
    - GIVEN case types exist WHEN the list renders THEN each type shows: title, Published/Draft badge, processing deadline (human-readable), status type count, validity range
    - GIVEN a type is the default THEN a star icon MUST be shown
    - GIVEN a type has expired THEN an "Expired" indicator MUST be shown
    - GIVEN no case types exist THEN an empty state with "No case types configured" is shown
    - GIVEN the "Add Case Type" button WHEN clicked THEN navigates to create mode
    - Loading state with NcLoadingIcon while fetching

- [x] 4.2 Add "Set as default" and delete actions to CaseTypeList
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-13`, `#REQ-CT-01`
  - **files**: `src/views/settings/CaseTypeList.vue`
  - **acceptance_criteria**:
    - GIVEN a published case type WHEN "Set as default" is clicked THEN the default_case_type setting is updated AND the star indicator moves
    - GIVEN a draft case type WHEN "Set as default" is clicked THEN an error is shown: "Only published case types can be set as default"
    - GIVEN a case type with no active cases WHEN "Delete" is clicked and confirmed THEN the type and all linked status types are deleted
    - GIVEN a case type with active cases WHEN "Delete" is clicked THEN an error is shown

## 5. Case Type Detail — General Tab

- [x] 5.1 Create `CaseTypeDetail.vue` with tab navigation
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-15`
  - **files**: `src/views/settings/CaseTypeDetail.vue`
  - **acceptance_criteria**:
    - GIVEN an existing case type WHEN the detail loads THEN tabs "General" and "Statuses" are shown
    - GIVEN the page loads THEN "General" tab is active by default
    - GIVEN a new case type (create mode) WHEN the detail loads THEN the form is empty with `isDraft = true`
    - Header shows case type title (or "New Case Type") and a "Back to list" button
    - Publish/Unpublish button visible in header based on `isDraft` state
    - Save button persists the case type (full PUT for updates, POST for create)

- [x] 5.2 Create `GeneralTab.vue` with all case type fields
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-01`, `#REQ-CT-05`, `#REQ-CT-06`, `#REQ-CT-14`
  - **files**: `src/views/settings/tabs/GeneralTab.vue`
  - **acceptance_criteria**:
    - Form fields: title*, description, purpose*, trigger*, subject*, initiatorAction, handlerAction, processingDeadline* (with human-readable preview), serviceTarget, extensionAllowed (checkbox), extensionPeriod (conditional, shown when extensionAllowed), origin* (select: internal/external), confidentiality* (select), publicationRequired (checkbox), publicationText (conditional), responsibleUnit*, referenceProcess, keywords (text input), validFrom*, validUntil
    - GIVEN processingDeadline = "P56D" THEN a helper text shows "56 days"
    - GIVEN extensionAllowed = false THEN extensionPeriod field is hidden
    - GIVEN publicationRequired = false THEN publicationText field is hidden
    - All required fields marked with asterisk
    - Validation errors shown inline per field

- [x] 5.3 Add publish/unpublish functionality to CaseTypeDetail
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-02`
  - **files**: `src/views/settings/CaseTypeDetail.vue`, `src/utils/caseTypeValidation.js`
  - **acceptance_criteria**:
    - GIVEN a draft type with valid config WHEN "Publish" is clicked THEN `isDraft` is set to `false` and saved
    - GIVEN a draft type missing required fields or status types WHEN "Publish" is clicked THEN validation errors are shown listing all blockers
    - GIVEN a published type WHEN "Unpublish" is clicked THEN a warning is shown about impact AND if confirmed `isDraft` is set to `true`

## 6. Case Type Detail — Statuses Tab

- [x] 6.1 Create `StatusesTab.vue` with status type list and CRUD
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-04`
  - **files**: `src/views/settings/tabs/StatusesTab.vue`
  - **acceptance_criteria**:
    - GIVEN a case type WHEN Statuses tab is active THEN all status types are fetched with `_filters[caseType]={id}` and displayed ordered by `order`
    - Each status type row shows: order number, name, isFinal checkbox, notifyInitiator checkbox, notification text (if notifyInitiator), edit/delete actions
    - "Add Status Type" button opens an inline form with fields: name*, order*, isFinal, notifyInitiator, notificationText
    - GIVEN an empty name WHEN submitting THEN error: "Status type name is required"
    - GIVEN a duplicate order WHEN submitting THEN error: "A status type with this order already exists"
    - Loading state while fetching status types

- [x] 6.2 Add drag-and-drop reordering to StatusesTab
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-04`
  - **files**: `src/views/settings/tabs/StatusesTab.vue`
  - **acceptance_criteria**:
    - GIVEN status types [A(1), B(2), C(3)] WHEN B is dragged before A THEN orders become [B(1), A(2), C(3)] and all affected types are saved
    - Drag handles are visible on each row
    - During drag, the drop target is visually indicated

- [x] 6.3 Add delete and edit for status types with validation
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-04`
  - **files**: `src/views/settings/tabs/StatusesTab.vue`
  - **acceptance_criteria**:
    - GIVEN a status type not in use WHEN "Delete" is clicked and confirmed THEN it is removed
    - GIVEN only one final status exists WHEN admin unchecks `isFinal` THEN error: "At least one status type must be marked as final"
    - GIVEN a status type WHEN "Edit" is clicked THEN fields become editable inline
    - GIVEN changes WHEN "Save" is clicked on the row THEN the status type is updated

## 7. Cascade Delete & Error Handling

- [x] 7.1 Implement cascade delete for case types
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-01`
  - **files**: `src/views/settings/CaseTypeList.vue`
  - **acceptance_criteria**:
    - GIVEN a case type with 3 status types WHEN deleted THEN all 3 status types are deleted first, then the case type
    - GIVEN a deletion fails midway THEN an error message is shown and remaining items are not deleted
    - Confirmation dialog shows: "This will delete the case type and all {N} status types. Continue?"

- [x] 7.2 Add error display for publish validation and API errors
  - **spec_ref**: `procest/openspec/specs/case-types/spec.md#REQ-CT-16`
  - **files**: `src/views/settings/CaseTypeDetail.vue`
  - **acceptance_criteria**:
    - GIVEN publish validation fails THEN all errors are shown in a list (e.g., "Missing: purpose, trigger", "No status types defined", "No final status")
    - GIVEN an API error on save THEN a toast notification shows the error message
    - GIVEN an API error on delete THEN the error is shown and the item remains

## Verification

- [x] All tasks checked off
- [ ] Manual testing: create, edit, publish, unpublish, delete case types
- [ ] Manual testing: add, edit, reorder, delete status types
- [ ] Manual testing: validation errors for all required fields
- [ ] Manual testing: publish blocked when prerequisites not met
- [ ] Manual testing: default case type set/change/draft-blocked
- [ ] Manual testing: cascade delete removes status types
- [ ] Manual testing: ISO 8601 duration display and validation
