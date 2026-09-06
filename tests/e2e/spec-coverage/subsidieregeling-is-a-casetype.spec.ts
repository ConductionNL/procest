/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A subsidieregeling is a zaaktype.
 *
 * WHAT THIS HAS TO CATCH THAT A UNIT TEST CANNOT
 * ----------------------------------------------
 * The migration's three real bugs were all shape mismatches against a live
 * OpenRegister — `findAll()` returning ObjectEntity rather than arrays, a
 * `schema` key beside `filters` being silently ignored so the query returned
 * the whole register, and `saveObject` needing ids rather than slugs. Every one
 * of them produced a repair WARNING while `occ upgrade` still printed "Update
 * successful", and every one would have passed a mocked test, because a mock
 * validates the fixture you wrote rather than the query the server runs.
 *
 * So these assertions go through the real API against real migrated data.
 */

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, navToRoute, sidebarNav } from '../helpers/nav.ts'

test.setTimeout(90000)

const SCHEMES = ['Innovatiefonds 2026', 'Cultuursubsidie 2026']

/**
 * Read every object of a schema through the OpenRegister API.
 *
 * @param request The Playwright request context.
 * @param schema The schema slug.
 * @return The objects.
 */
async function readAll(request, schema) {
	const res = await request.get(
		`/index.php/apps/openregister/api/objects/dossiq/${schema}?_limit=400`,
	)
	expect(
		res.status(),
		`reading ${schema} must succeed — a 404 on a LIST is a resolution failure, not an empty set`,
	).toBe(200)
	const body = await res.json()
	return body.results ?? []
}

/**
 * THREE OUTCOMES, NOT TWO.
 *
 * The migration is a POST-migration repair step: it runs on upgrade and never
 * on a fresh install — and CI installs. Asserting that migrated data exists
 * therefore fails there for a reason that has nothing to do with the migration
 * being wrong, which is exactly what happened the first time these were written.
 *
 * But "no migrated data" must not simply pass either: on an instance that DOES
 * carry subsidieRegeling objects, their absence from caseType IS the migration
 * having failed. So the state is measured before it is judged:
 *
 *   no schemes and none migrated  -> SKIP, saying why
 *   schemes present, none migrated -> FAIL, the migration did not run
 *   migrated case types present    -> assert their shape
 *
 * A lookup failure must not wear the same words as a judgement.
 *
 * WHAT THIS PROVES IN CI, STATED PLAINLY. CI reaches the third outcome, but
 * the case types it finds were written by the SEED DATA, not by the migration:
 * a fresh install no longer creates subsidieRegeling objects at all, because
 * the schema is being retired and seeding rows into it would mean every new
 * install immediately needed migrating.
 *
 * So these assertions verify the SHAPE both paths must produce — and in CI
 * they measure the seeder reaching it, not the migration. The migration's own
 * behaviour is covered by MigrateSubsidieRegelingToCaseTypeTest. Reading a
 * green run here as "the migration works" would be reading it about the wrong
 * writer, which is the whole reason this note exists.
 *
 * @param request The Playwright request context.
 * @return The two populations.
 */
async function migrationState(request) {
	const schemes = await readAllTolerant(request, 'subsidieRegeling')
	const caseTypes = await readAll(request, 'caseType')
	const migrated = caseTypes.filter((c) => SCHEMES.includes(c.title))
	return { schemes, caseTypes, migrated }
}

/**
 * Read a schema the register may legitimately no longer carry.
 *
 * @param request The Playwright request context.
 * @param schema The schema slug.
 * @return The objects, or [] when the schema is not resolvable.
 */
async function readAllTolerant(request, schema) {
	const res = await request.get(
		`/index.php/apps/openregister/api/objects/dossiq/${schema}?_limit=400`,
	)
	if (res.status() !== 200) {
		return []
	}
	const body = await res.json()
	return body.results ?? []
}

