/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage tests for semantic-case-intake. The backend /
 * cross-app scenarios (SemanticTypeResolver discovery, graceful degrade,
 * the pipelinq→dossiq handoff execution, and the declarative notification)
 * run inside OpenRegister's handoff engine + pipelinq's produce-side and
 * carry @e2e excludes in the spec (proven by PHPUnit against the REAL
 * HandoffKindContracts, plus OR's own engine tests). These tests cover the
 * dossiq-owned UI surface: a handoff-created case (carrying handoffSource)
 * shows its provenance in the Werkvoorraad intake list and on the case
 * detail overview.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { request as pwRequest } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	ensureCaseType,
	getRequestToken,
	objectId,
	seedCase,
} from '../helpers/fixtures.ts'

const APP = '/index.php/apps/dossiq'

test.describe('Semantic case intake — handoff provenance UI', () => {
	let api: APIRequestContext
	let token: string
	let caseId: string

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: STORAGE_STATE })
		token = await getRequestToken(api)
		const ct = await ensureCaseType(api, token)
		// Seed a case that looks handoff-created: the mandatory contract
		// `source` field maps to handoffSource; a non-empty value marks it.
		const kase = await seedCase(api, token, {
			title: 'HANDOFF Intake demo case',
			caseType: ct.id,
			identifier: 'HANDOFF-INTAKE-1',
			description: 'Case that arrived via the ns#Case semantic handoff.',
			// NOT 'handoff': the case schema's intakeChannel enum is
			// ["manual","balie","phone","email","post","website","other",
			// "zgw-api"], so 'handoff' is rejected by OpenRegister with a 400
			// and the fixture never got created. The provenance UI keys off
			// `handoffSource` alone (InitiatorSection#hasHandoff), so the
			// channel value is incidental to what this test proves.
			intakeChannel: 'other',
			handoffSource: 'urn:openregister:pipelinq:request:demo-123',
		})
		caseId = objectId(kase)
	})

	test.afterAll(async () => {
		await api?.dispose()
	})

	// The Werkvoorraad ("All cases in progress") list was retired (work-queue
	// streamlining); the handoff-provenance scenario is covered by the case-detail
	// test below.
	// @e2e openspec/specs/semantic-case-intake/spec.md#behandelaar-sees-the-handoff-case-with-origin
	test('case detail shows the handoff provenance with a source link', async ({
		page,
	}) => {
		// History-mode router: the old `…/index#/cases/<id>` hash URL loaded a
		// non-route and the detail view never rendered.
		await page.goto(`${APP}/cases/${caseId}`)
		const provenance = page.getByTestId('handoff-provenance')
		await expect(provenance).toBeVisible({ timeout: 20000 })
		await expect(provenance).toContainText('Received via handoff')
		await expect(
			provenance.getByRole('link', { name: 'Open source object' }),
		).toHaveAttribute('href', /openregister/)
	})
})
