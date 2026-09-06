/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the PDOK-via-openconnector shim in src/services/pdokService.js.
 *
 * These assert the consumer contract from the migrate-pdok-to-openconnector
 * change: every network-calling export delegates to
 * `/index.php/apps/openconnector/api/pdok/{suggest|lookup|free|reverse}`,
 * never to api.pdok.nl; the pure utility functions make no network call; and
 * the two degraded modes (503 / 404) are handled without throwing.
 *
 * @spec openspec/changes/migrate-pdok-to-openconnector/specs/pdok-consumer/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	extractCoordinates,
	formatAddress,
	free,
	lastWarning,
	lookup,
	reverse,
	suggest,
} from '../../src/services/pdokService.js'

const BASE = '/index.php/apps/openconnector/api/pdok'

/**
 * Build an axios-style success response.
 *
 * @param {*} data The response payload.
 * @return {{data: *}} An axios-shaped response.
 */
function ok(data) {
	return { data }
}

/**
 * Build an axios-style HTTP error with a response status + body.
 *
 * @param {number} status HTTP status to attach to error.response.
 * @param {object} [data] Response body (e.g. { message_key }).
 * @return {Error} An axios-shaped rejection error.
 */
function httpError(status, data = {}) {
	const err = new Error(`HTTP ${status}`)
	err.response = { status, data }
	return err
}

describe('pdokService shim — endpoint routing', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('suggest delegates to the openconnector suggest endpoint, never api.pdok.nl', async () => {
		vi.useFakeTimers()
		axios.get.mockResolvedValue(
			ok({ docs: [{ id: 'adr-1', weergavenaam: 'Lauriergracht 116' }] }),
		)

		const promise = suggest('Lauriergracht')
		await vi.runAllTimersAsync()
		const result = await promise

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/suggest`, {
			params: { q: 'Lauriergracht' },
		})
		expect(result).toEqual([{ id: 'adr-1', weergavenaam: 'Lauriergracht 116' }])
		const calledUrls = axios.get.mock.calls.map((c) => c[0])
		expect(calledUrls.every((u) => !u.includes('api.pdok.nl'))).toBe(true)
		vi.useRealTimers()
	})

	it('suggest returns [] for queries shorter than 3 characters without calling the network', async () => {
		const result = await suggest('La')
		expect(result).toEqual([])
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('lookup delegates to the openconnector lookup endpoint and unwraps the first doc', async () => {
		axios.get.mockResolvedValue(
			ok({ docs: [{ id: 'adr-1', weergavenaam: 'Lauriergracht 116' }] }),
		)
		const result = await lookup('adr-1')
		expect(axios.get).toHaveBeenCalledWith(`${BASE}/lookup`, {
			params: { id: 'adr-1' },
		})
		expect(result).toEqual({ id: 'adr-1', weergavenaam: 'Lauriergracht 116' })
	})

	it('free delegates to the openconnector free endpoint with rows', async () => {
		axios.get.mockResolvedValue(ok({ docs: [{ id: 'x' }] }))
		const result = await free('Tilburg', 5)
		expect(axios.get).toHaveBeenCalledWith(`${BASE}/free`, {
			params: { q: 'Tilburg', rows: 5 },
		})
		expect(result).toEqual([{ id: 'x' }])
	})

	it('reverse delegates to the openconnector reverse endpoint with lat/lng', async () => {
		axios.get.mockResolvedValue(ok({ docs: [{ id: 'rev-1' }] }))
		const result = await reverse(52.37025, 4.88525)
		expect(axios.get).toHaveBeenCalledWith(`${BASE}/reverse`, {
			params: { lat: 52.37025, lng: 4.88525 },
		})
		expect(result).toEqual({ id: 'rev-1' })
	})
})

describe('pdokService shim — graceful degradation', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('503 on lookup resolves with null and surfaces the message_key', async () => {
		axios.get.mockRejectedValue(
			httpError(503, {
				error: 'pdok_unavailable',
				message_key: 'pdok.unavailable',
			}),
		)
		const result = await lookup('adr-1')
		expect(result).toBeNull()
		expect(lastWarning).toEqual({ messageKey: 'pdok.unavailable', status: 503 })
	})

	it('503 on suggest resolves with null and surfaces the message_key', async () => {
		vi.useFakeTimers()
		axios.get.mockRejectedValue(
			httpError(503, { message_key: 'pdok.unavailable' }),
		)
		const promise = suggest('Tilburg')
		await vi.runAllTimersAsync()
		const result = await promise
		expect(result).toBeNull()
		expect(lastWarning).toEqual({ messageKey: 'pdok.unavailable', status: 503 })
		vi.useRealTimers()
	})

	it('404 (openconnector absent) on free returns the empty fallback and sets a non-blocking warning', async () => {
		axios.get.mockRejectedValue(httpError(404))
		const result = await free('Tilburg')
		expect(result).toEqual([])
		expect(lastWarning).toEqual({
			messageKey: 'pdok.openconnector_missing',
			status: 404,
		})
	})

	it('404 on lookup returns null and sets the openconnector-missing warning', async () => {
		axios.get.mockRejectedValue(httpError(404))
		const result = await lookup('adr-1')
		expect(result).toBeNull()
		expect(lastWarning).toEqual({
			messageKey: 'pdok.openconnector_missing',
			status: 404,
		})
	})

	it('a non-503/404 error rethrows so the caller can decide', async () => {
		axios.get.mockRejectedValue(httpError(500, { error: 'boom' }))
		await expect(lookup('adr-1')).rejects.toThrow()
	})
})

describe('pdokService shim — pure utilities make no network call', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('extractCoordinates parses a PDOK WKT POINT(lng lat) into {lat, lng}', () => {
		expect(extractCoordinates('POINT(4.88525 52.37025)')).toEqual({
			lat: 52.37025,
			lng: 4.88525,
		})
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('extractCoordinates reads a normalized PostalAddress location.coordinates [lng, lat]', () => {
		expect(
			extractCoordinates({ location: { coordinates: [4.88525, 52.37025] } }),
		).toEqual({ lat: 52.37025, lng: 4.88525 })
	})

	it('extractCoordinates reads a raw PDOK centroide_ll field', () => {
		expect(
			extractCoordinates({ centroide_ll: 'POINT(4.88525 52.37025)' }),
		).toEqual({ lat: 52.37025, lng: 4.88525 })
	})

	it('extractCoordinates returns null for empty or unparseable input', () => {
		expect(extractCoordinates(null)).toBeNull()
		expect(extractCoordinates('NOT-WKT')).toBeNull()
		expect(extractCoordinates({})).toBeNull()
	})

	it('formatAddress returns the human-readable name for both normalized and raw shapes', () => {
		expect(formatAddress({ displayName: 'Lauriergracht 116' })).toBe(
			'Lauriergracht 116',
		)
		expect(formatAddress({ weergavenaam: 'Lauriergracht 116' })).toBe(
			'Lauriergracht 116',
		)
		expect(formatAddress(null)).toBe('')
		expect(axios.get).not.toHaveBeenCalled()
	})
})
