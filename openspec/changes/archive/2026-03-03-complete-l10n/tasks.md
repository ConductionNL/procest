# Tasks: Complete l10n

**Reference**: Use `exploration.md` as the definitive list of ~120 missing keys. Keys must match exact form used in code.

## 1. Add Missing Keys to en.json (by category)

- [x] 1.1 Navigation & layout (Track and manage tasks, Documentation, Case Types, Configuration, Register and schema settings)
- [x] 1.2 Dashboard (New Case, New Task, Open Cases, Overdue, KPI strings, welcome messages, Retry, Failed to load dashboard data)
- [x] 1.3 Overdue panel (Overdue Cases, No overdue cases, View all overdue)
- [x] 1.4 Cases (Manage cases and workflows, Case Information, Identifier, Handler, Participants, Add Participant, Assign Handler, Reassign, etc.)
- [x] 1.5 Case detail & extension (Closed on {date}, Extend Deadline, Status changed from/to, Updated: {fields}, delete confirmations)
- [x] 1.6 Case types admin (Draft, Published, Publish, Unpublish, Configure case types, Statuses, delete/confirm dialogs)
- [x] 1.7 Status types tab (Save the case type first..., Drag to reorder, Final status, Notify initiator, Add Status Type, validation errors)
- [x] 1.8 Tasks (Due today, Completed on {date}, Case: {id}, Title is required, Create task, Change status, etc.)
- [x] 1.9 My Work (Show completed, All caught up!, Due this week, Upcoming, No deadline, Completed, All, CASE, TASK)
- [x] 1.10 Result & activity (Result, Type: {type}, Deadline & Timing, Extension strings, Activity, Add note)
- [x] 1.11 Validation (four ISO duration variants, {field} is required, Valid from/until, case type validation messages)
- [x] 1.12 Duration & relative time (1 year, {n} years, 1 day, {n} days, just now, yesterday, {days} days ago, Due tomorrow, {days} days remaining)
- [x] 1.13 Task lifecycle (Available, Active, Terminated, Disabled, Start, Complete, Terminate, Disable)
- [x] 1.14 Priority capitalized (Urgent, High, Normal, Low)
- [x] 1.15 Confidentiality & case type fields (Internal, External, Purpose, Trigger, Subject, etc.)
- [x] 1.16 User settings & Add participant (Procest settings, No settings available yet, Role type, Select role type..., etc.)

## 2. Add Dutch Translations to nl.json

- [x] 2.1 Add nl translations for all keys added in step 1 — match existing nl.json style (zaak, taak, etc.)
- [x] 2.2 Preserve placeholder syntax ({field}, {count}, {days}, {n}, {name}, {title}, {from}, {to}, {period}, {date}, {user}, {type}, {old}, {new}, {reason}, {fields}, {min}, {hours}) in Dutch strings

## 3. Translation Loading Fix (reverted — caused all text to show empty)

- [x] 3.0 ~~Import `t`, `n`, `loadTranslations` from `@nextcloud/l10n`~~ — REVERTED: loadTranslations approach caused all text to display empty; restored original global t/n

## 4. Rebuild (required for l10n to take effect)

- [x] 4.1 Run `npm run build` in the procest app directory — Nextcloud Vue apps must be rebuilt after l10n changes (on Windows, if `NODE_ENV=production` fails, use `npx webpack --config webpack.config.js --progress` or run from WSL)
- [x] 4.2 Hard refresh browser (Ctrl+Shift+R) or clear cache after rebuild
- [x] 4.3 Optionally: `occ app:disable procest` then `occ app:enable procest` to clear Nextcloud app cache

## 5. Verify

- [x] 5.1 Validate JSON syntax (no trailing commas, valid escaping)
- [x] 5.2 Verify key count: en.json and nl.json have identical keys (302 total)
- [x] 5.3 Spot-check: switch Nextcloud to Dutch — **FAILED**: Dutch texts are not shown (app displays English regardless of locale)

## Verification Tasks

- [x] V-auto Automated: `node openspec/verify-l10n.js` — JSON valid, key sync, placeholders, code coverage
- [x] V01 Manual test: Set Nextcloud language to Dutch, verify dashboard labels in Dutch — **FAILED**: Dutch not displayed
- [x] V02 Manual test: Verify case detail, case type admin, and task forms show Dutch labels — **FAILED**: Dutch not displayed
- [x] V03 Manual test: Verify error messages and validation text appear in Dutch — **FAILED**: Dutch not displayed
- [x] V04 Manual test: Verify Overdue panel, My Work, and relative time strings in Dutch — **FAILED**: Dutch not displayed

---

## Archive Note

**Status**: Archived. L10n keys added (302 total); automated verification passed. Manual Dutch verification failed — Dutch texts are not displayed in the app. A **follow-up change** will be created to fix Dutch display (locale/translation loading on the server or app side). This change completes the l10n file updates only.
