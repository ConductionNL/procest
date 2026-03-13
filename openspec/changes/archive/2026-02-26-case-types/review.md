# Review: case-types

## Summary
- Tasks completed: 15/15
- GitHub issues: N/A (no plan.json)
- Spec compliance: **PASS with warnings**

## Requirements Verified

| Requirement | Status |
|---|---|
| REQ-CT-01 Case Type CRUD | PASS |
| REQ-CT-02 Draft/Published Lifecycle | PASS |
| REQ-CT-03 Validity Periods | WARNING |
| REQ-CT-04 Status Type Management | WARNING |
| REQ-CT-05 Processing Deadline | PASS |
| REQ-CT-06 Extension Configuration | PASS |
| REQ-CT-13 Default Case Type | PASS |
| REQ-CT-14 Validation Rules | PASS |
| REQ-CT-15 Admin UI Tabs | PASS |
| REQ-CT-16 Error Scenarios | PASS |

## Findings

### CRITICAL

None.

### WARNING

- [ ] **CT-03b: Missing explicit "Expired" text indicator** (spec_ref: REQ-CT-03)
  Expired case types in the list only get red-colored text on the validity date range (`validity--expired` CSS class). The spec says "MUST show an 'Expired' indicator." An explicit "Expired" badge or label should be added alongside the date styling for accessibility and clarity.
  File: `src/views/settings/CaseTypeList.vue` lines 51-53, `validityClass()` method

- [ ] **CT-04e: No active-case check before status type deletion** (spec_ref: REQ-CT-04)
  `StatusesTab.vue` `deleteStatusType()` does not check whether active cases reference a status type before allowing deletion. The spec says the system MUST show "Cannot delete: active cases are at this status." Currently untriggerable since case management is not yet built, but the guard should exist for when cases are implemented.
  File: `src/views/settings/tabs/StatusesTab.vue` lines 341-363

- [ ] **`validFrom` label marked required (*) but not validated on save** (spec_ref: REQ-CT-14)
  The "Valid from" label has `class="required"` (showing asterisk) but `validFrom` is not in `REQUIRED_FIELDS` and is not validated by `validateCaseType()`. It is only enforced on publish via `validateForPublish()`. This mismatch may confuse admins who see a required indicator but can save without it.
  File: `src/views/settings/tabs/GeneralTab.vue` line 177, `src/utils/caseTypeValidation.js` REQUIRED_FIELDS

### SUGGESTION

- CaseTypeList.vue and CaseTypeDetail.vue use native `confirm()` dialogs instead of `NcDialog`. Using NcDialog would provide a more consistent Nextcloud UX.
- `CaseTypeList.vue:loadStatusTypeCount()` re-fetches the entire caseType collection after each status type count fetch (line 142), creating an N+1 query pattern. Consider batching or caching.
- "Set as default" button is hidden for draft types via `v-if="!ct.isDraft"`. The spec implies showing an error when a draft is set as default, suggesting the button should be visible but error on click for drafts.

## Recommendation

**APPROVE** — 0 critical findings. The 3 warnings are low-risk: the expired indicator is cosmetic, the in-use check guards against a scenario that can't yet occur, and the validFrom asterisk mismatch is a UX inconsistency. Safe to archive or fix warnings first at your discretion.
