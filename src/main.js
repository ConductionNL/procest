// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import {
	buildManifest,
	CnPageRenderer,
	defaultPageTypes,
	fieldInspectionIntegration,
	registerIcons,
	registerIntegration,
	registerTranslations,
	useAppManifest,
} from '@conduction/nextcloud-vue'
// @vue/compat REMOVED (ADR-066 task 6.1): lib + dossiq source are compat-
// construct-free (v-model, no .sync/$set/filters/Vue.extend) — pure Vue 3.
import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { createApp, h, markRaw } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import customComponents from './customComponents.js'
import appIcons from './icons.js'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import pinia from './pinia.js'
import registry from './registry.js'
import formatters from './services/formatters.js'
import mapFormatters from './services/mapFormatters.js'
import { routerBase } from './utils/routerBase.js'

// Must stay first: sets __webpack_public_path__ before any dynamic import()
// (map/Leaflet, manifest validator) triggers lazy-chunk loading.
import './publicPath.js'
// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'
// Global (unscoped) app styles
import './assets/app.css'

// ADR-049: the app-specific `audit-trail` catalog override (AuditTrailWidget)
// was removed — the manifest `audit-trail` widget key now resolves to the
// library built-in CnAuditTrailWidget (self-registered into the shared
// dashboard-widget catalog with surfaces:['detail-page'], and present in
// BUILT_IN_WIDGETS for the slot CnWidgetGrid path). The built-in reads the
// detail object context the same way, so detail-page audit trails are unchanged.

// Vue 3 (ADR-066): global t/n now install via app.config.globalProperties
// after createApp (below), not Vue.mixin. pinia + router install via app.use.

// Register library-side icon set + lib translations once at bootstrap.
registerIcons(appIcons)
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[dossiq] registerTranslations failed; falling back to English', e)
}

// Fire-and-forget translation load. Some Nextcloud installs (including
// this repo's standard dev container) only allow the JS/CSS allowlist
// through Apache and rewrite everything else to index.php — there's no
// route for /custom_apps/<app>/l10n/<locale>.json so the request 404s.
// `loadTranslations` rejects on 404, so wrapping the Vue mount inside
// its callback meant boot silently failed when translations couldn't
// load. Strings just fall back to their English source on miss; boot
// MUST not depend on this resolving.
/**
 *
 */
function tryLoadTranslations() {
	try {
		const result = loadTranslations('dossiq', () => {})
		if (result && typeof result.then === 'function') {
			result.then(
				() => {},
				() => {},
			)
		}
	} catch {
		// no-op
	}
}

// Surface the generic `field-inspection` OpenRegister integration leaf with
// dossiq's own offline schema mapping. The leaf (a nc-vue builtin, registered
// live by OpenRegister's `integration-global` bundle) owns the offline planning
// list, checklist completion, mutation queue and reconnect-replay; dossiq only
// supplies its `offlineConfig` so the generic core points at dossiq's schemas.
//
// Bootstrap-order safety: dossiq's bundle may load before OpenRegister's, so
// install a minimal `_queue` stub that buffers the registration and replays it
// once OR's registry attaches. Registering dossiq's mapping FIRST means the
// AD-13 first-wins collision policy keeps dossiq's `offlineConfig` even when
// OR later registers the leaf with its canonical defaults. The mapping mirrors
// `DailySyncService` exactly (fieldInspection / inspectionChecklist /
// checklistResult, filtered by inspectorRef + scheduledAt).
//
// @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
registerIntegration({
	...fieldInspectionIntegration,
	offlineConfig: {
		// Schema holding today's planned inspections.
		plannedSchema: 'fieldInspection',
		// Schema holding the checklist templates referenced by planned items.
		referenceSchema: 'inspectionChecklist',
		// Property on a planned item that references its checklist template.
		templateRefField: 'checklistTemplateRef',
		// Schema the completed checklist result is written back to.
		resultSchema: 'checklistResult',
		// Planning filter: assignee uid property + the scheduled-date field.
		assigneeField: 'inspectorRef',
		dateField: 'scheduledAt',
		// Property on a planned item used as its display title.
		titleField: 'caseRef',
	},
})

// Apply ADR-037 manifest fragments before routes/app consume the manifest.
// `require.context` is a WEBPACK build-time API, not CommonJS `require`: the
// bundler rewrites this call at compile time and no `require` exists at
// runtime. eslint's browser globals therefore report `no-undef` correctly —
// the code is right and the linter is right. Scoped to this one identifier so
// a genuinely undefined name elsewhere in the file still fails.
/* global require */
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx
	.keys()
	.sort()
	.map((key) => fragmentCtx(key))
// markRaw: the manifest's ~130KB pages/menu/widget tree never needs
// per-property reactivity — only the top-level ref reassignment (below)
// needs to be tracked to re-render the nav after the backend delta lands.
// Without this, Vue 2 walks the entire nested structure with
// Object.defineProperty getters/setters on every app boot.
const builtManifest = markRaw(buildManifest(bundledManifest, fragments, menuLayout))

