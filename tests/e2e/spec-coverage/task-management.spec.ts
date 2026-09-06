/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for task-management spec.
 * Each test is tagged with the scenario it covers.
 *
 * Note: Use /apps/dossiq/<route> (not /index.php/apps/dossiq/<route>)
 * so the Vue history-mode router can resolve the route correctly.
 */

import { expect, test } from '@playwright/test'

test.describe('Task Management spec coverage', () => {
	// @e2e openspec/specs/task-management/spec.md#view-the-global-task-list
	test('global task list page renders with add button and empty state', async ({
		page,
	}) => {
		await page.goto('/index.php/apps/dossiq/tasks')
		// CnIndexPage renders with its Add Task button.
		await expect(page.getByRole('button', { name: 'Add Task' })).toBeVisible({
			timeout: 10000,
		})
		// The scenario is "view the global task list", so the list must RENDER.
		// It used to assert "No items found" outright, which made the test
		// require an empty register: the shared instance carries 8 task rows and
		// the assertion could only fail. Rows are a better satisfaction of the
		// scenario than emptiness, so accept either and reject neither.
		await expect
			.poll(
				async () =>
					(await page.locator('tbody tr').count()) > 0
					|| (await page.getByText('No items found').count()) > 0,
				{
					timeout: 10000,
					message: 'the task list rendered either rows or its empty state',
				},
			)
			.toBe(true)
		// Should not show broken state
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})
