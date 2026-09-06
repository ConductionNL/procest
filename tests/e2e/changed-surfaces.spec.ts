/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The user-visible surfaces changed by the committee, parafering, workflow and
 * LHS work — driven against a live instance rather than asserted from a mock.
 *
 * Each test states what it would take to make it pass wrongly, because that is
 * the only thing that makes a green e2e worth reading. Where a fixture is
 * absent these FAIL naming what was missing rather than skipping into a pass:
 * an e2e that cannot tell "absent" from "broken" reports both as fine.
 *
 * @spec openspec/changes/workflow-definitions-to-flow/specs/workflow-definitions-to-flow/spec.md
 * @spec openspec/specs/enforcement-lhs/spec.md
 */
import { expect, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'

/** The provenance marker the workflow projection writes into a flow's notes. */
const FLOW_MARKER = 'dossiq:workflowTemplate:'

test.describe('workflow definitions projected onto flows', () => {
	// The dossiq shell mounts a large manifest and queries OpenRegister on
	// load, which on a cold instance eats most of a 30s budget before the nav
	// is interactive. The menu test below timed out at 30s with the Settings
	// group already expanded and the Flows link already rendered — it ran out
	// of budget, it did not disagree with the page. work-navigation.spec.ts
	// sets the same explicit budget for the same reason; a bare timeout that
	// reads as breakage is exactly what this avoids.
	test.setTimeout(300_000)

	test('the projected flows are listed, and every one is disabled', async ({
		page,
	}) => {
		await page.goto(`${BASE_URL}/apps/dossiq/flows`)
		await page.waitForLoadState('domcontentloaded')

		// Read the flow store through the app's own API rather than scraping the
		// list: the assertion is about what was PROJECTED, and a rendering
		// change should not be able to turn this red or green.
		const flows = await page.evaluate(async () => {
			const res = await fetch('/apps/openregister/api/flows?limit=200', {
				headers: { requesttoken: (window as any).OC?.requestToken ?? '' },
			})
			if (!res.ok) return { error: res.status }
			return res.json()
		})

		expect(
			flows,
			'the flow API must answer; a failure here is not "no flows"',
		).not.toHaveProperty('error')

		const items = (flows.results ?? flows.items ?? flows) as Array<
			Record<string, unknown>
		>
		const projected = items.filter((f) =>
			String(f.notes ?? '').startsWith(FLOW_MARKER),
		)

		expect(
			projected.length,
			'no projected flow found — run `occ dossiq:workflows:migrate-to-flows --user=admin` first',
		).toBeGreaterThan(0)

		// 🔴 The invariant the migration exists to protect. An enabled projection
		// would move every case a second time on each status change.
		for (const flow of projected) {
			expect(
				flow.enabled,
				`projected flow "${String(flow.name)}" must arrive disabled`,
			).toBeFalsy()
		}
	})

	test('the settings menu offers Flows once, not Flows and Workflow definitions', async ({
		page,
	}) => {
		await page.goto(`${BASE_URL}/apps/dossiq/`)
		await page.waitForLoadState('domcontentloaded')

		const nav = page.locator('[id^="app-navigation"]').first()
		await expect(
			nav,
			'the app navigation must render, or this asserts nothing',
		).toBeVisible({ timeout: 30000 })

		// The settings leaves sit in a collapsed foldout, so they are in the DOM
		// but hidden until it is opened. Opening it is what a user does to see
		// them, so the test does it too.
		await nav
			.getByRole('button', { name: 'Settings', exact: true })
			.first()
			.click()

		// THE CONTROL. Without it an absence proves only that the menu failed to
		// render, which is exactly how this assertion would pass wrongly.
		await expect(
			nav.getByRole('link', { name: /^(Flows|Stromen)$/ }),
			'Flows must be the surviving entry, or the absence below proves nothing',
		).toBeVisible({ timeout: 30000 })

		// The assertion. Both entries sat adjacent at orders 96 and 97 wearing
		// Sitemap and SitemapOutline, which reads as one feature listed twice.
		await expect(
			nav.getByRole('link', {
				name: /^(Workflow definitions|Workflowdefinities)$/,
			}),
		).toHaveCount(0)
	})

	test('the retired definitions page is still reachable by direct link', async ({
		page,
	}) => {
		// Losing a menu entry is not the same as losing the page. The definition
		// is what a projected flow was generated FROM, so a reader following a
		// legacy link must still land on it rather than on the dashboard.
		//
		// A PATH, not `#/settings/workflow-definitions`: dossiq runs on
		// createWebHistory, where a hash deep link navigates NOWHERE and throws
		// nothing, so this would silently render the dashboard and pass.
		await page.goto(
			`${BASE_URL}/index.php/apps/dossiq/settings/workflow-definitions`,
		)

		await expect(
			page
				.getByRole('button', { name: 'Add Workflow Template', exact: true })
				.first(),
			'the definitions page must still render for a deep link',
		).toBeVisible({ timeout: 60000 })
	})
})

test.describe('LHS override authorisation', () => {
	// Same budget, same reason: the second test here drives the navigation.
	test.setTimeout(300_000)

	test('an inspector cannot escalate by claiming a harsher recommendation', async ({
		page,
	}) => {
		await page.goto(`${BASE_URL}/apps/dossiq/`)
		await page.waitForLoadState('domcontentloaded')

		// The vulnerability, driven as a request: a body that CLAIMS the matrix
		// recommended the harshest measure, so that anything else reads as an
		// override-down and skips the manager gate.
		const outcome = await page.evaluate(async () => {
			const res = await fetch('/apps/dossiq/api/lhs/override', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: (window as any).OC?.requestToken ?? '',
				},
				body: JSON.stringify({
					recommendation: {
						id: 'does-not-exist',
						recommendedIntervention: 'bestuursdwang',
						severity: 'ernstig',
					},
					intervention: 'last_under_penaltypayment',
					justification:
						'Gemotiveerde afwijking van de interventieladder.',
				}),
			})
			return { status: res.status, body: await res.text() }
		})

		// The forged baseline must not be honoured. Before the fix this returned
		// 200 with a stored override; now the row is read back from the store,
		// so an id that does not exist cannot be overridden at all.
		expect(
			outcome.status,
			`a forged recommendation must not succeed; got ${outcome.status} ${outcome.body.slice(0, 200)}`,
		).not.toBe(200)
	})

	test('the LHS recommendations entry is gone from the settings menu', async ({
		page,
	}) => {
		await page.goto(`${BASE_URL}/apps/dossiq/`)
		await page.waitForLoadState('domcontentloaded')

		const nav = page.locator('[id^="app-navigation"]').first()
		await expect(
			nav,
			'the app navigation must render, or this asserts nothing',
		).toBeVisible({
			timeout: 30000,
		})

		// The settings entries live inside a collapsed foldout
		// (NcAppNavigationSettings), so they are in the DOM but not visible
		// until it is opened. Opening it is part of what a user does to see
		// them, so the test does it too.
		await nav
			.getByRole('button', { name: 'Settings', exact: true })
			.first()
			.click()

		// THE CONTROL, and it earned its place twice. The first version asserted
		// only the absence and would have passed against a navigation that never
		// rendered. The second hard-coded the Dutch labels and broke the moment the
		// instance served English — a test that depends on the session locale tells
		// you about the locale, not the feature. Both languages are accepted.
		// The control was 'Enforcement strategy', which has since been retired:
		// the LHS matrix is a decision table, OpenRegister evaluates it, and
		// authoring moved to the Decision Tables admin tab. A control has to be
		// an entry that still exists, so it is now Case types — still a
		// settings leaf, still inside the same collapsed group.
		await expect(
			nav.getByRole('link', {
				name: /^(Case types|Zaaktypen)$/,
			}),
			'the sibling settings entry must render, or an absence proves nothing',
		).toBeVisible({ timeout: 30000 })

		// The assertion: a recommendation is a per-enforcement audit record, not
		// configuration, so it no longer has a settings entry.
		await expect(
			nav.getByRole('link', {
				name: /^(LHS recommendations|LHS-aanbevelingen)$/,
			}),
		).toHaveCount(0)
	})
})

