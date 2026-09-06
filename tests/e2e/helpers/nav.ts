/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared navigation helpers for Dossiq e2e tests.
 *
 * The dossiq app ships a "Support Dossiq" dialog (`cn-support-dialog`)
 * that can auto-open over the app and whose modal-mask subtree intercepts
 * pointer events on the sidebar navigation — breaking nav clicks. Always
 * dismiss it before interacting with the app chrome.
 *
 * Navigation note: a direct deep-link `page.goto('/apps/dossiq/<route>')`
 * resets the Vue history-mode router to the Dashboard, so the deep-linked
 * route never renders its own view. Land on a route that resolves, then
 * click the sidebar nav entry (client-side) to reach the target view.
 */

import type { Page } from '@playwright/test'

/**
 * The app's sidebar navigation container.
 *
 * @param page
 * @return The navigation locator.
 */
export function sidebarNav(page: Page) {
	return page.locator('[id^="app-navigation"]').first()
}

/**
 * Close a modal by testid if it is open, tolerating its absence.
 *
 * @param page   The page.
 * @param testid The modal's `data-testid-modal` value.
 */
async function closeModalIfOpen(page: Page, testid: string): Promise<void> {
	const modal = page.locator(`[data-testid-modal="${testid}"]`)
	if (await modal.isVisible().catch(() => false)) {
		await modal
			.getByRole('button', { name: 'Close' })
			.click()
			.catch(() => {})
		await modal.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {})
	}
}

/**
 * Dismiss the "Support Dossiq" dialog if it is open. The dialog's
 * modal-mask intercepts pointer events on the navigation, so it must be
 * closed before any nav click.
 *
 * @param page
 */
export async function dismissSupportDialog(page: Page): Promise<void> {
	await closeModalIfOpen(page, 'cn-support-dialog')
	// The non-gating first-time-setup wizard mounts the same way and has the
	// same consequence: its modal-mask subtree swallows every click on the
	// app behind it. It only started appearing once CnAppRoot learned to tell
	// "the server reports this optional step as not done" from "the server
	// never mentioned it" — before that it could not open at all, so no spec
	// in this suite had ever had to account for it. It is dismissed here
	// rather than in each spec so a fresh browser profile (which has no
	// dismissal recorded, so the wizard DOES open) cannot silently redden
	// every click-driven test in the suite.
	await closeModalIfOpen(page, 'cn-wizard-dialog')
}

/**
 * Read every sidebar link as `{ label, href }` straight out of the DOM.
 *
 * Deliberately NOT `getByRole('link', …)`: most nav leaves live inside a
 * COLLAPSED group, so they are present in the DOM but `display:none`. A
 * role/visibility-based locator cannot see them, and the old implementation
 * therefore fell through to clicking a locator that matched nothing.
 *
 * @param page
 */
async function readNavLinks(
	page: Page,
): Promise<Array<{ label: string; href: string | null }>> {
	return await sidebarNav(page)
		.locator('a')
		.evaluateAll((els) =>
			els.map((e) => ({
				label: (e.textContent || '').trim().replace(/\s+/g, ' '),
				href: e.getAttribute('href'),
			})),
		)
}

