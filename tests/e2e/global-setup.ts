/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright globalSetup — logs into Nextcloud once and persists the
 * resulting cookie jar / localStorage to `tests/e2e/.auth/user.json`.
 * Every spec then reuses that storage state via the `use.storageState`
 * setting in playwright.config.ts, so individual tests start from an
 * authenticated session without each one paying the login cost.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/), mirrored
 * from the canonical journeydoc template in hydra/templates/journeydoc/.
 */

import type { FullConfig } from '@playwright/test'

import { chromium, request } from '@playwright/test'
import { execSync } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'
import { BASE_URL } from './base-url.ts'
import { STORAGE_STATE } from './helpers/auth.ts'
import { getRequestToken, sweepFixtureResidue } from './helpers/fixtures.ts'
import { assertOccReachable } from './helpers/occ.ts'

const APP_ROOT = path.resolve(__dirname, '..', '..')
const BUNDLE_PATH = path.join(APP_ROOT, 'js', 'dossiq-main.js')

/**
 * Ensure the webpack bundle exists before specs hit `/apps/dossiq`.
 * On a fresh CI VM the shared quality.yml workflow runs `npm ci` +
 * `npx playwright install` but never `npm run build`, so without the
 * bundle the rendered page loads a 404 script tag and the Vue app
 * never mounts — every selector wait then times out.
 */
function ensureBundleBuilt(): void {
	if (fs.existsSync(BUNDLE_PATH)) {
		return
	}

	console.log(
		`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`,
	)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, {
			failOnStatusCode: false,
		})
		if (!res.ok()) {
			throw new Error(
				`Nextcloud status.php returned ${res.status()} at ${baseURL}. `
					+ 'Make sure the docker container is running and reachable.',
			)
		}
		const body = await res.json().catch(() => ({}))
		if (!body || body.installed !== true) {
			throw new Error(
				`Nextcloud at ${baseURL} is not installed (status.php = ${JSON.stringify(body)}).`,
			)
		}
	} finally {
		await ctx.dispose()
	}
}

/**
 * Confirm the suite can run `occ` on the instance under test, BEFORE any spec
 * runs.
 *
 * The suite is otherwise pure HTTP, but `dossiq/case` declares
 * `x-openregister-archival` and OpenRegister refuses an archival record on every
 * HTTP delete route it serves (openregister#3428). The only sanctioned removal
 * is `occ openregister:objects:purge --force --apply`, so a rig where occ is out
 * of reach is a rig where teardown cannot remove a single case.
 *
 * Deliberately fatal, and deliberately here. Left to teardown it would surface
 * as a survivor list half an hour in, after the run had already seeded the data
 * it could not clear; this way it is one step failure naming exactly what to
 * set. The probe also proves the deployed OpenRegister actually HAS the command,
 * which is the other half of the same question.
 */
async function ensureOccReachable(): Promise<void> {
	const invocation = await assertOccReachable()
	console.log(`[playwright globalSetup] occ reachable via ${invocation}`)
}

