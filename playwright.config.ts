import { defineConfig, devices } from '@playwright/test'
import path from 'path'
import { BASE_URL } from './tests/e2e/base-url.ts'

const STORAGE_STATE = path.join(__dirname, 'tests/e2e/.auth/user.json')

export default defineConfig({
	testDir: './tests/e2e',
	timeout: 30000,
	expect: { timeout: 10000 },
	fullyParallel: false,
	retries: 1,
	workers: 1,
	// The shared quality.yml Playwright job is `timeout-minutes: 45`, and a job
	// cancelled by that cap produces NO verdict: Playwright never prints its
	// tally, the `if: failure()` trace upload never fires, and the
	// `if: always()` report upload does not run on a cancelled job either — the
	// run you most need to read is the one that leaves nothing behind, and it
	// still renders as "fail" in `gh pr checks` while carrying no information.
	// Runs cancelled at ~45m16s have been observed in this fleet. Measured
	// overhead before `Run Playwright tests` starts is 2.0-2.4 min and the
	// uploads after it take seconds, so 38m keeps ~7 min of margin while
	// guaranteeing both a tally and the artifacts that explain it.
	globalTimeout: 38 * 60_000,
	reporter: [
		// Output paths match the shared quality.yml workflow's artifact-upload
		// paths (server/apps/<app>/playwright-report and .../test-results) so
		// the HTML report + failure screenshots/traces actually get uploaded.
		['html', { open: 'never', outputFolder: 'playwright-report' }],
		['junit', { outputFile: 'test-results/results.xml' }],
	],
	outputDir: 'test-results',
	globalSetup: './tests/e2e/global-setup.ts',

	use: {
		// ONE resolver (tests/e2e/base-url.ts). This line used to read
		// `NEXTCLOUD_URL || 'http://localhost:8080'` on its own, so a run with
		// only PLAYWRIGHT_BASE_URL set (the variable every runbook uses) went to
		// the SHARED dev container: global-setup takes the first project's
		// baseURL, and the suite logged in and read from 8080 while reporting
		// on the instance the operator named. Measured 2026-09-01.
		baseURL: BASE_URL,
		storageState: STORAGE_STATE,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		// Default regression project — excludes the docs capture spec so
		// PR pipelines don't reshoot screenshots on every push.
		{
			name: 'chromium',
			testIgnore: [
				'**/docs-screenshots.spec.ts',
				'**/visual/**',
				// Needs the case flow ENABLED, which the shared instance must never be.
				'**/case-flow-live-journeys.spec.ts',
			],
			use: { ...devices['Desktop Chrome'] },
		},

		// Live case-flow journeys (case-flow-human-steps tasks 7.1/7.2). Opt-in,
		// for a DISPOSABLE instance on which the operator enabled the flow:
		//
		//   PLAYWRIGHT_BASE_URL=http://localhost:8614 \
		//   FLOW_WORKER_CMD='docker exec -u www-data <container> php occ background-job:execute <id> --force-execute' \
		//   npx playwright test --project live-journeys
		//
		// Never part of the default project: it creates cases that start runs.
		{
			name: 'live-journeys',
			testMatch: /case-flow-live-journeys\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
			},
			timeout: 180_000,
			retries: 0,
		},

		// Documentation capture project (ADR-030). Opt-in run:
		//
		//   npx playwright test --project docs-capture
		//
		// Output lands in `docs/static/screenshots/tutorials/{user,admin}/`.
		// See `tests/e2e/docs-screenshots.spec.ts`.
		{
			name: 'docs-capture',
			testMatch: /docs-screenshots\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
			},
			timeout: 90_000,
		},
		// Visual-regression project (GAP-5). Opt-in / non-gating:
		//   npx playwright test --project visual
		//   npx playwright test --project visual --update-snapshots  (rebaseline)
		// Fixed viewport + authenticated session => deterministic shots.
		// Baselines live in tests/e2e/visual/*-snapshots/ and ARE committed.
		// PLATFORM CAVEAT: PNG baselines are host-font/GPU specific, so a CI
		// Linux runner will not byte-match a dev-container baseline; the visual
		// project must regenerate its baselines in-CI before it can gate.
		{
			name: 'visual',
			testMatch: /visual\/.*\.visual\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
				storageState: STORAGE_STATE,
			},
			timeout: 90_000,
		},
	],
})
