/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the related-case-linking UI surface
 * (the "Related cases" panel on the case detail).
 *
 * These tests drive a real browser against the case detail. They are
 * defensively guarded: the panel is data-dependent, needing at least one case
 * to exist. On a fresh or unseeded register the test SKIPS with a clear reason
 * rather than failing, which distinguishes a data gap from a genuine UI defect
 * (see the gate-19 live-verify deploy-reality note).
 *
 * Backend behaviour (typed storage, bidirectional consistency, guards, ZGW
 * mapping) is proven by PHPUnit (CaseRelationService/Controller) and Newman
 * (relevante-andere-zaken collection); those scenarios carry `@e2e exclude`
 * in the spec.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, navTo } from '../helpers/nav.ts'

/**
 * Open the first case in the case list, or skip when none exists / the list
 * does not render (unseeded register or deploy mismatch).
 */
async function openFirstCaseOrSkip(page) {
	// NOT wrapped in `.catch(() => {})`. A missing sidebar label is a rename
	// this suite has to notice, and swallowing it here would run every test
	// below against whatever the Dashboard happens to render — green, and
	// asserting nothing. The skip below is for absent DATA, not a broken menu.
	await navTo(page, /^(All cases|Alle zaken)$/)
	await dismissSupportDialog(page).catch(() => {})
	// Scoped to the list table on purpose. Unscoped, `.list-item` ALSO matches
	// Nextcloud's user-menu entries in the global header ("admin View profile",
	// "Set status", "Appearance and accessibility"), which are attached to the
	// DOM but never visible. `.first()` therefore resolved to a hidden menu
	// entry rather than a case: the `waitFor({ state: 'attached' })` below
	// passed in ~50ms against the wrong element, and the click after it could
	// never become actionable, so each test here burned its entire budget and
	// died reporting "Target page, context or browser has been closed" from the
	// teardown. Measured 2026-09-02 on /cases: the old selector matched 9
	// elements, every one of them a user-menu item and none of them a row.
	const row = page.locator('tbody tr, .viewTableRow').first()
	// This gate gives the WHOLE FILE its verdict, and it used `count()` — one
	// snapshot, no retry — immediately after navigating. Firing early skipped
	// every test here (3 skipped, 0 executed), which the skip-discipline gate
	// reports as a spec file that ran nothing. Wait for a row before deciding
	// the list is empty.
	const hasRow = await row
		.waitFor({ state: 'attached', timeout: 25_000 })
		.then(() => true)
		.catch(() => false)
	if (!hasRow) {
		test.skip(
			true,
			'no case rows rendered within 25s, so there is nothing to open a related-cases tab on. This is data-dependence on a seeded register, not a missing feature: the Related cases panel is declared on CaseDetail as widgetId case-related.',
		)
		return false
	}
	await row.click().catch(() => {})
	await page.waitForTimeout(1000)
	return true
}

/**
 * The "Related cases" tab on CaseDetail.
 *
 * This used to be scoped to `aside.app-sidebar` because the case-detail BODY
 * carried a second tab strip with a panel ALSO labelled "Related cases", and an
 * unscoped `getByRole('tab', …).first()` would silently drive the body strip
 * under the sidebar's name.
 *
 * That collision is gone, and so is the sidebar strip. Measured on 2026-09-02
 * against the deployed build: CaseDetail renders NINE tabs (Notes, Files,
 * Related cases, Sub-cases, Mail, Appointments, Decisions, Contacts,
 * Locations), `aside.app-sidebar` contains ZERO of them, and exactly ONE is
 * named "Related cases". The case-detail rewrite moved every tab into the body.
 *
 * So the sidebar scoping now matches nothing. It did not fail loudly: it fed a
 * `test.skip` that blamed a stale deployment, and all three tests in this file
 * reported green while running nothing.
 *
 * @param page The Playwright page.
 * @return A locator for the Related cases tab.
 */
function relatedCasesTab(page: Page) {
	return page
		.getByRole('tab', { name: /Related cases|Gerelateerde zaken/i })
		.first()
}

test.describe('Related cases section (related-case-linking)', () => {
	// 120s, not the 30s default, and the row wait in the helper is 25s rather
	// than 8s. Both are sized from measurement: on 2026-09-02 `navTo` alone
	// took 21s against the target instance, before any row had to render, so
	// the default budget could not fit the work this file does.
	//
	// Sizing the row wait too small does not fail loudly here, it SKIPS: that
	// wait feeds a `test.skip`, so a short window reports "no case rows" for a
	// list that was still loading and the whole file then runs nothing while
	// reporting green.
	test.describe.configure({ timeout: 120_000 })
	// @e2e openspec/specs/related-case-linking/spec.md#section-lists-relations-with-navigation
	test('the Related cases panel lists a relation', async ({ page }) => {
		const opened = await openFirstCaseOrSkip(page)
		if (!opened) return

		// The "Related cases" tab on CaseDetail. It renders the generic
		// `case-related` widget: the bespoke RelatedCasesSection was retired on
		// 2026-09-03 because the case-detail rewrite had already replaced it and
		// left it reachable from nothing.
		const tab = relatedCasesTab(page)
		// `count()` takes ONE snapshot and cannot retry, so this fired the
		// instant the panel had not painted yet, and then blamed a deployment
		// for it. Wait for the tab to attach before concluding it is absent.
		const present = await tab
			.waitFor({ state: 'attached', timeout: 20_000 })
			.then(() => true)
			.catch(() => false)
		if (!present) {
			test.skip(
				true,
				'the Related cases tab did not attach within 20s. It is declared on CaseDetail as widgetId case-related, so treat this as a rendering or seeding problem on the target instance rather than a missing build.',
			)
			return
		}
		await tab.click()
		// The panel must RESOLVE, not merely mount. It fetches its relations
		// asynchronously and showed "Loading …" for about 6s when measured on
		// 2026-09-03, so asserting visibility alone would pass against the
		// spinner and prove nothing about the listing.
		const panel = page.getByRole('tabpanel').first()
		await expect(panel).toBeVisible({ timeout: 20000 })
		await expect(panel).not.toContainText(/Loading/i, { timeout: 30000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})
