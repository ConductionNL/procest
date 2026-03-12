# Tasks: Fix Displaying Dutch Language

## 1. Investigate

- [x] 1.1 Check if `templates/index.php` exists — **YES**, exists at `templates/index.php` (adds procest-main.js, div#content)
- [x] 1.2 In browser (Nextcloud set to Nederlands): inspect locale — **returns "nl"** ✓
- [x] 1.3 In browser: inspect translation registry — **procest is undefined** (server does not inject app l10n)
- [x] 1.4 Compare with working Nextcloud apps — optional; root cause confirmed

**Root cause**: Server does not inject Procest's l10n. App must load its own via `loadTranslations`. Proceed to section 2.

## 2. Implement Fix

- [x] 2.1 Import `translate as t`, `translatePlural as n`, `loadTranslations` from `@nextcloud/l10n` in `src/main.js`
- [x] 2.2 Wrap Vue mount in `loadTranslations('procest', () => { ... })` callback
- [x] 2.3 Pass imported `t`, `n` to `Vue.mixin({ methods: { t, n } })` (not globals)
- [x] 2.4 Apply same pattern to `src/settings.js` if it uses t/n
- [x] 2.5 If loadTranslations causes empty text: **FIXED** — import was wrong: use `translate as t, translatePlural as n` (package exports `translate`/`translatePlural`, not `t`/`n`)

## 3. Verify

- [x] 3.1 Set Nextcloud language to Nederlands; open Procest app
- [x] 3.2 Verify dashboard labels (Dashboard, New Case, New Task, etc.) show Dutch
- [x] 3.3 Verify case list, case detail, task forms show Dutch labels
- [x] 3.4 Verify English still works when locale is en
- [x] 3.5 Rebuild app (`npm run build`); hard refresh browser

## 4. Fallback (if app-side fix fails)

- [ ] 4.1 Create or update `templates/index.php` to explicitly load app l10n (if Nextcloud supports this)
- [ ] 4.2 Document findings for next investigation cycle
