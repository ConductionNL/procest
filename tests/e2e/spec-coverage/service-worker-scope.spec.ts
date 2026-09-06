/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Guards the two properties of the mobiel-inspectie-offline Service Worker
 * that, when broken, are invisible everywhere except the one request the
 * worker happens to swallow.
 *
 *  1. SCOPE DISCIPLINE — the worker is registered at the dossiq app-root
 *     scope, so it sees every fetch every dossiq page makes. It must claim
 *     ONLY the traffic the offline field-inspection feature owns. It shipped
 *     with a tile rule that substring-matched `pdok` against
 *     `url.host + url.pathname`, which also matched this app's own address
 *     lookups at `/apps/openconnector/api/pdok/*`.
 *
 *  2. THE WORKER CAN REACH THE NETWORK — a Service Worker inherits the CSP of
 *     its own SCRIPT response, not the page's. Nextcloud's default for a
 *     controller response is `default-src 'none'` with no `connect-src`, under
 *     which every `fetch()` the worker makes is blocked. Both worker
 *     strategies then degrade to `Response.error()`, i.e. the worker could
 *     only ever break a request and never serve one — silently, because
 *     nothing in the app reports it.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

/**
 * Resolve the scope of dossiq's active service worker.
 *
 * @param page    The page under test.
 * @param timeout How long to wait for the worker to reach `activated`.
 * @return the worker's scope URL.
 */
async function activeWorkerScope(page: Page, timeout = 20_000): Promise<string> {
	const deadline = Date.now() + timeout
	while (Date.now() < deadline) {
		const scope = await page.evaluate(async () => {
			const regs = await navigator.serviceWorker.getRegistrations()
			return regs.find((r) => r.active !== null)?.scope ?? null
		})
		if (scope !== null) {
			return scope
		}
		await page.waitForTimeout(250)
	}
	throw new Error(
		`dossiq registered no ACTIVE service worker within ${timeout}ms.`,
	)
}

/**
 * Open dossiq with its service worker in control of the document.
 *
 * @param page The page under test.
 */
async function openControlled(page: Page): Promise<void> {
	await page.goto('/index.php/apps/dossiq/dashboard')
	await expect(page).not.toHaveURL(/login/, { timeout: 15000 })
	const scope = await activeWorkerScope(page)
	await page.goto(scope)
	await page.waitForFunction(
		() => navigator.serviceWorker.controller !== null,
		undefined,
		{ timeout: 20_000 },
	)
}

test.describe('mobiel-inspectie-offline service worker', () => {
	// @e2e openspec/specs/mobiel-inspectie-offline/spec.md#scenario-download-daily-schedule-with-cases-and-checklists
	test('the worker script is served with a connect-src it can actually use', async ({
		request,
	}) => {
		const res = await request.get('/index.php/apps/dossiq/service-worker.js', {
			failOnStatusCode: false,
		})
		expect(res.status()).toBe(200)
		const csp = res.headers()['content-security-policy'] ?? ''
		// Assert the DIRECTIVE, not merely that a policy exists: the broken
		// header was a perfectly well-formed policy that happened to forbid
		// every network call the worker makes.
		expect(
			csp,
			`service-worker.js CSP must grant connect-src; got "${csp}"`,
		).toContain('connect-src')
		expect(csp).toContain("connect-src 'self'")
		// The BRT achtergrondkaart WMTS host the tile cache fetches.
		expect(csp).toContain('https://service.pdok.nl')
	})

	// @e2e openspec/specs/mobiel-inspectie-offline/spec.md#scenario-download-daily-schedule-with-cases-and-checklists
	test('a request the worker CLAIMS still reaches the server', async ({
		page,
	}) => {
		await openControlled(page)
		// `/apps/dossiq/api/sync/...` is the one same-origin prefix the worker
		// answers itself (network-first). Under the old script CSP the worker's
		// own fetch was blocked, so this resolved to `Response.error()` and the
		// page saw `TypeError: Failed to fetch` — the offline feature could
		// never populate its cache in the first place.
		const outcome = await page.evaluate(async () => {
			try {
				const res = await fetch('/index.php/apps/dossiq/api/sync/planning', {
					headers: { 'OCS-APIRequest': 'true' },
				})
				return { status: res.status, type: res.type }
			} catch (e) {
				return { threw: String(e) }
			}
		})
		expect(
			outcome.threw,
			`the worker must be able to reach the network: ${outcome.threw ?? ''}`,
		).toBeUndefined()
		// `error` is the opaque type a `Response.error()` carries; a real answer
		// from Nextcloud is `basic`, whatever its status code.
		expect(outcome.type).toBe('basic')
	})

	// Scope discipline itself (property 1 in the header) is asserted in
	// `pdok-via-openconnector.spec.ts`, not here. A claimed request never
	// reaches Playwright's `page.route()`, so the marker header on the
	// fulfilled response there is a real detector for "the worker took this
	// one" — and it was mutation-verified: restoring the old substring rule
	// turns those three specs red. A version of that assertion written here
	// against the response alone was NOT a detector: once the worker can
	// reach the network again it re-fetches the same URL and hands back an
	// indistinguishable same-origin response, so it stayed green under the
	// planted defect. It is left out rather than left in as a check that
	// cannot fail.
})
