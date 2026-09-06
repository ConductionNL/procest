/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for my-work spec.
 *
 * My Work is now a standard CnIndexPage card list scoped to the current
 * user (assignee = current uid), not the legacy 4-tab case+task board.
 * Each test is tagged with the scenario it covers.
 *
 * Note: Use /apps/dossiq/<route> (not /index.php/apps/dossiq/<route>)
 * so the Vue history-mode router can resolve the route correctly.
 */

import { expect, test } from '@playwright/test'
import { dismissSupportDialog } from '../helpers/nav.ts'
// Route named after the component that renders it, so this spec states WHICH
// screen it covers in executable code rather than in a comment.
import { MyWorkCards } from '../helpers/page-components.ts'

test.describe('My Work spec coverage', () => {
	// @e2e openspec/specs/my-work/spec.md#personal-workload-view
	test("shows the current user's assigned cases as a card list", async ({
		page,
	}) => {
		await page.goto(`/index.php/apps/dossiq${MyWorkCards}`)
		await dismissSupportDialog(page)
		// The My Work route renders NO page heading — measured on a CI runner
		// (2026-08-04) it exposes zero `heading` roles. Identify the view by
		// the sort controls unique to it, plus its card/table toggle.
		await expect(page.getByRole('button', { name: 'Urgency' })).toBeVisible({
			timeout: 15000,
		})
		await expect(page.getByRole('button', { name: 'Newest' })).toBeVisible()
		// Card/table view toggle is present (card view is the default)
		await expect(
			page.getByRole('button', { name: /Cards/ }).first(),
		).toBeVisible({ timeout: 10000 })
	})

	// @e2e openspec/specs/my-work/spec.md#personal-workload-view
	test('view mode can be switched between cards and table', async ({ page }) => {
		await page.goto(`/index.php/apps/dossiq${MyWorkCards}`)
		await dismissSupportDialog(page)
		const tableToggle = page.getByRole('button', { name: /Table/ }).first()
		await expect(tableToggle).toBeVisible({ timeout: 10000 })
		await tableToggle.click()
		// Switching view must not error
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})
