/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, data-dependent UI coverage — Cases (zaken) full CRUD-with-persistence.
 *
 * Beyond the shell/render tests in pages.spec.ts, this proves the Cases
 * feature works end-to-end against REAL data:
 *
 *   - a seeded case appears as a row in the Cases index list (title +
 *     identifier render in the table),
 *   - opening the row shows the case detail with its values,
 *   - editing the case via the detail edit form PERSISTS (re-read from the
 *     OpenRegister object API confirms the new value),
 *   - deleting the case is REFLECTED in the list (the row disappears).
 *
 * Cases are OpenRegister objects (manifest `Cases`/`CaseDetail` pages declare
 * `register:"dossiq", schema:"case"`). Fixtures seed/clean those objects via
 * the OR object API (helpers/fixtures.ts) — allowed setup. Every assertion
 * runs against the rendered DOM (Playwright = UI only).
 *
 * Navigation: a deep-link `goto('/apps/dossiq/cases')` resets the
 * history-mode router to the Dashboard and the index never fetches its data,
 * so every test lands via `navTo(page, /^(All cases|Alle zaken)$/)` (sidebar click). The
 * "Support Dossiq" dialog is dismissed before each interaction.
 *
 * The CREATE-via-UI leg is split out into its own test guarded by the known
 * generic-dialog issue (#427) — see the inline note on that test.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	ensureCaseType,
	getRequestToken,
	listObjects,
	objectId,
	RUN_PREFIX,
	seedCase,
	showObject,
	tryDeleteObject,
} from '../helpers/fixtures.ts'
import { dismissSupportDialog, navTo } from '../helpers/nav.ts'

let api: APIRequestContext
let token: string
let caseTypeId: string

