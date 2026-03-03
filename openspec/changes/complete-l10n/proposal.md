# Proposal: Complete l10n

## Summary

Add all missing translation keys to the Procest app's l10n files (`l10n/en.json` and `l10n/nl.json`). Currently 55 keys exist; ~120 keys used in the codebase are missing (exploration: 25+ files across views, utils, services). Dutch users see untranslated English strings for many UI elements. This change completes the localization so the app displays correctly in both English and Dutch.

## Problem

The Procest app uses `t('procest', '...')` for user-facing strings throughout the codebase. When a key is missing from the active locale's JSON file, Nextcloud falls back to the key itself (often English). For Dutch users, this means many labels, messages, placeholders, and error texts appear in English instead of Dutch.

**User-reported symptom**: When Nextcloud language is set to Nederlands, the Procest app still displays almost entirely in English. This is caused by the incomplete l10n list — not by a locale-loading bug. With ~55 keys in `nl.json` and ~120 keys used in code, most strings fall back to the (English) key.

The admin-settings spec requires: "All labels, error messages, validation messages, and placeholder text MUST support English and Dutch localization."

## Scope — MVP

**In scope:**
- Add all missing translation keys to `l10n/en.json` (English as source)
- Add Dutch translations for all keys to `l10n/nl.json`
- Preserve placeholder syntax (`{field}`, `{count}`, `{days}`, etc.) in both files
- Cover: navigation, dashboard, cases, tasks, case types, validation, utilities, user settings

**Out of scope:**
- Additional languages (beyond en/nl)
- Changing existing translations (only adding missing ones)
- Extracting new strings from code (only add keys already used via t())

## Approach

1. Use exploration report (`exploration.md`) as the definitive list of missing keys — already extracted from 25+ files (views, utils, services)
2. Add missing keys to `en.json` in batches by category (see design Key Categories)
3. Add corresponding Dutch translations to `nl.json`, preserving placeholder syntax
4. Verify JSON syntax and that en.json and nl.json have identical key sets

## Capabilities

### New Capabilities

- **localization**: Complete English and Dutch translations for all user-facing strings in the Procest app. Ensures compliance with admin-settings REQ (localization) and improves UX for Dutch users.

## Impact

- **Files**: `l10n/en.json`, `l10n/nl.json`
- **Backend**: None
- **Dependencies**: Nextcloud l10n system, existing t() calls in code

## Dependencies

- admin-settings spec (localization requirement)
- Existing codebase (no code changes — only l10n file updates)