test.describe('the grant schemes became case types', () => {
	// @e2e openspec/changes/subsidieregeling-is-a-casetype/proposal.md
	test('both schemes exist as case types, with their fields carried', async ({
		request,
	}) => {
		const { schemes, migrated } = await migrationState(request)
		test.skip(
			schemes.length === 0 && migrated.length === 0,
			'no subsidieRegeling objects and no case types — neither the seeder nor the migration produced anything to check',
		)
		expect(
			migrated.length,
			`${schemes.length} subsidieRegeling object(s) present but none reached caseType — the migration did not run`,
		).toBeGreaterThan(0)

		for (const ct of migrated) {
			const name = ct.title

			// The four direct-mapped fields. Asserting they are NON-EMPTY rather
			// than merely present: a migration that creates the case type and
			// carries nothing across would satisfy a presence check.
			expect(
				ct.validFrom,
				`${name}: termStart should have become validFrom`,
			).toBeTruthy()
			expect(
				ct.validUntil,
				`${name}: termEnd should have become validUntil`,
			).toBeTruthy()
			expect(
				ct.purpose,
				`${name}: legalBasis should have become purpose`,
			).toBeTruthy()
		}
	})

	// @e2e openspec/changes/subsidieregeling-is-a-casetype/proposal.md
	test('requestTermWeeks became an ISO-8601 duration, not a bare integer', async ({
		request,
	}) => {
		// processingDeadline is read as a duration by the renderer and by the
		// AWB 4:13 deadline maths. An integer would be stored happily and
		// understood by neither, so the FORMAT is the assertion.
		const { schemes, migrated } = await migrationState(request)
		test.skip(
			schemes.length === 0 && migrated.length === 0,
			'no subsidieRegeling objects and no case types — neither the seeder nor the migration produced anything to check',
		)
		for (const ct of migrated) {
			const name = ct.title
			expect(
				String(ct.processingDeadline),
				`${name}: processingDeadline must be an ISO-8601 duration such as P13W`,
			).toMatch(/^P\d+[WDMY]$/)
		}
	})

	// @e2e openspec/changes/subsidieregeling-is-a-casetype/proposal.md
	test('the enum property kept its allowed values instead of flattening', async ({
		request,
	}) => {
		// The whole reason propertyType gained `enum`. As a plain string the
		// value survives and the CONSTRAINT does not, which is the silent half.
		const { schemes, migrated } = await migrationState(request)
		test.skip(
			schemes.length === 0 && migrated.length === 0,
			'no subsidieRegeling objects and no case types — neither the seeder nor the migration produced anything to check',
		)
		const defs = await readAll(request, 'propertyDefinition')
		const freq = defs.filter((d) => d.name === 'interimReportFrequency')

		expect(
			freq.length,
			'interimReportFrequency should exist as a property definition',
		).toBeGreaterThan(0)
		for (const d of freq) {
			expect(d.propertyType, 'it must be an enum, not a string').toBe('enum')
			expect(
				d.enumValues,
				'an enum with no enumValues is indistinguishable from a string',
			).toEqual(
				expect.arrayContaining([
					'none',
					'annually',
					'halfjaarlijks',
					'on_milestone',
				]),
			)
		}
	})

	// @e2e openspec/changes/subsidieregeling-is-a-casetype/proposal.md
	test('the grant-specific properties came across as property definitions', async ({
		request,
	}) => {
		const { schemes, migrated } = await migrationState(request)
		test.skip(
			schemes.length === 0 && migrated.length === 0,
			'no subsidieRegeling objects and no case types — neither the seeder nor the migration produced anything to check',
		)
		const defs = await readAll(request, 'propertyDefinition')
		const names = new Set(defs.map((d) => d.name))
		for (const p of ['plafond', 'targetGroup', 'auditorsStatementThreshold']) {
			expect(
				names.has(p),
				`${p} should have become a propertyDefinition`,
			).toBe(true)
		}
	})
})

test.describe('the retired scheme surface', () => {
	// @e2e openspec/changes/subsidieregeling-is-a-casetype/proposal.md
	test('the Subsidy schemes menu entry is gone', async ({ page }) => {
		await page.goto('/index.php/apps/dossiq')
		await dismissSupportDialog(page)
		await expect(sidebarNav(page)).toBeVisible({ timeout: 15000 })

		const toggle = sidebarNav(page)
			.getByRole('button', { name: 'Settings', exact: true })
			.first()
		if (await toggle.isVisible().catch(() => false)) {
			await toggle.click().catch(() => {})
			await sidebarNav(page)
				.getByRole('link', { name: 'Case types' })
				.first()
				.waitFor({ state: 'visible', timeout: 8000 })
				.catch(() => {})
		}

		const labels = await sidebarNav(page)
			.locator('a')
			.evaluateAll((els) => els.map((e) => (e.textContent || '').trim()))

		expect(labels).not.toContain('Subsidy schemes')

		// BOTH halves. The absence check alone would pass on a build where the
		// capability vanished; Case types is where schemes are administered now,
		// which is what keeps this inside ADR-044 Decision 5.
		expect(labels).toContain('Case types')
	})

	// @e2e openspec/changes/subsidieregeling-is-a-casetype/proposal.md
	test('the retired route no longer renders a scheme index', async ({ page }) => {
		await navToRoute(page, '/subsidieregelingen')

		// The page's own create control, not a heading: a retired route falls
		// through to the app root, and the root has headings of its own.
		await expect(
			page.getByRole('button', { name: /Add Subsidieregeling|Add Grant/i }),
		).toHaveCount(0)
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})
})
