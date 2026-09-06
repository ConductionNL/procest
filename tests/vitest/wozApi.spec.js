/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the WOZ lookup shim in src/services/wozApi.js.
 *
 * These assert the consumer contract from the brk-woz-register-adapters
 * change: every export delegates to dossiq's own
 * `/apps/dossiq/api/external/woz/*` routes (never wozwaardeloket.nl,
 * which has no programmatic API — see design.md Decision 2), forwards
 * optional huisletter/huisnummertoevoeging only when present, and returns
 * the adapter's raw envelope (`{lookupStatus, wozObject, dormant, extras}`)
 * unmodified — callers branch on `lookupStatus`, not on HTTP status.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it } from 'vitest'
import {
	lookupWozObject,
	lookupWozValue,
	lookupWozValueByNummeraanduiding,
} from '../../src/services/wozApi.js'

const BASE = '/index.php/apps/dossiq/api/external/woz'

/**
 * Build an axios-style success response.
 *
 * @param {*} data The response payload.
 * @return {{data: *}} An axios-shaped response.
 */
function ok(data) {
	return { data }
}

describe('wozApi shim — endpoint routing', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('lookupWozValue delegates to the dossiq value route with postcode + huisnummer', async () => {
		const envelope = {
			lookupStatus: 'FOUND',
			wozObject: { wozobjectnummer: '05180000001234' },
			dormant: false,
			extras: { tier: 'test' },
		}
		axios.get.mockResolvedValue(ok(envelope))

		const result = await lookupWozValue('1234AB', '10')

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/value`, {
			params: { postcode: '1234AB', huisnummer: '10' },
		})
		expect(result).toEqual(envelope)
		const calledUrls = axios.get.mock.calls.map((c) => c[0])
		expect(calledUrls.every((u) => !u.includes('wozwaardeloket.nl'))).toBe(true)
	})

	it('lookupWozValue forwards huisletter/huisnummertoevoeging only when supplied', async () => {
		axios.get.mockResolvedValue(
			ok({
				lookupStatus: 'NOT_FOUND',
				wozObject: {},
				dormant: false,
				extras: {},
			}),
		)

		await lookupWozValue('1234AB', '10', {
			huisletter: 'A',
			huisnummertoevoeging: 'II',
		})

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/value`, {
			params: {
				postcode: '1234AB',
				huisnummer: '10',
				huisletter: 'A',
				huisnummertoevoeging: 'II',
			},
		})
	})

	it('lookupWozValue omits optional params entirely when not supplied', async () => {
		axios.get.mockResolvedValue(
			ok({
				lookupStatus: 'NOT_FOUND',
				wozObject: {},
				dormant: false,
				extras: {},
			}),
		)

		await lookupWozValue('1234AB', '10')

		const [, config] = axios.get.mock.calls[0]
		expect(config.params).not.toHaveProperty('huisletter')
		expect(config.params).not.toHaveProperty('huisnummertoevoeging')
	})

	it('lookupWozValueByNummeraanduiding delegates to the dossiq value route with nummeraanduidingId', async () => {
		const envelope = {
			lookupStatus: 'FOUND',
			wozObject: { wozobjectnummer: '05180000001234' },
			dormant: false,
			extras: {},
		}
		axios.get.mockResolvedValue(ok(envelope))

		const result = await lookupWozValueByNummeraanduiding('0518010000123456')

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/value`, {
			params: { nummeraanduidingId: '0518010000123456' },
		})
		expect(result).toEqual(envelope)
	})

	it('lookupWozObject delegates to the dossiq object route with an encoded wozobjectnummer', async () => {
		const envelope = {
			lookupStatus: 'FOUND',
			wozObject: { waarde: 385000 },
			dormant: false,
			extras: {},
		}
		axios.get.mockResolvedValue(ok(envelope))

		const result = await lookupWozObject('05180000001234')

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/value/05180000001234`)
		expect(result).toEqual(envelope)
	})
})

describe('wozApi shim — dormant / degraded passthrough', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('returns the LOOKUP_DEFERRED envelope unmodified when the adapter is dormant', async () => {
		const envelope = {
			lookupStatus: 'LOOKUP_DEFERRED',
			wozObject: {},
			dormant: true,
			extras: { reason: 'no-outbound-connector-bound' },
		}
		axios.get.mockResolvedValue(ok(envelope))

		const result = await lookupWozValue('1234AB', '10')
		expect(result).toEqual(envelope)
	})
})
