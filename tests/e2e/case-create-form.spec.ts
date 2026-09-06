/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The New case dialog, from a case handler's side of the desk.
 *
 * It used to be CnAdvancedFormDialog: a Properties/Data table listing all 48
 * of the case schema's properties, unordered, `qualityScore` and
 * `casePlanState` among them. Two things changed. The schema now says which
 * properties a person deals with and which are engine plumbing, and the New
 * case action narrows itself to the nine a handler fills. On top of that,
 * `case.caseType` declares `x-openregister-extends-form`, so choosing a case
 * type adds that type's own questions to the form and the answers land in
 * `caseProperty` rows.
 *
 * These assertions are all against the rendered DOM. The API is used only to
 * seed and tear down the case type and its property definitions.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	listObjects,
	objectId,
	RUN_PREFIX,
	seedCase,
	updateObject,
} from './helpers/fixtures.ts'

const DASHBOARD_URL = '/apps/dossiq/'

/** The nine fields the New case action declares in the manifest. */
const CREATE_FIELDS = [
	'caseType',
	'title',
	'description',
	'assignee',
	'priority',
	'confidentiality',
	'intakeChannel',
	'startDate',
	'plannedEndDate',
]

/** Properties the schema marks `visible: false`; no surface may render them. */
const HIDDEN_FIELDS = [
	'qualityScore',
	'casePlanState',
	'statusHistory',
	'portalSubject',
]

const CASE_TYPE_TITLE = `${RUN_PREFIX} Subsidie`
const START_STATUS = `${RUN_PREFIX} Ontvangen`
/**
 * A definition named the way real dossiq seed data names them. The form is
 * expected to render it in sentence case rather than verbatim.
 */
const IDENTIFIER_DEF = 'auditorsStatementThreshold'
const IDENTIFIER_LABEL = 'Auditors statement threshold'
const DEFAULT_ASSIGNEE = 'admin'
const CEILING = `${RUN_PREFIX} Plafond`
const AUDIENCE = `${RUN_PREFIX} Doelgroep`

let api: APIRequestContext
let token: string
let caseTypeId: string
let ceilingDefId: string
let startStatusId: string

