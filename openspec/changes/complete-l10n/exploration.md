# Exploration: Complete l10n

**Date**: 2026-03-03  
**Scope**: Extract all `t('procest', '...')` keys from codebase, compare with l10n files, produce definitive missing-key list.

---

## User-Reported Symptom & Diagnosis

**Symptom**: Nextcloud language is set to Nederlands, but the Procest app displays almost entirely in English.

**Cause**: Incomplete l10n list — not a locale-loading bug. Nextcloud correctly loads `l10n/nl.json` when the user's language is Dutch. However, when `t('procest', 'key')` is called and the key is missing from `nl.json`, Nextcloud falls back to the key string itself (which is typically the English source text). With only ~55 keys in `nl.json` and ~120 keys used in code, most UI strings fall back to English.

**Fix**: Add all missing keys to `nl.json` with Dutch translations. No code changes required.

---

## Summary

| Metric | Value |
|--------|-------|
| Keys in en.json | 55 |
| Unique keys used in code | ~175 |
| Missing keys | ~120 |
| Files with t() calls | 25+ (views, utils, services) |

---

## Key Sources

### Views (Vue components)
- `MainMenu.vue` — navigation
- `Dashboard.vue`, `KpiCards.vue`, `StatusChart.vue`, `MyWorkPreview.vue`, `ActivityFeed.vue`, `OverduePanel.vue`
- `CaseList.vue`, `CaseDetail.vue`, `CaseCreateDialog.vue`
- `TaskList.vue`, `TaskDetail.vue`, `TaskCreateDialog.vue`
- `MyWork.vue`
- `CaseTypeList.vue`, `CaseTypeDetail.vue`, `StatusesTab.vue`
- `Settings.vue`, `UserSettings.vue`
- Case components: `ResultSection`, `AddParticipantDialog`, `DeadlinePanel`, `ActivityTimeline`, `ParticipantsSection`, `QuickStatusDropdown`

### Utils
- `dashboardHelpers.js` — formatRelativeTime, getMyWorkItems
- `caseHelpers.js` — getCaseOverdueText, formatDeadlineCountdown
- `caseValidation.js` — validateCaseCreate, validateCaseUpdate
- `caseTypeValidation.js` — validateCaseType, validateForPublish, getFieldLabel
- `durationHelpers.js` — formatDuration, getDurationError
- `taskHelpers.js` — PRIORITY_LABELS, getOverdueText
- `taskLifecycle.js` — STATUS_LABELS, ACTION_LABELS

### Services
- `taskApi.js` — formatTaskForDisplay

---

## Missing Keys (Categorized)

### 1. Navigation & layout
- `Track and manage tasks`
- `Documentation`
- `Case Types`
- `Configuration`
- `Register and schema settings`

### 2. Dashboard
- `New Case`
- `New Task`
- `Refresh dashboard`
- `Open Cases`
- `Overdue`
- `Completed This Month`
- `My Tasks`
- `Cases by Status`
- `No open cases`
- `My Work`
- `No items assigned to you`
- `View all my work`
- `View all activity`
- `Recent Activity`
- `No recent activity`
- `Retry`
- `0 today`
- `+{n} today`
- `action needed`
- `all on track`
- `no data`
- `{n} due today`
- `none due today`
- `avg {days} days`
- `Failed to load dashboard data`
- `Welcome to Procest! Get started by creating your first case type in Settings.`
- `Welcome to Procest! Get started by creating your first case or task using the buttons above.`

### 3. Overdue panel
- `Overdue Cases`
- `No overdue cases`
- `View all overdue`

### 4. Cases
- `Manage cases and workflows`
- `Case`
- `Case Information`
- `Case type`
- `Identifier`
- `Handler`
- `Assign handler...`
- `Start date`
- `Result`
- `Result (required)`
- `Change status...`
- `Select result type...`
- `Confirm`
- `Participants`
- `No participants assigned`
- `Assign Handler`
- `Reassign`
- `Reassign handler to:`
- `Unknown`
- `Remove this participant?`
- `New Case`
- `New Task`
- `Create case`
- `Create task`
- `Select a case type...`
- `Enter case title...`
- `Not set`
- `Initial status`
- `Calculated deadline`
- `Case created with type '{type}'`

### 5. Case detail & extension
- `Closed on {date}`
- `This will extend the deadline by {period}.`
- `Extend Deadline`
- `Reason`
- `Why is an extension needed?`
- `Extend deadline`
- `Please select a result type`
- `Result is required when closing a case`
- `Status changed from '{from}' to '{to}'`
- `Status changed to '{status}'`
- `Updated: {fields}`
- `Are you sure you want to delete this case?`
- `This case has {count} linked tasks. Are you sure you want to delete it?`
- `Deadline extended from {old} to {new}. Reason: {reason}`
- `No reason provided`

### 6. Case types (admin)
- `Configure case types`
- `Draft`
- `Published`
- `Set as default`
- `Delete`
- `Only published case types can be set as default`
- `Cannot delete: active cases are using this type`
- `Failed to delete case type`
- `{from} — (no end)`
- `This will delete the case type and all {count} status types. Continue?`
- `Delete case type "{title}"?`
- `Failed to delete status type "{name}"`
- `New Case Type`
- `Case Type`
- `Publish`
- `Unpublish`
- `Cannot publish:`
- `Saved successfully`
- `General`
- `Statuses`
- `Please fix the validation errors`
- `Failed to save case type`
- `Unpublishing this case type will prevent new cases from being created. Existing cases will continue to function. Continue?`