// Case-type navigation now lives on the Cases index page itself: a folder
// sidebar (config.folderSidebar) lists the live `caseType` objects and filters
// cases by `caseType` on select — replacing the former per-case-type menu
// children injected by the backend ManifestController (`/api/manifest` delta,
// now removed). `useAppManifest` still returns a REACTIVE ref (the render
// function reads `resolvedManifest.value`); with no backend delta it resolves
// to the built manifest.
const { manifest: resolvedManifest } = useAppManifest('dossiq', builtManifest)

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible (webpack ESM module records). Vue 2's `Vue.extend()`
// adds an internal `_Ctor` cache to the component definition; mutating
// a non-extensible export throws "Cannot add property _Ctor, object is
// not extensible". Cloning gives Vue Router an extensible
// component-options object without altering the lib's internals.
const RoutePageRenderer = { ...CnPageRenderer }

/**
 * Build the vue-router config from the manifest. Each manifest page becomes
 * one route; the route's `name` IS `page.id` (per the lib's manifest contract).
 * Routes whose path declares a `:` parameter receive `props: true` so the
 * underlying detail/custom component receives the route param.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 3 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to dashboard, preserving prior router behaviour.
	// vue-router 4 syntax: the bare '*' catch-all became a named param matcher.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}

// Routes are built from the built manifest only. The backend delta merely adds
// menu CHILDREN that point at the existing `Cases` route (via `query.caseType`);
// it introduces no new pages, so the route table needs no reactive rebuild.
//
// The base comes from the DOCUMENT'S OWN PATH, not from generateUrl().
// Nextcloud serves this app under both `/index.php/apps/dossiq/...` and
// `/apps/dossiq/...`, while generateUrl() returns only the form the
// instance's front-controller config prefers. A hard load of the other form
// then fell outside the router base, matched only the catch-all below, and
// was redirected to the dashboard — a deep link to a case answered 200 from
// the server and landed on `/apps/dossiq/` client-side, with only sidebar
// navigation ever reaching the detail page. See routerBase() for the rule.
const router = createRouter({
	history: createWebHistory(
		routerBase(window.location.pathname, generateUrl('/apps/dossiq')),
	),
	routes: routesFromManifest(builtManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib exports
// `defaultPageTypes` (and consumers' `customComponents`) as frozen module
// objects in some bundle shapes — Vue 2's `Vue.extend()` mutates component
// definitions to attach an internal `_Ctor` cache, which throws
// "Cannot add property _Ctor, object is not extensible" against a frozen
// source map. Cloning here yields extensible objects without changing
// the values the lib resolves at render time.
const pageTypesProp = { ...defaultPageTypes }
const customComponentsProp = { ...customComponents }
const registryProp = { ...registry }
const mapFormattersProp = { ...mapFormatters }
const formattersProp = { ...formatters }

const app = createApp({
	// This root uses a native Vue-3 render() (h from 'vue'). @vue/compat would
	// otherwise treat ANY `render` option as the deprecated Vue-2 RENDER_FUNCTION
	// API and wrap it, which nulls `currentRenderingInstance` for the whole
	// subtree — breaking renderSlot/resolveComponent in CnAppRoot (ADR-066).
	compatConfig: { RENDER_FUNCTION: false },
	// Expose the reactive manifest ref through setup() so the root render tracks
	// it as a reactive dependency: when useAppManifest resolves the backend
	// `/api/manifest` delta and reassigns `manifest.value`, the root re-renders
	// and re-passes the merged manifest to App (→ CnAppRoot → CnAppNav), so the
	// dynamic per-case-type children appear without a reload. A plain arrow
	// render reading `resolvedManifest.value` does NOT establish this tracking
	// reliably; a setup-returned ref does.
	setup() {
		return { resolvedManifest }
	},
	render() {
		return h(App, {
			// markRaw defensively: the initial value is already raw (see
			// builtManifest above); if a future backend `/api/manifest`
			// delta ever reassigns `resolvedManifest.value` to a fresh
			// (non-raw) merged object, this keeps the render tree from
			// paying the deep-reactivity walk on the merged result too.
			// The ref reassignment itself (not deep property tracking)
			// is what drives the case-type-nav re-render.
			// Vue 3: props pass FLAT (no `props:` wrapper in the data object).
			manifest: markRaw(this.resolvedManifest),
			customComponents: customComponentsProp,
			registry: registryProp,
			pageTypes: pageTypesProp,
			mapFormatters: mapFormattersProp,
			formatters: formattersProp,
		})
	},
})

// Vue 3 global install contract (ADR-066 task 4.5): t/n and $mapFormatters move
// from Vue.mixin / Vue.prototype to app.config.globalProperties. CnMapPage (and
// future map-type pages) resolve named formatters from `config.marker.formatter`.
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.config.globalProperties.$mapFormatters = mapFormattersProp

app.use(pinia)
app.use(router)
app.mount('#content')

// Register the mobiel-inspectie-offline Service Worker (PWA offline shell).
// Fire-and-forget: registration failure must never block app boot, and the
// app degrades gracefully to online-only when the worker is unavailable.
// @spec openspec/specs/mobiel-inspectie-offline/spec.md#requirement-offline-daily-planning-synchronization
if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
	window.addEventListener('load', () => {
		navigator.serviceWorker
			.register(generateUrl('/apps/dossiq/service-worker.js'), {
				scope: generateUrl('/apps/dossiq/'),
			})

			.catch((e) =>
				console.warn('[dossiq] service worker registration failed', e),
			)
	})
}
