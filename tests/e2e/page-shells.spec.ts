/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Render-shell coverage for six manifest pages that no other spec drove.
 *
 * WHAT THESE ASSERT, AND WHY IT IS NOT A TAUTOLOGY
 * -----------------------------------------------
 * Each page below renders a heading that sits OUTSIDE every `v-if` in its own
 * template, so the assertion holds whether or not OpenRegister returns data —
 * the same data-independent contract `spec-coverage/ui-pages.spec.ts` asserts
 * for `/doorlooptijd`. What it proves is that the route resolves AND the
 * manifest renderer found the component AND the component mounted without
 * throwing.
 *
 * That is a real question with three distinguishable answers, measured on a
 * live instance (dossiq 0.3.9) before these tests were written:
 *
 *   route resolves, component registered   → the page's own heading
 *   route resolves, component NOT registered → the manifest renderer's
 *                                            "This page is empty" placeholder
 *   route does not resolve                 → falls back to the Dashboard
 *
 * The middle case is not hypothetical: `/public/consultations/:token` is in
 * exactly that state today (page `ExternalConsultationResponse` is declared in
 * `src/manifest.d/consultation-public.json`, its component is not in
 * `src/customComponents.js`), which is why it has no test here. A heading
 * assertion tells those three apart; `.app-content` being visible does not,
 * because it is visible in all three.
 *
 * The three public pages are token-addressed and deliberately use a token that
 * CANNOT resolve, so no fixture has to be seeded and the branch under test is
 * deterministic — see the constants' own docblocks in
 * `helpers/page-components.ts` for why each backend answers a uniform 404.
 *
 * Naming: the routes come from `helpers/page-components.ts`, whose identifiers
 * are the components' file stems, so the screen each test covers is stated in
 * executable code rather than in a comment (hydra gate-26 / .github#358).
 */

import { expect, test } from '@playwright/test'
import { loadAllAdminSections, navToRoute } from './helpers/nav.ts'
import {
	ProcessMiningDashboard,
	PublicAppointmentPage,
	PublicFederatedTransferPage,
	PublicStatusPage,
	TenantOnboardingAdminSettings,
	TermijnDashboard,
} from './helpers/page-components.ts'

/**
 * Drive one route and assert its own heading rendered.
 *
 * @param page    the Playwright page
 * @param route   app-relative route, from `helpers/page-components.ts`
 * @param heading the heading text this screen renders unconditionally
 */
async function expectPageShell(page, route: string, heading: string) {
	await navToRoute(page, route)
	await expect(
		page.getByRole('heading', { name: heading, level: 2 }).first(),
	).toBeVisible({ timeout: 15000 })
	// A rendered heading rules out the Dashboard fallback and the empty-page
	// placeholder; this rules out a shell that rendered around a 500.
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
}

test.describe('Dashboard page shells', () => {
	test('process mining dashboard renders its bottleneck-analysis heading', async ({
		page,
	}) => {
		await expectPageShell(page, ProcessMiningDashboard, 'Process mining')
	})

	// Tenant onboarding was retired as an app page by page-topology-cleanup (B3)
	// and is now a section inside the ADMIN settings surface — an absolute
	// Nextcloud path, not an app route, so navToRoute does not apply.
	test('tenant onboarding renders as an administration section', async ({
		page,
	}) => {
		await page.goto(`/index.php${TenantOnboardingAdminSettings}`)
		// AdminRoot mounts its sections lazily as the viewport reaches them, and
		// this one is 14th of 16 — well below the fold on first paint. Without
		// scrolling them in, the assertion fails for position rather than for
		// anything the test is about, which is what the other admin specs use
		// this helper to avoid.
		await loadAllAdminSections(page)
		await expect(
			page.getByRole('heading', { name: /Tenant.?onboarding/i }).first(),
		).toBeVisible()
	})

	test('termijn dashboard renders its deadline-monitoring heading', async ({
		page,
	}) => {
		await expectPageShell(page, TermijnDashboard, 'Deadline monitoring')
	})
})

test.describe('Public token-addressed page shells', () => {
	// This page performs no fetch on mount at all — its own header records that
	// no GET endpoint exists to pre-load transfer details — so the heading is
	// independent of every backend.
	test('federated transfer page renders the accept/reject surface', async ({
		page,
	}) => {
		await expectPageShell(
			page,
			PublicFederatedTransferPage,
			'Case transfer request',
		)
	})

	// An unresolvable token is answered with a uniform 404 by OpenRegister's
	// public case-token endpoint, so the error branch is the deterministic one.
	test('public case-status page renders its unresolvable-token state', async ({
		page,
	}) => {
		await expectPageShell(page, PublicStatusPage, 'Status unavailable')
	})

	test('public appointment page renders its unresolvable-token state', async ({
		page,
	}) => {
		await expectPageShell(page, PublicAppointmentPage, 'Appointment not found')
	})
})
