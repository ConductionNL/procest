# Proposal: Case Management

## Summary

Enhance the existing basic case CRUD (CaseList + CaseDetail) with full case management capabilities: case type integration, status timeline, deadline tracking, extension support, case-scoped participants, and activity timeline. This builds on the completed case-types and task-management foundations.

## Problem

The current CaseList and CaseDetail views are minimal — hardcoded status options (open, in_progress, closed), no case type integration, no deadline calculation, no status timeline, and no activity log. Cases cannot leverage the rich case type configuration already built in the admin settings.

## Scope — MVP

**In scope (MVP tier):**
- Case creation with case type selection, validation (published + valid), auto-defaults (identifier, startDate, deadline, confidentiality, initial status)
- Case update with title, description, assignee, priority fields
- Case deletion with confirmation (warn about linked tasks)
- Case list with filters (type, status, handler, priority, overdue), search, sort, pagination
- Quick status change from list via dropdown (case-type-aware statuses)
- Case detail: info panel, status change dropdown, deadline panel with countdown
- Status timeline visualization (passed/current/future dots with dates)
- Participants panel (MVP: handler assignment via assignee field + display initiator)
- Tasks section (already exists from task-management, minor enhancements)
- Activity timeline (frontend-only event log stored as case property, not full Nextcloud Activity integration)
- Case result recording (basic: text field on final status)
- Deadline extension (when case type allows it, single extension)
- Deadline countdown display across list and detail views
- Validation rules (title required, case type required + published + valid)

**Out of scope (V1):**
- Full participant/role type management (CM-08b)
- Custom properties panel (CM-09)
- Document checklist (CM-10)
- Decisions section (CM-12)
- Status change blocked by missing properties/documents (CM-14c, CM-14d)
- Notification on status change (CM-14e)
- Case suspension (CM-17)
- Sub-cases (CM-18)
- Confidentiality level override UI (CM-19)
- Nextcloud Activity system integration for audit trail (CM-22 — use frontend event array instead)

## Approach

- Enhance existing CaseList.vue and CaseDetail.vue rather than replacing them
- New utility modules: `caseHelpers.js` (deadline calculations, countdown, identifier generation), `caseValidation.js` (form validation with case type awareness)
- New components: StatusTimeline.vue, DeadlinePanel.vue, ActivityTimeline.vue, CaseCreateDialog.vue
- Reuse existing patterns: useObjectStore for all CRUD, duration helpers for deadline display, task helpers for overdue logic

## Dependencies

- **case-types** (archived) — Case type and status type data in OpenRegister
- **task-management** (archived) — Task lifecycle, task section in CaseDetail
- **OpenRegister** — All data persistence
