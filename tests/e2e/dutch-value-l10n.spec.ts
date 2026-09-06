/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Dutch→English value migration translated dossiq's enum values, and the
 * l10n files carry the Dutch word back so a Dutch-rendered UI still reads
 * correctly. Nothing tested that end to end, and the failure is quiet: a Dutch
 * user simply sees English.
 *
 * WHY E2E RATHER THAN A UNIT TEST ON THE JSON. Nextcloud loads
 * `l10n/<lang>.js` at RUNTIME; the l10n coverage checker reads
 * `l10n/<lang>.json`. Two different artefacts, and they had already drifted
 * here — `en.js` held 779 entries against 3246 in `en.json`. A test that reads
 * the JSON proves nothing about what the browser received.
 *
 * TWO TRAPS THIS SPEC IS BUILT AROUND, both hit while writing it:
 *
 *   1. `/apps/dossiq/l10n/nl.js` returns HTTP 200 — and serves the SPA SHELL.
 *      Nextcloud answers any app sub-path with the app's HTML. A `res.ok`
 *      check passes on that HTML. The real path comes from
 *      `OC.appswebroots`, which is how Nextcloud itself resolves it and which
 *      differs between `apps/` and `custom_apps/` installs.
 *   2. Scraping the bundle's text would not notice a bundle that fails to
 *      PARSE, which at runtime registers nothing. So it is evaluated, and what
 *      it registers is what gets asserted.
 *
 * @e2e exclude No canonical spec covers the vocabulary migration; this pins the
 *  l10n contract the migration depends on.
 */

import { expect, test } from '@playwright/test'

/**
 * English source string => the Dutch term a Dutch reader must still see.
 *
 * The Awb outcomes are three distinct results: `gegrond` (the challenge
 * succeeds), `ongegrond` (it fails on the merits) and `niet-ontvankelijk` (it
 * is not considered at all).
 */
const REQUIRED_TRANSLATIONS: Record<string, string> = {
	Upheld: 'Gegrond',
	Dismissed: 'Ongegrond',
	Inadmissible: 'Niet-ontvankelijk',
	'Partly upheld': 'Deels gegrond',
}

test.describe('Dutch value vocabulary survives in l10n', () => {
	test('the runtime nl bundle translates each migrated value back to Dutch', async ({
		page,
	}) => {
		await page.goto('/apps/dossiq/')
		await page.waitForFunction(
			() =>
				typeof (window as unknown as { OC?: { appswebroots?: unknown } }).OC
					?.appswebroots === 'object',
			null,
			{ timeout: 30000 },
		)

		const bundle = await page.evaluate(async () => {
			const oc = (
				window as unknown as {
					OC: { appswebroots: Record<string, string> }
				}
			).OC
			// Resolve the way Nextcloud does. `/apps/dossiq/l10n/nl.js` answers
			// 200 with the SPA shell on a custom_apps install.
			const root = oc.appswebroots.dossiq
			const res = await fetch(`${root}/l10n/nl.js`)
			return { root, ok: res.ok, body: res.ok ? await res.text() : '' }
		})

		expect(bundle.root, 'dossiq must be in OC.appswebroots').toBeTruthy()
		expect(bundle.ok, `fetching ${bundle.root}/l10n/nl.js failed`).toBeTruthy()

		// The shell guard: HTML is not a translation bundle, and it arrives with
		// a 200. Assert the artefact is what it claims to be before trusting it.
		expect(
			bundle.body.trimStart().startsWith('OC.L10N.register'),
			`${bundle.root}/l10n/nl.js did not return a translation bundle — `
				+ `first bytes: ${JSON.stringify(bundle.body.slice(0, 60))}`,
		).toBeTruthy()

		const registered = await page.evaluate((source: string) => {
			const captured: Record<string, string> = {}
			const w = window as unknown as {
				OC: { L10N: { register: unknown } }
			}
			const previous = w.OC.L10N.register
			w.OC.L10N.register = (_app: string, map: Record<string, string>) => {
				Object.assign(captured, map)
			}
			try {
				// eslint-disable-next-line no-eval
				eval(source)
			} finally {
				w.OC.L10N.register = previous
			}
			return captured
		}, bundle.body)

		expect(
			Object.keys(registered).length,
			'the nl.js bundle must parse and register its entries',
		).toBeGreaterThan(0)

		const wrong = Object.entries(REQUIRED_TRANSLATIONS)
			.filter(([english, dutch]) => registered[english] !== dutch)
			.map(
				([english, dutch]) =>
					`${english} => ${JSON.stringify(registered[english])} (expected ${JSON.stringify(dutch)})`,
			)

		expect(
			wrong,
			`Dutch readers would see the wrong word for:\n  ${wrong.join('\n  ')}`,
		).toEqual([])

		// The three Awb outcomes must stay three DISTINCT Dutch words. If a
		// future migration collapsed two of them and updated both sides of the
		// map together, the check above would still pass; this one would not.
		const outcomes = ['Upheld', 'Dismissed', 'Inadmissible'].map(
			(k) => registered[k],
		)
		expect(
			new Set(outcomes).size,
			`the three Awb outcomes must stay distinct in Dutch, got: ${outcomes.join(', ')}`,
		).toBe(3)
	})
})
