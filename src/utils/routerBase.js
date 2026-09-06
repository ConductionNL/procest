// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The history base the router must use: the app prefix of the path the
 * document was ACTUALLY served under.
 *
 * Nextcloud accepts the app under two URL forms — `/index.php/apps/dossiq/...`
 * (front-controller) and `/apps/dossiq/...` (pretty URLs) — and serves the
 * same page for both. `generateUrl('/apps/dossiq')` returns exactly ONE of
 * them, decided by the instance's front-controller config, not by the URL in
 * the address bar. Basing the router on it therefore breaks every hard load
 * of the OTHER form: the location falls outside the base, vue-router matches
 * only the `/:pathMatch(.*)*` catch-all, and the catch-all redirects to the
 * dashboard. That is why a deep link to `/apps/dossiq/cases/<id>` answered
 * 200 from the server and then landed on the dashboard client-side, while
 * sidebar navigation (which never re-derives the base) worked fine.
 *
 * Deriving the base from the document's own pathname makes both forms
 * deep-linkable: whatever prefix the page was served under IS the base, by
 * construction. The marker must end the path or be followed by `/` so that
 * another app whose id merely starts with ours can never match.
 *
 * @param {string} pathname The document's `window.location.pathname`.
 * @param {string} fallback The base to use when the path does not contain the
 *   app mount at all (e.g. a test harness serving from `/`); pass
 *   `generateUrl('/apps/dossiq')`.
 *
 * @return {string} The history base for `createWebHistory()`.
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */
export function routerBase(pathname, fallback) {
	const marker = '/apps/dossiq'
	const at = String(pathname ?? '').indexOf(marker)

	if (at !== -1) {
		const after = String(pathname).charAt(at + marker.length)
		if (after === '' || after === '/') {
			return String(pathname).slice(0, at + marker.length)
		}
	}

	return fallback
}
