/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A POLLING visibility probe, for use as a `test.skip()` condition.
 *
 * ⚠️ WHY THIS FILE EXISTS — `locator.isVisible()` DOES NOT WAIT.
 * ------------------------------------------------------------------
 * `Locator.isVisible()` is an *immediate* predicate: it answers "is this
 * element visible on this tick". Its `timeout` option is **ignored** — passing
 * `{ timeout: 10000 }` buys nothing at all, which is precisely why Playwright
 * deprecated the option. Only `expect(...).toBeVisible()` and
 * `locator.waitFor()` poll.
 *
 * So this shape, which this repo used in three spec files:
 *
 *     await page.goto('/index.php/apps/dossiq/workflow-board')
 *     if (!(await heading.isVisible({ timeout: 10000 }).catch(() => false))) {
 *         test.skip(true, 'Workflow Board surface not deployed in target instance')
 *     }
 *
 * asks the question before the SPA has issued a single XHR. It answers "no"
 * essentially always, and the test skips **with a reason that is false**.
 *
 * A SKIP WHOSE STATED REASON IS UNTRUE IS AN INVISIBLE PASS — worse than a
 * stub assertion, because it renders as "not applicable" rather than as a gap,
 * the reason looks investigated, and it inflates the skip count, which is the
 * number that separates a flake from a regression. dossiq skips 38 of 128.
 *
 * `waitFor` polls. **The skip that survives it is a real one.**
 *
 * ⚠️ NOTHING MOVED — AND THE RECORDED REASONS SAY WHY. READ THIS FIRST.
 * ---------------------------------------------------------------------
 * Measured base vs branch: 128 collected, 90 passed, 38 skipped on BOTH, and —
 * decisively — the SKIP REASONS THEMSELVES ARE IDENTICAL on both sides. They
 * are recoverable from the `playwright-report` artifact (recipe at the bottom).
 *
 * The reasons actually recorded, base and branch alike:
 *
 *    12  Sub-cases tab not present in the deployed build (deploy mismatch).
 *     9  Related cases sidebar tab not present in the deployed build (…).
 *     9  No case types in the deployed/seeded register — the Workflow tab is
 *        data-dependent.
 *     3  No cases on the Workflow Board to exercise the move control
 *     3  No cases on the Workflow Board to exercise the drag path
 *     3  OR addresses register not installed (sibling change not shipped)
 *
 * 🔑 NOTE WHAT IS ABSENT: "Workflow Board surface not deployed in target
 * instance" — the reason I twice argued about — **never fires, on either side.**
 * That gate was already passing before this change; the non-waiting probe
 * happened to win. The gates that actually fire are the SECOND ones in each
 * chain, and their reasons are true: these specs seed nothing, and the only
 * spec that ever sees case cards (`workflows/case-lifecycle.spec.ts:185`)
 * **seeds two cases itself** first.
 *
 * So the honest verdict for these nine gates is: **they were latent, not
 * false.** Nothing here was an invisible pass. The value of this change is that
 * each surviving skip now reports a real absence instead of depending on a
 * probe that could not have known — not that any test was recovered.
 *
 * ➡️ WHERE THE REAL SUSPECTS ARE, AND THEY ARE NOT `isVisible()`:
 * The two largest clusters above (21 of 39 records) come from
 * `spec-coverage/deelzaak-support.spec.ts` and
 * `spec-coverage/related-case-linking.spec.ts`, which gate on
 * **`(await tab.count()) === 0`** after a bare `waitForTimeout(1000)`.
 * `count()` DOES NOT WAIT EITHER — it is the same defect with a different
 * method name, invisible to any `isVisible` grep — and the reason blames the
 * DEPLOYMENT ("deploy mismatch"), which is the tell. "Sub-cases" is declared in
 * `src/manifest.json`. **Unverified**, deliberately: declaration is not
 * rendering, and this file has already been wrong about that once.
 *
 * 🔑 HOW TO CHECK A SKIP REASON (this is the instrument, and it is cheap):
 *   1. download the run's `playwright-report` artifact and unzip it;
 *   2. the report data is base64 inside index.html:
 *      `<script id="playwrightReportBase64">data:application/zip;base64,…`
 *   3. b64-decode → it is a ZIP of JSON → collect every
 *      `{"type":"skip","description":…}`.
 * The `list` reporter drops reasons entirely, so the CI log CANNOT answer this.
 * Compare the reason SETS between base and branch — the skip COUNT is the one
 * number that cannot tell you whether a reason changed.
 */

/**
 * Wait up to `timeout` for a locator to become visible; return whether it did.
 *
 * @param {import('@playwright/test').Locator} locator The locator to poll.
 *        `.first()` is applied so a strict-mode violation on a multi-match
 *        selector cannot masquerade as an absence.
 *
 * @param {number} [timeout] Milliseconds to poll for. Default 10s — enough for
 *        a Nextcloud SPA route to mount and fetch.
 *
 * @return {Promise<boolean>} `true` when the element became visible within
 *         `timeout`, else `false`. Never throws.
 */
export async function becomesVisible(locator, timeout = 10000) {
	return await locator
		.first()
		.waitFor({ state: 'visible', timeout })
		.then(() => true)
		.catch(() => false)
}
