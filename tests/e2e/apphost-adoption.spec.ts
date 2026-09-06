/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AppHost adoption survives boot — the ADR-040 autoload prelude, observed.
 *
 * WHAT THIS PROTECTS, AND WHY IT NEEDS A RUNNING NEXTCLOUD.
 *
 * `health#index` and `metrics#index` are not dossiq classes. They exist only
 * as DI aliases that OpenRegister's `AppHost\Bootstrap::register()` installs
 * onto its own generic controllers, and that registration happens inside
 * dossiq's `Application::register()`.
 *
 * Apps register ONE AT A TIME, in SORTED order: `OC_App::getEnabledApps()`
 * does `sort()`, and `Coordinator::registerApps()` calls
 * `registerAutoloading($appId)` then `$application->register()` per app. So
 * every app's `register()` runs BEFORE the PSR-4 prefix of every
 * alphabetically LATER app exists. Without the prelude in
 * `lib/AppInfo/OpenRegisterAutoloader.php`, `OCA\OpenRegister\`
 * is not autoloadable at that moment for any app sorting before it.
 *
 * The failure is quiet: `AppHostRegistrar` guards the entry point with
 * `class_exists()`, so the miss does not fatal — it SKIPS. The app boots, the
 * SPA renders, the nav works, and smoke.spec.ts passes.
 *
 * ⚠️ STATUS CODE IS NOT THE DISCRIMINATOR, AND ASSUMING IT WAS COST A REWRITE.
 * The first version of this spec asserted `status < 500` and `status !== 404`.
 * Both pass on a completely broken adoption, because dossiq's SPA catch-all
 * answers ANY unmatched path with the app shell:
 *
 *     GET /api/health                  -> 200  application/json
 *     GET /api/definitely-not-a-route  -> 200  text/html      <-- measured
 *
 * A route that no longer resolves therefore returns 200 HTML, not 404 and not
 * 500. So these assertions read the CONTENT TYPE and the BODY SHAPE, which the
 * catch-all cannot fake: the shell is HTML and can never be Prometheus text or
 * a JSON health document.
 *
 * `dossiq` currently sorts after `openregister`, so the adoption also happens
 * to work by alphabet alone. That is exactly why the property is written down
 * rather than trusted — renaming the app, or moving the adoption into one that
 * sorts earlier, would flip it, and this spec is what would notice.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

import { expect, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'

const API_HEADERS = { 'OCS-APIRequest': 'true' }

test.describe('AppHost adoption (ADR-040)', () => {
	test('health#index is served by the AppHost generic, not the SPA catch-all', async ({
		request,
	}) => {
		const res = await request.get(
			`${BASE_URL}/index.php/apps/dossiq/api/health`,
			{
				headers: { ...API_HEADERS, Accept: 'application/json' },
				failOnStatusCode: false,
			},
		)

		const contentType = res.headers()['content-type'] ?? ''
		const body = await res.text()

		// The catch-all returns `text/html` with the app shell. Only the real
		// AppHost controller can return JSON here, so this single assertion is
		// what distinguishes "the alias was installed" from "the alias was
		// silently skipped and something else answered".
		expect(
			contentType,
			`health returned ${res.status()} ${contentType}. text/html means the SPA `
				+ 'catch-all answered — Bootstrap::register() never installed the AppHost '
				+ "alias, i.e. OCA\\OpenRegister\\ was not autoloadable during dossiq's "
				+ 'register(). See lib/AppInfo/Registrar/OpenRegisterAutoloadRegistrar.php.',
		).toContain('application/json')

		const doc = JSON.parse(body)
		expect(doc.app).toBe('dossiq')
		expect(doc.status).toBeTruthy()
		// The generic reports its own view of OpenRegister. If the adoption were
		// half-wired this key would not be here at all.
		expect(
			doc.checks,
			'the AppHost health generic always reports a checks block',
		).toBeTruthy()
	})

	test('metrics#index emits Prometheus text, which the SPA shell cannot', async ({
		request,
	}) => {
		const res = await request.get(
			`${BASE_URL}/index.php/apps/dossiq/api/metrics`,
			{
				headers: API_HEADERS,
				failOnStatusCode: false,
			},
		)

		const contentType = res.headers()['content-type'] ?? ''
		const body = await res.text()

		expect(
			contentType,
			`metrics returned ${res.status()} ${contentType}; expected Prometheus text. `
				+ 'See the health assertion above for what an HTML answer means.',
		).toContain('text/plain')

		// Prometheus exposition format. The app shell starts `<!DOCTYPE html>`,
		// so this cannot be satisfied by the catch-all under any circumstance.
		expect(body.startsWith('# HELP'), `body began: ${body.slice(0, 60)}`).toBe(
			true,
		)
		expect(body).toContain('dossiq_info')
	})

	test('the catch-all is alive, and no longer answers unmatched API paths', async ({
		request,
	}) => {
		// ⚠️ THIS TEST CHANGED, ON PURPOSE. It used to assert that
		// /api/definitely-not-a-route answered 200 text/html, and said "if this
		// is now 404, update the reasoning in this file". It is now 404, so this
		// is that update.
		//
		// `\OCA\OpenRegister\AppHost\Routes::catchAllRoute()` gained a
		// `(?!api/)` lookahead (openregister#3270). Its `/{path}` requirement
		// was `.+`, which matches slashes, so the catch-all swallowed every
		// unmatched `api/...` path and answered the SPA shell with HTTP 200 —
		// in zaakafhandelapp it ate all seventeen ZGW resource routes, and a
		// JSON caller received HTML without anything erroring. dossiq reaches
		// the same builder through the `class_exists()` branch at the bottom of
		// appinfo/routes.php, so the behaviour changed here too.
		//
		// This is STRICTLY better for the two assertions above. Their whole
		// point is to distinguish "the AppHost alias was installed" from
		// "something else answered": previously only the content-type could
		// tell those apart, because a missing alias fell through to a 200-HTML
		// shell. Now an unadopted API path fails loudly with a 404 as well.
		const unmatchedApi = await request.get(
			`${BASE_URL}/index.php/apps/dossiq/api/definitely-not-a-route`,
			{ headers: API_HEADERS, failOnStatusCode: false },
		)
		expect(
			unmatchedApi.status(),
			'an unmatched API path must NOT be answered by the SPA catch-all — if this '
				+ 'is 200 again, the (?!api/) lookahead has been lost from '
				+ 'Routes::catchAllRoute() and JSON callers are silently receiving HTML',
		).toBe(404)

		// The other half of the original guarantee, which still holds and still
		// matters: the catch-all itself is alive, so a non-api deep link is
		// served the SPA shell rather than 404ing. Probing an API path can no
		// longer establish that, which is why the probe moved off `api/`.
		const deepLink = await request.get(
			`${BASE_URL}/index.php/apps/dossiq/definitely-not-a-route`,
			{ headers: API_HEADERS, failOnStatusCode: false },
		)
		expect(
			deepLink.status(),
			'the SPA catch-all is dead: a non-api deep link 404s, so every bookmarked '
				+ 'dossiq route below / is broken',
		).toBe(200)
		expect(deepLink.headers()['content-type'] ?? '').toContain('text/html')
	})

	test('the SPA still boots with the prelude in place', async ({ page }) => {
		// Guards the other direction: registering another app's autoloader during
		// register() must not break dossiq's own boot. A prelude that threw, or
		// that booted OpenRegister early via loadApp(), would surface here.
		const errors: string[] = []
		page.on('pageerror', (e) => errors.push(String(e)))

		await page.goto('/index.php/apps/dossiq')
		await expect(page).toHaveURL(/.*dossiq/)
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, `uncaught page errors: ${errors.join(' | ')}`).toHaveLength(0)
	})
})
