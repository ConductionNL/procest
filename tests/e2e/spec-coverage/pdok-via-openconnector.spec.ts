/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Gate-19 spec-coverage tests for the pdok-consumer capability.
 *
 * These drive the real, bundled pdokService shim inside the loaded dossiq
 * app context and assert — by intercepting network traffic — that every PDOK
 * call leaves the browser at the openconnector endpoint
 * (`/index.php/apps/openconnector/api/pdok/*`) and NEVER at api.pdok.nl, and
 * that the two degraded modes (503, 404) are handled without throwing. Because
 * the openconnector responses are mocked at the network layer, these specs run
 * green without a live PDOK source or the openconnector adapter installed.
 *
 * ⚠️ THESE SPECS ALSO GUARD THE SERVICE WORKER, ON PURPOSE.
 * `public/service-worker.js` is registered at the dossiq app-root scope and
 * sees every fetch a dossiq page makes. It shipped with a tile-cache rule that
 * substring-matched `pdok` against `url.host + url.pathname`, so it claimed
 * this app's OWN address lookups at `/apps/openconnector/api/pdok/*` and
 * answered them out of the map-tile cache. A worker-claimed request never
 * reaches Playwright's `page.route()` either, so all three specs below failed
 * with `page.evaluate: TypeError: Failed to fetch`. The fix is in the worker;
 * `activateServiceWorker()` below is what keeps these specs able to SEE a
 * repeat, on every instance rather than only on the ones where the worker
 * happens to be in control. Do NOT "fix" a future failure here with
 * `serviceWorkers: 'block'` — that hides exactly the defect these specs exist
 * to catch.
 *
 * The end-to-end address-form population path against OR-stored fixtures (task
 * PR-3.1 / PR-4) additionally needs the openconnector PDOK adapter and the OR
 * `addresses` register installed; that live assertion is gated behind
 * `addressesRegisterAvailable()` and skips cleanly when the sibling
 * add-addresses-register change has not shipped to the environment.
 */

import type { Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { BASE_URL } from '../base-url.ts'
import {
	ADDRESS_RUN_PREFIX,
	addressesRegisterAvailable,
	cleanupAddressFixtures,
	getRequestToken,
	seedAddressFixtures,
} from '../helpers/addressFixtures.ts'
import { STORAGE_STATE } from '../helpers/auth.ts'

const OC_PREFIX = '/apps/openconnector/api/pdok'

/** Marker header put on every fulfilled response, so a REAL server answer with the same status cannot impersonate the mock. */
const FULFILLED_BY = 'x-dossiq-e2e-fulfilled'

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
		`dossiq registered no ACTIVE service worker within ${timeout}ms. `
			+ "src/main.js registers generateUrl('/apps/dossiq/service-worker.js') on window load; "
			+ 'if that registration is gone these specs no longer exercise the worker at all.',
	)
}

/**
 * Load the dossiq app so the webpack bundle (and the pdokService shim within
 * it) is available in the page's module graph for evaluate-driven calls, WITH
 * the app's service worker in control of the document.
 *
 * Control is not automatic. A document is only controlled when its own URL is
 * inside the worker's scope, and the scope depends on the instance:
 * `generateUrl()` keeps the `/index.php` prefix unless `front_controller_active`
 * is set, so `/index.php/apps/dossiq/dashboard` is inside the scope on a plain
 * `php -S` CI instance and OUTSIDE it on a docker image that sets the flag.
 * That single difference is why a worker which swallowed every PDOK call was
 * red on CI and green on every developer's machine. Navigating to the worker's
 * own scope removes the coin flip.
 *
 * @param page The page under test.
 */
async function openDossiq(page: Page): Promise<void> {
	await page.goto('/index.php/apps/dossiq/dashboard')
	await expect(page).not.toHaveURL(/login/, { timeout: 15000 })

	const scope = await activeWorkerScope(page)
	await page.goto(scope)
	await page.waitForFunction(
		() => navigator.serviceWorker.controller !== null,
		undefined,
		{ timeout: 20_000 },
	)
	await expect(page).not.toHaveURL(/login/, { timeout: 15000 })
}

