/*
 * SPDX-FileCopyrightText: 2026 Procest Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AVG verwerkingenlogging spec coverage — AFTER the surface moved to OpenRegister.
 *
 * page-topology-cleanup (C1) retired procest's /verwerkingen page. Per ADR-047
 * the AVG/DSAR workflow and its register are OpenRegister capabilities, and this
 * page was never an implementation of one — it was a window onto OR's, and a
 * broken one: it called `/api/avg/verwerkingsactiviteiten`, which OR had renamed
 * to `/api/avg/processing-activities`. The call 404'd, and the page rendered a
 * "No processing activities — run the repair step" empty state that blamed
 * missing data for a dead endpoint. The catalogue was in OR the whole time.
 *
 * So the requirement did not disappear, it changed address. These tests assert
 * exactly that: procest no longer hosts the surface, and the capability is live
 * where it belongs.
 */
import { expect, test } from '@playwright/test'
import { BASE_URL } from '../base-url.ts'

const BASE = BASE_URL

test.describe('AVG verwerkingenlogging spec coverage', () => {
	// @e2e openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md#scenario-procest-hosts-no-processing-activities-page
	test('procest no longer hosts a processing-activities page', async ({
		page,
	}) => {
		// MUST be the app's real id. Against `/apps/procest/...` the server
		// serves nothing at all, so `toHaveCount(0)` would pass without the
		// retirement having anything to do with it — the test would assert the
		// absence of a heading that could never have rendered either way.
		await page.goto(`${BASE}/index.php/apps/dossiq/verwerkingen`)
		// The retired route is unrouted, so the SPA falls back to the app root
		// rather than rendering the old overview. Assert the heading is gone —
		// asserting a 404 would be wrong, the server serves the SPA for any app path.
		await expect(
			page.getByRole('heading', { name: 'Processing activities (AVG)' }),
		).toHaveCount(0)
	})

	// @e2e openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md#scenario-the-capability-is-reachable-in-openregister
	test('the processing-activity catalogue is served by OpenRegister', async ({
		request,
	}) => {
		// The endpoint procest used to call, under the name OR actually serves.
		// A 200 with the seeded case-handling catalogue is what makes retiring
		// procest's window lossless.
		const res = await request.get(
			`${BASE}/index.php/apps/openregister/api/avg/processing-activities`,
		)
		expect(res.status()).toBe(200)
		const body = await res.json()
		expect(Array.isArray(body.results)).toBe(true)
	})

	// @e2e openspec/changes/page-topology-cleanup/specs/avg-processing-surface/spec.md#scenario-the-capability-is-reachable-in-openregister
	test('the per-subject access export is served by OpenRegister', async ({
		request,
	}) => {
		// OR-PA-7. Called without a subject identifier on purpose: a 4xx proves
		// the route EXISTS and validates, where a 404 would mean it does not.
		const res = await request.get(
			`${BASE}/index.php/apps/openregister/api/avg/verwerkingen/betrokkene`,
		)
		expect(res.status()).toBeGreaterThanOrEqual(400)
		expect(res.status()).toBeLessThan(500)
		expect(res.status()).not.toBe(404)
	})
})