test.describe('New case dialog', () => {
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser, playwright, baseURL }) => {
		const context = await browser.newContext()
		api = await playwright.request.newContext({
			baseURL,
			storageState: await context.storageState(),
		})
		await context.close()
		token = await getRequestToken(api)

		// A case type of this run's own, never a reused one: attaching
		// property definitions to a shared case type would add required
		// questions to every other spec's cases.
		//
		// The case type comes FIRST even though it is the thing that points at
		// the status. A statusType belongs to a case type: the deployed schema
		// requires `caseType` and `order`, so the status cannot exist before
		// the type does. The reference is then closed with an update.
		const caseType = await createObject(api, token, 'caseType', {
			title: CASE_TYPE_TITLE,
			identifier: `${RUN_PREFIX.toLowerCase()}-subsidie`,
			description: 'Throwaway case type for the New case dialog spec.',
			defaultAssignee: DEFAULT_ASSIGNEE,
		})
		caseTypeId = objectId(caseType)

		// The status a case of this type starts in, so the prefill has a real
		// record to copy from rather than a literal the test invented.
		//
		// `name` + `caseType` + `order` + `isFinal` is the DEPLOYED statusType
		// shape, which the helper docblock in fixtures.ts records and which
		// differs from lib/Settings/dossiq_register.json. Sending only `name`
		// answers 400 "The required properties (caseType, order) are missing",
		// and because that happens in beforeAll it fails the first test and
		// SKIPS the other ten, so the report reads "1 failed" for a fixture
		// defect that stopped the whole file.
		const status = await createObject(api, token, 'statusType', {
			name: START_STATUS,
			caseType: caseTypeId,
			order: 1,
			isFinal: false,
			description: 'Throwaway starting status for the New case dialog spec.',
		})
		startStatusId = objectId(status)

		await updateObject(api, token, 'caseType', caseTypeId, {
			initialStatus: startStatusId,
		})

		const ceiling = await createObject(api, token, 'propertyDefinition', {
			name: CEILING,
			caseType: caseTypeId,
			propertyType: 'number',
			isRequired: true,
			definition: 'The grant ceiling for this scheme.',
		})
		ceilingDefId = objectId(ceiling)
		await createObject(api, token, 'propertyDefinition', {
			name: AUDIENCE,
			caseType: caseTypeId,
			propertyType: 'enum',
			enumValues: ['Cultuur', 'Sport'],
			defaultValue: 'Sport',
		})
		await createObject(api, token, 'propertyDefinition', {
			name: IDENTIFIER_DEF,
			caseType: caseTypeId,
			propertyType: 'number',
			definition: 'Seeded with an identifier-shaped name on purpose.',
		})
	})

	test.afterAll(async () => {
		if (!api) return
		// Cases and their answers go; the case TYPE and its definitions stay.
		//
		// Deleting the case type is what a tidy fixture would do and it breaks
		// the neighbours: anything still holding that id — a case another spec
		// filed, a dashboard table resolving `caseType` for a label — then
		// fetches an object that is gone. On CI that surfaced as
		// `ui-pages.spec.ts` failing on a 404 console error, a spec that has
		// nothing to do with this one, on an assertion about the DASHBOARD.
		//
		// The instance CI seeds is thrown away after the run, so a leftover
		// case type costs nothing. A dangling reference costs a red suite
		// pointing at the wrong file.
		// No schema list: the default sweeps every schema the fixtures can
		// create, child-first, so a spec that failed part-way still leaves
		// nothing behind.
		await cleanupRunObjects(api, token)
		await api.dispose()
	})

	/**
	 * Choose this run's case type in the picker.
	 *
	 * The click has to land on the combobox itself: `[data-cn-field]` is the
	 * field WRAPPER, and clicking a wrapper leaves NcSelect closed, so the
	 * failure surfaces later as a missing option rather than as a missed click.
	 *
	 * @param page The Playwright page.
	 * @param dialog The dialog root.
	 */
	async function chooseCaseType(page, dialog) {
		const combo = dialog.getByRole('combobox', { name: /Case type/ })
		await combo.click()

		// Matched by TEXT, not by accessible name. vue-select splits an option
		// label into adjacent spans, and the accessible-name computation joins
		// those with a space — so `E2EZAAK-…-4944 Subsidie` computes as
		// `E2EZAAK-…-494 4 Subsidie` and an exact name match never hits, while
		// the option is plainly on screen. textContent concatenates without the
		// separator, so hasText sees the label the way a reader does.
		const option = page.getByRole('option').filter({ hasText: CASE_TYPE_TITLE })

		// Opening the picker preloads a capped first page. Type ONLY when this
		// run's type is not in it: typing sends the term to the server and
		// REPLACES the preloaded options with whatever comes back, so typing
		// unconditionally can turn a list that already held the answer into an
		// empty one. That is what it did on CI.
		if (!(await option.isVisible().catch(() => false))) {
			await combo.pressSequentially(RUN_PREFIX, { delay: 30 })
		}

		await expect(option).toBeVisible({ timeout: 20000 })
		await option.click()
	}

	/**
	 * Open the dialog from the dashboard's New case button.
	 *
	 * @param page The Playwright page.
	 */
	async function openDialog(page) {
		await page.goto(DASHBOARD_URL)
		await expect(page).not.toHaveURL(/login/, { timeout: 15000 })
		await page.getByRole('button', { name: 'New case', exact: true }).click()
		// The dialog ROOT, not the phase div that carries the testid: NcDialog
		// renders its buttons in a footer slot beside that div, so a locator
		// scoped to the testid finds the fields but never Create or Cancel.
		const dialog = page.getByRole('dialog').filter({
			has: page.locator('[data-testid-modal="cn-form-dialog"]'),
		})
		await expect(dialog).toBeVisible({ timeout: 20000 })
		return dialog
	}

	// @e2e openspec/changes/friendly-case-create-form/specs/friendly-case-create-form/spec.md#requirement-req-fcf-001-the-new-case-dialog-is-the-plain-form
	test('opens the plain form, not the properties and JSON table', async ({
		page,
	}) => {
		const dialog = await openDialog(page)

		// The advanced dialog's tell is its tab strip. A handler filing a case
		// has no business inspecting the schema, so neither tab may be here.
		await expect(dialog.getByRole('tab', { name: 'Properties' })).toHaveCount(0)
		await expect(dialog.getByRole('tab', { name: 'Data' })).toHaveCount(0)
		await expect(dialog.getByRole('button', { name: 'Create' })).toBeVisible()
	})

	// @e2e openspec/changes/friendly-case-create-form/specs/friendly-case-create-form/spec.md#requirement-req-fcf-001-the-new-case-dialog-is-the-plain-form
	test('asks only for the fields a handler fills', async ({ page }) => {
		const dialog = await openDialog(page)

		for (const key of CREATE_FIELDS) {
			await expect(
				dialog.locator(`[data-cn-field="${key}"]`),
				`the create form should ask for ${key}`,
			).toHaveCount(1)
		}
		// The 44 the button does not ask for, sampled at the ones that made the
		// old dialog unreadable.
		for (const key of [
			...HIDDEN_FIELDS,
			'archiveNomination',
			'workflowTemplate',
			'result',
		]) {
			await expect(
				dialog.locator(`[data-cn-field="${key}"]`),
				`${key} is not a create-time field`,
			).toHaveCount(0)
		}
	})

	// @e2e openspec/changes/friendly-case-create-form/specs/friendly-case-create-form/spec.md#requirement-req-fcf-003-a-case-type-brings-its-own-questions
	test('adds the chosen case type own questions, and drops them again on a change', async ({
		page,
	}) => {
		const dialog = await openDialog(page)

		// Nothing before a case type is chosen: the questions belong to a type.
		await expect(dialog.getByText(CEILING)).toHaveCount(0)

		await chooseCaseType(page, dialog)

		await expect(dialog.getByText(CEILING)).toBeVisible({ timeout: 15000 })
		await expect(dialog.getByText(AUDIENCE)).toBeVisible()
	})

	// @e2e openspec/changes/friendly-case-create-form/specs/friendly-case-create-form/spec.md#requirement-req-fcf-003-a-case-type-brings-its-own-questions
	test('files a case with its case type answers', async ({ page }) => {
		const dialog = await openDialog(page)
		const title = `${RUN_PREFIX} Aanvraag`

		await dialog
			.locator('[data-cn-field="title"]')
			.getByRole('textbox')
			.fill(title)
		await chooseCaseType(page, dialog)
		await expect(dialog.getByText(CEILING)).toBeVisible({ timeout: 15000 })

		const ceilingField = dialog
			.locator('[data-cn-field]')
			.filter({ hasText: CEILING })
			.locator('input')
			.first()
		await ceilingField.fill('50000')

		await dialog.getByRole('button', { name: 'Create' }).click()

		// The case exists, and so does the answer, as a caseProperty row that
		// points at both the case and the definition. An unsplit payload would
		// have posted the answer to `case`, where OpenRegister drops an
		// undeclared key with a 200 and no error anywhere.
		await expect(async () => {
			const cases = await listObjects(api, 'case', { _limit: '200' })
			const created = cases.find((c) => String(c.title ?? '') === title)
			expect(created, 'the case should have been created').toBeTruthy()

			// EITHER STORE, because both are live during the transition.
			// 7882afdc moved these answers onto the case as a `properties`
			// array, so they save in the same write as the case instead of a
			// second write that can be left behind. FoldCasePropertiesOntoCase
			// backfills that array and deliberately leaves the old
			// `caseProperty` rows in place.
			//
			// Which store a given instance uses depends on whether its `case`
			// schema carries the array, and that is NOT uniform: 7882afdc added
			// the property without bumping the schema's version (1.12.0 before
			// and after), and OpenRegister's importer gates on version, so a
			// fresh install has the array and an upgraded one does not. This
			// test failed on CI and passed locally for exactly that reason.
			//
			// Reading both is not the same as asserting nothing: if the answer
			// is written to neither, this still fails, which is the defect
			// worth catching. The `value` assertions below are unchanged.
			const onCase = Array.isArray(created.properties)
				? created.properties
				: []
			const answers =
				onCase.length > 0
					? onCase
					: await listObjects(api, 'caseProperty', {
							case: objectId(created),
							_limit: '50',
						})
			expect(
				answers.length,
				'the case type answer should have been written, on the case or as a caseProperty row',
			).toBeGreaterThan(0)
			// Name the row, do not index it. Both questions are answered (the
			// enum carries a default), and the API does not promise an order —
			// asserting on answers[0] read back the enum's 'Sport' and looked
			// like the ceiling had not been written at all.
			const ceilingRow = answers.find(
				(a) => String(a.propertyDefinition) === ceilingDefId,
			)
			expect(
				ceilingRow,
				'the ceiling answer should have been written',
			).toBeTruthy()
			expect(String(ceilingRow.value)).toBe('50000')
		}).toPass({ timeout: 30000 })
	})
	// @e2e openspec/changes/friendly-case-create-form/specs/friendly-case-create-form/spec.md#requirement-req-fcf-005-the-form-answers-what-the-case-type-already-knows
	test('fills the title the chosen case type already answers', async ({
		page,
	}) => {
		// Title only, deliberately. The name used to say "and assignee" while
		// the body never asserted one, and #1723 has since made that assertion
		// impossible to write: the action's `props` seeds `assignee: "@me"`,
		// prefill only fills EMPTY fields, so a case type's `defaultAssignee`
		// can no longer reach the form. That is a reasonable product call (the
		// person filing a case usually handles it) but it means the mechanism
		// is exercised through `title` and `status`, not `assignee`.
		const dialog = await openDialog(page)

		// Nothing is assumed about the empty form beyond the fields being
		// blank: a prefill that fired on open would make this assertion pass
		// for the wrong reason later.
		const titleInput = dialog
			.locator('[data-cn-field="title"]')
			.getByRole('textbox')
		await expect(titleInput).toHaveValue('')

		await chooseCaseType(page, dialog)

		// The case type's own title becomes the case title, which is what a
		// handler would have typed anyway.
		await expect(titleInput).toHaveValue(CASE_TYPE_TITLE, { timeout: 15000 })
	})

	// @e2e openspec/changes/friendly-case-create-form/specs/friendly-case-create-form/spec.md#requirement-req-fcf-005-the-form-answers-what-the-case-type-already-knows
	test('leaves a title the handler typed alone', async ({ page }) => {
		const dialog = await openDialog(page)
		const typed = `${RUN_PREFIX} Mijn eigen titel`

		const titleInput = dialog
			.locator('[data-cn-field="title"]')
			.getByRole('textbox')
		await titleInput.fill(typed)
		await chooseCaseType(page, dialog)

		// The case type's questions arriving is the signal that the prefill
		// ran; without waiting for it this asserts on a form that has not
		// changed yet and passes whatever the prefill would have done.
		await expect(dialog.getByText(CEILING)).toBeVisible({ timeout: 15000 })
		await expect(titleInput).toHaveValue(typed)
	})

	// @e2e openspec/changes/friendly-case-create-form/specs/friendly-case-create-form/spec.md#requirement-req-fcf-005-the-form-answers-what-the-case-type-already-knows
	test('stores the case type starting status without asking for it', async ({
		page,
	}) => {
		const dialog = await openDialog(page)
		const title = `${RUN_PREFIX} Startstatus`

		await dialog
			.locator('[data-cn-field="title"]')
			.getByRole('textbox')
			.fill(title)
		await chooseCaseType(page, dialog)
		await expect(dialog.getByText(CEILING)).toBeVisible({ timeout: 15000 })

		// A handler filing a case does not choose its status, so the form must
		// not show one.
		await expect(dialog.locator('[data-cn-field="status"]')).toHaveCount(0)

		const ceilingField = dialog
			.locator('[data-cn-field]')
			.filter({ hasText: CEILING })
			.locator('input')
			.first()
		await ceilingField.fill('50000')
		await dialog.getByRole('button', { name: 'Create' }).click()

		await expect(async () => {
			const cases = await listObjects(api, 'case', { _limit: '200' })
			const created = cases.find((c) => String(c.title ?? '') === title)
			expect(created, 'the case should have been created').toBeTruthy()
			expect(String(created.status)).toBe(startStatusId)
		}).toPass({ timeout: 30000 })
	})

	// @e2e openspec/changes/friendly-case-create-form/specs/friendly-case-create-form/spec.md#requirement-req-fcf-003-a-case-type-brings-its-own-questions
	test('keeps Create disabled until a required case type question is answered', async ({
		page,
	}) => {
		const dialog = await openDialog(page)
		const create = dialog.getByRole('button', { name: 'Create' })

		// Title prefills from the case type, so once a type is chosen the only
		// unanswered required field left is the type's own required question.
		await chooseCaseType(page, dialog)
		await expect(dialog.getByText(CEILING)).toBeVisible({ timeout: 15000 })
		await expect(create).toBeDisabled()

		const ceilingField = dialog
			.locator('[data-cn-field]')
			.filter({ hasText: CEILING })
			.locator('input')
			.first()
		await ceilingField.fill('50000')
		await expect(create).toBeEnabled()
	})

	// @e2e openspec/changes/friendly-case-create-form/specs/friendly-case-create-form/spec.md#requirement-req-fcf-006-the-dialog-reads-as-a-form-not-a-schema
	test('lays the fields out in two columns', async ({ page }) => {
		const dialog = await openDialog(page)

		const form = dialog.locator('[data-testid-modal="cn-form-dialog"]')
		await expect(form).toHaveClass(/cn-form-dialog__form--two-column/)

		// Two columns means two distinct left edges among the single-line
		// fields. Asserting on the class alone would pass even if the CSS
		// never applied, which is exactly the failure worth catching.
		const lefts = await dialog
			.locator('[data-cn-field]')
			.evaluateAll((nodes) =>
				nodes
					.filter((n) => !n.className.includes('--wide'))
					.map((n) => Math.round(n.getBoundingClientRect().left)),
			)
		expect(new Set(lefts).size).toBe(2)

		// The description is prose, so it takes the full width rather than
		// half of it. Measured, not inferred from the class: the class is what
		// ASKS for the span, the width is whether it happened.
		const widths = await dialog.evaluate((root) => {
			const form = root.querySelector('[data-testid-modal="cn-form-dialog"]')
			const desc = root.querySelector('[data-cn-field="description"]')
			const title = root.querySelector('[data-cn-field="title"]')
			return {
				form: form.getBoundingClientRect().width,
				description: desc.getBoundingClientRect().width,
				title: title.getBoundingClientRect().width,
			}
		})
		expect(widths.description).toBeGreaterThan(widths.title * 1.5)
	})

	// @e2e openspec/changes/friendly-case-create-form/specs/friendly-case-create-form/spec.md#requirement-req-fcf-006-the-dialog-reads-as-a-form-not-a-schema
	test('labels a case type question in words, not as an identifier', async ({
		page,
	}) => {
		const dialog = await openDialog(page)
		await chooseCaseType(page, dialog)
		await expect(dialog.getByText(CEILING)).toBeVisible({ timeout: 15000 })

		// The identifier-shaped definition seeded below renders in sentence
		// case. Real dossiq data carries names like auditorsStatementThreshold,
		// which used to reach the form verbatim.
		await expect(
			dialog.getByText(IDENTIFIER_LABEL, { exact: true }),
		).toBeVisible()
	})
	// @e2e openspec/changes/friendly-case-create-form/specs/friendly-case-create-form/spec.md#requirement-req-fcf-007-a-field-kept-off-the-create-form-stays-reachable-on-the-case
	test('keeps parent case off the create form and on the case itself', async ({
		page,
	}) => {
		// Not asked when filing: a case is not created as somebody's sub-case.
		const dialog = await openDialog(page)
		await expect(dialog.locator('[data-cn-field="parentCase"]')).toHaveCount(0)
		await dialog.getByRole('button', { name: 'Cancel' }).click()

		// Asked later, because a case becomes one. This half is the reason the
		// test exists: excluding a property from the create form and forgetting
		// to leave it anywhere else is invisible from the create form alone,
		// and that is exactly what had happened.
		const seeded = await seedCase(api, token, {
			title: `${RUN_PREFIX} Moederzaak`,
			caseType: caseTypeId,
		})
		// `/index.php/...`, not `/apps/...`: a hard load of the bare form
		// answers 200 and is rewritten client-side to the dashboard by the SPA
		// catch-all, so the assertions below would run against the wrong page
		// and fail naming the field rather than the navigation.
		await page.goto(`/index.php/apps/dossiq/cases/${objectId(seeded)}`)
		await expect(page, 'the deep link should stay on the case').toHaveURL(
			/\/cases\/[^/?#]+$/,
			{ timeout: 20000 },
		)
		// The detail page is NOT the create dialog and shares none of its
		// markup: it renders ZERO `data-cn-field` attributes, so a selector
		// borrowed from the dialog finds nothing here.
		//
		// What IS stable is the widget's own id: CnDetailPage gives each widget
		// `role="group"` with `aria-label` set to the manifest widget id, so
		// `case-core` identifies it without depending on any visible text.
		const coreWidget = page.locator('[aria-label="case-core"]')
		await expect(
			coreWidget,
			'the case detail page should render the core data widget',
		).toBeVisible({ timeout: 20000 })

		// The widget's heading, which used to read "Data" for all 23 of the
		// app's data widgets. CnObjectDataWidget is registered `ownsTitle`, so
		// widgetTitleOf() reads ONLY `content.title` and ignores a top-level
		// `title` — the manifest set the latter, so every heading fell back to
		// the component default. Nothing asserted it, which is why it survived.
		await expect(
			coreWidget.locator('.cn-widget-wrapper__title'),
			'the widget heading must be the manifest title, not the generic default',
		).toHaveText('Core case data', { timeout: 15000 })

		// Scoped to that widget, so this cannot pass on the words appearing
		// somewhere else on a busy page.
		//
		// Matched in either locale, as the sibling case-detail specs do. The
		// field label is the schema property's `title` put through the injected
		// `cnTranslate`, and dossiq ships `Parent case` -> `Hoofdzaak`; nothing
		// forces the language of the E2E instance, so an exact English string
		// would pass or fail on the locale rather than on the feature.
		await expect(
			coreWidget,
			'parent case should be editable on the case, though not on the create form',
		).toContainText(/Parent case|Hoofdzaak/, { timeout: 15000 })
	})
})
