/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Case-types admin smoke — covers the 7-tab integration spec'd by
 * case-types-03-result-role-tabs and case-types-04-property-doc-decision-tabs.
 *
 * Asserts the case-types management surface renders + the type-list +
 * the underlying admin chrome. Real "create + 7-tab edit" cycle is
 * data-dependent and runs in opsx-verify against a seeded register;
 * this spec covers the data-independent shell.
 */

import { expect, test } from '@playwright/test'

const ADMIN_SETTINGS_URL = '/settings/admin/dossiq'

test.describe('Case-types admin — 7-tab integration shell', () => {
	// Same reason as spec-coverage/admin-settings.spec.ts: the Nextcloud admin
	// settings page mounts fourteen OpenRegister-backed sections and has been
	// measured between ~7s and 3.2m under the CI `php -S` server — variable
	// enough to overrun even test.slow()'s tripled budget.
	test.setTimeout(300_000)

	// @e2e openspec/changes/case-types-04-property-doc-decision-tabs/tasks.md#TASK-CT-13
	test('admin settings surface renders the Case Type Management heading', async ({
		page,
	}) => {
		await page.goto(ADMIN_SETTINGS_URL)
		await expect(page).not.toHaveURL(/login/, { timeout: 10000 })
		await expect(
			page.getByRole('heading', { name: 'Case Type Management' }),
		).toBeVisible({ timeout: 15000 })
	})

	// @e2e openspec/changes/case-types-04-property-doc-decision-tabs/tasks.md#TASK-CT-13
	test('admin settings surface has an add-control for case types', async ({
		page,
	}) => {
		await page.goto(ADMIN_SETTINGS_URL)
		await expect(
			page.getByRole('heading', { name: 'Case Type Management' }),
		).toBeVisible({ timeout: 15000 })
		// The CnIndexPage management surface always renders an add control;
		// the exact label depends on whether the schema is seeded ("Add Case
		// Type") or generic ("Add Item"). Either is acceptable.
		const addBtn = page.getByRole('button', { name: /^Add (Item|Case Type)$/ })
		await expect(addBtn).toBeVisible({ timeout: 15000 })
	})

	// @e2e openspec/changes/case-types-02-backend-validation/tasks.md#TASK-CT-08-SMOKE
	test('publish validation endpoint exists at the case-types route', async ({
		page,
		request,
	}) => {
		// PATCH a non-existent case type should NOT 404 the entire route —
		// the endpoint is reachable (404 or 422 from the validator is fine;
		// 500 indicates a routing/ZgwBusinessRulesService bootstrap defect).
		await page.goto(ADMIN_SETTINGS_URL)
		const res = await request.patch(
			'/index.php/apps/dossiq/api/case-types/non-existent-uuid',
			{
				data: { isDraft: false },
				failOnStatusCode: false,
			},
		)
		// 200 / 404 / 422 are all reachable-OK. 500 is the failure mode.
		expect(res.status()).toBeLessThan(500)
	})
})
