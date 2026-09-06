/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Documentation screenshot capture suite — dossiq.
 *
 * This spec is *not* a regression test — it drives the Dossiq UI
 * through the flows documented under `docs/tutorials/{user,admin}/*.md`
 * and writes a fresh PNG into `docs/static/screenshots/tutorials/<track>/`
 * for each step the markdown references.
 *
 * Run manually whenever the UI changes and tutorial screenshots need
 * to be refreshed:
 *
 *     NEXTCLOUD_URL=http://localhost:8080 \
 *       npx playwright test --project docs-capture
 *
 * Excluded from the default `npm run test:e2e` run via the
 * `docs-capture` project flag in `playwright.config.ts` so PR pipelines
 * don't reshoot screenshots on every push.
 *
 * Authentication: `playwright.config.ts` wires `globalSetup` (a one-time
 * Nextcloud login → storage state) and `use.storageState`, so the
 * `page` fixture here arrives already signed in.
 *
 * Data dependency: Dossiq stores cases / tasks / bezwaren / decisions
 * in OpenRegister. On an instance with no seed data the list views
 * still render (empty state) and the *Add Item* dialog still opens, so
 * the structural screenshots below capture cleanly. The flow-detail
 * screenshots (a populated case detail, a status transition, a
 * recorded decision) need real objects; until seed data lands those
 * steps fall back to the relevant list/empty-state view, and the
 * markdown pages that reference the as-yet-uncaptured PNGs warn under
 * `onBrokenMarkdownImages: 'warn'` rather than failing the docs build.
 *
 * Dossiq routing nuance: the SPA shell mounts at `/apps/dossiq`
 * (no trailing slash). Hitting `/apps/dossiq/` returns a 404 page —
 * the wildcard catch-all does not match the literal trailing slash
 * in this NC version. `go()` below normalises trailing slashes.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const SHOT_ROOT = path.resolve(
	__dirname,
	'..',
	'..',
	'docs',
	'static',
	'screenshots',
	'tutorials',
)
const APP = '/apps/dossiq'

/**
 * Save a viewport screenshot under
 * `docs/static/screenshots/tutorials/<track>/<file>`.
 * Lives under `static/` so Docusaurus copies the PNG into the build
 * root — markdown image refs use `/screenshots/...` (root-absolute).
 */
async function shoot(
	page: Page,
	track: 'user' | 'admin',
	file: string,
): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({
		path: path.join(dir, file),
		fullPage: false,
		type: 'png',
	})
}

/**
 * Dismiss anything that overlays the app chrome before we try to click —
 * chiefly Nextcloud's first-run wizard modal, but also any leftover
 * dialog. Best-effort: silently no-op when nothing's there.
 */
async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		const close = wizard
			.getByRole('button', { name: /close|got it|finish|skip/i })
			.first()
		if (await close.isVisible().catch(() => false)) {
			await close.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await wizard.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {})
	}
	const stray = page.locator('[role="dialog"]:not(#firstrunwizard)')
	if (
		await stray
			.first()
			.isVisible()
			.catch(() => false)
	) {
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(300)
	}
}

/**
 * Navigate to a Dossiq sub-route (or an absolute /apps/... NC route).
 * The bare app root is `/apps/dossiq` with NO trailing slash — adding
 * one returns a NC 404. Sub-routes use a single leading slash and the
 * trailing slash is stripped before goto.
 */
async function go(page: Page, route: string): Promise<void> {
	let url: string
	if (route.startsWith('/apps/')) {
		url = route
	} else if (route === '' || route === '/') {
		url = APP
	} else {
		const tail = route.startsWith('/') ? route : `/${route}`
		url = `${APP}${tail}`.replace(/\/$/, '')
	}
	await page.goto(url, { waitUntil: 'domcontentloaded' }).catch(() => {
		/* tolerate a 404 — caller decides */
	})
	// The NC SPA keeps background XHR alive, so `networkidle` never settles
	// (ADR-074 rule 4). Wait on the actual content region instead — the main
	// app-content area rendering — then let any loading spinner clear.
	await page
		.locator('main, #app-content, .app-content, #content-vue')
		.first()
		.waitFor({ state: 'visible', timeout: 20_000 })
		.catch(() => {
			/* 404 pages have no app-content */
		})
	await page
		.locator(
			'.icon-loading, .loading, .material-design-icon.loading-icon, [class*="skeleton"]',
		)
		.first()
		.waitFor({ state: 'hidden', timeout: 8_000 })
		.catch(() => {
			/* no spinner present, or it never appeared */
		})
	await dismissOverlays(page)
	await page.waitForTimeout(900)
}

