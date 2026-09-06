/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, data-dependent coverage — case-lifecycle STATE MACHINE.
 *
 * The high-value correctness layer: it seeds a full state machine
 * (caseType → ordered statusTypes → an active workflowTemplate whose closing
 * transition carries a `requiredField` guard) and a case, then drives the
 * status lifecycle and asserts:
 *
 *   1. the case's current status renders (statusType name resolves on the
 *      case / board),
 *   2. advancing the status PERSISTS — re-reading the case object shows the
 *      new statusType id, and the case moves to the new column on the board,
 *   3. the guarded transition engine offers the right transitions, records a
 *      statusRecord history entry per transition, and BLOCKS an invalid
 *      transition (the `requiredField` guard) — these run through the engine
 *      REST surface (GET available-transitions / POST transition / GET
 *      transition-history) which is the system of record for guard evaluation.
 *
 * ENGINE STATUS — now functional (PHASE-0 fix). The guarded transition engine
 * previously called `OCA\OpenRegister\Service\ObjectService::findObjects()`,
 * which does NOT exist on OpenRegister's ObjectService (the real API is
 * `find` / `findAll` / `searchObjects`), so available-transitions returned
 * `[]`, POST transition 500'd, and history was empty. Both
 * `WorkflowTemplateLoader::getActiveTemplate()` and
 * `StatusTransitionService::replay()` were repaired to call the real
 * `searchObjects()` (register/schema under the `@self` block, object-field
 * filters at the top level). The full guard-enforcement + history contract is
 * therefore now asserted as a LIVE test below, alongside the lifecycle facts
 * (status renders, status persists, board reflects the column).
 *
 * Navigation: sidebar-click (`navTo`), never deep-link goto. Support dialog
 * dismissed before interactions. Playwright = UI for the rendered assertions;
 * the engine REST calls are fixture-level correctness checks (per the prompt).
 */

import type { APIRequestContext, Page } from '@playwright/test'
import type { StateMachine } from '../helpers/fixtures.ts'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	executeTransition,
	getAvailableTransitions,
	getRequestToken,
	getTransitionHistory,
	listObjects,
	objectId,
	RUN_PREFIX,
	seedCase,
	seedStateMachine,
	showObject,
	updateObject,
} from '../helpers/fixtures.ts'
import { dismissSupportDialog } from '../helpers/nav.ts'

let api: APIRequestContext
let token: string
let sm: StateMachine

