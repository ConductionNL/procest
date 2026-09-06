import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baselines for Dossiq's key surfaces (GAP-5).
 *
 * Run:    npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat.
 *
 * NOTE: dossiq serves its SPA at /apps/dossiq/index (the bare
 * /apps/dossiq/ route 404s), so navigation targets the /index entrypoint.
 */
import { request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	objectId,
	purgeObject,
	seedCase,
} from '../helpers/fixtures.ts'
import { shootByNav, shootSurface } from './_visual-helpers.ts'

const APP = '/index.php/apps/dossiq/index'

test.describe('Dossiq — visual baselines', () => {
	test('dashboard', async ({ page }) => {
		await shootSurface(page, `${APP}#/`, 'dashboard.png')
	})

	test('cases list', async ({ page }) => {
		// The label is 'All cases', NOT 'Cases'. dossiq#1646 renamed this entry
		// and shootByNav resolves the label behind `if (isVisible)`, so a stale
		// label does not fail: it silently skips the click and shoots the
		// DASHBOARD under the name cases.png. The baseline was that dashboard.
		await shootByNav(page, `${APP}#/`, 'All cases', 'cases.png')
	})

	// Baselines src/views/store/StoreGallery.vue, the manifest's `Store` page.
	test('store (StoreGallery)', async ({ page }) => {
		// Shot with no registry configured, which is the state a fresh install
		// is in: the not-configured note plus the built-in templates.
		// A PATH: dossiq is on createWebHistory, so `#/store` would shoot the
		// Dashboard under this baseline's name.
		await shootSurface(page, '/index.php/apps/dossiq/store', 'StoreGallery.png')
	})

	// The "verwerkingen overview (AVG)" baseline was retired with the page it
	// shot: page-topology-cleanup (C1) moved the processing-activity register to
	// OpenRegister per ADR-047, so the screenshot belongs to OR's /avg surface,
	// not here.
})

/*
 * New-UI surface (this week): the CaseDetail page is where the deelzaak
 * (Sub-cases) + case-email (Email) tabs attach. Baseline the detail shell on a
 * seeded case. Dynamic regions (ids, timestamps, owner) are masked by the
 * shared visual helper, so the shot is deterministic across runs even though
 * the seeded case's uuid differs each time.
 *
 * FLAG: in the deployed @conduction/nextcloud-vue (beta.108) the CaseDetail
 * main panel renders empty in the fixed visual viewport (the same slot-render
 * gap that hides the Sub-cases/Email tabs — see deelzaak-case-email.spec.ts).
 * The baseline therefore captures the detail SHELL (nav + content host) and is
 * kept `fixme` until the lib renders the detail body, so a misleading blank
 * baseline is never committed. The e2e proves the underlying data round-trip.
 */
test.describe('Dossiq — case detail (deelzaak/email host) visual', () => {
	let api: APIRequestContext
	let token: string
	let caseId: string
	let caseTypeId: string

	test.beforeAll(async ({ baseURL }) => {
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		const ct = await ensureCaseType(api, token)
		caseTypeId = ct.id
		const kase = await seedCase(api, token, {
			title: 'VISUAL-BASELINE Case detail',
			caseType: caseTypeId,
			identifier: 'VISUAL-BASELINE-DETAIL',
			description: 'Stable case for the CaseDetail visual baseline.',
		})
		caseId = objectId(kase)
	})

	test.afterAll(async () => {
		// The baseline case is named for the SCREENSHOT, not for the run, so no
		// prefix sweep finds it — purge it by the id we kept. The prefix sweep
		// still runs for anything else the fixtures produced.
		await purgeObject(api, token, 'case', caseId)
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	test.fixme('case detail', async ({ page }) => {
		// History-mode detail route (verified live): /apps/dossiq/cases/:id.
		await shootSurface(
			page,
			`/index.php/apps/dossiq/cases/${caseId}`,
			'case-detail.png',
		)
	})
})
