import type { APIRequestContext, Page } from '@playwright/test'

/**
 * The case flow, LIVE: the journeys tasks 7.1 (second half) and 7.2 of
 * `openspec/changes/case-flow-human-steps` could not run on the shared dev
 * instance, because they need the shipped flow ENABLED, and enabling it there
 * runs it on every case anybody creates. This spec is for a disposable
 * instance where an operator has adopted the flow first.
 *
 * WHAT IT ASSERTS. What a person sees at every waiting point: the status the
 * applicant reads, the task they are given, the document on the closed case.
 * Run rows are read only to find WHICH run to follow and to prove the
 * traceability read (7.3).
 *
 * 🔴 IT REFUSES TO PASS ON AN ABSENT PRECONDITION. The flow must be enabled
 * with an owner, and the worker command must be configured; each is asserted
 * with a message naming what was missing. Skipping would report "not set up"
 * as a pass.
 *
 * HOW THE WORKER IS DRIVEN. The shipped flow is `executionMode: async`, so a
 * case's run is QUEUED and advanced by openregister's FlowRunWorker on cron.
 * A test cannot wait for cron, so it performs a worker pass itself through
 * FLOW_WORKER_CMD, a shell command that runs one pass, for example:
 *
 *   FLOW_WORKER_CMD='docker exec -u www-data dossiq-proof-nextcloud-1 \
 *     php occ background-job:execute <FlowRunWorker job id> --force-execute'
 *
 * (`occ background-job:list | grep FlowRunWorker` gives the id.)
 *
 * The tests run in SERIAL order: a journey that cannot start its run has
 * nothing to complete, so the later steps are reported as skipped rather than
 * as a second failure with a misleading message.
 *
 * Screenshots of each waiting point land in PROOF_SCREENS_DIR (default
 * `test-results/proof-screens`).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */
import { expect, request, test } from '@playwright/test'
import { execSync } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'
import { BASE_URL } from './base-url.ts'

test.describe.configure({ mode: 'serial' })

const FLOW_NAME = 'Case behandeling'
const CASE_TYPE = 'Omgevingsvergunning kleine bouwactiviteit'
const RUN_PREFIX = `E2ELIVE-${Date.now().toString(36)}`
const SCREENS =
	process.env.PROOF_SCREENS_DIR
	?? path.join(__dirname, '..', '..', 'test-results', 'proof-screens')
const WORKER_CMD = process.env.FLOW_WORKER_CMD ?? ''
const ADMIN_USER = process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'
const OR = '/index.php/apps/openregister/api'

/** Copy the applicant supplies when asked to complete their case. */
const SUPPLIED_DESCRIPTION =
	'Aanvulling: bouwtekening, constructieberekening en situatieschets zijn nu bijgevoegd.'

type Json = Record<string, any>

/**
 * The API view. Basic auth as the admin operator, the same way ci-seed.sh
 * talks to the instance: it passes RBAC with a real identity and needs no
 * CSRF token because `OCS-APIRequest` short-circuits the check.
 */