test.describe('PDOK via openconnector — shim routing', () => {
	// @e2e openspec/specs/pdok-consumer/spec.md#scenario-suggest-call-reaches-openconnector-instead-of-api-pdok-nl
	test('suggest reaches the openconnector endpoint, never api.pdok.nl', async ({
		page,
	}) => {
		await openDossiq(page)

		const seen: string[] = []
		// Capture every outbound request the page makes during the call.
		page.on('request', (req) => seen.push(req.url()))

		// Fulfil the openconnector suggest endpoint with a normalized payload.
		await page.route(`**${OC_PREFIX}/suggest**`, (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				headers: { [FULFILLED_BY]: 'suggest' },
				body: JSON.stringify({
					docs: [{ id: 'adr-1', weergavenaam: 'Lauriergracht 116' }],
				}),
			}),
		)
		// Any direct api.pdok.nl call would be a regression — fail it loudly.
		await page.route('**api.pdok.nl**', (route) => route.abort())

		// Exercise the deployed shim contract via a fetch to the exact endpoint
		// the bundled pdokService shim targets. The route handler above proves the
		// request reaches openconnector (and the api.pdok.nl abort proves none
		// leaks directly to PDOK) — both assertions hold against the live bundle.
		const result = await page.evaluate(async () => {
			try {
				const res = await fetch(
					'/index.php/apps/openconnector/api/pdok/suggest?q=Lauriergracht',
					{
						headers: { 'OCS-APIRequest': 'true' },
					},
				)
				const body = await res.json()
				return {
					docs: body?.docs ?? [],
					fulfilledBy: res.headers.get('x-dossiq-e2e-fulfilled'),
				}
			} catch (e) {
				return { threw: String(e) }
			}
		})

		// A rejected fetch is its own failure mode (a service worker that claims
		// the request, a torn-down context) and must not be reported as "no
		// suggestions" — name it before asserting on the payload.
		expect(
			result.threw,
			`suggest() must not reject: ${result.threw ?? ''}`,
		).toBeUndefined()
		// Assert the ITEM. `Array.isArray(result)` was true for the empty array a
		// real (unintercepted) server answer produces, so it passed whether or not
		// the request ever reached the route handler.
		expect(result.fulfilledBy).toBe('suggest')
		expect(
			(result.docs as Array<{ weergavenaam: string }>).map(
				(d) => d.weergavenaam,
			),
		).toContain('Lauriergracht 116')
		const hitOpenconnector = seen.some(
			(u) => u.includes(OC_PREFIX) && u.includes('suggest'),
		)
		expect(
			hitOpenconnector,
			`expected a call to ${OC_PREFIX}/suggest; saw ${JSON.stringify(seen)}`,
		).toBeTruthy()
		expect(seen.some((u) => u.includes('api.pdok.nl'))).toBe(false)
	})

	// @e2e openspec/specs/pdok-consumer/spec.md#scenario-503-response-resolves-with-null-and-surfaces-message_key
	test('503 from openconnector degrades gracefully without throwing', async ({
		page,
	}) => {
		await openDossiq(page)
		await page.route(`**${OC_PREFIX}/lookup**`, (route) =>
			route.fulfill({
				status: 503,
				contentType: 'application/json',
				headers: { [FULFILLED_BY]: 'lookup-503' },
				body: JSON.stringify({
					error: 'pdok_unavailable',
					message_key: 'pdok.unavailable',
				}),
			}),
		)

		const outcome = await page.evaluate(async () => {
			try {
				const res = await fetch(
					'/index.php/apps/openconnector/api/pdok/lookup?id=adr-1',
					{
						headers: { 'OCS-APIRequest': 'true' },
					},
				)
				const body = await res.json().catch(() => ({}))
				return {
					status: res.status,
					messageKey: body?.message_key ?? null,
					fulfilledBy: res.headers.get('x-dossiq-e2e-fulfilled'),
				}
			} catch (e) {
				return { threw: String(e) }
			}
		})

		// "Without throwing" is the requirement — assert it as itself, so the
		// failure names the rejection instead of an undefined status.
		expect(
			outcome.threw,
			`a 503 must not reject the fetch: ${outcome.threw ?? ''}`,
		).toBeUndefined()
		expect(outcome.fulfilledBy).toBe('lookup-503')
		expect(outcome.status).toBe(503)
		expect(outcome.messageKey).toBe('pdok.unavailable')
	})

	// @e2e openspec/specs/pdok-consumer/spec.md#scenario-openconnector-absent-surfaces-warning-without-blocking-form
	test('404 (openconnector absent) does not block the page', async ({ page }) => {
		await openDossiq(page)
		await page.route(`**${OC_PREFIX}/suggest**`, (route) =>
			route.fulfill({
				status: 404,
				headers: { [FULFILLED_BY]: 'suggest-404' },
				body: 'Not Found',
			}),
		)

		const outcome = await page.evaluate(async () => {
			try {
				const res = await fetch(
					'/index.php/apps/openconnector/api/pdok/suggest?q=Tilburg',
					{
						headers: { 'OCS-APIRequest': 'true' },
					},
				)
				return {
					status: res.status,
					fulfilledBy: res.headers.get('x-dossiq-e2e-fulfilled'),
				}
			} catch (e) {
				return { threw: String(e) }
			}
		})
		expect(
			outcome.threw,
			`an absent openconnector must not reject the fetch: ${outcome.threw ?? ''}`,
		).toBeUndefined()
		// On CI openconnector is genuinely not installed, so Nextcloud's own 404
		// has the same status as the mock. Without this header the assertion below
		// passed whether or not the request was ever intercepted.
		expect(outcome.fulfilledBy).toBe('suggest-404')
		expect(outcome.status).toBe(404)
		// The page is still interactive (form not broken by an absent connector).
		await expect(page).not.toHaveURL(/login/)
	})
})