/**
 * Navigate to a dossiq view by its sidebar label.
 *
 * WHY THIS DEEP-LINKS INSTEAD OF CLICKING
 * ---------------------------------------
 * This helper used to land on the app root and CLICK the sidebar entry,
 * because of a belief — stated in this file for months — that "a cold deep
 * link resets the history-mode router to the Dashboard". Measured on a CI
 * runner (2026-08-04), that is false: `/index.php/apps/dossiq/cases`,
 * `/my-work`, `/doorlooptijd` and `/tasks` each render their own view on a
 * direct GET.
 *
 * The click path, by contrast, was the single largest cause of CI failure.
 * The nav renders its leaves inside COLLAPSED groups ("Work queue",
 * "Reports", "Personal settings"), so `My work`, `Workflow board`,
 * `Processing time`, `Objections` and `Appeals` are all
 * `display:none` on load. Playwright's `.click()` waits for actionability,
 * and this suite sets no `actionTimeout`, so each such click blocked for the
 * ENTIRE 60s test budget and then failed with a bare timeout that named an
 * element rather than the cause. 122 tests × up to 2×60s is what pushed the
 * job past the shared workflow's 45-minute cap, where it was cancelled having
 * run only 65 tests.
 *
 * Resolving the label to its `href` and navigating directly is immune to
 * collapsed groups, needs no group-expansion bookkeeping, and is faster.
 * ## Why a RegExp is accepted
 *
 * A pinned exact label is a standing tripwire. dossiq#1646 regrouped the work
 * surfaces and renamed three at once — `Cases` became `All issues`, and later `All cases`, the `My
 * work` PAGE became `Assigned to me` once the GROUP took that name, and
 * `Voorstellen` became `Proposals` — and every call site naming the old string
 * broke together. Three of them wrapped the call in `.catch(() => {})`, so they
 * did not break loudly: they carried on against whatever the Dashboard renders.
 *
 * Passing a RegExp lets a call site accept the label in either locale, and
 * survive a rename that keeps the meaning, without giving up the fail-fast
 * behaviour below.
 *
 * @param page
 * @param label exact sidebar label, or a RegExp matching it — e.g. 'Dashboard'
 *              or `/^(All cases|Alle zaken)$/`
 */
export async function navTo(page: Page, label: string | RegExp): Promise<void> {
	await page.goto('/index.php/apps/dossiq')
	await dismissSupportDialog(page)

	const matches = (l: string) =>
		typeof label === 'string' ? l === label : label.test(l)

	const links = await readNavLinks(page)
	// Prefer a real navigating entry; a group header renders as href="#".
	const target = links.find((l) => matches(l.label) && l.href && l.href !== '#')

	if (!target || !target.href) {
		// Fail FAST and by NAME. The old code swallowed this into two
		// full-length action timeouts and then silently asserted against the
		// Dashboard, so a renamed nav label surfaced as an unrelated
		// "element not found" 60s later.
		const available = links
			.filter((l) => l.href && l.href !== '#')
			.map((l) => l.label)
		throw new Error(
			`[dossiq e2e] navTo(${String(label)}): no navigating sidebar link matches.\n`
				+ `Available navigating labels: ${JSON.stringify(available)}`,
		)
	}

	await page.goto(target.href)
	await dismissSupportDialog(page)
}

/**
 * Navigate to a dossiq route that has no sidebar nav entry (e.g. the global
 * Tasks list, which the nav-dedup pass dropped as a top-level leaf). A direct
 * deep-link resets the history-mode router to the Dashboard, so land on a
 * resolving route first and then push the target route client-side via a
 * sidebar link is not possible — instead navigate from within the loaded SPA.
 *
 * @param page
 * @param route in-app vue-router path, e.g. '/tasks'
 */
export async function navToRoute(page: Page, route: string): Promise<void> {
	// A direct GET renders the target view — measured on a CI runner
	// (2026-08-04) for /cases, /my-work, /doorlooptijd and /tasks. The previous
	// `$router.push` dance existed only to work around a deep-link reset that
	// does not actually happen, and it silently went nowhere whenever the
	// router handle could not be reached (returning as if it had navigated).
	//
	// THE `/index.php` PREFIX IS NOT SAFE TO HARD-CODE (measured 2026-08-26).
	// The app's router base is `generateUrl('/apps/dossiq')`, which returns
	// `/index.php/apps/dossiq` only where Nextcloud's front-controller URLs are
	// in play; on an instance with pretty URLs (`htaccess.IgnoreFrontController`)
	// it returns `/apps/dossiq`. Against such an instance every prefixed URL
	// falls outside the router base, so vue-router matches its
	// `/:pathMatch(.*)*` catch-all and REDIRECTS TO THE DASHBOARD — and the
	// dashboard renders happily, so a spec asserting on generic content passes
	// while never having visited the route it named. Measured: `/cases` and
	// `/flows` both landed on "Dashboard" with the prefix and rendered
	// correctly without it.
	//
	// Probing both forms and keeping whichever actually resolves makes the
	// helper correct under either configuration rather than under the one the
	// CI runner happened to have.
	for (const url of [`/apps/dossiq${route}`, `/index.php/apps/dossiq${route}`]) {
		await page.goto(url)
		await dismissSupportDialog(page)
		// The catch-all redirect drops the requested path entirely, so a URL
		// that still carries it is the tell that the route resolved.
		if (new URL(page.url()).pathname.endsWith(route)) return
	}
	// Neither form resolved. Leave the page where the last attempt landed and
	// let the caller's own assertion report what is missing — throwing here
	// would mask a genuinely retired route, which several specs assert on.
}

