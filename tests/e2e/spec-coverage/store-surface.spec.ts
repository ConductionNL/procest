/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the store surface (ADR-080).
 *
 * The scenario worth driving a browser for is the OFFLINE one, and it is the
 * one the ADR turns on: with no registry configured the page must render
 * dossiq's own templates and make NO outbound request. "Renders something"
 * is not the assertion — a page that quietly called a registry and fell back
 * on the error would look identical. So the network is watched, and the
 * absence of the call is asserted directly.
 *
 * The install allowlist is a server-side boundary and is proven by
 * StoreControllerTest, including a negative control that widens the list and
 * watches the refusal tests fail. Those scenarios carry `@e2e exclude` in the
 * spec: a browser cannot see which schema a write went to.
 */

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, navTo } from '../helpers/nav.ts'

test.describe('Store surface', () => {
	// The dossiq shell mounts a large manifest and queries OpenRegister on
	// load; the neighbouring specs set the same explicit budget.
	test.setTimeout(300_000)

	// @e2e openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md
	test('the store entry sits in the footer between Documentation and Reports', async ({
		page,
	}) => {
		await page.goto('/apps/dossiq/')

		const nav = page.locator('#app-navigation-vue, .app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 60_000 })

		await expect(
			nav.getByText(/^\s*Store\s*$/i),
			'the navigation must offer a Store entry',
		).toHaveCount(1, { timeout: 30_000 })

		// Order, not merely presence. The entry was placed at order 92
		// deliberately, and an entry that exists in the wrong place is the
		// defect a presence-only assertion cannot see.
		const labels = await nav
			.locator('a, li')
			.allInnerTexts()
			.then((texts) => texts.map((entry) => entry.trim()))

		const documentation = labels.findIndex((label) =>
			/^Documentation$/i.test(label),
		)
		const store = labels.findIndex((label) => /^Store$/i.test(label))
		const reports = labels.findIndex((label) => /^Reports$/i.test(label))

		expect(documentation, 'Documentation must be in the footer').toBeGreaterThan(
			-1,
		)
		expect(store, 'Store must be in the footer').toBeGreaterThan(-1)
		expect(reports, 'Reports must be in the footer').toBeGreaterThan(-1)
		expect(store, 'Store must follow Documentation').toBeGreaterThan(
			documentation,
		)
		expect(store, 'Store must precede Reports').toBeLessThan(reports)
	})

	// @e2e openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md
	test('an unconfigured instance renders the built-in templates and calls no registry', async ({
		page,
	}, testInfo) => {
		// The comparison host comes from the CONFIGURED base URL, not from
		// page.url(). Requests fire while the page is still about:blank, whose
		// host is the empty string, so comparing against the live URL counts
		// every same-origin asset as external and the assertion fails for a
		// reason that has nothing to do with the store.
		const baseHost = new URL(String(testInfo.project.use.baseURL)).host

		// Watch every request the page makes BEFORE navigating, so a call made
		// during load is caught rather than missed.
		const external: string[] = []
		page.on('request', (request) => {
			const url = request.url()
			// Same-origin traffic is the app itself. What must not happen is a
			// call to somebody else's registry.
			if (url.startsWith('http') === true && new URL(url).host !== baseHost) {
				external.push(url)
			}
		})

		await navTo(page, /^Store$/)
		await dismissSupportDialog(page).catch(() => {})

		await expect(
			page.locator('[data-testid="store-page"]'),
			'the store page must render',
		).toBeVisible({ timeout: 60_000 })

		await expect(
			page.locator('[data-testid="store-not-configured"]'),
			'an instance with no registry must say so rather than showing an error',
		).toBeVisible({ timeout: 60_000 })

		await expect(
			page.locator('[data-testid="store-builtin"]'),
			'the built-in templates are the fallback surface',
		).toBeVisible({ timeout: 30_000 })

		expect(
			external,
			'an unconfigured store must make NO outbound request',
		).toEqual([])
	})

	// @e2e openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md
	test('the store page is reachable by direct link', async ({ page }) => {
		// Relabelling or moving the menu entry must not move the ROUTE.
		//
		// A PATH, not `#/store`. dossiq runs on createWebHistory, where a hash
		// deep link navigates NOWHERE and throws nothing: the shell renders the
		// Dashboard and an assertion on "some page rendered" would pass against
		// the wrong page entirely. The sidebar link's own href is this path.
		await page.goto('/index.php/apps/dossiq/store')

		await expect(
			page.locator('[data-testid="store-page"]'),
			'the store page must render for a deep link',
		).toBeVisible({ timeout: 60_000 })
	})
})