/**
 * Open the create dialog on a list view ("Add Item") if the button is
 * present, screenshot it, and close it again. Returns whether the
 * dialog appeared.
 */
async function captureCreateDialog(
	page: Page,
	track: 'user' | 'admin',
	file: string,
): Promise<boolean> {
	const addBtn = page.getByRole('button', { name: /Add Item/i }).first()
	if (!(await addBtn.isVisible().catch(() => false))) {
		return false
	}
	await addBtn.click().catch(() => {})
	const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
	await dialog.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {
		/* no dialog */
	})
	await page.waitForTimeout(400)
	await shoot(page, track, file)
	const cancel = dialog.getByRole('button', { name: /Cancel/i }).first()
	if (await cancel.isVisible().catch(() => false)) {
		await cancel.click().catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await page.waitForTimeout(300)
	return true
}

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
})

// ---------------------------------------------------------------------------
// USER TRACK — see docs/tutorials/user/
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('U1 first-launch', async ({ page }) => {
		// docs/tutorials/user/01-first-launch.md
		await go(page, '')
		await shoot(page, 'user', '01-first-launch-01.png')
		await shoot(page, 'user', '01-first-launch-02.png')
		await shoot(page, 'user', '01-first-launch-03.png')
		await go(page, '/cases')
		await shoot(page, 'user', '01-first-launch-04.png')
		expect(page.url()).toContain('/apps/dossiq')
	})

	test('U2 my-work', async ({ page }) => {
		// docs/tutorials/user/02-my-work.md
		await go(page, '/my-work')
		await shoot(page, 'user', '02-my-work-01.png')
		// Steps 2-4 use the tabs / filter / parafering panel that render
		// even on empty data — same viewport, captured for structural
		// reference.
		await shoot(page, 'user', '02-my-work-02.png')
		await shoot(page, 'user', '02-my-work-03.png')
		await shoot(page, 'user', '02-my-work-04.png')
	})

	test('U3 view-case', async ({ page }) => {
		// docs/tutorials/user/03-view-case.md — case detail needs a real
		// case object; the list view stands in for steps 1-2, the empty
		// list stands in for steps 3-4 until seed data lands.
		await go(page, '/cases')
		await shoot(page, 'user', '03-view-case-01.png')
		await shoot(page, 'user', '03-view-case-02.png')
		// TODO: capture case detail header / sidebar tabs once a case
		// object exists — for now reuse the list as a stand-in.
		await shoot(page, 'user', '03-view-case-03.png')
		await shoot(page, 'user', '03-view-case-04.png')
	})

	test('U4 advance-case', async ({ page }) => {
		// docs/tutorials/user/04-advance-case.md — needs a case at a
		// transition-eligible status.
		await go(page, '/cases')
		await shoot(page, 'user', '04-advance-case-01.png')
		// TODO: capture transition dialog, confirm step, generated task,
		// and history once a case object exists.
		await shoot(page, 'user', '04-advance-case-02.png')
		await shoot(page, 'user', '04-advance-case-03.png')
		await go(page, '/tasks')
		await shoot(page, 'user', '04-advance-case-04.png')
		await go(page, '/cases')
		await shoot(page, 'user', '04-advance-case-05.png')
	})

	test('U5 record-decision', async ({ page }) => {
		// docs/tutorials/user/05-record-decision.md — the standalone /advice
		// index was retired on 2026-09-02: decision-making moved to decidesk and
		// dossiq keeps the per-case view, so the case list stands in.
		await go(page, '/cases')
		await shoot(page, 'user', '05-record-decision-01.png')
		const hadAdvice = await captureCreateDialog(
			page,
			'user',
			'05-record-decision-02.png',
		)
		if (!hadAdvice) {
			await shoot(page, 'user', '05-record-decision-02.png')
		}
		await go(page, '/cases')
		await shoot(page, 'user', '05-record-decision-03.png')
		await shoot(page, 'user', '05-record-decision-04.png')
		await shoot(page, 'user', '05-record-decision-05.png')
	})

	test('U6 track-deadlines', async ({ page }) => {
		// docs/tutorials/user/06-track-deadlines.md — dashboard widgets.
		await go(page, '')
		await shoot(page, 'user', '06-track-deadlines-01.png')
		await shoot(page, 'user', '06-track-deadlines-02.png')
		// Step 3 (click into a case) needs data; the dashboard stands in.
		await shoot(page, 'user', '06-track-deadlines-03.png')
		// Step 4 — scroll for the lower widgets.
		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
		await page.waitForTimeout(300)
		await shoot(page, 'user', '06-track-deadlines-04.png')
	})

	test('U7 handle-objection', async ({ page }) => {
		// docs/tutorials/user/07-handle-objection.md — the standalone BAC
		// (/bezwaar-advice-requests), beslissing-op-bezwaar (/bezwaar-decisions)
		// and objections (/bezwaren) index pages are all retired now; objections
		// are cases, so the captures target the case list and its own tabs.
		await go(page, '/cases')
		await shoot(page, 'user', '07-handle-objection-01.png')
		await shoot(page, 'user', '07-handle-objection-02.png')
	})

	test('U8 inspection-checklist', async ({ page }) => {
		// docs/tutorials/user/08-inspection-checklist.md — LHS matrix
		// configuration + recommendations live under settings; cases need
		// an LHS-enabled type. Capture the settings + recommendations
		// surfaces as structural stand-ins until seed data lands.
		//
		// The matrix's own settings page is RETIRED: the LHS matrix is a
		// decision table, OpenRegister evaluates it, and authoring moved to the
		// Decision Tables (DMN) section of the ADMIN settings — which is a
		// Nextcloud core route, not an app route, so it cannot go through go().
		await page.goto('/index.php/settings/admin/dossiq', {
			waitUntil: 'domcontentloaded',
		})
		await shoot(page, 'user', '08-inspection-checklist-01.png')
		await shoot(page, 'user', '08-inspection-checklist-02.png')
		await shoot(page, 'user', '08-inspection-checklist-03.png')
		await shoot(page, 'user', '08-inspection-checklist-04.png')
		await go(page, '/settings/lhs-recommendations')
		await shoot(page, 'user', '08-inspection-checklist-05.png')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK — see docs/tutorials/admin/
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test('A1 configure-case-types', async ({ page }) => {
		// docs/tutorials/admin/01-configure-case-types.md
		await go(page, '/case-types')
		await shoot(page, 'admin', '01-configure-case-types-01.png')
		const had = await captureCreateDialog(
			page,
			'admin',
			'01-configure-case-types-02.png',
		)
		if (!had) {
			await shoot(page, 'admin', '01-configure-case-types-02.png')
		}
		await go(page, '/case-types')
		await shoot(page, 'admin', '01-configure-case-types-03.png')
		await shoot(page, 'admin', '01-configure-case-types-04.png')
		await shoot(page, 'admin', '01-configure-case-types-05.png')
	})

	// A2 automatic-actions was retired with the page it captured
	// (page-topology-cleanup C2). Automatic actions are OpenRegister flows now,
	// so docs/user-guide/admin/02-automatic-actions.md documents the migration
	// command and the flow editor instead — neither of which is a Dossiq screen,
	// and OpenRegister's own capture spec owns the Flows page.
	//
	// The five 02-automatic-actions-*.png screenshots this produced are stale in
	// the same way the old page was: they show a create dialog for a record that
	// nothing executed. The rewritten tutorial no longer references them.

	test('A3 admin-settings', async ({ page }) => {
		// docs/tutorials/admin/03-admin-settings.md — Dossiq's admin
		// surface lives at /index.php/settings/admin/dossiq (NC core
		// settings, not the in-app /settings route).
		await page.goto('/index.php/settings/admin/dossiq', {
			waitUntil: 'domcontentloaded',
		})
		// networkidle never settles on Nextcloud (ADR-074 rule 4) — wait on
		// the actual admin settings section instead.
		await page
			.locator('#content, main, .section')
			.first()
			.waitFor({ state: 'visible', timeout: 20_000 })
			.catch(() => {})
		await dismissOverlays(page)
		await page.waitForTimeout(900)
		await page.evaluate(() => window.scrollTo(0, 0))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-01.png')
		const reimport = page
			.getByRole('button', { name: /Re-import configuration/i })
			.first()
		if (await reimport.isVisible().catch(() => false)) {
			await reimport.scrollIntoViewIfNeeded().catch(() => {})
			await page.waitForTimeout(300)
		}
		await shoot(page, 'admin', '03-admin-settings-02.png')
		const config = page.getByText(/Register and schema settings/i).first()
		if (await config.isVisible().catch(() => false)) {
			await config.scrollIntoViewIfNeeded().catch(() => {})
			await page.waitForTimeout(300)
		}
		await shoot(page, 'admin', '03-admin-settings-03.png')
		const zgw = page.getByText(/ZGW API Mapping/i).first()
		if (await zgw.isVisible().catch(() => false)) {
			await zgw.scrollIntoViewIfNeeded().catch(() => {})
			await page.waitForTimeout(300)
		}
		await shoot(page, 'admin', '03-admin-settings-04.png')
		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-05.png')
		expect(page.url()).toContain('/settings/admin/dossiq')
	})
})