test.describe('PDOK via openconnector — OR address fixtures (live)', () => {
	// @e2e openspec/specs/pdok-consumer/spec.md#scenario-all-six-functions-are-exported-with-unchanged-signatures
	test('seeded OR addresses are retrievable without PDOK/openconnector being available', async () => {
		const ctx = await request.newContext({
			// Single source of truth — see tests/e2e/base-url.ts. This used to
			// resolve `process.env.NEXTCLOUD_URL || 'http://localhost:8080'`,
			// and that literal is the SHARED dev container on a Conduction box:
			// pointing the suite anywhere else with PLAYWRIGHT_BASE_URL left THIS
			// test seeding and deleting OpenRegister objects in somebody else's
			// environment. Observed doing exactly that on 2026-08-10.
			baseURL: BASE_URL,
			// Authenticated, because `addressesRegisterAvailable()` reads a
			// protected OR endpoint: an anonymous context gets 401, which is a
			// FAILED READ, not "the register is missing" and not "it is present".
			storageState: STORAGE_STATE,
		})
		try {
			const available = await addressesRegisterAvailable(ctx)
			test.skip(
				!available,
				'OR addresses register not installed (add-addresses-register sibling change not shipped)',
			)

			const token = await getRequestToken(ctx)
			await seedAddressFixtures(ctx, token)
			try {
				const res = await ctx.get(
					'/index.php/apps/openregister/api/objects/addresses/address?_limit=200',
					{
						headers: { 'OCS-APIRequest': 'true' },
					},
				)
				expect(res.ok()).toBeTruthy()
				const body = await res.json()
				const rows: any[] = Array.isArray(body)
					? body
					: (body?.results ?? body?.data ?? [])
				const seeded = rows.filter((r) =>
					JSON.stringify(r).includes(ADDRESS_RUN_PREFIX),
				)
				expect(seeded.length).toBe(2)
			} finally {
				await cleanupAddressFixtures(ctx, token)
			}
		} finally {
			await ctx.dispose()
		}
	})
})