async function globalSetup(config: FullConfig): Promise<void> {
	// Whatever the active config resolved, else the single shared resolver.
	// Deliberately no `?? 'http://localhost:8080'` tail: off CI that literal is
	// the SHARED dev container, and this setup logs in and writes storage state
	// against it. See tests/e2e/base-url.ts — it throws instead.
	const baseURL =
		(config.projects[0]?.use?.baseURL as string | undefined) ?? BASE_URL
	const user = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
	const password =
		process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

	ensureBundleBuilt()
	await ensureNextcloudReachable(baseURL)
	await ensureOccReachable()
	fs.mkdirSync(path.dirname(STORAGE_STATE), { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	// `domcontentloaded` (not the default `load`) so first-paint themed-asset
	// compilation on a cold instance doesn't blow the 30s navigation budget;
	// the form inputs we need are in the initial HTML. Retry once on a spike.
	try {
		await page.goto('/index.php/login', {
			waitUntil: 'domcontentloaded',
			timeout: 60_000,
		})
	} catch {
		await page.goto('/index.php/login', {
			waitUntil: 'domcontentloaded',
			timeout: 60_000,
		})
	}
	await page
		.locator('input[name="user"]')
		.waitFor({ state: 'visible', timeout: 30_000 })
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(password)
	// The themed NC submit button sometimes swallows a plain .click() (the
	// click lands but no navigation is scheduled). Submit the form directly so
	// the POST always fires; fall back to the button click if no form is found.
	const submitted = await page.evaluate(() => {
		const form =
			document.querySelector('form[action*="login"]')
			|| document.querySelector('form')
		if (form && typeof (form as HTMLFormElement).requestSubmit === 'function') {
			;(form as HTMLFormElement).requestSubmit()
			return true
		}
		return false
	})
	if (submitted === false) {
		await page
			.locator('button[type="submit"], input[type="submit"]')
			.first()
			.click()
	}
	// Nextcloud bounces to /apps/dashboard/ on success.
	try {
		await page.waitForURL('**/apps/dashboard/**', { timeout: 30_000 })
	} catch {
		// Some NC versions redirect elsewhere; fall back to checking the URL.
	}
	const currentUrl = page.url()
	if (/\/login(\?|$|\/)/.test(currentUrl)) {
		throw new Error(
			`Login appears to have failed — still on ${currentUrl}. `
				+ 'Check ADMIN_USER / ADMIN_PASSWORD (defaults admin/admin).',
		)
	}

	// Suppress the dossiq product walkthrough (ADR-043) for automated runs: on
	// first visit it mounts a modal spotlight tour (`.cn-walkthrough`) whose full
	// dim layer intercepts pointer events and blocks every sidebar click. Its
	// "seen" marker is browser-local (`cn-walkthrough-seen:<appId>` in
	// localStorage), so a fresh Playwright context always re-triggers it. Seed the
	// marker into the persisted storageState with a high sentinel version — every
	// tour step's `sinceVersion` sorts below it, so the tour composes to an empty
	// step set (see useWalkthrough compareSemver gate) and never shows.
	try {
		await page.goto('/apps/dossiq/', {
			waitUntil: 'domcontentloaded',
			timeout: 60_000,
		})
		await page.evaluate(() => {
			try {
				window.localStorage.setItem('cn-walkthrough-seen:dossiq', '999.0.0')
				// Same problem, different overlay: the NON-GATING first-time-setup
				// wizard (ADR-042). It only started appearing once CnAppRoot learned
				// to tell "the server reports this optional step as not done" from
				// "the server never mentioned it" — before that it could not open at
				// all, so no spec in this suite had ever had to account for it. Its
				// modal-mask subtree intercepts every click on the app behind it, and
				// `navigation.spec.ts` clicks the sidebar without dismissing anything,
				// so leaving it armed turns one library fix into a suite-wide timeout.
				//
				// The dismissal key is per manifest `setup.version`; seed a generous
				// range so a version bump does not silently re-arm it.
				for (let v = 0; v <= 20; v++) {
					window.localStorage.setItem(
						`cn-setup-wizard-dismissed:dossiq:${v}`,
						'1',
					)
				}
			} catch {
				// localStorage unavailable — tour dismissal falls back to helper clicks.
			}
		})
	} catch {
		// App origin unreachable here is non-fatal; specs still run, tours dismiss via helper.
	}

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()

	await clearFixtureResidue(baseURL)
}

/**
 * Delete every object an earlier fixture run left on this instance.
 *
 * CI builds a throwaway Nextcloud per run, so this is a no-op there. A
 * developer rig is the case it exists for: the suite seeds cases, caseTypes,
 * statusTypes and workflowTemplates, and until now a run that was interrupted
 * before its teardown — or one whose cases the archival schema refused to
 * delete — left them behind. Eleven runs on one rig accumulated 68 cases, 33 of
 * them fixture leftovers, and the sixth soft-deleted statusType they still
 * pointed at is what made `spec-coverage/ui-pages.spec.ts:55` fail on a second
 * run for a reason no change had introduced.
 *
 * Failure here is reported, not thrown: a residue sweep that cannot reach the
 * API should not stop the suite from running and saying so itself.
 *
 * @param baseURL The resolved Nextcloud base URL.
 */
async function clearFixtureResidue(baseURL: string): Promise<void> {
	const api = await request.newContext({
		baseURL,
		storageState: STORAGE_STATE,
	})
	try {
		const token = await getRequestToken(api)
		const survivors = await sweepFixtureResidue(api, token)
		if (survivors.length > 0) {
			console.warn(
				'[playwright globalSetup] fixture residue that could NOT be removed: '
					+ survivors.join(', '),
			)
		}
	} catch (error) {
		console.warn(
			`[playwright globalSetup] fixture residue sweep skipped: ${String(error)}`,
		)
	} finally {
		await api.dispose()
	}
}

export default globalSetup
