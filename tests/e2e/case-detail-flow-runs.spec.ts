/**
 * The case half of the waiting relationship: a case shows its own flow run
 * and where it stands.
 *
 * WHAT THIS ASSERTS. Three things a caseworker sees on the case detail page,
 * none of them internal: the runs widget is there; a case that has a run
 * lists THAT run and nobody else's; a case that never ran says so in its own
 * words, distinct from "nothing running right now". The widget itself is
 * nextcloud-vue's CnFlowRunsWidget in subject mode, covered there; what
 * belongs to dossiq is the placement, the subject binding and the copy, so
 * that is what these tests pin.
 *
 * SCOPING IS PROVED BY COUNT, NOT BY NAME. A row shows the flow's name, and
 * every case on this instance runs the same shipped flow, so two cases' runs
 * are indistinguishable by text. What does distinguish them is the number:
 * the widget must list exactly as many rows as the subject-scoped endpoints
 * return for this case, while the instance as a whole holds more. A widget
 * that ignored its subject would list them all.
 *
 * 🔴 IT REFUSES TO PASS ON AN ABSENT FIXTURE. Where the seeded case is missing
 * the test fails naming it, rather than skipping: a skip cannot tell "not
 * seeded" from "the seeder is broken". The one skip here is the run-bearing
 * case, and it follows the sibling spec's reason: the shipped flow arrives
 * DISABLED by spec (2.2), so a shared instance may honestly hold no run at
 * all. That is a property of the instance, not of this change.
 *
 * @e2e pending proof rig: written against the widget's DOM contract and the
 * OpenRegister endpoints it reads; not yet run against an instance serving
 * this bundle (the local instance mounts another checkout as dossiq).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { listObjects, objectId } from './helpers/fixtures.ts'
import { navToRoute } from './helpers/nav.ts'

/** The seeded case that is INCOMPLETE, and should carry the applicant loop's run. */
const INCOMPLETE_CASE = 'Schuur Molenweg 3'

/** The widget's root, as CnFlowRunsWidget renders it. */
const WIDGET = '.cn-flow-runs-widget'

/** One run row, live or finished. */
const ROW = '.cn-flow-runs-widget__row'

/** The widget title as the manifest declares it, in either locale the instance may run. */
const TITLE = /Flow runs|Flow-uitvoeringen/

/** The never-ran line, in either locale. */
const NEVER_RAN = /No flows have run yet|Er is nog geen flow uitgevoerd/

/** The rows the widget shows at most, mirroring the manifest's `content.limit`. */
const LIMIT = 5

/**
 * Every run on the instance the caller may read, with its subject.
 *
 * Goes through the org-wide list because the question is which cases have
 * runs AT ALL, before any one case is opened.
 */
async function allRuns(
	api: APIRequestContext,
): Promise<Array<Record<string, unknown>>> {
	const response = await api.get(
		'/index.php/apps/openregister/api/flow-runs?limit=50',
	)
	expect(response.ok(), 'The flow-runs surface must answer.').toBeTruthy()
	const body = await response.json()
	return (body?.results ?? []) as Array<Record<string, unknown>>
}

/**
 * The runs the subject-scoped endpoints return for one case: what the
 * widget is specified to render, live and finished.
 */
async function runsForSubject(
	api: APIRequestContext,
	subject: string,
): Promise<number> {
	let total = 0
	for (const surface of ['active', 'completed']) {
		const response = await api.get(
			`/index.php/apps/openregister/api/flow-runs/${surface}?subject=${encodeURIComponent(subject)}&limit=50`,
		)
		expect(
			response.ok(),
			`GET /api/flow-runs/${surface}?subject= must answer: the widget reads it.`,
		).toBeTruthy()
		const body = await response.json()
		total += ((body?.results ?? []) as unknown[]).length
	}
	return total
}

/** Open one case's detail page and wait for its runs widget to settle. */
async function openCase(page: Page, uuid: string) {
	await navToRoute(page, `/cases/${uuid}`)
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
	const widget = page.locator(WIDGET)
	await expect(
		widget,
		'The flow runs widget must render on the case detail page.',
	).toBeVisible({
		timeout: 15000,
	})
	// The loading spinner is the only state that is not an answer.
	await expect(widget.locator('.cn-flow-runs-widget__loading')).toHaveCount(0, {
		timeout: 15000,
	})
	return widget
}

test.describe('Case detail — flow runs widget', () => {
	test('the case detail page renders the runs widget under its title', async ({
		page,
	}) => {
		await navToRoute(page, '/cases')

		const body = page.locator('body')
		await expect(body).not.toContainText('Internal Server Error')
		await expect(
			body,
			`The seeded case "${INCOMPLETE_CASE}" is missing, so there is no case to open.`,
		).toContainText(INCOMPLETE_CASE, { timeout: 15000 })

		await page.getByText(INCOMPLETE_CASE).first().click()

		await expect(page.locator(WIDGET)).toBeVisible({ timeout: 15000 })
		// The card heading is the manifest title run through the app's
		// catalogue: English or Dutch, never a raw key and never absent.
		await expect(page.getByRole('heading', { name: TITLE })).toBeVisible()
	})

	test("a case with a run lists exactly its own runs, and not another case's", async ({
		page,
	}) => {
		const runs = await allRuns(page.request)
		const bySubject = new Map<string, number>()
		for (const run of runs) {
			const subject = String(run.subjectUuid ?? '')
			if (subject !== '') {
				bySubject.set(subject, (bySubject.get(subject) ?? 0) + 1)
			}
		}

		test.skip(
			bySubject.size === 0,
			'no run with a subject on this instance — the shipped flow arrives disabled, so nothing has run here yet',
		)

		// Prefer a case that leaves other cases' runs to be wrongly included.
		const [subject] = [...bySubject.entries()].sort((a, b) => a[1] - b[1])[0]
		const expected = Math.min(await runsForSubject(page.request, subject), LIMIT)
		expect(
			expected,
			'the subject-scoped reads must see the run the org-wide list saw',
		).toBeGreaterThan(0)

		const widget = await openCase(page, subject)

		await expect(widget.locator(ROW)).toHaveCount(expected, { timeout: 15000 })
		// The never-ran line and a listed run are mutually exclusive by design.
		await expect(widget).not.toContainText(NEVER_RAN)

		if (runs.length > expected) {
			// Other cases hold runs too: the count above is the proof that
			// the widget filtered to this one.
			await expect(widget.locator(ROW)).not.toHaveCount(runs.length)
		}
	})

	test('a case with no run says so, in its own words', async ({ page }) => {
		const runs = await allRuns(page.request)
		const withRuns = new Set(runs.map((run) => String(run.subjectUuid ?? '')))

		const cases = await listObjects(page.request, 'case')
		expect(
			cases.length,
			'No cases on this instance: nothing to open.',
		).toBeGreaterThan(0)

		const quiet = cases.find((c) => !withRuns.has(objectId(c)))
		expect(
			quiet,
			'Every case on this instance carries a run; the never-ran state has nothing to render on.',
		).toBeTruthy()

		const widget = await openCase(page, objectId(quiet))

		await expect(widget.locator('.cn-flow-runs-widget__empty')).toContainText(
			NEVER_RAN,
		)
		await expect(widget.locator(ROW)).toHaveCount(0)
		// Distinct from "nothing running now": that line only belongs to a
		// case whose history is non-empty.
		await expect(widget).not.toContainText(
			/No flow is running for this case|Er loopt op dit moment geen flow/,
		)
	})
})
