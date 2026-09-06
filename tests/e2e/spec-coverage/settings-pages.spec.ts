/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behavioural UI coverage for the dossiq administrative settings pages.
 * Each renders an OpenRegister-backed index with a view-specific primary
 * create control. Every test navigates to the page's route and asserts that
 * it renders its OWN distinct create control — proving the route resolves to
 * the right view, not a stale one — while guarding against a 5xx render and
 * dossiq-origin console errors.
 *
 * See the note above SETTINGS_PAGES for why these navigate by route rather
 * than by clicking a nav label.
 */

import { expect, test } from '@playwright/test'
import { navToRoute, trackDossiqErrors } from '../helpers/nav.ts'

// name (for the test title), the ROUTE the settings page lives at, and the
// view-specific create control it must render.
//
// WHY THESE NAVIGATE BY ROUTE, NOT BY NAV LABEL
// ---------------------------------------------
// These tests used to expand the collapsible "Settings" nav group and click
// the entry by its rendered label. Every one of them failed on CI because the
// labels they clicked are Dutch/legacy strings the app no longer renders — the
// settings menu was translated to English ("Parafeerroutes" is now "Approval
// routes", "Kaartlagen" is "Map layers", "Tenants" is "Organisations",
// "Automatische acties" is "Automatic actions", "Handhavingsstrategie" is
// "Enforcement strategy"). Because those leaves also sit inside a COLLAPSED
// group they are `display:none`, so the click blocked on actionability rather
// than failing on a missing label, and the whole 60s test budget was consumed
// before a bare timeout named an element instead of the cause.
//
// Routes are the stable contract here — they are declared in src/manifest.json
// and are what the nav entries themselves link to — so navigating to them
// directly tests the same view without coupling every assertion to the current
// translation of a menu string. Each page's OWN create control still proves
// the route resolved to the right view rather than a stale one.
//
// Control labels below were measured against a CI runner (2026-08-04).
const SETTINGS_PAGES: Array<{ name: string; route: string; addBtn: string }> = [
	// The in-app /settings page was retired by page-topology-cleanup (B1): it
	// mounted the SAME AdminRoot.vue as /settings/admin/procest, reaching an
	// administration component through the in-app router and bypassing the
	// settings framework's server-side checks (ADR-004). Administration is
	// covered by the admin-settings surface, not by an app route.
	// Leges (the municipal-fee engine — verordeningen, articles, calculations)
	// was retired from Dossiq in Wave 1 of the case-model consolidation
	// (ADR-003). Fees are now Pipelinq products referenced from a case type's
	// productsOrServices; Dossiq owns no fee settings entries.
	// Approval routes lost their settings ENTRY in dossiq#1632, which enabled
	// the projected routes: an approval route is a flow now, and the route
	// object is what a flow was generated FROM rather than what drives
	// parafering. Editing one no longer reaches the running flow unless
	// somebody re-runs the projection, which would overwrite whatever was
	// authored on the canvas — so the design screen left the menu.
	//
	// The pages stay ROUTABLE, deliberately: a reader can still open a legacy
	// route to see what a flow came from. What this table asserts, though, is
	// that a settings page is REACHABLE AND OFFERS ITS CREATE CONTROL, and
	// creating a route is exactly the thing that no longer does anything on
	// its own. Flows are authored at /flows, covered by flows.spec.ts.

	// Automatic actions was retired by page-topology-cleanup (C2). The
	// `automaticAction` objects it administered were never executed by anything
	// — SideEffectDispatcher runs a separate vocabulary — so the page was a
	// surface over a capability with no runtime. They migrate to OpenRegister
	// flows via `occ dossiq:actions:migrate-to-flows`.
	//
	// The deep link that replaced it is gone too (ADR-110): flows are authored
	// in THIS app now, at /flows and /flows/:id, on the shared canvas over the
	// same single engine. That surface has its own spec — flows.spec.ts — rather
	// than an entry here, because its create control is a canvas action, not the
	// "Add X" button every row in this table asserts on.
	// 'Enforcement strategy' (/settings/lhs-matrices) is RETIRED. The LHS
	// matrix is a three-axis lookup yielding one value, which is a decision
	// table, and OpenRegister already carries one evaluator for those.
	// LhsRecommendationService now evaluates the projected table and falls back
	// to the matrix only where no projection exists; authoring lives in the
	// Decision Tables (DMN) admin tab.
	{
		name: 'LHS recommendations',
		route: '/settings/lhs-recommendations',
		addBtn: 'Add LHS Recommendation',
	},
	// 'Partner organisations' (/settings/partners) is RETIRED. A ketenpartner
	// is an organisation, and OpenRegister owns Organisation, so dossiq no
	// longer keeps a private second answer to "which organisations does this
	// system know about". Rows moved by `occ dossiq:migrate-partners`,
	// preserving each partner's uuid so existing case shares keep resolving.
	{
		name: 'Map layers',
		route: '/settings/wms-layers',
		addBtn: 'Add WMS/WFS Layer',
	},
	// 'Workflow definitions' (/settings/workflow-definitions) lost its settings
	// ENTRY, for the same reason Approval routes did above: a workflow is a flow
	// now, and the definition is what a flow was generated FROM. Two adjacent
	// settings entries at orders 96 and 97, called Flows and Workflow
	// definitions, wearing Sitemap and SitemapOutline, read as one feature
	// listed twice — and editing the definition does not reach the running
	// flow unless somebody re-runs the projection, which would overwrite
	// whatever was authored on the canvas.
	//
	// The page stays ROUTABLE so a reader can still open a legacy link and see
	// what a flow came from. Flows are authored at /flows, covered by
	// flows.spec.ts, whose create control is a canvas action rather than the
	// "Add X" button every row in this table asserts on.
	{ name: 'Organisations', route: '/settings/tenants', addBtn: 'Add Tenant' },
	// The standalone "Status history" (StatusRecords) settings page was retired
	// by retire-status-history-page — change history is now the CaseDetail
	// audit-trail surface, not a page/menu item. Entry removed accordingly.
	// The standalone "Case locations" page was retired by page-topology-cleanup
	// (B5) — `case` is a required property on the `location` schema, so every
	// location is reachable through its case via the /cases map view and the
	// case-detail widget. No page to exercise.
]

for (const { name, route, addBtn } of SETTINGS_PAGES) {
	test.describe(`Settings · ${name}`, () => {
		// @e2e openspec/specs/admin-settings/spec.md#settings-page-renders-distinct-control
		test(`${name} settings page renders its own "${addBtn}" control`, async ({
			page,
		}) => {
			const errors = trackDossiqErrors(page)
			await navToRoute(page, route)
			await expect(
				page.getByRole('button', { name: addBtn, exact: true }).first(),
			).toBeVisible({ timeout: 15000 })
			await expect(page.locator('body')).not.toContainText(
				'Internal Server Error',
			)
			expect(errors, errors.join('\n')).toEqual([])
		})
	})
}
