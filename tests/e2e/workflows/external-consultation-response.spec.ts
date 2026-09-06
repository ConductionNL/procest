/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The token-addressed external consultation-response surface:
 * `/public/consultations/:token` (manifest page `ExternalConsultationResponse`,
 * component `src/views/public/ExternalConsultationResponsePage.vue`) and the
 * `consultationPublic#publicResponseGet` endpoint that feeds it.
 *
 * WHY THIS SPEC ASSERTS THE GUARD AND NOT ONLY THE RENDER
 * ------------------------------------------------------
 * This page is anonymous and addressed by a bearer secret in the URL — the
 * token IS the authorization. A spec that only proved the page renders would
 * pass just as well against a lookup that ignored its filter and handed back
 * `results[0]` of the whole consultation collection, which is a cross-tenant
 * disclosure with no error to notice. So the guard is asserted in BOTH
 * directions, and the "yes" direction is what makes the "no" direction mean
 * anything:
 *
 *   token of consultation A  -> 200, and the body is A
 *   token of consultation B  -> 200, and the body is B      <- discriminating
 *   an unknown 44-char token -> not 200, and no consultation body
 *   a 5-char token           -> not 200, and no consultation body
 *
 * The A/B pair is the load-bearing one. A guard that returned the first row of
 * an unfiltered query would answer both A and B with the SAME object and still
 * satisfy every single-token assertion; only asking for two different tokens
 * and getting two different consultations shows the filter is real. Both were
 * measured against a running instance (dossiq 0.3.9 + OpenRegister
 * 0.2.17-unstable.38, 2026-08-17) before this spec was written.
 *
 * The response body is also checked to carry no `secureToken`: the controller
 * unsets it before responding, and a regression there would hand every reader
 * of one consultation the credential for it.
 *
 * WHAT THE RENDER TEST PROVES, AND WHY IT IS NOT A TAUTOLOGY
 * ---------------------------------------------------------
 * Three outcomes are distinguishable on this route (measured; see
 * `../page-shells.spec.ts` for the same three):
 *
 *   route resolves + component registered    -> the component's own root
 *                                               `.external-consultation-response`
 *   route resolves + component NOT registered -> the manifest renderer's
 *                                               "This page is empty" placeholder
 *   route does not resolve                    -> falls back to the Dashboard
 *
 * The middle case is what this page was in until the component was added to
 * `src/customComponents.js`. The root element is OUTSIDE every `v-if` in the
 * component's template, so it holds whether or not the token resolves, and it
 * is a thing neither the placeholder nor the Dashboard can produce. The
 * heading assertion below uses the UNRESOLVABLE token deliberately: a token
 * nothing carries answers 404 for an anonymous and for an authenticated caller
 * alike, so the `loadError` branch stays deterministic and the assertion does
 * not rot when the anonymous read path changes.
 *
 * KNOWN GAP, DELIBERATELY NOT PINNED AS AN ASSERTION
 * --------------------------------------------------
 * Measured on a live instance: an ANONYMOUS caller presenting a token that a
 * consultation really carries is also answered 404, because OpenRegister
 * returns zero rows for a caller with no organisation scope, so the whole
 * public surface currently cannot be entered. Nothing mints a `secureToken`
 * either (see `AdvisoryBodyService`'s own note). Asserting "valid token +
 * anonymous -> 404" here would pin that gap as the contract, so this spec
 * asserts only the security invariant that survives the fix: an anonymous
 * caller MUST NOT receive a consultation body for a token no consultation
 * carries.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { STORAGE_STATE } from '../helpers/auth.ts'
import {
	cleanupRunObjects,
	createObject,
	getRequestToken,
	objectId,
	RUN_PREFIX,
} from '../helpers/fixtures.ts'
import { navToRoute } from '../helpers/nav.ts'
import { ExternalConsultationResponsePage } from '../helpers/page-components.ts'

/** The app-relative public endpoint the page reads on mount. */
const PUBLIC_CONSULTATION_API = '/index.php/apps/dossiq/api/public/consultations'

/**
 * Tokens for the two seeded consultations. 48 hex characters each, matching
 * the shape `ConsultationRepository::findBySecureToken()` expects (it refuses
 * anything shorter than 32 outright) and unique per run so two concurrent runs
 * cannot resolve each other's fixtures.
 */
const RUN_SUFFIX = RUN_PREFIX.replace(/[^a-z0-9]/gi, '').toLowerCase()
const TOKEN_A = `a1a1a1a1a1a1a1a1${RUN_SUFFIX}`.padEnd(48, '0').slice(0, 48)
const TOKEN_B = `b2b2b2b2b2b2b2b2${RUN_SUFFIX}`.padEnd(48, '0').slice(0, 48)

/** A token no consultation carries, long enough to reach the real lookup. */
const TOKEN_UNKNOWN = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3'

/** Shorter than the repository's 32-character floor. */
const TOKEN_TOO_SHORT = 'short'

let api: APIRequestContext
let anon: APIRequestContext
let token: string

/**
 * Seed one consultation carrying a known secure token.
 *
 * `parentCase`, `adviceAuthority` and `questionFormulation` are the schema's
 * required properties, and `parentCase` carries `format: uuid` — measured
 * against the live schema, which answers 400 naming each of them in turn.
 *
 * @param label       Human-visible marker, embedded in `subject`.
 * @param secureToken The token the public endpoint must resolve to this row.
 * @param parentCase  A syntactically valid case uuid.
 */
async function seedConsultation(
	label: string,
	secureToken: string,
	parentCase: string,
): Promise<string> {
	const created = await createObject(api, token, 'consultation', {
		subject: `${RUN_PREFIX} ${label}`,
		status: 'open',
		parentCase,
		adviceAuthority: `${RUN_PREFIX}-body`,
		questionFormulation: `${RUN_PREFIX} question ${label}`,
		latestResponseDate: '2026-12-31',
		secureToken,
	})
	return objectId(created)
}

test.describe('External consultation response — public token surface', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ baseURL }) => {
		api = await request.newContext({ baseURL, storageState: STORAGE_STATE })
		anon = await request.newContext({ baseURL })
		token = await getRequestToken(api)
		await seedConsultation(
			'CONSULTATION-A',
			TOKEN_A,
			'11111111-1111-4111-8111-111111111111',
		)
		await seedConsultation(
			'CONSULTATION-B',
			TOKEN_B,
			'22222222-2222-4222-8222-222222222222',
		)
	})

	test.afterAll(async () => {
		// `deleteObject` only SOFT-deletes, and this file sorts last, so the two
		// consultations it removed were the residue every run ended with: gone
		// from the object API, still in OpenRegister's trash, and no later
		// teardown to sweep them. Measured as 2 trashed rows after a full run.
		await cleanupRunObjects(api, token)
		await api.dispose()
		await anon.dispose()
	})

	test('the route renders the ExternalConsultationResponsePage component, not the empty-page placeholder', async ({
		page,
	}) => {
		await navToRoute(page, ExternalConsultationResponsePage)

		// The component's template root — outside every v-if, so it is present
		// in the loading, error, form and submitted states alike. Neither the
		// manifest renderer's placeholder nor the Dashboard fallback can
		// produce it.
		await expect(page.locator('.external-consultation-response')).toBeVisible({
			timeout: 15000,
		})

		// The exact string the manifest renderer shows when it cannot resolve a
		// declared page's component. Asserting its ABSENCE is what makes the
		// test able to fail for the defect it was written for.
		await expect(page.locator('body')).not.toContainText('This page is empty')

		// An unresolvable token deterministically lands in the loadError branch.
		await expect(
			page.getByRole('heading', { name: 'Not found', level: 2 }).first(),
		).toBeVisible({ timeout: 15000 })

		await expect(page.locator('body')).not.toContainText('Internal Server Error')
	})

	test('the token guard resolves each token to its OWN consultation', async () => {
		const resA = await api.get(`${PUBLIC_CONSULTATION_API}/${TOKEN_A}`)
		expect(
			resA.status(),
			'a token a consultation really carries must resolve — without this the refusal tests below prove nothing',
		).toBe(200)
		const bodyA = await resA.json()
		expect(bodyA.subject).toBe(`${RUN_PREFIX} CONSULTATION-A`)

		const resB = await api.get(`${PUBLIC_CONSULTATION_API}/${TOKEN_B}`)
		expect(resB.status()).toBe(200)
		const bodyB = await resB.json()

		// The discriminating assertion: a lookup that ignored its filter and
		// returned the first row of the collection would answer both calls with
		// the same consultation and pass every other check in this file.
		expect(bodyB.subject).toBe(`${RUN_PREFIX} CONSULTATION-B`)
		expect(bodyB.subject).not.toBe(bodyA.subject)
	})

	test('the response never echoes the secure token back', async () => {
		const res = await api.get(`${PUBLIC_CONSULTATION_API}/${TOKEN_A}`)
		expect(res.status()).toBe(200)
		const raw = await res.text()
		expect(raw).not.toContain('secureToken')
		expect(raw).not.toContain(TOKEN_A)
	})

	test('an unknown token is refused and discloses no consultation', async () => {
		const res = await api.get(`${PUBLIC_CONSULTATION_API}/${TOKEN_UNKNOWN}`)
		expect(res.status()).not.toBe(200)
		const raw = await res.text()
		expect(raw).not.toContain(RUN_PREFIX)
	})

	test('a token below the minimum length is refused and discloses no consultation', async () => {
		const res = await api.get(`${PUBLIC_CONSULTATION_API}/${TOKEN_TOO_SHORT}`)
		expect(res.status()).not.toBe(200)
		const raw = await res.text()
		expect(raw).not.toContain(RUN_PREFIX)
	})

	test('an anonymous caller with an unknown token receives no consultation', async () => {
		const res = await anon.get(`${PUBLIC_CONSULTATION_API}/${TOKEN_UNKNOWN}`)
		expect(res.status()).not.toBe(200)
		const raw = await res.text()
		expect(raw).not.toContain(RUN_PREFIX)
	})
})