/**
 * The dossiq admin settings page (`/settings/admin/dossiq`) renders its many
 * sections progressively — the lower ones (Case Email — Shared Mailbox,
 * KCC-werkplek Integration, …) only mount once scrolled near. Scroll to the
 * bottom in steps so every section's heading + fields are in the DOM before a
 * test asserts on them, then return to the top.
 *
 * @param page
 */
export async function loadAllAdminSections(page: Page): Promise<void> {
	// The admin page mounts its lower sections lazily as the viewport scrolls
	// near them, so drive real Playwright scrolls to the document bottom in a
	// few steps. The page does expensive layout on each scroll; the calling
	// tests use test.slow() so the per-test budget covers it.
	for (let i = 0; i < 4; i++) {
		await page
			.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
			.catch(() => {})
		await page.waitForTimeout(500)
	}
	await page.evaluate(() => window.scrollTo(0, 0)).catch(() => {})
}

/**
 * Console / network errors that originate from Nextcloud core or the test
 * environment — NOT from the dossiq app — and must not fail a dossiq
 * coverage test. The dev instance emits a 500 on the core user-status
 * endpoint on every page load (`core: Failed to load user status`), which
 * surfaces as an un-attributed "Failed to load resource" console error.
 */
const NON_DOSSIQ_NOISE = [
	'favicon',
	'status.php',
	'Download the Vue Devtools',
	'Download the React',
	'user status', // core: Failed to load user status
	'/apps/user_status/',
	'Failed to load resource: the server responded with a status of 500', // generic, un-attributed
	// The dev/test instance serves a strict CSP (script-src 'nonce-…') with no
	// explicit worker-src, so the browser blocks registering dossiq's
	// service-worker.js. This is a CSP-hardening artifact of the test rig, not
	// an app-logic error — the page renders fine without the SW. (The URL is
	// dossiq-scoped so it is not caught by the generic filters above.)
	'service-worker.js',
	'worker-src',
	'violates the following Content Security Policy',
]

/**
 * Request URLs whose console errors are environment noise, matched against the
 * console message's LOCATION rather than its text.
 *
 * A failed subresource logs the bare text "Failed to load resource: the server
 * responded with a status of 404 (Not Found)" — the URL appears only in
 * `location()`. Filtering that text outright would hide real dossiq 404s, so
 * match the URL instead.
 *
 * dossiq probes optional cross-app capabilities on load (e.g. whether the
 * hermiq assistant is installed). On an instance that does not ship the other
 * app those probes 404 BY DESIGN, which is not a dossiq defect — the CI
 * instance installs only openregister alongside dossiq.
 */
const NON_DOSSIQ_URL_NOISE = [
	'/apps/hermiq/',
	'/apps/user_status/',
	'/status.php',
	'favicon',
]

/**
 * Attach console-error + 5xx listeners and return a live array of
 * dossiq-origin errors. Filters out known Nextcloud-core / environment
 * noise so a test fails only on errors the app itself is responsible for.
 * Read the returned array AFTER the page has settled.
 *
 * @param page
 */
export function trackDossiqErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('console', (m) => {
		if (m.type() !== 'error') return
		const text = m.text()
		if (NON_DOSSIQ_NOISE.some((n) => text.includes(n))) return
		const url = m.location()?.url ?? ''
		if (url && NON_DOSSIQ_URL_NOISE.some((n) => url.includes(n))) return
		errors.push(text)
	})
	page.on('response', (r) => {
		if (r.status() >= 500 && r.url().includes('/apps/dossiq/')) {
			errors.push(`HTTP ${r.status()} ${r.url()}`)
		}
	})
	return errors
}
