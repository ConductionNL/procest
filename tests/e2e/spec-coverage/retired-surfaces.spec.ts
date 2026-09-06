/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Coverage for surfaces `page-topology-cleanup` RETIRED, and for what replaced
 * them.
 *
 * A retirement is the one change nothing else in the suite can catch. Every
 * other spec asserts that something renders; delete a page and those specs stay
 * green by simply not running. So the risk is the opposite of the usual one —
 * not "the page broke", but "the page is still there, or the thing that was
 * supposed to replace it never arrived and nobody noticed".
 *
 * These tests therefore assert BOTH halves: the old surface no longer renders
 * its own view, and the replacement is reachable. Asserting only the first half
 * would pass just as happily on a build where the capability vanished entirely.
 */

import { expect, test } from '@playwright/test'
import { navToRoute } from '../helpers/nav.ts'

// NO trackDossiqErrors HERE, deliberately.
//
// A retired route falls through to the app root, so a console-error assertion
// on it grades the DASHBOARD's network traffic, not the retirement — and the
// dashboard legitimately 404s on every case/task fetch against an instance
// whose register is not seeded. That made the first version of this spec fail
// for a reason that had nothing to do with what it was testing. The specs that
// own the dashboard assert its console cleanliness; these assert that a view is
// gone and its replacement is present.

test.describe('Retired: automatic-actions settings page (C2)', () => {
	// The `automaticAction` objects this page administered were never executed
	// by anything — SideEffectDispatcher runs a separate vocabulary keyed on an
	// inline type. They migrate to OpenRegister flows via
	// `occ dossiq:actions:migrate-to-flows`.
	//
	// @e2e openspec/changes/page-topology-cleanup/proposal.md
	test('the retired route no longer renders an automatic-actions view', async ({
		page,
	}) => {
		await navToRoute(page, '/settings/automatic-actions')

		// The create control the page used to own. Asserting on THIS rather than
		// on a heading is deliberate: a heading can be absent because a page is
		// still loading, but the create control only exists when the retired
		// index view is actually mounted.
		await expect(
			page.getByRole('button', { name: 'Add Automatic Action' }),
		).toHaveCount(0)
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	test('flows are authored in this app, not behind a deep link', async ({
		page,
	}) => {
		await navToRoute(page, '/')

		// UPDATED BY ADR-110. This test used to assert the opposite — that the
		// settings menu carried `a[href="/apps/openregister/#/flows"]`, a link
		// out to another app's list. That deep link is gone: a flow is
		// app-specific (a dossiq flow operates on cases), so the authoring
		// surface is now `/flows` and `/flows/:id` in this app, on the shared
		// canvas over the same single engine (ADR-065).
		//
		// Asserting BOTH halves on purpose. The absence check alone would pass
		// just as happily on a build where the capability vanished entirely,
		// which is exactly what ADR-044 Decision 5 forbids.
		await expect(
			page.locator('a[href="/apps/openregister/#/flows"]'),
		).toHaveCount(0)

		await expect(page.locator('a[href$="/apps/dossiq/flows"]')).toHaveCount(1)
	})
})

test.describe('Retired: besluitvorming agenda pages (D1)', () => {
	// decidiq owns agenda-building and meetings, and surfaces them on a case
	// through the `decidesk-decisions` integration leaf.
	//
	// @e2e openspec/changes/page-topology-cleanup/proposal.md
	test('the agenda compiler route no longer renders its view', async ({
		page,
	}) => {
		await navToRoute(page, '/besluitvorming/agenda')

		// The compiler's own control. A "no error" assertion alone would pass on
		// a build where the page still renders perfectly well.
		await expect(
			page.getByRole('heading', { name: /Agenda ?compiler|Agendacompiler/i }),
		).toHaveCount(0)
		// What DOES happen: the router falls through to the app root.
		await expect(page).toHaveURL(/\/apps\/dossiq\/?$/)
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	test('the vergadering detail route no longer renders its view', async ({
		page,
	}) => {
		await navToRoute(page, '/besluitvorming/vergaderingen/does-not-exist')

		await expect(page).toHaveURL(/\/apps\/dossiq\/?$/)
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	test('decidiq registers the decisions leaf that replaces them', async ({
		page,
	}) => {
		await navToRoute(page, '/')

		// The half that matters. Without it, "the agenda pages are gone" is
		// indistinguishable from "besluitvorming was deleted": the leaf is what
		// carries the capability now, and it is registered by decidiq's init
		// script on every page, not by dossiq.
		// THREE OUTCOMES, not two. The previous version returned `null` both when
		// the registry was absent and when the leaf was missing from it, then
		// reported every null as "registry not present" — so a real missing leaf
		// would have been described as an environment problem, and an
		// environment problem (this repo's CI does not install the decision app)
		// was reported as a missing leaf. A lookup failure must not wear the same
		// words as a judgement.
		const probe = await page.evaluate(() => {
			const registry = (
				window as unknown as {
					OCA?: {
						OpenRegister?: { integrations?: { list?: () => unknown[] } }
					}
				}
			).OCA?.OpenRegister?.integrations
			if (!registry?.list) {
				return { registry: false as const }
			}

			const entries = registry.list() as Array<{ id?: string; tab?: unknown }>
			const found = entries.find((entry) => entry.id === 'decidesk-decisions')
			return {
				registry: true as const,
				ids: entries.map((entry) => entry.id).filter(Boolean),
				leaf: found
					? { id: found.id, hasTab: found.tab !== undefined }
					: null,
			}
		})

		// No registry at all = the decision app is not installed on this
		// instance. That is an environment fact, not a defect in this repo, and
		// dossiq's CI does not install it. Skip with the reason stated, rather
		// than failing red on something this PR cannot affect.
		test.skip(
			probe.registry === false,
			'OpenRegister integration registry absent — the decision app is not installed on this instance',
		)

		// Registry present: now a missing leaf IS a real finding, and the message
		// can name what was actually registered instead of guessing.
		expect(
			probe.leaf,
			`decidesk-decisions leaf not registered; registry holds: ${JSON.stringify(probe.ids)}`,
		).not.toBeNull()
		expect(probe.leaf).toEqual({ id: 'decidesk-decisions', hasTab: true })
	})
})