### 7. Status types tab
- `Save the case type first before adding status types.`
- `Drag to reorder`
- `Final`
- `Notify`
- `Name`
- `Order`
- `Final status`
- `Notify initiator`
- `Notification text`
- `No status types defined. Add at least one to publish this case type.`
- `Add Status Type`
- `Name *`
- `Order *`
- `Add`
- `Status type name is required`
- `Order is required`
- `A status type with this order already exists`
- `Failed to add status type`
- `Failed to save`
- `At least one status type must be marked as final`
- `Delete status type "{name}"?`
- `Failed to delete status type`

### 8. Tasks
- `Due today`
- `Back to list`
- `New task`
- `Task`
- `Completed on {date}`
- `Case: {id}`
- `Username`
- `Title is required`
- `Are you sure you want to delete this task?`
- `New Task`
- `Enter task title...`
- `Optional description...`
- `Select priority`
- `Select due date`
- `Username (optional)`
- `Link to a case (optional)`
- `Create task`
- `Change status` (no ellipsis — QuickStatusDropdown)

### 9. My Work
- `Show completed`
- `Cases and tasks assigned to you will appear here`
- `All caught up!`
- `All your items are completed`
- `Due this week`
- `Upcoming`
- `No deadline`
- `Completed`
- `All`
- `Cases`
- `Tasks`
- `CASE`
- `TASK`

### 10. Result & activity
- `Result`
- `No result recorded yet`
- `Type: {type}`
- `Deadline & Timing`
- `Started`
- `Deadline`
- `Processing time`
- `Days elapsed`
- `Extension: allowed (+{period})`
- `Extension: already extended`
- `Extension: not allowed`
- `Request Extension`
- `Activity`
- `Add a note...`
- `Add note`
- `No activity yet`

### 11. Validation & utilities
- `{field} is required`
- `Title is required`
- `Case type is required`
- `Case type '{name}' is a draft and cannot be used to create cases`
- `Case type is not yet valid (valid from {date})`
- `Case type has expired (valid until {date})`
- `Must be a valid ISO 8601 duration (e.g., P56D for 56 days, P8W for 8 weeks, P2M for 2 months)`
- `Must be a valid ISO 8601 duration (e.g., P56D)`
- `Must be a valid ISO 8601 duration (e.g., P42D)`
- `Must be a valid ISO 8601 duration (e.g., P28D)`
- `Extension period is required when extension is allowed`
- `'Valid until' must be after 'Valid from'`
- `Missing required fields: {fields}`
- `'Valid from' date must be set`
- `At least one status type must be defined`
- `At least one status type must be marked as final`

### 12. Duration & relative time
- `1 year`
- `{n} years`
- `1 month`
- `{n} months`
- `1 week`
- `{n} weeks`
- `1 day`
- `{n} days`
- `1 day overdue`
- `{days} days overdue`
- `Due today`
- `Due tomorrow`
- `{days} days remaining`
- `{days} days`
- `just now`
- `{min} min ago`
- `{hours} hours ago`
- `yesterday`
- `{days} days ago`

### 13. Task lifecycle
- `Available`
- `Active`
- `Completed`
- `Terminated`
- `Disabled`
- `Start`
- `Complete`
- `Terminate`
- `Disable`

### 14. Priority (capitalized)
- `Urgent`
- `High`
- `Normal`
- `Low`

### 15. Confidentiality & origin
- `Internal`
- `External`
- `Public`
- `Restricted`
- `Case sensitive`
- `Confidential`
- `Highly confidential`
- `Secret`
- `Top secret`

### 16. Case type fields (getFieldLabel)
- `Purpose`
- `Trigger`
- `Subject`
- `Processing deadline`
- `Origin`
- `Confidentiality`
- `Responsible unit`
- `Extension period`
- `Service target`
- `Valid until`

### 17. User settings
- `Procest settings`
- `General`
- `No settings available yet`
- `User settings will appear here in a future update.`

### 18. Add participant
- `Add Participant`
- `Role type`
- `Select role type...`
- `Participant`
- `Select user...`
- `Failed to add participant`

---

## Placeholder Rules

- **Preserve exactly**: `{field}`, `{count}`, `{days}`, `{n}`, `{name}`, `{title}`, `{from}`, `{to}`, `{period}`, `{date}`, `{user}`, `{type}`, `{old}`, `{new}`, `{reason}`, `{fields}`, `{min}`, `{hours}`
- **Escaped quotes**: Keys like `Status changed from '{from}' to '{to}'` keep the quotes in the key; the placeholders are interpolated.

---

## Notes

1. **Duplicate keys**: Some keys appear in both en.json and code with different casing (e.g. "New case" vs "New Case"). The code uses exact keys — use the key as it appears in the t() call.
2. **ISO duration**: Four distinct keys exist (generic + P56D, P42D, P28D variants). Add all four.
3. **Priority**: en.json has lowercase `low`, `normal`, `high`, `urgent`; taskHelpers uses `Urgent`, `High`, `Normal`, `Low`. Both sets are needed.
4. **OverduePanel**: Uses `Overdue Cases`, `No overdue cases`, `View all overdue` — not in original design.
5. **QuickStatusDropdown**: Uses `Change status` (no ellipsis) vs `Change status...` elsewhere.

---

## Verification

After adding keys:
1. `en.json` and `nl.json` must have identical key sets
2. No trailing commas in JSON
3. Placeholders unchanged in both en and nl
4. Spot-check: Dutch locale shows Dutch strings for dashboard, case detail, case type admin, task forms
