/*
 * SPDX-FileCopyrightText: 2026 DossiQ Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The work navigation group.
 *
 * "Work queue" is now "My work", and it gathers the five surfaces a handler
 * actually works from: the queue of cases nobody has picked up, the cases
 * assigned to them, every case, their tasks, and the workflow board. "Cases" is
 * no longer a separate top-level entry — it
 * lives in the group as "All cases". It was briefly "All issues"; dossiq talks
 * about cases everywhere else, and one surface calling them issues was the only
 * place the vocabulary broke.
 *
 * The page that used to be labelled "My work" is now "Assigned to me". Both
 * could not keep that name once the GROUP took it, and a sidebar reading
 * "My work > My work" says nothing about what the inner entry holds.
 *
 * Entries are asserted by PRESENCE, not visibility: they sit inside a
 * collapsible group and are rendered but hidden until it is expanded, so a
 * visibility assertion fails on a perfectly correct menu.
 */

import { expect, test } from '@playwright/test'

test.describe('Work navigation', () => {
	// The dossiq shell mounts a large manifest and queries OpenRegister on
	// load; the admin-settings spec in this suite documents the same
	// variability and sets an explicit budget rather than trusting a multiplier.
	test.setTimeout(300_000)

	// @e2e openspec/specs/my-work/spec.md
	test('the work group is named after the work, and holds all five surfaces', async ({
		page,
	}) => {
		await page.goto('/apps/dossiq/')

		const nav = page.locator('#app-navigation-vue, .app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 60_000 })

		for (const label of [
			/^\s*(My work|Mijn werk)\s*$/i,
			/^\s*(Queue|Werkvoorraad)\s*$/i,
			/^\s*(Assigned to me|Aan mij toegewezen)\s*$/i,
			/^\s*(All cases|Alle zaken)\s*$/i,
			/^\s*(Tasks|Taken)\s*$/i,
		]) {
			await expect(
				nav.getByText(label),
				`the navigation must offer ${label}`,
			).toHaveCount(1, { timeout: 30_000 })
		}
	})

	// @e2e openspec/specs/my-work/spec.md
	test('the retired labels are gone', async ({ page }) => {
		await page.goto('/apps/dossiq/')

		const nav = page.locator('#app-navigation-vue, .app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 60_000 })

		// A half-applied rename leaves the old label alongside the new one, and
		// only this assertion would catch it.
		await expect(
			nav.getByText(/^\s*(Work queue|Werkvoorraad)\s*$/i),
			'the old group label must not survive',
		).toHaveCount(0)
		await expect(
			nav.getByText(/^\s*(Cases|Zaken)\s*$/i),
			'"Cases" must not survive alongside "All cases"',
		).toHaveCount(0)
		await expect(
			nav.getByText(/^\s*(All issues)\s*$/i),
			'"All issues" must not survive alongside "All cases"',
		).toHaveCount(0)
	})

	// @e2e openspec/specs/my-work/spec.md
	test('the cases page stays reachable by direct link', async ({ page }) => {
		// Relabelling and relocating a menu entry must not move its ROUTE.
		// Bookmarks, shared links and the other specs in this suite all target
		// /cases, and none of them would notice until they broke.
		//
		// A PATH, not `#/cases`. dossiq runs on createWebHistory: a hash deep
		// link navigates NOWHERE and throws nothing, so this went to the app
		// root and rendered the DASHBOARD. `[data-testid="cn-page"]` is present
		// there too, so the test passed for years while proving nothing about
		// /cases. The heading assertion below is the other half of the fix: a
		// page-shell locator alone cannot tell these two pages apart.
		await page.goto('/index.php/apps/dossiq/cases')

		await expect(
			page.locator('[data-testid="cn-page"]'),
			'the cases page must still render for a deep link',
		).toBeVisible({ timeout: 60_000 })

		await expect(
			page.getByRole('heading', { name: /^(Cases|Zaken)$/i }).first(),
			'the deep link must land on the CASES page, not whatever the shell defaults to',
		).toBeVisible({ timeout: 30_000 })
	})
})
