/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The shared workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. Whichever config it picks, EVERY project
 * in it runs. The ROOT `playwright.config.ts` declares three:
 *
 *   chromium     — the regression suite. This is the one CI wants.
 *   docs-capture — journeydoc screenshot capture (ADR-030). It re-shoots every
 *                  tutorial screenshot into `docs/static/screenshots/…` and is
 *                  driven deliberately by `npm run test:e2e:docs` (and by the
 *                  dedicated `Journeydoc Capture` job, which passes
 *                  `--project docs-capture` explicitly).
 *   visual       — pixel-diff baselines (GAP-5). Its own README records the
 *                  reason it cannot gate: the committed PNGs are host-font and
 *                  GPU specific, so a CI Linux runner does not byte-match a
 *                  dev-container baseline. Running it here would fail every
 *                  run for a reason that has nothing to do with the change.
 *
 * Letting the root config be picked would therefore make every PR both
 * re-shoot the documentation screenshots and fail on unmatched pixel
 * baselines. Rather than delete or weaken either project, `playwright-test-path:
 * tests/e2e` in the caller makes the workflow's FIRST lookup hit this file,
 * which declares only the regression project. The root config is untouched and
 * stays the entry point for local runs, `npm run test:e2e:docs` and
 * `--project visual`.
 *
 * ⚠️ `testIgnore` HAS TO BE REPEATED AT PROJECT LEVEL.
 * A project-level `testIgnore` REPLACES the top-level one, it does not merge
 * with it. Both lists below are therefore complete on their own, so a future
 * reader cannot delete one and silently start collecting `global-setup.ts`,
 * `base-url.ts` or `helpers/*.ts` as if they were specs (they export helpers,
 * not tests — Playwright errors with "no tests found in file"), or start
 * re-shooting docs screenshots and diffing visual baselines on CI.
 *
 * ARTIFACT PATHS
 * --------------
 * The report and trace output stay under `tests/e2e/`. The shared workflow's
 * upload steps list `server/apps/<app>/tests/e2e/playwright-report/` and
 * `.../tests/e2e/test-results/` alongside the app-root paths, so both produce
 * a downloadable artifact — and `tests/e2e/.gitignore` already ignores these
 * two directories plus `.auth/`, so nothing lands in `git status` locally.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { BASE_URL } from './base-url.ts'

/**
 * Non-spec modules that live inside `testDir` and must never be collected.
 *
 * `docs-screenshots.spec.ts` and `visual/**` ARE specs — they are excluded
 * because they belong to the two opt-in projects described in the header, not
 * because they are unrunnable.
 */
const IGNORED = [
	// Helper / infrastructure modules that export no tests.
	'**/global-setup.ts',
	'**/base-url.ts',
	'**/helpers/**',
	'**/visual/_visual-helpers.ts',
	// Opt-in projects — see the header.
	'**/docs-screenshots.spec.ts',
	'**/visual/**',
]

export default defineConfig({
	testDir: __dirname,
	// See the header: also repeated on the project below, because a
	// project-level testIgnore REPLACES this list rather than extending it.
	testIgnore: IGNORED,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	// One worker on purpose. `helpers/fixtures.ts#ensureCaseType` reuses
	// `listObjects('caseType')[0]` — whatever caseType happens to exist — and
	// `cleanupRunObjects` deletes by run prefix afterwards. With two workers,
	// worker B can adopt worker A's throwaway caseType and then have it deleted
	// out from under it mid-test. Serial execution removes that whole class of
	// cross-worker flake.
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	// Stop on our own clock, ahead of the shared job's `timeout-minutes: 45`.
	//
	// The `actionTimeout` note below records exactly what happens without this:
	// "a 45-minute job that CI cancelled after 65 of 122 tests". A cancelled
	// job is not a verdict — Playwright never prints its tally, the
	// `if: failure()` trace upload never fires, and the `if: always()` report
	// upload does not run on a cancelled job either. The evidence that 57 tests
	// never ran had to be reconstructed from the live log rather than read off
	// an artifact, because there was no artifact.
	//
	// With a globalTimeout Playwright stops itself and exits with a count, and
	// the uploads run. Measured overhead before `Run Playwright tests` starts
	// is 2.0-2.4 min and the uploads take seconds, so 38m keeps ~7 min of
	// margin under the cap.
	globalTimeout: 38 * 60_000,
	reporter: [
		[
			'html',
			{
				open: 'never',
				outputFolder: path.resolve(__dirname, 'playwright-report'),
			},
		],
		[
			'junit',
			{ outputFile: path.resolve(__dirname, 'test-results', 'results.xml') },
		],
		['list'],
	],
	outputDir: path.resolve(__dirname, 'test-results'),

	use: {
		// Single source of truth — see tests/e2e/base-url.ts. Deliberately NOT
		// `process.env.NEXTCLOUD_URL || 'http://localhost:8080'`: that literal
		// is the SHARED dev container off CI, and this suite both seeds and
		// deletes OpenRegister objects.
		baseURL: BASE_URL,
		// Playwright's `actionTimeout` defaults to 0 — NO limit — so a single
		// `.click()` on a non-actionable element (e.g. a nav leaf hidden inside
		// a collapsed group) blocks until the entire 60s test budget is gone,
		// then reports a bare timeout naming the element rather than the cause.
		// That is what turned this suite into a 45-minute job that CI cancelled
		// after 65 of 122 tests. A bounded action fails in 15s with the same
		// diagnostic and leaves the remaining budget for the real assertions.
		actionTimeout: 15_000,
		navigationTimeout: 30_000,
		// Written by global-setup.ts after the admin login. Path must match
		// `helpers/auth.ts#STORAGE_STATE`, which global-setup imports.
		storageState: path.resolve(__dirname, '.auth', 'user.json'),
		// `on-first-retry` writes a trace only when a retry actually happens, so
		// the trace artifact is a function of `retries`. Off CI `retries` is 0
		// above, so a local failure has never produced a trace at all; on CI it
		// traces the SECOND attempt only, which means the failure that does not
		// reproduce — the one actually worth a trace — leaves no record of the
		// attempt that failed. `retain-on-failure` traces every attempt and
		// keeps the ones that failed: strictly more informative, and
		// independent of the retry count. (The app-root config already used
		// `retain-on-failure`; this file, the one CI actually loads, did not.)
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			testIgnore: IGNORED,
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
