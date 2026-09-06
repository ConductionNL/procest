/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the BRK lookup shim in src/services/brkApi.js.
 *
 * These assert the consumer contract from the brk-woz-register-adapters
 * change: every export delegates to dossiq's own
 * `/apps/dossiq/api/external/brk/*` routes, forwards the optional
 * appartementsrechtVolgnummer only when present, and returns the
 * adapter's raw envelope (`{lookupStatus, parcel, dormant, extras}`)
 * unmodified — callers branch on `lookupStatus`, not on HTTP status.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it } from 'vitest'
import { lookupParcel, lookupParcelById } from '../../src/services/brkApi.js'

const BASE = '/index.php/apps/dossiq/api/external/brk'

/**
 * Build an axios-style success response.
 *
 * @param {*} data The response payload.
 * @return {{data: *}} An axios-shaped response.
 */
function ok(data) {
	return { data }
}

describe('brkApi shim — endpoint routing', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('lookupParcel delegates to the dossiq parcel route with kadastrale-aanduiding params', async () => {
		const envelope = {
			lookupStatus: 'FOUND',
			parcel: { sectie: 'A' },
			dormant: false,
			extras: { tier: 'test' },
		}
		axios.get.mockResolvedValue(ok(envelope))

		const result = await lookupParcel('VBSTD', 'A', '1234')

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/parcel`, {
			params: {
				kadastraleGemeenteCode: 'VBSTD',
				sectie: 'A',
				perceelnummer: '1234',
			},
		})
		expect(result).toEqual(envelope)
	})

	it('lookupParcel forwards appartementsrechtVolgnummer only when supplied', async () => {
		axios.get.mockResolvedValue(
			ok({
				lookupStatus: 'NOT_FOUND',
				parcel: {},
				dormant: false,
				extras: {},
			}),
		)

		await lookupParcel('VBSTD', 'A', '1234', {
			appartementsrechtVolgnummer: 'A1',
		})

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/parcel`, {
			params: {
				kadastraleGemeenteCode: 'VBSTD',
				sectie: 'A',
				perceelnummer: '1234',
				appartementsrechtVolgnummer: 'A1',
			},
		})
	})

	it('lookupParcel omits appartementsrechtVolgnummer entirely when not supplied', async () => {
		axios.get.mockResolvedValue(
			ok({
				lookupStatus: 'NOT_FOUND',
				parcel: {},
				dormant: false,
				extras: {},
			}),
		)

		await lookupParcel('VBSTD', 'A', '1234')

		const [, config] = axios.get.mock.calls[0]
		expect(config.params).not.toHaveProperty('appartementsrechtVolgnummer')
	})

	it('lookupParcelById delegates to the dossiq parcel-by-id route with an encoded id', async () => {
		const envelope = {
			lookupStatus: 'FOUND',
			parcel: { oppervlakte: 350 },
			dormant: false,
			extras: {},
		}
		axios.get.mockResolvedValue(ok(envelope))

		const result = await lookupParcelById('10280123450000')

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/parcel/10280123450000`)
		expect(result).toEqual(envelope)
	})
})

describe('brkApi shim — dormant / degraded passthrough', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('returns the LOOKUP_DEFERRED envelope unmodified when the adapter is dormant', async () => {
		const envelope = {
			lookupStatus: 'LOOKUP_DEFERRED',
			parcel: {},
			dormant: true,
			extras: { reason: 'no-outbound-connector-bound' },
		}
		axios.get.mockResolvedValue(ok(envelope))

		const result = await lookupParcel('VBSTD', 'A', '1234')
		expect(result).toEqual(envelope)
	})
})
