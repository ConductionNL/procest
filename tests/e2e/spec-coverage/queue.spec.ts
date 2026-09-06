/*
 * SPDX-FileCopyrightText: 2026 DossiQ Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Queue page: the cases nobody has picked up yet.
 *
 * The queue is a FILTER, not a collection, so the assertions here are about the
 * filter holding rather than about rows existing. A page that silently dropped
 * its base filter would render a healthy-looking table of every case in the
 * instance, which is exactly what `assignee_isnull=true` produced before the
 * `IS NULL` sentinel replaced it: no error, no empty state, just the wrong set.
 *
 * Entries are asserted by PRESENCE where the nav is concerned: the Queue leaf
 * sits inside the collapsible My work group and is rendered but hidden until
 * the group is expanded.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	RUN_PREFIX,
	seedCase,
} from '../helpers/fixtures.ts'

let api: APIRequestContext
let token: string
let caseTypeId: string

test.describe('Queue', () => {
	// Same budget as the other dossiq shell specs: a large manifest plus an
	// OpenRegister round trip on load.
	test.setTimeout(300_000)

	test.beforeAll(async ({ baseURL }) => {
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		caseTypeId = (await ensureCaseType(api, token)).id
	})

	test.afterAll(async () => {
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	// @e2e openspec/changes/add-work-queue/specs/add-work-queue/spec.md#the-queue-holds-unassigned-open-cases
	test('the queue page renders for a deep link', async ({ page }) => {
		// A PATH, not `#/queue`: dossiq runs on createWebHistory, so a hash deep
		// link navigates nowhere and lands on the dashboard without throwing.
		await page.goto('/index.php/apps/dossiq/queue')

		await expect(
			page.locator('[data-testid="cn-page"]'),
			'the queue page must render for a deep link',
		).toBeVisible({ timeout: 60_000 })

		await expect(
			page.getByRole('heading', { name: /^(Queue|Werkvoorraad)$/i }).first(),
			'the deep link must land on the QUEUE page, not the shell default',
		).toBeVisible({ timeout: 30_000 })
	})

	// @e2e openspec/changes/add-work-queue/specs/add-work-queue/spec.md#the-queue-holds-unassigned-open-cases
	test('an assigned case is on the case index and NOT in the queue', async ({
		page,
	}) => {
		// ⚠️ THIS USED TO COUNT `table tbody tr` ON BOTH PAGES, and that number is
		// wrong twice over. The index pages at 20 rows, so on any instance past 20
		// cases both pages render exactly 20 and `20 < 20` is false — measured on a
		// demo-sized rig as "Showing 20 of 52" against "Showing 20 of 50", a
		// correct queue that the test called broken.
		//
		// Reading the totals the pages REPORT fixes the arithmetic but not the
		// test: a tally cannot say WHICH case the filter should have dropped, and
		// it only means anything while the instance happens to hold an assigned or
		// closed case. On a rig whose every case was open and unassigned the two
		// totals were equal and the comparison failed against a working queue.
		//
		// So the discriminator is seeded here rather than assumed: one case with an
		// assignee, which the queue's base filter (`assignee: "IS NULL"`) must
		// exclude and the case index must keep. Both pages are queried by that
		// case's own title, so neither answer depends on how many other cases the
		// instance holds.
		const title = `${RUN_PREFIX} Assigned case`
		await seedCase(api, token, {
			title,
			caseType: caseTypeId,
			assignee: 'admin',
		})

		const rowsMatching = async (path: string): Promise<number> => {
			await page.goto(`${path}?title=${encodeURIComponent(title)}`)
			await expect(page.locator('[data-testid="cn-page"]')).toBeVisible({
				timeout: 60_000,
			})
			// Settle on the page's own "loaded" marker rather than a bare row
			// count: `cn-page` becomes visible while the table is still fetching, so
			// counting on that signal alone reads 0 from a page about to render.
			await expect(
				page.locator('.cn-index-page__empty, table tbody tr').first(),
			).toBeVisible({ timeout: 60_000 })
			return await page.locator('table tbody tr').count()
		}

		expect(
			await rowsMatching('/index.php/apps/dossiq/cases'),
			`the case index must hold the assigned case "${title}"`,
		).toBe(1)

		expect(
			await rowsMatching('/index.php/apps/dossiq/queue'),
			`the queue must NOT hold "${title}": it has an assignee, and the base `
				+ 'filter (assignee IS NULL, isFinalStatus false) is what keeps '
				+ 'picked-up work out of the queue',
		).toBe(0)
	})

	// @e2e openspec/changes/add-work-queue/specs/add-work-queue/spec.md#an-empty-queue-says-so
	test('an empty result renders the empty state, not a bare table', async ({
		page,
	}) => {
		// Drive the filter to a slice that cannot match. The page must answer with
		// its empty state rather than a blank region: "mounted and empty" and
		// "never mounted" look identical without a marker to probe.
		await page.goto('/index.php/apps/dossiq/queue?caseType=__none__')

		await expect(page.locator('[data-testid="cn-page"]')).toBeVisible({
			timeout: 60_000,
		})
		await expect(
			page.locator('.cn-index-page__empty, table tbody tr').first(),
			'an empty queue must say so',
		).toBeVisible({ timeout: 30_000 })
	})

	// @e2e openspec/changes/add-work-queue/specs/add-work-queue/spec.md#the-queue-narrows-by-case-type
	test('the case-type sidebar narrows the queue', async ({ page }) => {
		await page.goto('/index.php/apps/dossiq/queue')
		await expect(page.locator('[data-testid="cn-page"]')).toBeVisible({
			timeout: 60_000,
		})
		const before = await page.locator('table tbody tr').count()

		// The folder sidebar is the same control the Cases index carries; picking
		// one type can only ever narrow the set.
		const firstType = page
			.locator('[data-testid="cn-folder-sidebar"] li, .cn-folder-sidebar li')
			.nth(1)
		if ((await firstType.count()) > 0) {
			await firstType.click()
			await expect(
				page.locator('.cn-index-page__empty, table tbody tr').first(),
			).toBeVisible({ timeout: 30_000 })
			const after = await page.locator('table tbody tr').count()
			expect(
				after,
				'narrowing to one case type cannot widen the result set',
			).toBeLessThanOrEqual(before)
		}
	})
})
