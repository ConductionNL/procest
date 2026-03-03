# Tasks: Complete l10n

**Reference**: Use `exploration.md` as the definitive list of ~120 missing keys. Keys must match exact form used in code.

## 1. Add Missing Keys to en.json (by category)

- [ ] 1.1 Navigation & layout (Track and manage tasks, Documentation, Case Types, Configuration, Register and schema settings)
- [ ] 1.2 Dashboard (New Case, New Task, Open Cases, Overdue, KPI strings, welcome messages, Retry, Failed to load dashboard data)
- [ ] 1.3 Overdue panel (Overdue Cases, No overdue cases, View all overdue)
- [ ] 1.4 Cases (Manage cases and workflows, Case Information, Identifier, Handler, Participants, Add Participant, Assign Handler, Reassign, etc.)
- [ ] 1.5 Case detail & extension (Closed on {date}, Extend Deadline, Status changed from/to, Updated: {fields}, delete confirmations)
- [ ] 1.6 Case types admin (Draft, Published, Publish, Unpublish, Configure case types, Statuses, delete/confirm dialogs)
- [ ] 1.7 Status types tab (Save the case type first..., Drag to reorder, Final status, Notify initiator, Add Status Type, validation errors)
- [ ] 1.8 Tasks (Due today, Completed on {date}, Case: {id}, Title is required, Create task, Change status, etc.)
- [ ] 1.9 My Work (Show completed, All caught up!, Due this week, Upcoming, No deadline, Completed, All, CASE, TASK)
- [ ] 1.10 Result & activity (Result, Type: {type}, Deadline & Timing, Extension strings, Activity, Add note)
- [ ] 1.11 Validation (four ISO duration variants, {field} is required, Valid from/until, case type validation messages)
- [ ] 1.12 Duration & relative time (1 year, {n} years, 1 day, {n} days, just now, yesterday, {days} days ago, Due tomorrow, {days} days remaining)
- [ ] 1.13 Task lifecycle (Available, Active, Terminated, Disabled, Start, Complete, Terminate, Disable)
- [ ] 1.14 Priority capitalized (Urgent, High, Normal, Low)
- [ ] 1.15 Confidentiality & case type fields (Internal, External, Purpose, Trigger, Subject, etc.)
- [ ] 1.16 User settings & Add participant (Procest settings, No settings available yet, Role type, Select role type..., etc.)

## 2. Add Dutch Translations to nl.json

- [ ] 2.1 Add nl translations for all keys added in step 1 — match existing nl.json style (zaak, taak, etc.)
- [ ] 2.2 Preserve placeholder syntax ({field}, {count}, {days}, {n}, {name}, {title}, {from}, {to}, {period}, {date}, {user}, {type}, {old}, {new}, {reason}, {fields}, {min}, {hours}) in Dutch strings

## 3. Verify

- [ ] 3.1 Validate JSON syntax (no trailing commas, valid escaping)
- [ ] 3.2 Verify key count: en.json and nl.json have identical keys (~175 total)
- [ ] 3.3 Spot-check: switch Nextcloud to Dutch, verify sample strings display in Dutch

## Verification Tasks

- [ ] V01 Manual test: Set Nextcloud language to Dutch, verify dashboard labels in Dutch
- [ ] V02 Manual test: Verify case detail, case type admin, and task forms show Dutch labels
- [ ] V03 Manual test: Verify error messages and validation text appear in Dutch
- [ ] V04 Manual test: Verify Overdue panel, My Work, and relative time strings (e.g. "yesterday", "{days} days ago") in Dutch
