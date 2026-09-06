/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has already produced three
 * defects of exactly that shape: an icon name that is not registered renders
 * NO glyph (no fallback, no console error, and four apps shipped it), an entry
 * whose `route` names a page the app does not host renders a row that goes
 * nowhere, and `nav.includePersonalSettings: false` silently removed the entry
 * reaching the user's notification preferences in two apps.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 */

import { expect, test } from '@playwright/test'

const APP_BASE = '/index.php/apps/dossiq'

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
	})

	test('the footer reads Documentation, Store, Reports, Features & roadmap, each with a glyph', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		// ORDER is the rule, not the numbers, and not the entry ids either:
		// this app reaches its reports through an entry called AnalyticsGroup
		// LABELLED "Reports", which is compliant — ADR-114 constrains the label
		// and the position, not the id.
		const seen = texts.filter((t) =>
			/Documentation|Store|Reports|roadmap/i.test(t),
		)
		expect(seen.length).toBe(4)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Store/i)
		expect(seen[2]).toMatch(/Reports/i)
		expect(seen[3]).toMatch(/roadmap/i)

		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('Reports carries the three reports as cards', async ({ page }) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await footer
			.getByRole('link', { name: /^Reports$/ })
			.first()
			.click()
		await expect(page).toHaveURL(/\/apps\/dossiq\/reports(\?|$)/, {
			timeout: 15_000,
		})

		// Named individually rather than counted: a count assertion reds on
		// ADDING a report, passes on a swap, and never names what went missing.
		for (const label of [
			'Processing time',
			'Deadline monitoring',
			'Process mining',
		]) {
			await expect(page.getByText(label, { exact: true }).first()).toBeVisible(
				{ timeout: 15_000 },
			)
		}
	})

	test('the report pages behind the cards are still routable', async ({
		page,
	}) => {
		test.slow()

		// Process mining had NO menu entry before it was carded — it was a
		// standalone route reachable only if you already knew the URL. Carding
		// it gave it an entry point; this proves the route still answers.
		for (const path of ['/process-mining', '/termijn-dashboard']) {
			// 🔴 `domcontentloaded`, NOT the default `load`. Nextcloud's
			// notification poll keeps the network busy, so waiting for the load
			// event waits for something that does not settle — the loop dies
			// partway through and names whichever route it was on, which reads
			// as a broken route. The SPA mounts after DOM ready, and the
			// assertions below are what prove the mount.
			await page.goto(`${APP_BASE}${path}`, {
				waitUntil: 'domcontentloaded',
			})
			await expect(page).toHaveURL(new RegExp(`${path}(\\?|$)`), {
				timeout: 15_000,
			})
			await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
		}
	})

	test('the settings foldout carries Personal settings, Admin settings and Flows', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached()
		await expect(
			nav.locator('[data-testid="cn-nav-entry-FlowsMenu"]'),
		).toBeAttached()

		// ⚠️ The testid is on the <li> WRAPPER, not the <a>. Asserting href on
		// the wrapper reads back null and fails against a real browser, which
		// is invisible to `playwright test --list`.
		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]')
		await expect(admin).toBeAttached()
		await expect(admin.locator('a').first()).toHaveAttribute(
			'href',
			/\/settings\/admin\/dossiq$/,
		)
	})

	test('Case types and Map layers stay in the settings foldout', async ({
		page,
	}) => {
		// Both configure how cases behave rather than reporting on them, so
		// ADR-114 keeps them out of the four-item footer. Asserted directly so
		// a later promotion fails here rather than in the footer-order test,
		// where the cause would be much harder to read.
		const nav = page.locator('[data-testid="cn-nav"]')
		for (const id of ['CaseTypesMenu', 'WmsLayersMenu']) {
			await expect(
				nav.locator(`[data-testid="cn-nav-entry-${id}"]`),
			).toBeAttached({ timeout: 15_000 })
		}
	})
})
