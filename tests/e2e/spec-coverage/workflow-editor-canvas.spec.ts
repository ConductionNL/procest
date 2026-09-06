/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the visual workflow editor
 * (`workflow-editor-integration`): the case type's Workflow tab renders
 * the canonical canvas (`WorkflowEditor.vue` — the single surviving
 * editor after the dead `@vue-flow`-based duplicate was deleted), and its
 * keyboard-operable controls (WCAG 2.1.1 Keyboard) are reachable.
 *
 * These tests drive a real browser against a case type's detail page.
 * They are defensively guarded: reaching the Workflow tab needs at least
 * one case type to exist, and the keyboard-actions-menu assertion needs an
 * existing workflow definition with at least one status node. On an
 * unseeded register the tests SKIP with a clear reason rather than
 * failing — the same "skip gracefully" convention as
 * `kanban-board-keyboard-status-transition.spec.ts` and
 * `related-case-linking.spec.ts`.
 *
 * Deliberately non-destructive: unlike a full open-edit-save-reopen
 * round trip, no test here commits a mutation against the live/shared
 * register (adding a status node or publishing persists real data
 * immediately — `WorkflowEditor.vue::createStatusNode()` saves the
 * `statusType` object right away, before the template itself is even
 * saved). Controls are proven keyboard-reachable by focusing/opening them
 * and then cancelling (Escape) without committing, mirroring the kanban
 * spec's own "close the menu without selecting" pattern. The actual
 * open-edit-save-reopen round trip and blocked-save-on-invalid behaviour
 * are proven directly against the real component in
 * `tests/vitest/workflowEditorSmoke.spec.js` (renders a definition,
 * `validate()` blocks the exact gate `WorkflowTab.vue::publish()` calls)
 * and `tests/vitest/workflowGraphValidation.spec.js` (every validation
 * rule + a serialization round-trip).
 *
 * Note: Use /apps/dossiq/<route> (not /index.php/...) so the Vue
 * history-mode router resolves the route. The case-type admin surface
 * lives under Nextcloud's own /settings/admin/dossiq page.
 */

import { expect, test } from '@playwright/test'
import { becomesVisible } from '../helpers/becomes-visible.js'

const ADMIN_SETTINGS_URL = '/settings/admin/dossiq'

/**
 * Open the first case type's detail page and its Workflow tab, or skip
 * when no case type exists / the tab is not present in the deployed
 * build.
 *
 * @param page Playwright page
 * @return true when the Workflow tab is open and ready to assert against
 */
async function openFirstCaseTypeWorkflowTabOrSkip(page): Promise<boolean> {
	await page.goto(ADMIN_SETTINGS_URL)
	const heading = page.getByRole('heading', { name: 'Case Type Management' })
	if (!(await becomesVisible(heading, 15000))) {
		test.skip(
			true,
			'Case Type Management admin section not present in the deployed build',
		)
		return false
	}

	const row = page.locator('.viewTableRow, tr[role="row"], .list-item').first()
	if (!(await becomesVisible(row))) {
		test.skip(
			true,
			'No case types in the deployed/seeded register — the Workflow tab is data-dependent.',
		)
		return false
	}
	await row.click().catch(() => {})

	const workflowTab = page
		.locator('.case-type-detail__tab', { hasText: 'Workflow' })
		.first()
	if (!(await becomesVisible(workflowTab))) {
		test.skip(
			true,
			'Workflow tab not present on the case type detail page (deploy mismatch).',
		)
		return false
	}
	await workflowTab.click()
	await page.waitForTimeout(500)
	return true
}

test.describe('Visual workflow editor canvas (workflow-editor-integration)', () => {
	// @e2e openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#scenario-the-canvas-renders-an-existing-definitions-steps-and-transitions
	test('the Workflow tab renders the canonical canvas or its empty state', async ({
		page,
	}) => {
		const opened = await openFirstCaseTypeWorkflowTabOrSkip(page)
		if (!opened) return

		// Either an existing workflow's canvas (`.workflow-editor`) or the
		// "no workflow defined yet" empty state with a "Create workflow"
		// control is a valid render — both are the single canonical
		// `WorkflowEditor.vue` surface (via `WorkflowTab.vue`), not the
		// deleted `@vue-flow`-based duplicate.
		const canvas = page.locator('.workflow-editor')
		const emptyState = page.locator('.workflow-tab__empty')
		await expect(canvas.or(emptyState)).toBeVisible({ timeout: 10000 })
	})

	// @e2e openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#scenario-keyboard-operable-canvas
	test('a status node on the canvas is keyboard-focusable and exposes a keyboard-operable actions menu', async ({
		page,
	}) => {
		const opened = await openFirstCaseTypeWorkflowTabOrSkip(page)
		if (!opened) return

		const node = page.locator('.workflow-node').first()
		if (!(await becomesVisible(node, 5000))) {
			test.skip(
				true,
				'No workflow definition with status nodes for this case type — canvas keyboard interaction is data-dependent.',
			)
			return
		}

		await expect(node).toHaveAttribute('role', 'button')
		await expect(node).toHaveAttribute('tabindex', '0')

		// The actions menu (Connect to…/Disconnect from…/Add step/Delete
		// status) is a separate focusable control from the node's own
		// select handler — open it via keyboard alone and verify at least
		// one visible-text menu item renders, then cancel without
		// selecting (no mutation committed against live data).
		const actionsTrigger = node.locator('.workflow-node__actions button').first()
		await expect(actionsTrigger).toBeVisible()
		await actionsTrigger.focus()
		await page.keyboard.press('Enter')
		const firstMenuItem = page.getByRole('menuitem').first()
		await expect(firstMenuItem).toBeVisible({ timeout: 5000 })
		await page.keyboard.press('Escape')
	})

	// @e2e openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#scenario-keyboard-operable-canvas
	test('the palette exposes a keyboard-reachable "Add status node" button as a drag-and-drop alternative', async ({
		page,
	}) => {
		const opened = await openFirstCaseTypeWorkflowTabOrSkip(page)
		if (!opened) return

		const canvas = page.locator('.workflow-editor')
		if (!(await becomesVisible(canvas, 5000))) {
			test.skip(
				true,
				'No workflow defined for this case type yet — the palette only renders alongside the canvas.',
			)
			return
		}

		// Focusable native <button> (not click-triggered — adding a status
		// node persists a real statusType object immediately, so this test
		// proves reachability without committing a mutation).
		const addStatusButton = page.getByRole('button', { name: 'Add status node' })
		await expect(addStatusButton).toBeVisible({ timeout: 5000 })
		await addStatusButton.focus()
		await expect(addStatusButton).toBeFocused()
	})
})
