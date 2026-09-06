/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behavioural UI coverage for the operational views that sit alongside the
 * case lists: the Workflow Board (kanban of statuses), the Case Map, the
 * Transfers index, the Subsidies / Grant-schemes intake lists and the
 * Features & roadmap page. Each is reached via its sidebar nav entry and
 * asserted on its distinct rendered surface (heading / empty-state /
 * primary control) independently of seeded data.
 */

import { expect, test } from '@playwright/test'
import { navToRoute, trackDossiqErrors } from '../helpers/nav.ts'
// Routes named after the component that renders them, so this spec states
// WHICH screen it covers in executable code rather than in a comment.
import { CasesOnMapView, WorkflowBoard } from '../helpers/page-components.ts'

test.describe('Workflow Board page', () => {
	// @e2e openspec/specs/workflow-board/spec.md#workflow-board-renders-kanban-shell
	test('workflow board renders its heading and a status/empty surface', async ({
		page,
	}) => {
		// The nav label is "Workflow board" (lower-case b) and it sits inside
		// the collapsed "Work queue" group — navigate by route instead.
		await navToRoute(page, WorkflowBoard)
		// The board view renders its own header h2 inside `.workflow-board__header`
		// (the page also has a dashboard-wrapper title + widget title with the
		// same text, so scope to the board's own header).
		await expect(page.locator('.workflow-board__header h2')).toBeVisible({
			timeout: 15000,
		})
		// With no status types configured the board shows its guidance
		// empty-state; with statuses it renders one `.board-column` per status
		// type (the seeded register uses the Dutch ZGW status names —
		// "Received", … — each with a per-column "No cases" surface). Assert
		// the data- and locale-independent kanban surface: a status column or
		// the no-statuses guidance, never an error render.
		await expect(
			page.locator('.board-column, .workflow-board__empty').first(),
		).toBeVisible({ timeout: 10000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	// FIXED: WorkflowBoard.load() calls objectStore.fetchCollection('statusType')
	// / ('caseType'). Those types are registered in initializeStores()
	// (src/store/store.js), but the registration used to be skipped when the
	// app-config schema id (case_type_schema / status_type_schema) was blank —
	// which it is on a fresh OR register — leaving the types unregistered and
	// logging two dossiq-origin "Object type is not registered" console errors
	// per load while the kanban columns stayed empty. The same defect hit the
	// Doorlooptijd analytics view (caseType). store.js now falls back to the
	// canonical schema slug ('caseType' / 'statusType') when the config id is
	// empty, so the types are always registered and this contract holds.
	// @e2e openspec/specs/workflow-board/spec.md#workflow-board-loads-without-console-errors
	test('workflow board loads without dossiq console errors', async ({ page }) => {
		const errors = trackDossiqErrors(page)
		// The nav label is "Workflow board" (lower-case b) and it sits inside
		// the collapsed "Work queue" group — navigate by route instead.
		await navToRoute(page, WorkflowBoard)
		await expect(page.locator('.workflow-board__header h2')).toBeVisible({
			timeout: 15000,
		})
		await page.waitForTimeout(1500)
		expect(errors, errors.join('\n')).toEqual([])
	})
})

test.describe('Case Map page', () => {
	// @e2e openspec/specs/case-map/spec.md#case-map-renders-map-surface
	test('case map renders its heading and an interactive map surface', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		// Case Map has no top-level sidebar leaf after the nav-dedup pass; its
		// /map page route stays reachable, so navigate to it client-side.
		await navToRoute(page, CasesOnMapView)
		// The rendered heading is "Cases on map" — measured on a CI runner
		// (2026-08-04). "Case map" is the manifest page TITLE, not the heading
		// the view renders.
		await expect(
			page.getByRole('heading', { name: 'Cases on map' }),
		).toBeVisible({ timeout: 15000 })
		// Leaflet renders a tile/zoom container — assert the map pane exists
		// rather than any specific marker (data-independent).
		await expect(
			page.locator('.leaflet-container, [class*="map"]').first(),
		).toBeVisible({ timeout: 10000 })
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})

// Transfers list page removed — cases are transferred from their detail page
// (the TransferDetail route is kept for deep links). See
// feat(nav): streamline work queue.

test.describe('Subsidies intake page', () => {
	// FIXME(#719): /subsidies falls back to the GENERIC case index — measured
	// buttons are [Settings, Cards, Table, Add Case, Actions], i.e. "Add Case"
	// rather than any subsidy-specific create control, so there is no subsidy
	// intake shell to assert. (/subsidieregelingen renders "Add
	// Subsidieregeling" correctly and its test passes.)
	// @e2e openspec/specs/subsidy-intake/spec.md#subsidies-index-renders-list-shell
	test('subsidies index renders the subsidy intake list shell', async ({
		page,
	}) => {
		test.fixme(
			true,
			'FIXME(#719): /subsidies falls back to the GENERIC case index — measured buttons are [Settings, Cards, Table, Add Case, Actions], i.e. "Add Case" rather than any subsidy-specific create control, so there is no subsidy intake shell to assert. (/subsidieregelingen renders "Add Subsidieregeling" correctly and its test passes.)',
		)
		const errors = trackDossiqErrors(page)
		// "Subsidies" is a group header with no label in the subsidie manifest
		// fragment, so it renders no clickable nav entry — navigate by route.
		await navToRoute(page, '/subsidies')
		// View switcher renders as buttons, not radios.
		await expect(page.getByRole('button', { name: 'Cards' })).toBeVisible({
			timeout: 15000,
		})
		await expect(page.getByRole('button', { name: 'Table' })).toBeVisible()
		// Subsidy-specific create control distinguishes this from other lists.
		await expect(
			page.getByRole('button', { name: /^Add Subsidie/ }),
		).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})

test.describe('Grant schemes are administered as case types', () => {
	// UPDATED BY subsidieregeling-is-a-casetype. This used to assert that
	// /subsidieregelingen rendered its own index. A grant scheme IS a case type
	// — four of its properties were caseType fields under another name — so the
	// bespoke page retired and schemes are administered on the Case types index.
	//
	// Asserting BOTH halves. The absence check alone would pass just as happily
	// on a build where the capability vanished entirely, which is what ADR-044
	// Decision 5 forbids.
	//
	// @e2e openspec/changes/subsidieregeling-is-a-casetype/proposal.md
	test('the retired scheme index is gone and Case types took it over', async ({
		page,
	}) => {
		await navToRoute(page, '/subsidieregelingen')
		await expect(
			page.getByRole('button', { name: 'Add Subsidieregeling' }),
		).toHaveCount(0)
		await expect(page.locator('body')).not.toContainText('Internal Server Error')

		await navToRoute(page, '/settings/case-types')
		await expect(
			page.getByRole('button', { name: /Add|New|Create/i }).first(),
		).toBeVisible({ timeout: 20000 })
	})
})

test.describe('Features & roadmap page', () => {
	// @e2e openspec/specs/features-roadmap/spec.md#features-page-renders-controls
	test('features & roadmap renders heading and its action controls', async ({
		page,
	}) => {
		const errors = trackDossiqErrors(page)
		await navToRoute(page, '/features-roadmap')
		await expect(
			page.getByRole('heading', { name: 'Features' }).first(),
		).toBeVisible({ timeout: 15000 })
		await expect(
			page.getByRole('button', { name: 'Show roadmap' }),
		).toBeVisible()
		await expect(
			// A LINK, not a button. nextcloud-vue 2.36.4 removed the in-product
			// suggestion modal (team decision 2026-09-04: the forge is where the
			// conversation happens), and the CTA is an anchor to the forge's
			// feature-request issue form now. An `<a href>` has role `link`.
			page.getByRole('link', { name: 'Suggest feature' }),
		).toBeVisible()
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		expect(errors, errors.join('\n')).toEqual([])
	})
})
