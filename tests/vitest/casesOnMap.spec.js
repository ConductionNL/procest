/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the cases-on-map overview migration to OpenRegister's
 * page-level maps-overview surface (OR #154, issue #112):
 *
 *   - `shapeMarkerFeatures` (src/services/mapFormatters.js) — the pure
 *     presentation step that turns OR point rows into the GeoJSON Feature
 *     array `CnMapWidget` renders. This is the marker-shaping logic the
 *     CasesOnMapView relies on; it has no DOM / Leaflet dependency.
 *   - `casesOnMapApi.js` — the thin OR maps-overview client (register +
 *     fetch points). Asserts the endpoint URLs, the `{ points }` unwrapping,
 *     and the fail-closed degradation (empty array / null, never throws).
 *
 * @spec openspec/specs/case-map-overview/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeAll, beforeEach, describe, expect, it } from 'vitest'

beforeAll(() => {
	globalThis.t = (app, text, vars) => {
		if (!vars) return text
		return text.replace(/\{(\w+)\}/g, (_, k) =>
			vars[k] !== null && vars[k] !== undefined ? String(vars[k]) : '',
		)
	}
})

describe('shapeMarkerFeatures', () => {
	it('maps OR point rows to GeoJSON Point features ([lng, lat] order)', async () => {
		const { shapeMarkerFeatures } =
			await import('../../src/services/mapFormatters.js')
		const features = shapeMarkerFeatures([
			{ id: 'c1', label: 'Case one', lat: 52.1, lng: 5.2, status: 'open' },
		])
		expect(features).toHaveLength(1)
		expect(features[0].type).toBe('Feature')
		expect(features[0].geometry).toEqual({
			type: 'Point',
			coordinates: [5.2, 52.1],
		})
		expect(features[0].properties.caseId).toBe('c1')
		expect(features[0].properties.title).toBe('Case one')
		expect(features[0].properties.status).toBe('open')
	})

	it('carries a status colour + icon so markers are colour-coded', async () => {
		const { shapeMarkerFeatures } =
			await import('../../src/services/mapFormatters.js')
		const [blocked] = shapeMarkerFeatures([
			{ id: 'b', lat: 1, lng: 2, status: 'blocked' },
		])
		expect(blocked.properties.color).toBe('var(--color-status-error)')
		expect(blocked.properties.icon).toBe('alert-circle')
	})

	it('skips rows without finite coordinates (no throw on malformed data)', async () => {
		const { shapeMarkerFeatures } =
			await import('../../src/services/mapFormatters.js')
		const features = shapeMarkerFeatures([
			{ id: 'ok', lat: 52, lng: 5 },
			{ id: 'no-geo', lat: null, lng: null },
			{ id: 'nan', lat: 'x', lng: 'y' },
			null,
		])
		expect(features.map((f) => f.properties.caseId)).toEqual(['ok'])
	})

	it('returns an empty array for non-array input', async () => {
		const { shapeMarkerFeatures } =
			await import('../../src/services/mapFormatters.js')
		expect(shapeMarkerFeatures(undefined)).toEqual([])
		expect(shapeMarkerFeatures(null)).toEqual([])
		expect(shapeMarkerFeatures({})).toEqual([])
	})

	it('stringifies a numeric id and tolerates a missing label', async () => {
		const { shapeMarkerFeatures } =
			await import('../../src/services/mapFormatters.js')
		const [f] = shapeMarkerFeatures([{ id: 42, lat: 1, lng: 2 }])
		expect(f.properties.caseId).toBe('42')
		expect(f.properties.title).toBe('')
	})
})

describe('fetchCasePoints — consumes the OR maps-overview points endpoint', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('hits the OR points URL and unwraps the { points } envelope', async () => {
		const { fetchCasePoints } =
			await import('../../src/services/casesOnMapApi.js')
		axios.get.mockResolvedValue({
			data: { points: [{ id: 'c1', lat: 52, lng: 5 }], count: 1 },
		})

		const points = await fetchCasePoints({
			register: 'dossiq',
			schema: 'case',
			filters: { status: 'open' },
		})

		expect(axios.get).toHaveBeenCalledTimes(1)
		const [url, options] = axios.get.mock.calls[0]
		expect(url).toBe(
			'/index.php/apps/openregister/api/integrations/maps/overviews/dossiq/case/points',
		)
		expect(options).toEqual({ params: { status: 'open' } })
		expect(points).toEqual([{ id: 'c1', lat: 52, lng: 5 }])
	})

	it('returns an empty array when OR returns no points envelope', async () => {
		const { fetchCasePoints } =
			await import('../../src/services/casesOnMapApi.js')
		axios.get.mockResolvedValue({ data: {} })
		expect(await fetchCasePoints()).toEqual([])
	})

	it('degrades to an empty array when OR is unavailable (fail-closed, no throw)', async () => {
		const { fetchCasePoints } =
			await import('../../src/services/casesOnMapApi.js')
		axios.get.mockRejectedValue(new Error('503'))
		await expect(fetchCasePoints()).resolves.toEqual([])
	})
})

describe('registerCasesOnMapOverview — declares the overview with OR', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('POSTs the overview declaration with the stable key + scope', async () => {
		const { registerCasesOnMapOverview, CASES_ON_MAP_KEY } =
			await import('../../src/services/casesOnMapApi.js')
		axios.post.mockResolvedValue({
			data: { id: 'maps-overview:' + CASES_ON_MAP_KEY },
		})

		await registerCasesOnMapOverview({ register: 'dossiq', schema: 'case' })

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe(
			'/index.php/apps/openregister/api/integrations/maps/overviews',
		)
		expect(body.overviewKey).toBe(CASES_ON_MAP_KEY)
		expect(body.register).toBe('dossiq')
		expect(body.schema).toBe('case')
	})

	it('returns null (never throws) when the declaration fails', async () => {
		const { registerCasesOnMapOverview } =
			await import('../../src/services/casesOnMapApi.js')
		axios.post.mockRejectedValue(new Error('400'))
		await expect(registerCasesOnMapOverview()).resolves.toBeNull()
	})
})
