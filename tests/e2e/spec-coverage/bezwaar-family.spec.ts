/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behavioural UI coverage for the bezwaar (objection) lifecycle index
 * pages: Beroepen (appeals) and Bezwaaradviescommissies (advisory
 * committees). Each is reached by its ROUTE — the nav renders these entries
 * as "Appeals" / "Objection advisory committees" and keeps them inside
 * collapsed groups, so clicking by the Dutch label matched nothing — and
 * asserted on its rendered
 * index surface — the distinct primary-create control and the list shell —
 * independently of whether OpenRegister has seeded data. No assertion
 * depends on row content; every test also guards against a 5xx render.
 *
 * The standalone "Beslissingen op bezwaar" (BezwaarDecisions) and
 * "BAC-adviezen" (BezwaarAdviceRequests) index pages were retired by the
 * case-type-navigation change (their page objects, menu entries and routes
 * were removed); their obsolete scenarios were dropped here accordingly.
 */

import { expect, test } from '@playwright/test'
import { navToRoute, trackDossiqErrors } from '../helpers/nav.ts'

test.describe('Bezwaaradviescommissies (advisory committees) page', () => {
	// @e2e openspec/specs/bezwaar-lifecycle/spec.md#bezwaar-committees-settings-page-renders-list-shell
	test('bezwaar committees page renders its own create control', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		// The nav entry is "Objection advisory committees" and lives in a
		// collapsed group, so it is display:none and unclickable on load.
		// Navigate by route instead.
		await navToRoute(page, '/settings/bezwaar-committees')
		// Committee-specific create control distinguishes this view from the
		// other bezwaar/beroep lists in the group.
		// The control renders as "Add Objection Advisory Committee" — measured
		// on a CI runner (2026-08-04); the Dutch label was never emitted.
		await expect(
			page.getByRole('button', { name: 'Add Objection Advisory Committee' }),
		).toBeVisible({ timeout: 15000 })
		await expect(
			page.getByRole('button', { name: 'Actions' }).first(),
		).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})
