/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the brp-kvk-register-sets initiator UI
 * (initiator-selection + initiator-display specs). Register-side scenarios
 * (repair import, OR bsn validation, fixture parity against the live mock /
 * KvK test API) carry @e2e excludes in the specs — they are proven by
 * PHPUnit (BrpKvkRegisterSetsTest) and the external-integrations contract
 * lanes. These tests drive the dossiq-owned UI through real clicks: the
 * StartCaseWidget initiator step, cross-source search on the seeded
 * register sets, persistence of the projection fields, and the detail
 * display (including the no-initiator empty case).
 */

import { expect, test } from '@playwright/test'

// The app is history-mode, not hash-mode: `/index.php/apps/dossiq/index#/`
// loaded a non-route and left the dashboard unrendered, so the StartCaseWidget
// cards were never there to click.
const APP_ROOT = '/index.php/apps/dossiq'

test.describe('Initiator selection (brp-kvk-register-sets)', () => {
	// FIXME(#718): the Dashboard manifest declares twelve widgets (four `stat`,
	// two `chart`, six `object-table`) and StartCaseWidget is NOT among them,
	// so `.start-case-widget__card` never renders and the initiator flow has
	// no entry point to click.
	// @e2e openspec/specs/initiator-selection/spec.md#agent-picks-an-initiator-type
	test('start-case flow offers Person / Company / Contact and stays skippable', async ({
		page,
	}) => {
		test.fixme(
			true,
			'#718: the Dashboard manifest declares twelve widgets (four `stat`, two `chart`, six `object-table`) and StartCaseWidget is NOT among them, so `.start-case-widget__card` never renders and the initiator flow has no entry point to click.',
		)
		await page.goto(APP_ROOT)
		// The dashboard StartCaseWidget lists case types; picking one opens
		// the optional initiator step.
		await page.locator('.start-case-widget__card').first().click()
		await expect(
			page.getByRole('heading', { name: 'Who is the initiator?' }),
		).toBeVisible({ timeout: 15000 })
		await expect(page.getByText('Person', { exact: true })).toBeVisible()
		await expect(page.getByText('Company', { exact: true })).toBeVisible()
		await expect(page.getByText('Contact', { exact: true })).toBeVisible()
		// The case MUST remain creatable without selecting any initiator.
		await page.getByRole('button', { name: 'Skip' }).click()
		await expect(page).toHaveURL(/\/cases\//, { timeout: 20000 })
	})

	// FIXME(#718): needs a seeded `brp` register-set fixture. ci-seed.sh
	// provisions the dossiq register + schemas but creates no BRP objects, so
	// on a runner there is no "Stephan Janssen" to find.
	// @e2e openspec/specs/initiator-selection/spec.md#person-search-hits-the-brp-register-set
	test('person search lists a seeded personen-mock persona with BSN', async ({
		page,
	}) => {
		test.fixme(
			true,
			'#718: needs a seeded `brp` register-set fixture. ci-seed.sh provisions the dossiq register + schemas but creates no BRP objects, so on a runner there is no "Stephan Janssen" to find.',
		)
		await page.goto(APP_ROOT)
		await page.locator('.start-case-widget__card').first().click()
		await page.getByLabel('Search initiator').fill('Janssen')
		const result = page.locator('.initiator-picker__result', {
			hasText: 'Stephan Janssen',
		})
		await expect(result).toBeVisible({ timeout: 15000 })
		await expect(result).toContainText('BSN 999990627')
		await expect(result).toContainText('1975-04-06')
	})

	// FIXME(#718): needs a seeded `kvk` register-set fixture — no
	// "Test EMZ Dagobert" / KvK 69599084 object exists on a runner.
	// @e2e openspec/specs/initiator-selection/spec.md#company-search-hits-the-kvk-register-set
	test('company search by pinned KvK number lists the fixture company', async ({
		page,
	}) => {
		test.fixme(
			true,
			'#718: needs a seeded `kvk` register-set fixture — no "Test EMZ Dagobert" / KvK 69599084 object exists on a runner.',
		)
		await page.goto(APP_ROOT)
		await page.locator('.start-case-widget__card').first().click()
		await page.getByText('Company', { exact: true }).click()
		await page.getByLabel('Search initiator').fill('69599084')
		const result = page.locator('.initiator-picker__result', {
			hasText: 'Test EMZ Dagobert',
		})
		await expect(result).toBeVisible({ timeout: 15000 })
		await expect(result).toContainText('KVK 69599084')
	})

	// FIXME(#718): the Contacts source is backed by a register set that is not
	// provisioned on a runner, so the tab renders neither results nor the
	// "No contacts found" empty state this asserts.
	// @e2e openspec/specs/initiator-selection/spec.md#contacts-source-degrades-gracefully
	test('contact tab shows an explicit empty state, never an error toast', async ({
		page,
	}) => {
		test.fixme(
			true,
			'#718: the Contacts source is backed by a register set that is not provisioned on a runner, so the tab renders neither results nor the "No contacts found" empty state this asserts.',
		)
		await page.goto(APP_ROOT)
		await page.locator('.start-case-widget__card').first().click()
		await page.getByText('Contact', { exact: true }).click()
		await page.getByLabel('Search initiator').fill('zzz-no-such-contact-zzz')
		await expect(page.getByText('No contacts found')).toBeVisible({
			timeout: 15000,
		})
		await expect(
			page.locator('.toast-error, .toastify.toast-error'),
		).toHaveCount(0)
	})

	// FIXME(#718): needs the seeded `brp` persona to pick in the first place.
	// @e2e openspec/specs/initiator-selection/spec.md#selection-persists-on-the-case
	// @e2e openspec/specs/initiator-display/spec.md#initiator-visible-on-the-case
	test('picked persona persists as projection and shows on case detail with source link', async ({
		page,
	}) => {
		test.fixme(
			true,
			'#718: needs the seeded `brp` persona to pick in the first place.',
		)
		await page.goto(APP_ROOT)
		await page.locator('.start-case-widget__card').first().click()
		await page.getByLabel('Search initiator').fill('Janssen')
		await page
			.locator('.initiator-picker__result', { hasText: 'Stephan Janssen' })
			.click()
		await page.getByRole('button', { name: 'Use as initiator' }).click()
		await expect(page).toHaveURL(/\/cases\//, { timeout: 20000 })
		// Detail overview: initiator section with name, type, and source id
		// linking to the seeded brpPerson record.
		const section = page.getByTestId('initiator-section')
		await expect(section).toBeVisible({ timeout: 20000 })
		await expect(section).toContainText('Stephan Janssen')
		await expect(section).toContainText('Person')
		await expect(
			section.getByRole('link', { name: '999990627' }),
		).toHaveAttribute('href', /openregister/)
	})

	// FIXME(#718): same StartCaseWidget gap — there is no start-case card to
	// click, so the "created without an initiator" path cannot be driven.
	// @e2e openspec/specs/initiator-display/spec.md#no-initiator-no-clutter
	test('a case created without initiator renders no initiator block', async ({
		page,
	}) => {
		test.fixme(
			true,
			'#718: same StartCaseWidget gap — there is no start-case card to click, so the "created without an initiator" path cannot be driven.',
		)
		await page.goto(APP_ROOT)
		await page.locator('.start-case-widget__card').first().click()
		await page.getByRole('button', { name: 'Skip' }).click()
		await expect(page).toHaveURL(/\/cases\//, { timeout: 20000 })
		await expect(page.getByTestId('initiator-section')).toHaveCount(0)
	})
})
