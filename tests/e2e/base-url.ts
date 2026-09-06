/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ONE place that decides which Nextcloud the dossiq e2e suite talks to.
 *
 * Why this file exists
 * --------------------
 * Before this file, every entry point computed its own target:
 *
 *   playwright.config.ts        `process.env.NEXTCLOUD_URL || 'http://localhost:8080'`
 *   tests/e2e/global-setup.ts   `NEXTCLOUD_URL ?? NC_BASE_URL ?? 'http://localhost:8080'`
 *   6 spec files                their own copy of the same expression, for the
 *                               `request.newContext({ baseURL })` API probes
 *
 * Three things were wrong with that:
 *
 *  1. `BASE_URL` — the variable the shared ConductionNL/.github quality
 *     workflow actually exports for both the "Seed test data" step and the
 *     Playwright run step — was accepted by NONE of them. It only worked on CI
 *     because the workflow happens to export `NEXTCLOUD_URL` as well; a
 *     resolver that omits `BASE_URL` is one workflow change away from
 *     hard-failing every run. openconnector adopted a `PLAYWRIGHT_BASE_URL`-only
 *     resolver and its "E2E Tests (Playwright)" job has failed on every run
 *     since with "Error: PLAYWRIGHT_BASE_URL is not set."
 *  2. `PLAYWRIGHT_BASE_URL` — the variable every runbook in this programme uses
 *     to point a suite at a disposable instance — was ignored outright.
 *     Exporting it did nothing.
 *  3. The `|| 'http://localhost:8080'` default is the SHARED development
 *     container on a Conduction dev box. It bind-mounts real host checkouts, so
 *     a suite that quietly falls back to it creates fixture cases, caseTypes,
 *     statusTypes and workflowTemplates in somebody else's environment — and
 *     `tests/e2e/fixtures.ts` deletes every object whose body contains its run
 *     prefix afterwards. Two apps in this programme were found doing exactly
 *     this.
 *
 * So: off CI the target must be stated explicitly. A missing variable is a hard
 * error naming the fix, not a silent redirect onto somebody else's instance.
 *
 * The one exception is CI. A GitHub runner has no shared instance — the shared
 * workflow starts a throwaway Nextcloud on the runner's own `php -S
 * 0.0.0.0:8080` — so falling back there is safe, and is what keeps the suite
 * runnable if a future workflow revision renames its exported variable again.
 */

const CI_DEFAULT_BASE_URL = 'http://localhost:8080'

/**
 * Resolve the Nextcloud base URL for this run.
 *
 * @return the base URL, without a trailing slash
 * @throws when no target is configured outside CI
 */
export function resolveBaseURL(): string {
	const explicit =
		process.env.PLAYWRIGHT_BASE_URL
		?? process.env.NEXTCLOUD_URL
		?? process.env.NC_BASE_URL
		// Exported by the shared ConductionNL/.github quality workflow.
		?? process.env.BASE_URL

	if (explicit) {
		return explicit.replace(/\/+$/, '')
	}

	if (process.env.CI || process.env.GITHUB_ACTIONS) {
		console.warn(
			'[dossiq e2e] no PLAYWRIGHT_BASE_URL / NEXTCLOUD_URL / NC_BASE_URL / BASE_URL set; '
				+ `using the CI-local default ${CI_DEFAULT_BASE_URL}.`,
		)
		return CI_DEFAULT_BASE_URL
	}

	throw new Error(
		'[dossiq e2e] No target Nextcloud configured. Set PLAYWRIGHT_BASE_URL (preferred), '
			+ 'NEXTCLOUD_URL, NC_BASE_URL or BASE_URL to the instance you want to test, e.g.\n\n'
			+ '    PLAYWRIGHT_BASE_URL=http://localhost:8095 npx playwright test\n\n'
			+ 'There is deliberately no default: the historic one was http://localhost:8080, '
			+ 'the SHARED development container, and this suite seeds AND DELETES OpenRegister '
			+ "objects — running it there corrupts other people's environments.",
	)
}

/** The resolved base URL for this run. */
export const BASE_URL = resolveBaseURL()
