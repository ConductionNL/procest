/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest stub for `@conduction/nextcloud-vue`.
 *
 * The published package ships a CJS bundle that does `require('foo.vue')`,
 * which Vite's transform pipeline cannot consume under the component
 * (jsdom-environment) unit tests (`@vitejs/plugin-vue2` is gated on Vite's
 * resolver, not Node's `require`). Component specs that mount things which
 * transitively import this package (e.g. `src/store/modules/object.js`'s
 * `createObjectStore`) do not need the real Pinia-store-factory behaviour —
 * they override the `objectStore`/`workflowStore` computed properties with
 * plain mocks — so only enough of the surface to satisfy `import` at module
 * load time is stubbed here. Extend as further component specs need more of
 * the real package's exports (mirrors launchpad/tests/vitest/stubs/
 * conduction-nextcloud-vue.js, which solved the same problem for its own
 * dashboard-widget usage of this package).
 */

/**
 * Stand-in for `createObjectStore(id, options)` — returns a Pinia
 * `useStore()`-shaped function. Never actually called in tests that reach
 * this stub (they override the computed `objectStore`/`workflowStore`
 * before the component would call it), so a minimal placeholder is enough.
 *
 * @param {string} id Pinia store id (unused)
 * @param {object} [options] Store options (unused)
 * @return {Function} A no-op "useStore" function
 */
export function createObjectStore(_id, _options) {
	return function useStubObjectStore() {
		return {}
	}
}

export const filesPlugin = () => ({})
export const auditTrailsPlugin = () => ({})
export const relationsPlugin = () => ({})