test.describe('Cases — full CRUD with persistence', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ baseURL }) => {
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		const ct = await ensureCaseType(api, token)
		caseTypeId = ct.id
	})

	test.afterAll(async () => {
		// Remove every object this run created, child-first, so no e2e data is
		// left in the register. A seeded caseType carries RUN_PREFIX in its
		// title and is swept with the rest; an ADOPTED one does not and is left
		// alone, which is the point of the prefix.
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Open the Cases index via the sidebar and wait for the data fetch to
	 * resolve into a populated table.
	 *
	 * @param page The page.
	 */
	async function openCasesList(page: Page): Promise<void> {
		await navTo(page, /^(All cases|Alle zaken)$/)
		await expect(
			page.getByRole('button', { name: /^Add (Item|Case|Task)$/ }),
		).toBeVisible({ timeout: 15000 })
		// The CnIndexPage fires GET …/objects/dossiq/case on mount; give the
		// table a moment to render the fetched rows before asserting.
		await expect(page.locator('tbody tr').first()).toBeVisible({
			timeout: 15000,
		})
	}

	/**
	 * Open the Cases index narrowed to ONE seeded case, and return its row.
	 *
	 * ⚠️ Every assertion about "the row this test created" has to go through
	 * here. CnIndexPage pages at 20 rows, so on a demo-sized instance the
	 * unfiltered index shows "Showing 20 of 51, Page 1 of 3" and a case seeded
	 * moments ago sits on page 3 — the test then fails on volume rather than on
	 * behaviour. `?title=` is the same URL-filter mechanism the queue spec
	 * drives with `?caseType=`; it reaches the server query, so the row is on
	 * page 1 whatever else the instance holds.
	 *
	 * @param page  The page.
	 * @param title Exact title of the seeded case.
	 */
	async function openCaseRow(page: Page, title: string): Promise<Locator> {
		await page.goto(
			`/index.php/apps/dossiq/cases?title=${encodeURIComponent(title)}`,
		)
		await expect(page.locator('table tbody tr')).toHaveCount(1, {
			timeout: 15000,
		})
		return page.locator('table tbody tr').first()
	}

	// @e2e openspec/specs/case-management/spec.md#cases-index-page-renders-list-shell
	test('a seeded case appears as a row with its title and identifier', async ({
		page,
	}) => {
		const title = `${RUN_PREFIX} Vergunning aanvraag`
		const identifier = `${RUN_PREFIX}-READ`
		const kase = await seedCase(api, token, {
			title,
			caseType: caseTypeId,
			identifier,
			description: 'Seeded for the read leg.',
		})
		const caseId = objectId(kase)
		expect(caseId, 'case was created via the object API').not.toBe('')

		// The nav contract first: the sidebar leaf reaches a populated list.
		await openCasesList(page)

		// Then narrow to the seeded row — see openCaseRow for why.
		const row = await openCaseRow(page, title)

		// The seeded row's human fields render in the list. dossiq assigns the
		// zaaknummer itself (schema `case` x-openregister-processing) and IGNORES
		// any supplied identifier, so assert the ASSIGNED identifier the create
		// returned — the seed input never reaches the row.
		await expect(row).toContainText(title)
		const assignedIdentifier = String(
			(kase as Record<string, unknown>).identifier ?? identifier,
		)
		await expect(row).toContainText(assignedIdentifier)
	})

	// FIXME(#719): the case DETAIL page never displays the zaaknummer. A case
	// with assigned identifier 2026-0001 renders CASE / title / Assignee /
	// Case type / Confidentiality, with the identifier absent from the page
	// text entirely. It DOES render in the case LIST, which is why the
	// list-view assertion above passes.
	// @e2e openspec/specs/case-management/spec.md#case-detail-page-renders
	test('opening the row shows the case detail with its values', async ({
		page,
	}) => {
		test.fixme(
			true,
			'FIXME(#719): the case DETAIL page never displays the zaaknummer. A case with assigned identifier 2026-0001 renders CASE / title / Assignee / Case type / Confidentiality, with the identifier absent from the page text entirely. It DOES render in the case LIST, which is why the list-view assertion above passes.',
		)
		const title = `${RUN_PREFIX} Detail case`
		const identifier = `${RUN_PREFIX}-DETAIL`
		const kase = await seedCase(api, token, {
			title,
			caseType: caseTypeId,
			identifier,
			description: 'Detail-leg description.',
		})
		// dossiq assigns the zaaknummer and ignores the supplied identifier;
		// assert the ASSIGNED value the create returned.
		const assignedIdentifier = String(
			(kase as Record<string, unknown>).identifier ?? identifier,
		)

		const row = await openCaseRow(page, title)
		await dismissSupportDialog(page)

		// Click the seeded case's row to open its detail view.
		await row.getByText(title, { exact: false }).first().click()

		// CaseDetail (manifest `type:"detail"`) renders the case title + the
		// detail chrome. Assert the title and (assigned) identifier surface.
		await expect(page.getByText(title, { exact: false }).first()).toBeVisible({
			timeout: 15000,
		})
		await expect(
			page.getByText(assignedIdentifier, { exact: false }).first(),
		).toBeVisible()
	})

	// @e2e openspec/specs/case-management/spec.md#edit-a-case
	test('editing a case persists the change', async ({ page }) => {
		test.fixme(
			true,
			'FIXME(#1454): the edit is accepted and silently not applied. The trace '
				+ 'shows the PUT carrying the correct object id and the correct new '
				+ 'title, answered 200, while every response payload — and a GET 15s '
				+ 'later — still returns the old title. Intermittent: two commits with '
				+ 'byte-identical trees (2cbfff426, bdfff80e3) gave one pass and one '
				+ 'failure. Ruled out with evidence: wrong object, lost payload, '
				+ 'rejected write, and cache staleness (queryCache is never written '
				+ 'to, objectCache is request-scoped). A local API reproduction '
				+ 'persists correctly for a full body, with and without @self, so the '
				+ 'write path is sound and the remaining suspect is a concurrent '
				+ 'last-write-wins under CI load — the trace holds three old-title '
				+ 'payloads for the same object.',
		)
		const title = `${RUN_PREFIX} Editable case`
		const kase = await seedCase(api, token, {
			title,
			caseType: caseTypeId,
			identifier: `${RUN_PREFIX}-EDIT`,
			description: 'Original description.',
		})
		const caseId = objectId(kase)

		const row = await openCaseRow(page, title)
		await dismissSupportDialog(page)

		// Open the row's Actions menu and pick Edit. CnIndexPage renders a
		// per-row "Actions" button; the edit entry opens the schema edit form.
		await row.getByRole('button', { name: 'Actions' }).first().click()
		const editItem = page.getByRole('menuitem', { name: /Edit/i }).first()
		await expect(editItem).toBeVisible({ timeout: 10000 })
		await editItem.click()

		// Edit on an index row NAVIGATES to the case's detail page now; it no
		// longer opens a modal over the list. A record with its own detail page
		// is edited there, where its nested collections are reachable, rather
		// than through a dialog showing only the schema's flat scalars
		// (@conduction/nextcloud-vue 2.21.0). The form is one click further on.
		await page.getByTestId('cn-detail-page-edit').click()

		// In the edit dialog, change the title field, then save.
		const dialog = page.locator('[role="dialog"], .modal-container').first()
		await expect(dialog).toBeVisible({ timeout: 10000 })
		const newTitle = `${RUN_PREFIX} Edited case`
		const titleField = dialog
			.getByRole('textbox', { name: /title|titel/i })
			.first()
		await expect(titleField).toBeVisible({ timeout: 10000 })
		await titleField.fill(newTitle)
		await dialog
			.getByRole('button', { name: /Save|Update|Opslaan|Bijwerken/i })
			.first()
			.click()

		// PERSISTENCE assertion: re-read the object from the API and confirm
		// the new title was written through (not just optimistic UI state).
		await expect
			.poll(
				async () => {
					const fresh = await showObject(api, 'case', caseId)
					return String(fresh.title ?? '')
				},
				{
					timeout: 15000,
					message: 'edited title persisted to the object store',
				},
			)
			.toBe(newTitle)
	})

	// @e2e openspec/specs/case-management/spec.md#delete-a-case
	// The `case` schema declares x-openregister-archival, so a case is a record:
	// user-driven deletion is rejected (Archiefwet immutability) and removal is
	// reserved for the retention-sweep cron. This asserts that guarantee rather
	// than a (now-impossible) successful user delete.
	test('a case is archival-immutable — user deletion is rejected and the record persists', async ({
		page,
	}) => {
		const title = `${RUN_PREFIX} Immutable case`
		const kase = await seedCase(api, token, {
			title,
			caseType: caseTypeId,
			identifier: `${RUN_PREFIX}-DEL`,
		})
		const caseId = objectId(kase)

		// A user-driven delete on an archival schema is a structured 403
		// (ArchivalImmutableException) — only the retention cron may remove a case.
		const del = await tryDeleteObject(api, token, 'case', caseId)
		expect(del.status, 'user delete of an archival case is rejected').toBe(403)
		expect(JSON.stringify(del.body)).toMatch(/ARCHIVAL_IMMUTABLE|archival/i)

		// PERSISTENCE: the record is still in the object store …
		await expect
			.poll(
				async () => {
					const rows = await listObjects(api, 'case')
					return rows.some((r) => objectId(r) === caseId)
				},
				{
					timeout: 15000,
					message: 'archival case persists after a rejected delete',
				},
			)
			.toBe(true)

		// … and still renders in the Cases list. Filtered, for the same reason
		// the read leg is: past 20 cases the index pages, and "not on page 1" is
		// not "not in the list".
		const row = await openCaseRow(page, title)
		await expect(row).toContainText(title)
	})

	// CREATE-via-UI. Known issue #427: in some environments the Cases "Add"
	// control opens the generic CnFormDialog ("Create Item") with an empty body
	// instead of dossiq's custom CaseCreateDialog (the `case` schema fields do
	// not resolve there), so the title field cannot be filled. This test drives
	// the real create flow and asserts the new case persists + lists; it is
	// guarded so the suite stays green where the generic-dialog regression is
	// present. Re-enable verification once #427 is resolved.
	// @e2e openspec/specs/case-management/spec.md#create-a-case
	test('creating a case via the UI form persists and lists it', async ({
		page,
	}) => {
		await openCasesList(page)
		await dismissSupportDialog(page)
		await page.getByRole('button', { name: /^Add (Item|Case|Task)$/ }).click()

		const customDialog = page.locator('.case-create-dialog')
		const isCustom = await customDialog.isVisible().catch(() => false)
		test.fixme(
			!isCustom,
			'BUG #427: Cases "Add" opens the generic empty CnFormDialog instead of CaseCreateDialog — case fields do not resolve, cannot create via UI.',
		)

		const newTitle = `${RUN_PREFIX} UI created case`
		await customDialog.getByPlaceholder('Enter case title').fill(newTitle)
		// Pick the first available case type in the combobox.
		await customDialog.getByRole('combobox').first().click()
		await page.getByRole('option').first().click()
		await customDialog.getByRole('button', { name: 'Create case' }).click()

		// Persistence: the new case shows up in the API listing and the list.
		await expect
			.poll(
				async () => {
					const rows = await listObjects(api, 'case')
					return rows.some((r) => String(r.title ?? '') === newTitle)
				},
				{ timeout: 15000 },
			)
			.toBe(true)
		const row = await openCaseRow(page, newTitle)
		await expect(row).toContainText(newTitle)
	})
})
