/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for kanban-board-keyboard-status-transition
 * (WCAG 2.1.1 Keyboard fix on the Workflow Board's status-move control).
 *
 * ⚠️ WHY THIS FILE SEEDS ITS OWN DATA
 * -----------------------------------
 * Both tests here used to open the board, look for a `.case-card`, find none,
 * and `test.skip(true, 'No cases on the Workflow Board …')`. That reason was
 * TRUE — and that is exactly the problem. The board is a data-dependent
 * surface: `WorkflowBoard.fetchData()` builds one column per NON-FINAL
 * statusType and then groups cases into a column by resolving `case.status`
 * to that statusType's NAME. With no statusTypes and no cases in the target
 * register there is nothing to render, so the assertions below never ran —
 * on CI they had never run at all.
 *
 * A skip that is permanently true is an invisible pass under L8: the tests report
 * "not applicable" rather than "untested", and the skip count hides them.
 * The fix is therefore a FIXTURE change, not a timing change: seed the same
 * shape `workflows/case-lifecycle.spec.ts` seeds — `seedStateMachine()` for a
 * caseType + three ordered statusTypes + an active workflowTemplate, then
 * `seedCase()` for the cards — and then ASSERT, with no escape hatch. If the
 * board does not render the seeded card, that is a failure, and it should be.
 *
 * `case-lifecycle.spec.ts:185` ("the workflow board renders a column per
 * status type with real case rows") passes in CI using precisely this fixture,
 * so the shape is known-good; it is the one this file adopts rather than a
 * new one.
 *
 * Every seeded object carries `RUN_PREFIX` in a human-visible field, so the
 * assertions target THIS run's card (never another run's or an instance's
 * demo data) and `afterAll` deletes exactly what this run created.
 *
 * Note: navigation is `page.goto('/index.php/apps/dossiq/workflow-board')` —
 * the identical path `spec-coverage/workflow-operations.spec.ts:18` uses to
 * reach the same board, and that test passes.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'
import type { StateMachine } from '../helpers/fixtures.ts'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	getRequestToken,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
} from '../helpers/fixtures.ts'
import { dismissSupportDialog } from '../helpers/nav.ts'

/** Title of the card both tests drive. Carries RUN_PREFIX for isolation. */
const CARD_TITLE = `${RUN_PREFIX} Kanban card`

let api: APIRequestContext
let token: string
let sm: StateMachine

test.describe('Workflow Board keyboard status transition', () => {
	// ⚠️ DELIBERATELY NOT `test.describe.configure({ mode: 'serial' })`.
	// These two tests share only the beforeAll fixture; neither depends on the
	// other's side effects, so serial mode buys nothing — and it costs the one
	// thing this file exists to fix. MEASURED, not assumed: with serial mode
	// on, a failure in the first test marks the second `did not run`, which the
	// report records as **outcome "skipped" with NO annotation at all** — a
	// skip carrying no reason whatsoever, which is strictly worse than the
	// false-reason skips this change removes. Off serial, a real failure
	// reports as a failure in both.
	test.beforeAll(async ({ baseURL }) => {
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		// caseType + Ontvangen/In behandeling (non-final) + Afgehandeld (final)
		// + an active workflowTemplate. Two non-final statusTypes is the
		// minimum the move control needs: CaseCard renders its NcActions only
		// when `otherColumns.length > 0`, i.e. when a card has somewhere to go.
		sm = await seedStateMachine(api, token)
		await seedCase(api, token, {
			title: CARD_TITLE,
			caseType: sm.caseTypeId,
			status: sm.statusReceived,
		})
	})

	test.afterAll(async () => {
		// Everything this run produced goes, child-first: the statusRecords the
		// transition engine wrote, then the case, then the machine it belongs to.
		//
		// This afterAll used to leave the whole machine standing, and the note
		// it carried was right about the cause. The `case` schema is archival
		// (`x-openregister-archival`), so a user-driven DELETE is refused with
		// 403 SCHEMA_ARCHIVAL_IMMUTABLE — `workflows/cases-crud.spec.ts` asserts
		// exactly that, and it passes — while the old `deleteObject` never
		// inspected the response and reported success on removing NOTHING.
		// Deleting the (non-archival) caseType and statusTypes on top of that
		// left the surviving case pointing at ids that no longer resolved:
		// the dashboard's grouped aggregations still returned the orphan's group
		// keys, the chart widget resolved each key by id, and those lookups
		// 404'd. That is what reddened `spec-coverage/ui-pages.spec.ts:55`
		// ("dashboard mounts without dossiq console errors") on a second run —
		// a test that was doing its job.
		//
		// `helpers/fixtures.ts#purgeObject` removes the case for real, through
		// the sanctioned `occ openregister:objects:purge --force --apply`, so
		// there is no longer a surviving parent whose references have to be
		// preserved, and no residue to carry into the next run.
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Open the Workflow Board and return the seeded case's card.
	 *
	 * Scoped by `hasText: CARD_TITLE` rather than `.case-card` first(): on an
	 * instance that already holds cases, `.first()` would drive somebody
	 * else's card and the test would be asserting about data it did not
	 * create.
	 *
	 * @param page The page.
	 * @return The `.case-card` element rendering the seeded case.
	 */
	async function openBoardAndFindSeededCard(page: Page): Promise<Locator> {
		await page.goto('/index.php/apps/dossiq/workflow-board')
		await dismissSupportDialog(page)

		// The board renders its heading unconditionally; the columns and cards
		// arrive after fetchData() resolves three collections.
		await expect(
			page.getByRole('heading', { name: /Workflow Board/ }).first(),
		).toBeVisible({ timeout: 15000 })
		// The seeded non-final column must exist, or there is nowhere for a
		// card to be grouped — assert it separately so a missing column does
		// not present as "the card is missing".
		await expect(
			page.getByText(`${RUN_PREFIX} Ontvangen`, { exact: false }).first(),
		).toBeVisible({ timeout: 15000 })

		const card = page.locator('.case-card', { hasText: CARD_TITLE }).first()
		await expect(card).toBeVisible({ timeout: 15000 })
		return card
	}

	// @e2e openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#scenario-dash-v1-006d-keyboard-only-status-transition-new
	test('a case card exposes a keyboard-operable "Move to…" menu', async ({
		page,
	}) => {
		const card = await openBoardAndFindSeededCard(page)

		// The move-target menu trigger is reachable independent of the card's
		// own click-to-open handler (a separate focusable NcActions control).
		const moveTrigger = card.locator('.case-card__move-actions button').first()
		await expect(moveTrigger).toBeVisible()

		await moveTrigger.focus()
		await page.keyboard.press('Enter')
		const firstOption = page.getByRole('menuitem').first()
		await expect(firstOption).toBeVisible({ timeout: 5000 })
		// The offer is the OTHER seeded non-final column — proving the menu is
		// populated from the board's real column model, not an empty shell.
		await expect(
			page.getByRole('menuitem', {
				name: new RegExp(`${RUN_PREFIX} In behandeling`),
			}),
		).toBeVisible({ timeout: 5000 })

		// Do not actually commit a status change against a live board's data —
		// close the menu without selecting, proving the control opens via
		// keyboard alone without ever dispatching a drag/mouse event.
		await page.keyboard.press('Escape')
	})

	// @e2e openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#scenario-dash-v1-006e-drag-path-unchanged-new
	test('case cards remain draggable for mouse/touch users', async ({ page }) => {
		const card = await openBoardAndFindSeededCard(page)
		await expect(card).toHaveAttribute('draggable', 'true')
	})
})
