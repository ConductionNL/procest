/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the BAG lookup shim in src/services/bagApi.js.
 *
 * These assert the consumer contract from the bag-register-adapter change:
 * every export delegates to dossiq's own `/apps/dossiq/api/external/bag/*`
 * routes (not openconnector, and not api.bag.kadaster.nl directly), forwards
 * optional huisletter/huisnummertoevoeging only when present, and returns
 * the adapter's raw envelope (`{lookupStatus, address, dormant, extras}`)
 * unmodified — callers branch on `lookupStatus`, not on HTTP status.
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it } from 'vitest'
import {
	lookupAddress,
	lookupPand,
	lookupVerblijfsobject,
} from '../../src/services/bagApi.js'

const BASE = '/index.php/apps/dossiq/api/external/bag'

/**
 * Build an axios-style success response.
 *
 * @param {*} data The response payload.
 * @return {{data: *}} An axios-shaped response.
 */
function ok(data) {
	return { data }
}

describe('bagApi shim — endpoint routing', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('lookupAddress delegates to the dossiq address route with postcode + huisnummer', async () => {
		const envelope = {
			lookupStatus: 'FOUND',
			address: { street: 'Voorstraat' },
			dormant: false,
			extras: { tier: 'test' },
		}
		axios.get.mockResolvedValue(ok(envelope))

		const result = await lookupAddress('1234AB', '10')

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/address`, {
			params: { postcode: '1234AB', huisnummer: '10' },
		})
		expect(result).toEqual(envelope)
		const calledUrls = axios.get.mock.calls.map((c) => c[0])
		expect(
			calledUrls.every(
				(u) =>
					!u.includes('api.bag.kadaster.nl')
					&& !u.includes('openconnector'),
			),
		).toBe(true)
	})

	it('lookupAddress forwards huisletter/huisnummertoevoeging only when supplied', async () => {
		axios.get.mockResolvedValue(
			ok({
				lookupStatus: 'NOT_FOUND',
				address: {},
				dormant: false,
				extras: {},
			}),
		)

		await lookupAddress('1234AB', '10', {
			huisletter: 'A',
			huisnummertoevoeging: 'II',
		})

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/address`, {
			params: {
				postcode: '1234AB',
				huisnummer: '10',
				huisletter: 'A',
				huisnummertoevoeging: 'II',
			},
		})
	})

	it('lookupAddress omits optional params entirely when not supplied', async () => {
		axios.get.mockResolvedValue(
			ok({
				lookupStatus: 'NOT_FOUND',
				address: {},
				dormant: false,
				extras: {},
			}),
		)

		await lookupAddress('1234AB', '10')

		const [, config] = axios.get.mock.calls[0]
		expect(config.params).not.toHaveProperty('huisletter')
		expect(config.params).not.toHaveProperty('huisnummertoevoeging')
	})

	it('lookupPand delegates to the dossiq pand route with an encoded id', async () => {
		const envelope = {
			lookupStatus: 'FOUND',
			address: { oorspronkelijkBouwjaar: 1998 },
			dormant: false,
			extras: {},
		}
		axios.get.mockResolvedValue(ok(envelope))

		const result = await lookupPand('0518100000123456')

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/pand/0518100000123456`)
		expect(result).toEqual(envelope)
	})

	it('lookupVerblijfsobject delegates to the dossiq verblijfsobject route', async () => {
		const envelope = {
			lookupStatus: 'NOT_FOUND',
			address: {},
			dormant: false,
			extras: {},
		}
		axios.get.mockResolvedValue(ok(envelope))

		const result = await lookupVerblijfsobject('0518010000123456')

		expect(axios.get).toHaveBeenCalledWith(
			`${BASE}/verblijfsobject/0518010000123456`,
		)
		expect(result).toEqual(envelope)
	})
})

describe('bagApi shim — dormant / degraded passthrough', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('returns the LOOKUP_DEFERRED envelope unmodified when the adapter is dormant', async () => {
		const envelope = {
			lookupStatus: 'LOOKUP_DEFERRED',
			address: {},
			dormant: true,
			extras: { reason: 'no-outbound-connector-bound' },
		}
		axios.get.mockResolvedValue(ok(envelope))

		const result = await lookupAddress('1234AB', '10')
		expect(result).toEqual(envelope)
	})
})
