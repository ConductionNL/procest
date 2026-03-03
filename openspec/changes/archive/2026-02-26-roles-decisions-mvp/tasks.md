# Tasks: roles-decisions-mvp

## 1. Participants Section Component

- [x] 1.1 Create `src/views/cases/components/ParticipantsSection.vue` — Self-contained section receiving `caseId` prop. On mount: fetches roles via `objectStore.fetchCollection('role', { '_filters[case]': caseId })` and role types via `objectStore.fetchCollection('roleType', { _limit: 100 })`. Displays roles grouped by role type name. Each role row shows: participant display name (resolved via OCS user details or fallback to UID), role type label, and a remove button (trash icon). The handler role (identified by role type with `genericRole === 'handler'`) shows a "Reassign" button instead of remove. Empty state shows "No participants" with a prominent "Assign Handler" button. Footer has an "Add Participant" button that opens the AddParticipantDialog. Emits `@handler-changed` when handler is reassigned (so CaseDetail can refresh assignee). Follow BEM class naming `participants-section__*`. Use NcButton, NcLoadingIcon, NcEmptyContent.

- [x] 1.2 Create `src/views/cases/components/AddParticipantDialog.vue` — Modal dialog with two fields: (a) Role type selector (NcSelect populated from roleTypes prop), (b) Participant selector (NcSelect populated with Nextcloud users fetched from OCS API `GET /ocs/v2.php/cloud/users/details?format=json&limit=100` with requesttoken header; maps to `{ id: uid, label: displayName }` options). Validates both fields required before enabling "Add" button. On confirm: calls `objectStore.saveObject('role', { name: selectedRoleType.name, roleType: selectedRoleType.id, case: caseId, participant: selectedUser.id })`. Emits `@created` with the new role. Emits `@close` to dismiss. Uses `NcDialog` or `NcModal` for the container.

## 2. Handler Reassignment

- [x] 2.1 Add handler reassign flow to `ParticipantsSection.vue` — When "Reassign" is clicked on the handler role, show an inline NcSelect with the user list (same as AddParticipantDialog). On selection: (a) update the role via `objectStore.saveObject('role', { ...existingRole, participant: newUser })`, (b) update the case via `objectStore.saveObject('case', { id: caseId, assignee: newUser })`, (c) emit `@handler-changed`. If no handler role exists and "Assign Handler" is clicked, open AddParticipantDialog pre-filtered to handler role types.

## 3. Result Section Component

- [x] 3.1 Create `src/views/cases/components/ResultSection.vue` — Simple display component receiving `result` prop (result object or null) and `resultTypes` prop. If result exists: shows result type name, description, and date created. If no result: shows "No result recorded yet" in muted text. Read-only — result creation happens in the status change flow.

## 4. Result Type Selector in Status Change Flow

- [x] 4.1 Update `src/views/cases/CaseDetail.vue` — Replace the free-text `NcTextField` in the result prompt with an `NcSelect` dropdown of result types. Load result types filtered by case type on mount: `objectStore.fetchCollection('resultType', { '_filters[caseType]': caseTypeId, _limit: 100 })`. Store in `resultTypes` data. In `confirmStatusChange()`: (a) create a result object via `objectStore.saveObject('result', { name: selectedResultType.name, case: caseId, resultType: selectedResultType.id })`, (b) set `updateData.result = selectedResultType.name` for backward compat, (c) set `updateData.endDate`. If no result types exist for the case type, fall back to existing free-text field. Add `resultTypes` and `selectedResultType` to data. Import and add `ParticipantsSection` and `ResultSection` components to template.

## 5. Integration — CaseDetail Layout

- [x] 5.1 Update `src/views/cases/CaseDetail.vue` template — Add `<ParticipantsSection>` between the info/deadline panels and the tasks section, passing `caseId` prop and handling `@handler-changed` to refresh case data. Add `<ResultSection>` below the info panel (or in the info panel area), passing the result object and result types. Import both components.

## 6. Verification

- [ ] 6.1 Verify participants section shows on case detail with grouped roles
- [ ] 6.2 Verify "Add Participant" dialog opens and creates a role
- [ ] 6.3 Verify handler "Reassign" updates both role and case assignee
- [ ] 6.4 Verify "Assign Handler" appears on cases with no handler
- [ ] 6.5 Verify removing a non-handler role deletes it from the section
- [ ] 6.6 Verify result type dropdown appears when transitioning to final status
- [ ] 6.7 Verify result object is created on case completion
- [ ] 6.8 Verify free-text fallback works when no result types configured
- [ ] 6.9 Verify result displays in the ResultSection after case closure
- [ ] 6.10 Verify role validation rejects missing participant or roleType
