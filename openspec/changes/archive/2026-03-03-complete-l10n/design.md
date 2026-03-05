# Design: Complete l10n

## Context

Procest has `l10n/en.json` and `l10n/nl.json` with 55 keys each. The codebase uses ~175 unique keys via `t('procest', '...')` across 25+ files (views, utils, services). ~120 keys are missing. Missing keys cause fallback to the key string (often English), so Dutch users see mixed Dutch/English.

**User-reported**: With Nextcloud set to Nederlands, Procest displays almost entirely in English. This is caused by the incomplete l10n list (fallback to key), not by locale-loading. Exploration report: `exploration.md`.

## Goals / Non-Goals

**Goals:**
- Add all missing keys to en.json and nl.json
- Ensure valid JSON and correct placeholder syntax
- No code changes — l10n files only

**Non-Goals:**
- New languages
- Changing existing translations
- Refactoring t() calls

## File Map

### Modified Files

| File | Changes |
|------|---------|
| `l10n/en.json` | Add ~120 missing keys with English text |
| `l10n/nl.json` | Add ~120 missing keys with Dutch translations |

### Unchanged Files

| File | Reason |
|------|--------|
| All `src/` files | No code changes (loadTranslations fix reverted — caused empty text) |
| `l10n/*.pot` (if any) | Not used for this change |

## Design Decisions

### DD-01: Source of Truth for Keys

**Decision**: Extract keys from code via grep/search of `t('procest', '...')` patterns.

**Rationale**: The code is the source of truth for which strings need translation. Adding keys not used in code would create orphan entries.

### DD-02: Placeholder Syntax

**Decision**: Preserve `{name}` placeholders exactly in both en and nl. Only translate the surrounding text.

**Rationale**: Nextcloud's t() uses these for interpolation. Changing `{days}` to `{dagen}` would break the substitution. Example: `"{days} days overdue"` → nl: `"{days} dagen te laat"`.

### DD-03: Translation Approach

**Decision**: Add keys in batches by area (navigation, dashboard, cases, tasks, case types, validation, utilities). Use the exploration analysis as the reference list.

**Rationale**: Organized batches reduce errors and make review easier. The exploration already produced a categorized list.

## Key Categories (from exploration.md)

1. **Navigation & layout** — Track and manage tasks, Documentation, Case Types, Configuration, Register and schema settings
2. **Dashboard** — New Case, New Task, Open Cases, Overdue, Completed This Month, My Tasks, Cases by Status, KPI strings (+{n} today, avg {days} days, etc.), welcome messages
3. **Overdue panel** — Overdue Cases, No overdue cases, View all overdue
4. **Cases** — Case Information, Identifier, Handler, Result, Participants, Add Participant, Assign Handler, Reassign, etc.
5. **Case detail & extension** — Closed on {date}, Extend Deadline, Status changed from/to, Updated: {fields}, delete confirmations
6. **Case types (admin)** — Draft, Published, Publish, Unpublish, Configure case types, Statuses, delete/confirm dialogs
7. **Status types tab** — Save the case type first..., Drag to reorder, Final status, Notify initiator, Add Status Type, validation errors
8. **Tasks** — Due today, Completed on {date}, Case: {id}, Title is required, Create task, etc.
9. **My Work** — Show completed, All caught up!, Due this week, Upcoming, No deadline, Completed, All, CASE, TASK
10. **Result & activity** — Result, Type: {type}, Deadline & Timing, Extension: allowed (+{period}), Activity, Add note
11. **Validation** — {field} is required, four ISO duration variants (P56D, P42D, P28D, generic), Valid from/until
12. **Duration & relative time** — 1 year, {n} years, 1 day, {n} days, just now, yesterday, {days} days ago, Due tomorrow, {days} days remaining
13. **Task lifecycle** — Available, Active, Terminated, Disabled, Start, Complete, Terminate, Disable
14. **Priority (capitalized)** — Urgent, High, Normal, Low
15. **Confidentiality & origin** — Internal, External, Public, Restricted, Case sensitive, Confidential, etc.
16. **Case type fields** — Purpose, Trigger, Subject, Processing deadline, Origin, Responsible unit, etc.
17. **User settings** — Procest settings, No settings available yet
18. **Add participant** — Role type, Select role type..., Participant, Select user..., Failed to add participant

## Risks / Trade-offs

- **[Risk] Translation quality** → Dutch translations may need native review. Mitigation: Use consistent terminology (e.g., "zaak" for case, "taak" for task) matching existing nl.json.
- **[Trade-off] Large file** → en.json and nl.json will grow from 55 to ~175 entries. Acceptable; standard for localized apps.

## Exploration Notes

- **OverduePanel**: Uses `Overdue Cases`, `No overdue cases`, `View all overdue` — added to categories.
- **ISO duration**: Four distinct keys (generic + P56D, P42D, P28D). Add all.
- **Priority**: Both lowercase (en.json) and capitalized (taskHelpers) keys exist; add capitalized Urgent, High, Normal, Low.
- **QuickStatusDropdown**: `Change status` (no ellipsis) vs `Change status...` elsewhere — different keys.

## Post-Apply: Rebuild Required

After updating l10n files, run `npm run build` in the procest app directory. Nextcloud Vue apps compile assets at build time; l10n changes do not take effect until the app is rebuilt. Users may also need to hard refresh (Ctrl+Shift+R) or clear browser cache.

## Open Questions

None — exploration complete; `exploration.md` is the reference list.
