# Design: Fix Displaying Dutch Language

## Context

The Procest app uses `Vue.mixin({ methods: { t, n } })` so `t` and `n` are available in all components. These are global functions — not imported from `@nextcloud/l10n`. The `@nextcloud/l10n` library uses `window._oc_l10n_registry_translations[appId]` for translations and `document.documentElement.dataset.locale` for locale.

**Current flow**: `t('procest', key)` → `getAppTranslations('procest')` → `window._oc_l10n_registry_translations['procest']` → lookup or fallback to key.

**Problem**: Dutch translations exist in `l10n/nl.json` (302 keys) but are not displayed. Either (1) registry is empty for app; (2) registry has wrong locale; (3) locale is always 'en'.

**Failed attempt**: `loadTranslations('procest', callback)` before mount caused all text empty — suggests registration timing or wrong bundle format.

## Goals / Non-Goals

**Goals:**
- Dutch texts display when Nextcloud language is Nederlands
- No regression: English still works when locale is en
- Minimal changes to app code

**Non-Goals:**
- Changing l10n file content (complete-l10n already done)
- New languages

## File Map

### Files to Investigate / Modify

| File | Role |
|------|------|
| `src/main.js` | Entry point; may need to load/register translations before Vue mount |
| `src/settings.js` | Admin settings entry; same consideration |
| `templates/index.php` | If exists: app template; Nextcloud may inject l10n here |
| `appinfo/info.xml` | App metadata; no l10n config expected |

### Unchanged Files

| File | Reason |
|------|--------|
| `l10n/en.json`, `l10n/nl.json` | Complete; no changes |
| All Vue components | Use t() correctly; no changes |

## Root Cause Hypotheses

### H1: Server does not inject app translations

Nextcloud may inject core translations but not app-specific ones. For app pages (TemplateResponse), the app may need to load its own l10n via `loadTranslations` or the template must include a script that registers them.

**Check**: Inspect the rendered HTML for the Procest page; look for `window._oc_l10n_registry_translations` or `OC.L10n` scripts. Compare with a working Nextcloud app (e.g. Files, Calendar).

### H2: App template missing or does not load l10n

Procest uses `TemplateResponse(Application::APP_ID, 'index')`. If template does not exist, Nextcloud may use a default that does not load app l10n. If template exists, it may need to explicitly load `l10n/{locale}.json`.

**Check**: Does `templates/index.php` exist? If not, Nextcloud falls back to a default — what does it include?

### H3: loadTranslations approach — wrong usage or timing

The previous attempt used `loadTranslations('procest', callback)` before mount. It caused empty text. Possible causes:
- Callback ran before Vue mount but `t` was already bound to a stale/empty registry
- `register()` was called with wrong format (e.g. `bundle.translations` vs `bundle`)
- `t` from global scope vs `t` from @nextcloud/l10n — different implementations?

**Check**: `loadTranslations` fetches `l10n/{locale}.json` and calls `register(appName, result.translations)`. The nl.json format is `{ "translations": { "key": "value" } }`. So `result.translations` is correct. Verify `getLocale()` returns 'nl' when Nextcloud is Dutch. If locale is 'en', `loadTranslations` resolves immediately without fetching — that's correct.

**Fix attempt**: Import `t`, `n` from `@nextcloud/l10n` and use them in the mixin (not globals). Ensure `loadTranslations` completes and registers before any component renders. The empty-text bug may have been caused by using global `t` before registration — the global might come from a different source (e.g. OC.L10n) that expects different registration.

### H4: Locale not set on document

`getLocale()` returns `document.documentElement.dataset.locale || 'en'`. If Nextcloud does not set `data-locale` on the HTML element for app pages, locale would always be 'en'.

**Check**: In browser DevTools, when Nextcloud is Dutch: `document.documentElement.dataset.locale` and `document.documentElement.lang`.

## Design Decisions

### DD-01: Prefer app-side fix over server-side

**Decision**: First try fixing in the app (main.js, settings.js) by loading and registering translations before mount. Only if that fails, investigate server-side (app template, Nextcloud core).

**Rationale**: App-side changes are simpler to ship and don't require Nextcloud core changes. Many Nextcloud apps use `loadTranslations` successfully.

### DD-02: Import t, n from @nextcloud/l10n

**Decision**: Use `import { t, n, loadTranslations } from '@nextcloud/l10n'` and pass these to `Vue.mixin`. Do not rely on global `t`/`n`.

**Rationale**: The global `t`/`n` may come from Nextcloud core or another source. Using the package ensures we use the same implementation that `loadTranslations` registers with. This may fix the empty-text bug (mismatch between global and @nextcloud/l10n registry).

### DD-03: Async load before mount

**Decision**: Call `loadTranslations('procest', () => { /* mount Vue */ })` and only mount Vue in the callback after translations are loaded.

**Rationale**: Ensures translations are in `window._oc_l10n_registry_translations['procest']` before any component calls `t()`. For locale 'en', `loadTranslations` resolves immediately (no fetch). For 'nl', it fetches and registers.

## Implementation Sketch

```javascript
// main.js
import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import pinia from './pinia.js'
import router from './router/index.js'
import App from './App.vue'
import '@conduction/nextcloud-vue/css/index.css'

Vue.mixin({ methods: { t, n } })

loadTranslations('procest', () => {
  new Vue({
    pinia,
    router,
    render: h => h(App),
  }).$mount('#content')
})
```

## Risks / Trade-offs

- **[Risk] loadTranslations empty-text bug recurs** → If the fix was using global vs imported t, this should work. If not, need to debug: log `getLocale()`, `hasAppTranslations('procest')`, and registry contents before mount.
- **[Trade-off] Slight delay before mount** → For non-en locales, one extra network request. Acceptable; translations are small.

## Open Questions

1. Does Procest have a `templates/index.php`? If not, what template does Nextcloud use?
2. What does `document.documentElement.dataset.locale` show when Nextcloud is Dutch?
3. If loadTranslations + import t/n still fails, what does a working Nextcloud app (e.g. deck, files) do?
