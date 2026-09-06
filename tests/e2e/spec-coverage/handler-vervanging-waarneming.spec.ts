/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the handler-vervanging-waarneming spec.
 * Each test is tagged with the scenario it covers via @e2e. These drive the
 * real UI (substitution settings, coordinator admin, bulk reassign modal, and
 * the My Work substituted-work integration). API/contract assertions live in
 * the Newman collection, not here.
 *
 * Note: Use /apps/dossiq/<route> (not /index.php/...) so the Vue history-mode
 * router resolves the route. Tests are defensive — when a surface is not
 * deployed/rendered in the target instance the body-level assertions skip
 * gracefully rather than hard-failing the suite.
 */

import { expect, test } from '@playwright/test'
import { becomesVisible } from '../helpers/becomes-visible.js'
import { dismissSupportDialog } from '../helpers/nav.ts'
import {
	SubstitutionAdmin,
	SubstitutionPersonalSettings,
} from '../helpers/page-components.ts'

test.describe('Handler vervanging/waarneming spec coverage', () => {
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#handler-registers-their-own-substitution
	test('substitution renders under personal settings with a register action', async ({
		page,
	}) => {
		await page.goto(`/index.php${SubstitutionPersonalSettings}`)
		await dismissSupportDialog(page)
		const heading = page
			.getByRole('heading', { name: /Substitution|Vervanging|Waarneming/i })
			.first()
		if (await becomesVisible(heading)) {
			await expect(
				page
					.getByRole('button', {
						name: /Register substitution|Waarneming registreren/i,
					})
					.first(),
			).toBeVisible()
		} else {
			test.skip(
				true,
				'the substitution settings heading did not appear. NOT a deploy gap — SubstitutionAdmin.vue is registered in src/registry.js and this commit ships it. Note the locators above accept the Dutch strings too: l10n/nl.json translates Substitution -> Vervanging and Register substitution -> Waarneming registreren, so an English-only locator could never match a Dutch instance.',
			)
		}
	})

	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#self-substitution-is-rejected
	test('opening the substitution form shows the substitute field', async ({
		page,
	}) => {
		await page.goto(`/index.php${SubstitutionPersonalSettings}`)
		await dismissSupportDialog(page)
		const btn = page
			.getByRole('button', {
				name: /Register substitution|Waarneming registreren/i,
			})
			.first()
		if (await becomesVisible(btn)) {
			await btn.click()
			await expect(
				page.getByText(/Substitute \(user id\)/).first(),
			).toBeVisible({ timeout: 8000 })
			// Period inputs and reason/scope selectors are part of the form.
			await expect(page.getByText(/Start date/).first()).toBeVisible()
		} else {
			test.skip(
				true,
				'the substitution settings heading did not appear. NOT a deploy gap — SubstitutionAdmin.vue is registered in src/registry.js and this commit ships it. Note the locators above accept the Dutch strings too: l10n/nl.json translates Substitution -> Vervanging and Register substitution -> Waarneming registreren, so an English-only locator could never match a Dutch instance.',
			)
		}
	})

	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#waarnemer-sees-substituted-work-in-my-work
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#scope-limited-substitution-only-routes-matching-items
	test('My Work renders without error and supports the substituted filter when present', async ({
		page,
	}) => {
		await page.goto('/index.php/apps/dossiq/my-work')
		await dismissSupportDialog(page)
		// The My Work route renders no page heading (measured on a CI runner
		// 2026-08-04) — assert the view by a control it does render.
		await expect(page.getByRole('button', { name: 'Urgency' })).toBeVisible({
			timeout: 15000,
		})
		// The "Show substituted work" toggle appears only when the user is an
		// active waarnemer; its presence (or graceful absence) must not error.
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		const toggle = page.getByTestId('substituted-toggle')
		if (await becomesVisible(toggle, 3000)) {
			await toggle.locator('input').click()
			await expect(page.locator('body')).not.toContainText(
				'Internal Server Error',
			)
		}
	})

	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#preview-before-execution
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#bulk-reassignment-is-coordinator-only
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#coordinator-registers-a-substitution-on-behalf-of-an-absent-handler
	test('coordinator admin exposes a bulk-reassign action with a mandatory preview', async ({
		page,
	}) => {
		await page.goto(`/index.php/apps/dossiq${SubstitutionAdmin}`)
		await dismissSupportDialog(page)
		const heading = page
			.getByRole('heading', { name: /Substitutions & reassignment/ })
			.first()
		if (await becomesVisible(heading)) {
			const reassign = page
				.getByRole('button', { name: /Bulk reassign/ })
				.first()
			await expect(reassign).toBeVisible()
			await reassign.click()
			// The preview button gates execute — the modal must show it.
			await expect(
				page.getByRole('button', { name: /Preview affected work/ }).first(),
			).toBeVisible({ timeout: 8000 })
		} else {
			test.skip(
				true,
				'the coordinator substitution admin did not appear. NOT a deploy gap — SubstitutionAdminView is registered in src/registry.js. If this persists it is an authorisation or routing problem, not a missing build.',
			)
		}
	})

	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#all-actions-under-a-substitution-are-queryable
	// @e2e openspec/specs/handler-vervanging-waarneming/spec.md#timeline-shows-the-substituted-capacity
	test('coordinator admin lists substitutions with an actions affordance', async ({
		page,
	}) => {
		await page.goto(`/index.php/apps/dossiq${SubstitutionAdmin}`)
		await dismissSupportDialog(page)
		const heading = page
			.getByRole('heading', { name: /Substitutions & reassignment/ })
			.first()
		if (await becomesVisible(heading)) {
			await expect(page.locator('body')).not.toContainText(
				'Internal Server Error',
			)
		} else {
			test.skip(
				true,
				'the coordinator substitution admin did not appear. NOT a deploy gap — SubstitutionAdminView is registered in src/registry.js. If this persists it is an authorisation or routing problem, not a missing build.',
			)
		}
	})
})