test.describe('decision tables evaluated by OpenRegister', () => {
	// dossiq deleted its own DMN engine and now injects
	// `OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator`. Unit tests cannot
	// prove that resolves: dossiq's suite autoloads `OCA\OpenRegister\` from
	// tests/Stubs, so it asserts the delegation against a stub by construction.
	// Only a live instance, where both apps are installed, can say whether the
	// class is there and whether the container can inject it.
	test('a PRIORITY table decides, where the old engine refused it', async ({
		page,
	}) => {
		await page.goto(`${BASE_URL}/apps/dossiq/`)
		await page.waitForLoadState('domcontentloaded')

		const outcome = await page.evaluate(async () => {
			const token = (window as any).OC?.requestToken ?? ''
			const headers = {
				requesttoken: token,
				'Content-Type': 'application/json',
			}

			// PRIORITY is the point. dossiq's own engine answered
			// `hit_policy_not_implemented` for this table, while its schema
			// offered PRIORITY in the enum the whole time.
			const table = {
				// `key` is required by the create endpoint; without it this is a
				// 400 and the test fails at the fixture rather than the claim.
				key: `e2e-priority-${Date.now()}`,
				name: 'e2e priority table',
				hitPolicy: 'PRIORITY',
				inputs: [{ name: 'severity', type: 'string' }],
				outputs: [{ name: 'intervention', type: 'string' }],
				rules: [
					{
						id: 'low',
						inputEntries: ['gering'],
						outputEntries: ['brief'],
						priority: 1,
					},
					{
						id: 'high',
						inputEntries: ['gering'],
						outputEntries: ['fine'],
						priority: 10,
					},
				],
			}

			const created = await fetch('/apps/dossiq/api/decisions', {
				method: 'POST',
				headers,
				body: JSON.stringify(table),
			})
			if (!created.ok) {
				return {
					stage: 'create',
					status: created.status,
					body: await created.text(),
				}
			}

			const row = await created.json()
			const id = row.id ?? row.uuid ?? row['@self']?.id
			if (!id)
				return {
					stage: 'create',
					status: 200,
					body: 'no id in the created row',
				}

			const res = await fetch(`/apps/dossiq/api/decisions/${id}/evaluate`, {
				method: 'POST',
				headers,
				body: JSON.stringify({ severity: 'gering' }),
			})
			const body = await res.text()

			// This suite runs against the shared development instance, so the
			// fixture is removed rather than left behind for the next reader to
			// wonder about.
			await fetch(`/apps/dossiq/api/decisions/${id}`, {
				method: 'DELETE',
				headers,
			})

			return { stage: 'evaluate', status: res.status, body }
		})

		expect(
			outcome.stage,
			`could not create the decision table: ${outcome.body}`,
		).toBe('evaluate')

		// 🔴 What would make this pass wrongly: nothing quiet. If the shared
		// evaluator were unresolvable the container would fail to build the
		// controller and this would be a 500; if PRIORITY were still refused it
		// would be a 4xx carrying `hit_policy_not_implemented`. Both are asserted
		// against explicitly rather than inferred from a truthy response.
		expect(outcome.body, 'PRIORITY must no longer be refused').not.toContain(
			'hit_policy_not_implemented',
		)
		expect(
			outcome.status,
			`evaluate answered ${outcome.status}: ${outcome.body}`,
		).toBe(200)

		const decided = JSON.parse(outcome.body)

		// The higher priority wins. Asserting the VALUE, not merely that
		// something came back, is what separates this from a smoke test: a
		// delegation that returned the first rule instead would still be a 200.
		expect(
			decided.outputs?.intervention,
			'the rule with priority 10 must win, not the first in declaration order',
		).toBe('fine')
		expect(decided.hitPolicy).toBe('PRIORITY')
	})
})
