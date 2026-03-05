# Proposal: Fix Displaying Dutch Language

## Summary

Fix the Procest app so that Dutch translations are displayed when the user's Nextcloud language is set to Nederlands. The l10n files (`l10n/en.json`, `l10n/nl.json`) are complete with 302 keys (complete-l10n change, archived). However, the app displays English regardless of locale. This change addresses the translation loading/locale injection so Dutch texts appear correctly.

## Problem

**User-reported symptom**: When Nextcloud language is set to Nederlands, the Procest app still displays almost entirely in English.

**Context**: The complete-l10n change added all missing keys to both l10n files. Automated verification passed (JSON valid, keys in sync, placeholders preserved). Manual Dutch verification failed — Dutch texts are not shown.

**Known facts**:
- `l10n/nl.json` contains 302 keys with correct Dutch translations
- The app uses `t('procest', '...')` throughout; `t` and `n` are provided via `Vue.mixin({ methods: { t, n } })` (globals)
- A previous attempt to use `loadTranslations('procest', callback)` from `@nextcloud/l10n` before mount caused **all text to display empty** — reverted
- Root cause is unclear: may be server-side (locale injection, app template) or app-side (translation registration timing)

## Scope — MVP

**In scope:**
- Investigate and fix why Dutch translations are not displayed
- Ensure `t('procest', key)` returns Dutch when user locale is Nederlands
- Preserve existing l10n files (no changes to en.json/nl.json content)

**Out of scope:**
- Adding new translation keys (already done in complete-l10n)
- Additional languages beyond en/nl
- Changing translation strings

## Approach

1. **Investigate** how Nextcloud injects locale and translations for app pages (TemplateResponse, app template, `document.documentElement.dataset.locale`, `window._oc_l10n_registry_translations`)
2. **Identify** whether the app template loads app l10n, or if the app must load it itself
3. **Fix** translation loading: either (a) ensure server injects app translations; (b) use `@nextcloud/l10n` correctly (debug why loadTranslations caused empty text); or (c) register translations before Vue mount with correct timing
4. **Verify** manually: set Nextcloud to Nederlands, confirm dashboard and key views show Dutch

## Capabilities

### Modified Capabilities

- **localization**: Dutch translations will actually display when user language is Nederlands. Completes the localization story started in complete-l10n.

## Impact

- **Frontend**: Likely `src/main.js`, `src/settings.js` (translation loading); possibly app template if server-side
- **Backend**: Possibly app template (templates/index.php) if Nextcloud requires explicit l10n injection
- **Dependencies**: @nextcloud/l10n, Nextcloud core translation system

## Dependencies

- complete-l10n (archived) — l10n files are complete; this change fixes display
- admin-settings spec (localization requirement)
- Nextcloud core (locale, app template)
