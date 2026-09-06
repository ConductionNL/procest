/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for the kcc-werkplek-zaaksysteem-bridge spec.
 *
 * Scope here is strictly the dossiq-authored UI surface this change ships:
 * the KCC-werkplek integration admin settings panel (KccIntegrationSettings.vue,
 * rendered inside Nextcloud's admin settings at /settings/admin/dossiq), which
 * configures burger identification method/threshold, case-voorblad limits,
 * sentiment trigger words and belplan overflow thresholds.
 *
 * The contactmoment-capture API, case-voorblad resolution, quick-actions,
 * sentiment scoring and belplan routing are backend concerns covered by PHPUnit
 * + Newman. DigiD authentication (OpenConnector), the telephony SIP transfer
 * and the contact-center screen-pop UI are delivered by OpenConnector and
 * pipelinq respectively — those scenarios are @e2e-excluded at the spec level
 * as cross-app, not exercisable from the dossiq UI.
 *
 * Tests are defensive: the admin settings SPA is data-independent chrome, so
 * they assert the KCC fields render, guarding against a 5xx render.
 */

import { expect, test } from '@playwright/test'
import { loadAllAdminSections } from '../helpers/nav.ts'

const ADMIN_SETTINGS_URL = '/settings/admin/dossiq'

test.describe('kcc-werkplek-zaaksysteem-bridge spec coverage', () => {
	// The dossiq admin settings page is very heavy (1.9MB DOM, 20+ sections
	// with maps + forms) and renders sections progressively, so triple the
	// per-test budget — the default 30s is not enough to load + scroll it.
	test.slow()

	// @e2e openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#kcc-integration-settings-render-and-persist
	test('KCC integration settings render the identification + sentiment controls', async ({
		page,
	}) => {
		// The dossiq admin settings page mounts many heavy sections (ZGW, VTH,
		// Map Layers, AI, …); waiting for the full `load` event races past the
		// 30s test timeout and leaves page.url() empty. `domcontentloaded` is
		// enough — the KCC fields are asserted explicitly below.
		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		await expect(page).not.toHaveURL(/login/, { timeout: 10000 })

		// The admin settings page renders its sections progressively; the KCC
		// section is near the bottom and only mounts once scrolled near, so
		// load every section into the DOM before asserting.
		await loadAllAdminSections(page)
		const heading = page
			.getByRole('heading', { name: /KCC-werkplek Integration/i })
			.first()
		await heading.scrollIntoViewIfNeeded({ timeout: 15000 }).catch(() => {})
		await expect(heading).toBeVisible({ timeout: 15000 })

		// Identification score threshold and sentiment trigger-word fields are present.
		await expect(
			page.locator('#kcc_identification_score_threshold'),
		).toBeVisible({ timeout: 10000 })
		await expect(page.locator('#kcc_sentiment_trigger_words')).toBeVisible({
			timeout: 10000,
		})
	})

	// @e2e openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#kcc-integration-settings-render-and-persist
	test('KCC integration settings expose belplan overflow + voorblad-limit controls', async ({
		page,
	}) => {
		// The dossiq admin settings page mounts many heavy sections (ZGW, VTH,
		// Map Layers, AI, …); waiting for the full `load` event races past the
		// 30s test timeout and leaves page.url() empty. `domcontentloaded` is
		// enough — the KCC fields are asserted explicitly below.
		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		await expect(page).not.toHaveURL(/login/, { timeout: 10000 })

		await loadAllAdminSections(page)
		const kccHeading = page
			.getByRole('heading', { name: /KCC-werkplek Integration/i })
			.first()
		await kccHeading.scrollIntoViewIfNeeded({ timeout: 15000 }).catch(() => {})
		await expect(kccHeading).toBeVisible({ timeout: 15000 })

		await expect(page.locator('#kcc_max_zaken_voorblad')).toBeVisible({
			timeout: 10000,
		})
		await expect(
			page.locator('#kcc_belplan_overflow_threshold_wachttijd'),
		).toBeVisible({ timeout: 10000 })
	})
})