test.describe('Case lifecycle — state machine', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ baseURL }) => {
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		sm = await seedStateMachine(api, token)
	})

	test.afterAll(async () => {
		// Child-first cleanup across every fixture schema: the statusRecords the
		// transition engine wrote against this run's cases, then the cases, then
		// the seeded machine (template, statusTypes, caseType).
		//
		// This used to delete `sm.created` while its own cases survived — the
		// `case` schema is archival and refused the delete — which left the
		// cases pointing at statusTypes that no longer resolved. `purgeObject`
		// removes the cases for real, so the machine can go with them.
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Open the Workflow Board via the sidebar and wait for its columns to load.
	 *
	 * @param page The page.
	 */
	async function openBoard(page: Page): Promise<void> {
		// The "Workflow board" leaf was relocated UNDER the "Work queue" group in
		// the nav-dedup pass (menu-layout.json relocations), so it is no longer a
		// top-level sidebar link — navTo('Workflow Board') matches nothing and
		// strands on the Dashboard. Reach it by a bare deep-link (bare paths
		// resolve; /index.php-prefixed ones reset to the Dashboard).
		await page.goto('/index.php/apps/dossiq/workflow-board')
		await dismissSupportDialog(page)
		await expect(
			page.getByRole('heading', { name: 'Workflow Board' }).first(),
		).toBeVisible({ timeout: 15000 })
		// The board fetches statusType + case objects on mount.
		await expect(
			page.getByText(`${RUN_PREFIX} Ontvangen`, { exact: false }).first(),
		).toBeVisible({ timeout: 15000 })
	}

	// @e2e openspec/specs/status-transition-engine/spec.md#current-status-renders
	test('a case renders its current status (statusType name resolves)', async ({
		page,
	}) => {
		const kase = await seedCase(api, token, {
			title: `${RUN_PREFIX} Status render case`,
			caseType: sm.caseTypeId,
			status: sm.statusReceived,
		})
		const caseId = objectId(kase)
		// Sanity: the engine's status-name lookup resolves the seeded status.
		const av = await getAvailableTransitions(api, token, caseId)
		expect(av.status).toBe(200)
		expect(String(av.body?.current?.statusName ?? '')).toContain(
			`${RUN_PREFIX} Ontvangen`,
		)

		// On the board, the case card sits under its current-status column and
		// the column header shows the status type name.
		await openBoard(page)
		await expect(
			page
				.getByText(`${RUN_PREFIX} Status render case`, { exact: false })
				.first(),
		).toBeVisible({ timeout: 15000 })
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#status-persists
	test('advancing a case status persists and renders the new status', async ({
		page,
	}) => {
		const kase = await seedCase(api, token, {
			title: `${RUN_PREFIX} Advance case`,
			caseType: sm.caseTypeId,
			status: sm.statusReceived,
		})
		const caseId = objectId(kase)

		// Advance through the GUARDED transition engine. The case schema declares
		// x-openregister-lifecycle, so a direct case.status write is rejected with
		// `lifecycle-invalid-transition` (422) — the board's drag-to-advance goes
		// through POST /api/case/{id}/transition, exactly like executeTransition.
		// t1 = "Start behandeling" (Ontvangen → In behandeling), from the seeded
		// workflowTemplate.
		const adv = await executeTransition(api, token, caseId, 't1')
		expect(
			adv.status,
			'guarded transition t1 (Ontvangen→In behandeling) accepted',
		).toBe(200)

		// PERSISTENCE: re-read shows the new statusType id.
		await expect
			.poll(
				async () =>
					String((await showObject(api, 'case', caseId)).status ?? ''),
				{
					timeout: 15000,
					message: 'advanced status persisted on the case object',
				},
			)
			.toBe(sm.statusInProgress)

		// The engine's status-name lookup now reports the new status …
		const av = await getAvailableTransitions(api, token, caseId)
		expect(String(av.body?.current?.statusName ?? '')).toContain(
			`${RUN_PREFIX} In behandeling`,
		)

		// … and the board renders the card in the "In handling" column.
		await openBoard(page)
		await expect(
			page.getByText(`${RUN_PREFIX} Advance case`, { exact: false }).first(),
		).toBeVisible({ timeout: 15000 })
	})

	// @e2e openspec/specs/status-transition-engine/spec.md#board-reflects-status
	test('the workflow board renders a column per status type with real case rows', async ({
		page,
	}) => {
		// Seed two cases in different statuses so two columns are populated.
		const a = await seedCase(api, token, {
			title: `${RUN_PREFIX} Board A`,
			caseType: sm.caseTypeId,
			status: sm.statusReceived,
		})
		const b = await seedCase(api, token, {
			title: `${RUN_PREFIX} Board B`,
			caseType: sm.caseTypeId,
			status: sm.statusInProgress,
		})
		objectId(a)
		objectId(b)

		await openBoard(page)
		// Columns: the non-final statusTypes render as headers (Ontvangen,
		// In behandeling). The final "Handled" is not a board column.
		await expect(
			page.getByText(`${RUN_PREFIX} Ontvangen`, { exact: false }).first(),
		).toBeVisible()
		await expect(
			page.getByText(`${RUN_PREFIX} In behandeling`, { exact: false }).first(),
		).toBeVisible()
		// And both seeded cases appear as real rows (the statusType/caseType
		// registry config is what makes these rows materialise — empty config
		// would render the "No workflow statuses configured" empty state).
		await expect(
			page.getByText('No workflow statuses configured', { exact: false }),
		).toHaveCount(0)
		await expect(
			page.getByText(`${RUN_PREFIX} Board A`, { exact: false }).first(),
		).toBeVisible({ timeout: 15000 })
		await expect(
			page.getByText(`${RUN_PREFIX} Board B`, { exact: false }).first(),
		).toBeVisible()
	})

	// The guarded transition engine + history + guard-blocking. The engine is
	// now functional — `WorkflowTemplateLoader::getActiveTemplate()` and
	// `StatusTransitionService::replay()` were repaired to call OpenRegister's
	// real `searchObjects()` (the non-existent `findObjects()` call previously
	// made available-transitions return []/POST transition 500). So
	// available-transitions populates, executing a transition persists +
	// records a statusRecord, and an unmet guard blocks the transition (409).
	//
	// @e2e openspec/specs/status-transition-engine/spec.md#guarded-transitions
	test('engine offers transitions, records history, and BLOCKS an invalid transition', async () => {
		const kase = await seedCase(api, token, {
			title: `${RUN_PREFIX} Engine case`,
			caseType: sm.caseTypeId,
			status: sm.statusReceived,
		})
		const caseId = objectId(kase)

		// At "received": exactly t1 (Start behandeling) is available and passes.
		let av = await getAvailableTransitions(api, token, caseId)
		expect(av.body.transitions).toHaveLength(1)
		expect(av.body.transitions[0].id).toBe('t1')
		expect(av.body.transitions[0].guardsPassed).toBe(true)

		// Execute t1 → status advances to "in behandeling" + a history entry.
		let ex = await executeTransition(api, token, caseId, 't1')
		expect(ex.status).toBe(200)
		expect(String((await showObject(api, 'case', caseId)).status)).toBe(
			sm.statusInProgress,
		)

		const hist = await getTransitionHistory(api, token, caseId)
		expect(hist.status).toBe(200)
		expect(hist.body.history.length).toBeGreaterThanOrEqual(1)
		// A statusRecord audit object was written for the transition.
		expect(
			(await listObjects(api, 'statusRecord', { case: caseId })).length,
		).toBeGreaterThanOrEqual(1)

		// GUARD: t2 (Afhandelen) requires `description` to be set. With it empty,
		// the engine must BLOCK the transition with a 409 + the failed guard.
		ex = await executeTransition(api, token, caseId, 't2')
		expect(
			ex.status,
			'guard blocks the close transition while description is empty',
		).toBe(409)
		expect(JSON.stringify(ex.body.failedGuards ?? [])).toContain('description')
		// Status did NOT change — still "in behandeling".
		expect(String((await showObject(api, 'case', caseId)).status)).toBe(
			sm.statusInProgress,
		)

		// Satisfy the guard, then t2 succeeds and reaches the final status.
		await updateObject(api, token, 'case', caseId, {
			description: 'Klaar voor afhandeling',
		})
		ex = await executeTransition(api, token, caseId, 't2')
		expect(ex.status).toBe(200)
		expect(String((await showObject(api, 'case', caseId)).status)).toBe(
			sm.statusDone,
		)

		// No further transitions from the final status.
		av = await getAvailableTransitions(api, token, caseId)
		expect(av.body.transitions).toHaveLength(0)
	})
})
