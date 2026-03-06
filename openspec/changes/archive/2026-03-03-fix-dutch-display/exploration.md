# Exploration: Fix Displaying Dutch Language

**Date**: 2026-03-03  
**Scope**: Identify why Dutch translations are not displayed when Nextcloud language is Nederlands; fix translation loading.

---

## Context from complete-l10n (archived)

- **L10n files**: `l10n/en.json` and `l10n/nl.json` have 302 keys each; Dutch translations are complete
- **Symptom**: User sets Nextcloud to Nederlands; Procest app still shows English
- **Failed fix**: `loadTranslations('procest', callback)` before mount caused **all text to display empty** — reverted
- **Current**: App uses `Vue.mixin({ methods: { t, n } })` with global `t` and `n`

---

## Technical Background

### @nextcloud/l10n

- **getLocale()**: `document.documentElement.dataset.locale || 'en'`
- **Translations**: `window._oc_l10n_registry_translations[appId]`
- **loadTranslations(appName, callback)**: Fetches `l10n/{locale}.json`, parses, calls `register(appName, result.translations)`. For locale 'en', resolves immediately without fetch.
- **register(appName, bundle)**: Sets `window._oc_l10n_registry_translations[appId] = bundle`

### Procest app flow

1. DashboardController returns `TemplateResponse(Application::APP_ID, 'index')`
2. Nextcloud renders `templates/index.php` (exists): `Util::addScript($appId, $appId . '-main')` + `<div id="content"></div>`
3. Page loads `procest-main.js` (webpack bundle)
4. main.js: `Vue.mixin({ methods: { t, n } })` then `new Vue(...).$mount('#content')`
5. Components call `this.t('procest', key)` → `t('procest', key)` (from mixin)

**Template finding**: `templates/index.php` does not explicitly load l10n. It only adds the main script. Nextcloud core may inject app l10n automatically when rendering the page — or it may not for app pages. 1.2/1.3 will tell us.

### Where do global t, n come from?

The app does not import `t` or `n`. They are used as globals. Nextcloud core typically injects these via the page. For app pages, the core may inject `OC.L10n` or similar. The `@nextcloud/l10n` package exports `t`, `n` that use `window._oc_l10n_registry_translations`. If the core injects a different `t` that uses a different registry, there could be a mismatch.

---

## Root Cause Hypotheses

| ID | Hypothesis | How to verify |
|----|------------|---------------|
| H1 | Server does not inject app translations for Procest | Inspect rendered HTML; check for l10n script tags |
| H2 | App template missing or does not load l10n | Check if `templates/index.php` exists; check default template |
| H3 | Global t vs @nextcloud/l10n t mismatch | Import t from @nextcloud/l10n; use loadTranslations before mount |
| H4 | document.documentElement.dataset.locale not set | DevTools: inspect `document.documentElement.dataset` when locale is Dutch |
| H5 | loadTranslations callback timing — Vue used stale t | Ensure t from @nextcloud/l10n; mount only after callback |

---

## Investigation Checklist

- [x] Check if `templates/index.php` exists in Procest — **YES** (adds procest-main.js, div#content)
- [x] In browser (Nextcloud set to Dutch): `document.documentElement.dataset.locale` — **returns "nl"** ✓
- [x] In browser: `window._oc_l10n_registry_translations` — **procest is undefined** (server does not inject app l10n)
- [ ] Compare with working apps — optional; root cause confirmed
- [ ] Try: import t,n from @nextcloud/l10n + loadTranslations before mount; verify no empty text

---

## Investigation Results (2026-03-03)

| Check | Result | Meaning |
|-------|--------|---------|
| 1.2 Locale | `"nl"` | Nextcloud correctly sets locale; user language is Dutch |
| 1.3 Registry | `procest` undefined | **Root cause**: Nextcloud server does NOT inject Procest's translations into `window._oc_l10n_registry_translations` |

**Conclusion**: The server does not load Procest's l10n for app pages. The app must load its own translations. Use `loadTranslations('procest', callback)` before mount, and import `t`, `n` from `@nextcloud/l10n` (not globals). Proceed to implement fix (section 2).

**Why previous loadTranslations caused empty text**: The app used global `t`/`n` (from Nextcloud core). Those may use a different registry or expect server-injected data. By importing `t`/`n` from `@nextcloud/l10n` and passing them to the mixin, we use the same implementation that `loadTranslations` registers with. Mount only after the callback so the registry is populated before any component renders.

---

## How to Inspect Locale and Registry (1.2, 1.3)

**1.2 Locale**: Open Procest with Nextcloud set to Nederlands. In DevTools Console:
```javascript
document.documentElement.dataset.locale   // expect "nl" or "nl_NL"
document.documentElement.lang            // expect "nl"
```

**1.3 Registry**: In DevTools Console:
```javascript
window._oc_l10n_registry_translations
```
Expand the object. Check if `procest` exists and has Dutch strings. If missing/empty, server is not injecting app l10n.

---

## Reference Apps (1.4)

| App | URL | Pattern |
|-----|-----|---------|
| Deck | https://github.com/nextcloud/deck/blob/main/src/main.js | `import { translate, translatePlural } from '@nextcloud/l10n'`; `Vue.prototype.t = translate`; no loadTranslations |
| Files | nextcloud/server/apps/files | Check src for l10n usage |

---

## Empty Text Bug — Root Cause (2026-03-03)

**Symptom**: After loadTranslations fix, `window._oc_l10n_registry_translations.procest` has Dutch translations, but frontend shows no words.

**Root cause**: `@nextcloud/l10n` exports `translate` and `translatePlural`, NOT `t` and `n`. The import `{ t, n, loadTranslations }` was resolving `t` and `n` to `undefined`. The Vue mixin received `methods: { t: undefined, n: undefined }`, so `this.t()` in components failed (undefined is not a function).

**Fix**: Use the correct import alias:
```javascript
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
```

---

## References

- complete-l10n exploration: `archive/2026-03-03-complete-l10n/exploration.md`
- @nextcloud/l10n: https://nextcloud-libraries.github.io/nextcloud-l10n/
- Nextcloud translations: https://docs.nextcloud.com/server/latest/developer_manual/basics/translations.html