async function apiContext(): Promise<APIRequestContext> {
	return request.newContext({
		baseURL: BASE_URL,
		httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
}

async function getJson(api: APIRequestContext, url: string): Promise<Json> {
	const res = await api.get(url)
	expect(res.ok(), `GET ${url} answered ${res.status()}`).toBeTruthy()
	return (await res.json()) as Json
}

async function results(api: APIRequestContext, url: string): Promise<Json[]> {
	const body = await getJson(api, url)
	return (body.results ?? body ?? []) as Json[]
}

async function shippedFlow(api: APIRequestContext): Promise<Json | null> {
	const flows = await results(api, `${OR}/flows?limit=200`)
	return flows.find((f) => String(f.name ?? '') === FLOW_NAME) ?? null
}

async function caseTypeId(api: APIRequestContext): Promise<string> {
	const types = await results(api, `${OR}/objects/dossiq/caseType?_limit=100`)
	const type = types.find((t) => String(t.title ?? t.name ?? '') === CASE_TYPE)
	expect(
		type,
		`The seeded case type "${CASE_TYPE}" is missing; the flow has nothing to run against.`,
	).toBeTruthy()
	return String(type!.id)
}

/** Status uuid → name, within the case type. The flow moves BY NAME. */
async function statusNames(
	api: APIRequestContext,
	caseType: string,
): Promise<Map<string, string>> {
	const statuses = await results(
		api,
		`${OR}/objects/dossiq/statusType?_limit=100&caseType=${caseType}`,
	)
	return new Map(
		statuses.map((s) => [String(s.id), String(s.title ?? s.name ?? '')]),
	)
}

async function createCase(api: APIRequestContext, body: Json): Promise<Json> {
	const res = await api.post(`${OR}/objects/dossiq/case`, { data: body })
	expect(
		res.status(),
		`Creating a case answered ${res.status()}: ${await res.text()}`,
	).toBe(201)
	return (await res.json()) as Json
}

/**
 * PUT a partial update onto an existing object, merged over its current body.
 *
 * OpenRegister's PUT is a full replace validated against the schema, so a
 * bare partial body 400s on every required property the patch does not carry
 * ("The required properties (title, caseType) are missing"). Measured live on
 * the proof rig 2026-09-01. Same pattern as `helpers/fixtures.ts#updateObject`.
 */
async function updateObject(
	api: APIRequestContext,
	schema: string,
	id: string,
	body: Json,
	register = 'dossiq',
): Promise<Json> {
	const current = await getJson(api, `${OR}/objects/${register}/${schema}/${id}`)
	const res = await api.put(`${OR}/objects/${register}/${schema}/${id}`, {
		data: { ...current, ...body },
	})
	expect(
		res.ok(),
		`Updating ${schema} ${id} answered ${res.status()}: ${await res.text()}`,
	).toBeTruthy()
	return (await res.json()) as Json
}

/**
 * Complete a task the dossiq way: an object update that walks the CMMN
 * lifecycle the task schema enforces (x-openregister-lifecycle on
 * dossiq/task).
 *
 * The walk is available → active → completed. A one-step PUT to `completed`
 * is refused with 422 lifecycle-invalid-transition, and that refusal is the
 * contract: REQ-TASK-002 (openspec/specs/task-management/spec.md) requires a
 * task to be `active` before it can be `completed`. So this helper claims the
 * task first when it is still `available`. The intermediate update resumes
 * nothing; TaskCompletionResumeListener fires only on the transition INTO
 * `completed`, so the run is still signalled exactly once.
 *
 * KNOWN FOLLOW-UP (ADR-098): dossiq tasks are OR objects in dossiq's own
 * register, so openregister's POST /api/flow-tasks/{uuid}/complete answers
 * 404 for them. When dossiq migrates onto openregister's task entity,
 * completion moves to that endpoint; until then this object update IS the
 * completion API.
 */
async function completeTask(api: APIRequestContext, taskId: string): Promise<void> {
	const current = await getJson(api, `${OR}/objects/dossiq/task/${taskId}`)
	if (String(current.status ?? '') === 'available') {
		await updateObject(api, 'caseTask', taskId, { status: 'active' })
	}
	await updateObject(api, 'caseTask', taskId, { status: 'completed' })
}

async function runsForCase(api: APIRequestContext, caseId: string): Promise<Json[]> {
	const runs = await results(api, `${OR}/flow-runs?limit=100`)
	return runs.filter((r) => String(r.subjectUuid ?? '') === caseId)
}

async function tasksForCase(
	api: APIRequestContext,
	caseId: string,
): Promise<Json[]> {
	return results(api, `${OR}/objects/dossiq/task?_limit=50&case=${caseId}`)
}

/** One worker pass: what cron would do. */
function workerPass(): void {
	expect(
		WORKER_CMD,
		'FLOW_WORKER_CMD is not set. The flow is async, so its run only moves on a worker pass; '
			+ 'export a command that performs one (see the header of this spec).',
	).not.toBe('')
	execSync(WORKER_CMD, { stdio: 'pipe', timeout: 120_000 })
}

/**
 * Where the run stands, in one line, for assertion messages. Reads the step
 * rows so a failure names the NODE that failed rather than "not suspended".
 */
async function describeRun(api: APIRequestContext, uuid: string): Promise<string> {
	const run = await getJson(api, `${OR}/flow-runs/${uuid}`)
	const log = (run.log ?? []) as Json[]
	const steps = log.map(
		(s) =>
			`${s.transition}:${s.status}${s.error ? `(${String(s.error).slice(0, 80)})` : ''}`,
	)
	return `run ${uuid} is ${run.status}${run.error ? ` (${run.error})` : ''}; steps: ${steps.join(' → ') || 'none'}`
}

async function shoot(page: Page, name: string): Promise<void> {
	fs.mkdirSync(SCREENS, { recursive: true })
	await page.screenshot({ path: path.join(SCREENS, name), fullPage: true })
}

/**
 * The cases list filtered to one exact title, via the deep-link filter
 * contract (non-underscore query keys become equality filters on the
 * fetch — CnIndexPage `resolveQueryFilters`).
 *
 * The unfiltered list sorts identifier-asc and pages at 20, so on any
 * rig carrying more than 20 cases a just-created case lands on the LAST
 * page and a bare `toContainText` against page one is pagination-blind:
 * it passes on an empty rig and fails on a lived-in one. Filtering pins
 * the assertion to the created case regardless of rig size.
 *
 * A hard load of `/cases` has been seen to land on the dashboard while
 * the SPA boots under load, and a sidebar fallback click would drop the
 * query — so the filtered deep link is retried until the router holds
 * the /cases route.
 */
async function openCasesListFilteredByTitle(
	page: Page,
	title: string,
): Promise<void> {
	const url = `/index.php/apps/dossiq/cases?title=${encodeURIComponent(title)}`
	for (let attempt = 0; attempt < 3; attempt++) {
		await page.goto(url, { waitUntil: 'domcontentloaded' })
		const nav = page
			.getByRole('link', { name: /^(All cases|Alle zaken)$/ })
			.first()
		await nav.waitFor({ state: 'visible', timeout: 20_000 })
		if (page.url().includes('/cases')) return
	}
	throw new Error('The SPA never settled on the filtered cases route.')
}

async function openCase(page: Page, caseId: string, title: string): Promise<void> {
	await page.goto(`/index.php/apps/dossiq/cases/${caseId}`, {
		waitUntil: 'domcontentloaded',
	})
	await expect(page.locator('body')).toContainText(title, { timeout: 20_000 })
}

test.describe('Case flow — live journeys on an adopted flow', () => {
	let api: APIRequestContext
	let caseType = ''
	let names = new Map<string, string>()

	// Journey state, carried across the serial tests.
	let incompleteCase = ''
	let incompleteRun = ''
	let applicantTask = ''
	let completeCase = ''
	let completeRun = ''

	test.beforeAll(async () => {
		api = await apiContext()
		caseType = await caseTypeId(api)
		names = await statusNames(api, caseType)

		// This suite is for an instance where an OPERATOR has adopted the flow.
		// Adoption is a deliberate manual act (PUT /api/flows/{uuid} {"enabled":
		// true}), and this app's own message says the shared dev instance must
		// keep the flow DISABLED — enabling a projection would move every case a
		// second time on each status change.
		//
		// So on CI these nine tests asserted a precondition CI is required never
		// to satisfy: they could not pass by construction, and failed every run.
		//
		// 🔴 The skip is deliberately NARROW. It fires only when the flow EXISTS
		// and is disabled, which is the documented correct state here. A MISSING
		// flow still fails loudly below, because that means the register import
		// did not run — a real defect, and the one thing a blanket skip would
		// have hidden.
		const adopted = await shippedFlow(api)
		test.skip(
			adopted !== null && !adopted.enabled,
			`The flow "${FLOW_NAME}" is present but not adopted (enabled=false). `
				+ 'That is the correct state for the shared dev instance, so these '
				+ 'adopted-flow journeys do not apply here. Enable it on an operator '
				+ 'instance to run them.',
		)
	})

	test.afterAll(async () => {
		await api?.dispose()
	})

	test('Given: the operator has adopted the flow, so it is enabled and has an owner', async ({
		page,
	}) => {
		const flow = await shippedFlow(api)
		expect(
			flow,
			`The flow "${FLOW_NAME}" is not in the flow store; the register import did not run.`,
		).toBeTruthy()

		expect(
			flow!.enabled,
			'The flow is DISABLED. This spec is for an instance where the operator enabled it '
				+ '(PUT /api/flows/{uuid} {"enabled": true}); on the shared dev instance it must stay disabled.',
		).toBeTruthy()
		expect(
			String(flow!.owner ?? ''),
			'The flow has no owner. openregister refuses to dispatch an ownerless flow '
				+ '(Flow::canDispatch), so no case would ever start a run.',
		).not.toBe('')

		// The same flow, read through the browser session a person has. The
		// two views must agree, or the editor shows an operator a flow that
		// is not the one running.
		const seen = await page.request.get(`${OR}/flows/${flow!.uuid}`)
		expect(
			seen.ok(),
			'The flow must be readable in the browser session.',
		).toBeTruthy()
		const inSession = (await seen.json()) as Json
		expect(
			inSession.enabled,
			'The browser session sees the flow as disabled while the API sees it enabled.',
		).toBe(flow!.enabled)
		expect(
			inSession.owner ?? null,
			'The browser session sees a different owner than the API.',
		).toBe(flow!.owner ?? null)
	})

	test('When an incomplete case is filed, exactly one run starts for it', async ({
		page,
	}) => {
		const created = await createCase(api, {
			title: `${RUN_PREFIX} Carport Molenweg 5`,
			caseType,
			intakeChannel: 'website',
			assignee: ADMIN_USER,
			// No description: the completeness check reads `description`.
		})
		incompleteCase = String(created.id)

		await expect
			.poll(async () => (await runsForCase(api, incompleteCase)).length, {
				message:
					'Creating a case must start its run (object.created trigger on dossiq/case).',
				timeout: 15_000,
			})
			.toBe(1)
		incompleteRun = String((await runsForCase(api, incompleteCase))[0].uuid)

		await openCasesListFilteredByTitle(page, `${RUN_PREFIX} Carport Molenweg 5`)
		await expect(page.locator('body')).toContainText(
			`${RUN_PREFIX} Carport Molenweg 5`,
			{ timeout: 20_000 },
		)
		await shoot(page, '01-incomplete-case-filed.png')
	})

	test('Then one worker pass gives the handler a supplement task and the case says "Wacht op aanvulling"', async ({
		page,
	}) => {
		workerPass()

		// What the applicant reads on the case, captured BEFORE the assertions so a
		// failing run still leaves the evidence of what a person saw.
		await openCase(page, incompleteCase, 'Carport Molenweg 5')
		await shoot(page, '02-applicant-waiting-case.png')

		const run = await getJson(api, `${OR}/flow-runs/${incompleteRun}`)
		expect(
			run.status,
			`After the worker pass the run must be suspended on the supplement ask. ${await describeRun(api, incompleteRun)}`,
		).toBe('suspended')

		const tasks = await tasksForCase(api, incompleteCase)
		expect(
			tasks,
			'The incomplete case must have exactly one supplement task.',
		).toHaveLength(1)
		const task = tasks[0]
		applicantTask = String(task.id)
		expect(String(task.title)).toBe('Vraag de indiener om aanvulling')
		expect(String(task.flowRun ?? '')).toBe(incompleteRun)
		expect(String(task.flowNode ?? '')).toBe('ask-aanvulling')
		// The flow names `{{ case.assignee }}`; the task must carry the PERSON,
		// not the placeholder, or nobody is allowed to answer it.
		expect(String(task.assignee ?? '')).toBe(ADMIN_USER)

		const status = names.get(
			String(
				(await getJson(api, `${OR}/objects/dossiq/case/${incompleteCase}`))
					.status,
			),
		)
		expect(status).toBe('Wacht op aanvulling')
		await expect(page.locator('body')).toContainText('Wacht op aanvulling')

		await page.goto(`/index.php/apps/dossiq/tasks/${applicantTask}`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('body')).toContainText(
			'Vraag de indiener om aanvulling',
			{
				timeout: 20_000,
			},
		)
		// The task says WHICH case is waiting on it.
		await expect(page.locator('body')).toContainText('Carport Molenweg 5')
		await shoot(page, '03-applicant-task.png')
	})

	test('When the missing detail is supplied and the task completed, the case moves to "In behandeling"', async ({
		page,
	}) => {
		await updateObject(api, 'case', incompleteCase, {
			description: SUPPLIED_DESCRIPTION,
		})
		await completeTask(api, applicantTask)

		workerPass()

		await openCase(page, incompleteCase, 'Carport Molenweg 5')
		await shoot(page, '04-case-after-applicant-answered.png')

		const status = names.get(
			String(
				(await getJson(api, `${OR}/objects/dossiq/case/${incompleteCase}`))
					.status,
			),
		)
		expect(
			status,
			`Completing the supplement task must resume the run at the step that asked and re-check completeness. ${await describeRun(api, incompleteRun)}`,
		).toBe('In behandeling')
		await expect(page.locator('body')).toContainText('In behandeling')

		// It is the SAME run that continues, now waiting on the first decision.
		const run = await getJson(api, `${OR}/flow-runs/${incompleteRun}`)
		expect(run.status, await describeRun(api, incompleteRun)).toBe('suspended')
	})

	test('When a complete case is filed, it is not asked for anything and goes straight to handling', async ({
		page,
	}) => {
		const created = await createCase(api, {
			title: `${RUN_PREFIX} Dakkapel Kerkstraat 14`,
			description:
				'Compleet ingediend: bouwtekening, constructieberekening en situatieschets zijn bijgevoegd.',
			caseType,
			intakeChannel: 'website',
			assignee: ADMIN_USER,
		})
		completeCase = String(created.id)

		await expect
			.poll(async () => (await runsForCase(api, completeCase)).length, {
				timeout: 15_000,
			})
			.toBe(1)
		completeRun = String((await runsForCase(api, completeCase))[0].uuid)

		workerPass()

		await openCase(page, completeCase, 'Dakkapel Kerkstraat 14')
		await shoot(page, '05-complete-case-in-handling.png')

		const tasks = await tasksForCase(api, completeCase)
		expect(
			tasks.filter(
				(t) => String(t.title) === 'Vraag de indiener om aanvulling',
			),
			'A complete case must never be asked for more.',
		).toHaveLength(0)

		const status = names.get(
			String(
				(await getJson(api, `${OR}/objects/dossiq/case/${completeCase}`))
					.status,
			),
		)
		expect(
			status,
			`A complete case must pass the completeness check. ${await describeRun(api, completeRun)}`,
		).toBe('In behandeling')
		await expect(page.locator('body')).not.toContainText('Wacht op aanvulling')
	})

	test('Then two decisions are raised in decidiq, and concluding each moves the run on', async () => {
		// decidiq keeps decisions as objects in its own register; a delegated
		// decision carries the case as externalReference.
		const decisions = await results(
			api,
			`${OR}/objects/decidiq/decision?_limit=50&externalReference=${completeCase}`,
		)
		expect(
			decisions.length,
			`The run must have raised a decision in decidiq for the case. ${await describeRun(api, completeRun)}`,
		).toBeGreaterThanOrEqual(1)

		for (const question of [
			'Toets de aanvraag aan register B',
			'Tweede inhoudelijke toets',
		]) {
			const open = (
				await results(
					api,
					`${OR}/objects/decidiq/decision?_limit=50&externalReference=${completeCase}`,
				)
			).filter(
				(d) =>
					!['decided', 'enacted', 'archived'].includes(
						String(d.lifecycle ?? d.status ?? ''),
					),
			)
			expect(
				open.length,
				`Expected an open decision for "${question}".`,
			).toBeGreaterThanOrEqual(1)
			const decision = open[0]

			// Record the outcome, then walk the lifecycle to `decided` through
			// decidiq's own transition endpoint: that is what emits the
			// DecisionConcludedEvent the run is waiting for.
			//
			// `text` is what the clerk types when recording the outcome, and it
			// is also load-bearing here for a reason worth naming: decidiq's
			// decision schema requires (title, text, decisionType), yet the
			// flow's delegate-decision step CREATES the decision without
			// `text` — the create path skips required-property validation that
			// every later PUT then enforces, so the stored object cannot be
			// updated at all until someone supplies it. Measured live on the
			// proof rig 2026-09-01 (decision 7f2dc8f4, schema 33).
			await updateObject(
				api,
				'decision',
				String(decision.id),
				{
					text: `Toets uitgevoerd: ${question}. Geen bezwaren.`,
					outcome: 'adopted',
					decisionDate: new Date().toISOString(),
				},
				'decidiq',
			)
			// `openVoting` is in the walk because it is the only schema-legal
			// route to `decided`: decidiq's PHP guard advertises deliberating →
			// decided (`allowDecideWithoutVote`, operations domain), but the
			// decision schema's x-openregister-lifecycle declares no such
			// edge — only voting → decided — so the guard-approved write is
			// then rejected by OpenRegister's lifecycle validation ("No
			// transition allows moving lifecycle from deliberating to
			// decided"). Measured live on the proof rig 2026-09-01.
			for (const action of ['propose', 'deliberate', 'openVoting', 'decide']) {
				const res = await api.post(
					`/index.php/apps/decidiq/api/decisions/${decision.id}/transition`,
					{ data: { action } },
				)
				expect([200, 422]).toContain(res.status())
			}
			const after = await getJson(
				api,
				`${OR}/objects/decidiq/decision/${decision.id}`,
			)
			expect(
				String(after.lifecycle ?? after.status ?? ''),
				`Decision ${decision.id} must reach "decided".`,
			).toBe('decided')

			workerPass()
		}

		const run = await getJson(api, `${OR}/flow-runs/${completeRun}`)
		expect(run.status, await describeRun(api, completeRun)).toBe('suspended')
	})

	test('Then the employee gets a preparation task; completing it puts the case before the commission', async ({
		page,
	}) => {
		const tasks = (await tasksForCase(api, completeCase)).filter(
			(t) => String(t.title) === 'Rond de inhoudelijke voorbereiding af',
		)
		expect(
			tasks,
			`The employee task must exist after both decisions. ${await describeRun(api, completeRun)}`,
		).toHaveLength(1)
		expect(String(tasks[0].assignee)).toBe('behandelaars')

		await page.goto(`/index.php/apps/dossiq/tasks/${tasks[0].id}`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('body')).toContainText(
			'Rond de inhoudelijke voorbereiding af',
			{ timeout: 20_000 },
		)
		await shoot(page, '06-employee-task.png')

		// The admin is a member of `behandelaars`, so may complete it.
		await completeTask(api, String(tasks[0].id))
		workerPass()

		await openCase(page, completeCase, 'Dakkapel Kerkstraat 14')
		await shoot(page, '07-case-before-commission.png')
		const status = names.get(
			String(
				(await getJson(api, `${OR}/objects/dossiq/case/${completeCase}`))
					.status,
			),
		)
		expect(status, await describeRun(api, completeRun)).toBe('Bij commissie')
	})

	test('When the commission approves, the decision document is attached and the case is closed', async ({
		page,
	}) => {
		const open = (
			await results(
				api,
				`${OR}/objects/decidiq/decision?_limit=50&externalReference=${completeCase}`,
			)
		).filter(
			(d) =>
				!['decided', 'enacted', 'archived'].includes(
					String(d.lifecycle ?? d.status ?? ''),
				),
		)
		expect(
			open.length,
			`The commission decision must be open in decidiq. ${await describeRun(api, completeRun)}`,
		).toBeGreaterThanOrEqual(1)
		const decision = open[0]

		// `text` also fills the required property the flow's create step left
		// out — see the note on the first decision above.
		await updateObject(
			api,
			'decision',
			String(decision.id),
			{
				text: 'De commissie stemt in met het voorgenomen besluit.',
				outcome: 'adopted',
				decisionDate: new Date().toISOString(),
			},
			'decidiq',
		)
		// `openVoting` for the same reason as the first decision: the schema's
		// lifecycle map only reaches `decided` through `voting`.
		for (const action of ['propose', 'deliberate', 'openVoting', 'decide']) {
			await api.post(
				`/index.php/apps/decidiq/api/decisions/${decision.id}/transition`,
				{ data: { action } },
			)
		}
		workerPass()

		await openCase(page, completeCase, 'Dakkapel Kerkstraat 14')
		await shoot(page, '08-closed-case-with-document.png')

		const closed = await getJson(
			api,
			`${OR}/objects/dossiq/case/${completeCase}`,
		)
		expect(
			String(closed.besluitDocument ?? ''),
			`The case must carry its decision document before it closes. ${await describeRun(api, completeRun)}`,
		).toContain('Besluit op de aanvraag')
		expect(names.get(String(closed.status))).toBe('Afgehandeld')
		await expect(page.locator('body')).toContainText('Afgehandeld')

		// `stopped` is the platform's word for a run that reached its end
		// node: `openregister.end` (EndNode) throws FlowStop when items reach
		// it, and FlowEngine maps FlowStop to STATUS_STOPPED. STATUS_COMPLETED
		// only marks a run whose marking drained without any stop node, which
		// this flow, ending deliberately at `end`, never does. Asserting
		// `completed` here contradicted that contract and failed a run that
		// had in fact closed the case correctly.
		const run = await getJson(api, `${OR}/flow-runs/${completeRun}`)
		expect(run.status, await describeRun(api, completeRun)).toBe('stopped')
	})

	test('And the run reports the objects it touched, grouped by node (7.3)', async () => {
		const uuid = incompleteRun || completeRun
		expect(
			uuid,
			'No run to read; the journeys above did not start one.',
		).not.toBe('')

		const touched = await getJson(api, `${OR}/flow-runs/${uuid}/objects`)
		expect(touched).toHaveProperty('run', uuid)
		const nodes = (touched.nodes ?? []) as Json[]
		expect(
			nodes.length,
			'A run that moved a status must report at least the node that moved it.',
		).toBeGreaterThan(0)

		// The status step names the CASE it updated, so the case's history can
		// say which node moved each status.
		const statusNode = nodes.find((n) => String(n.node) === 'status-ontvangen')
		expect(
			statusNode,
			'The first status step must appear in the traceability read.',
		).toBeTruthy()
		const updates = ((statusNode!.objects ?? []) as Json[]).filter(
			(o) => String(o.action) === 'update',
		)
		expect(updates.map((o) => String(o.objectUuid))).toContain(
			incompleteRun ? incompleteCase : completeCase,
		)
	})
})
