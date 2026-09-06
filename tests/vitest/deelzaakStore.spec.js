/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component-layer unit tests for the deelzaak (sub-case) Pinia store
 * (src/store/modules/deelzaak.js) — the data backbone the new
 * DeelzaakList / DeelzaakDetail / DeelzaakCreateModal components consume.
 *
 * The store is the cleanest real target for the new sub-case UI: every
 * deelzaak component reads `getSubCases` / `getParentCase` / `getSubCaseCount`
 * and drives `fetchSubCases` / `fetchParentCase` / `fetchSubCaseCounts` /
 * `validateSubCase` / `unlinkSubCases`. We mount the REAL store on a fresh
 * Pinia and stub only the network seam (`services/deelzaakApi.js`) so the
 * action/getter/state wiring + the cache-eviction logic are asserted exactly.
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T04
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	fetchParentCase as apiFetchParentCase,
	fetchSubCaseCounts as apiFetchSubCaseCounts,
	fetchSubCases as apiFetchSubCases,
	unlinkSubCases as apiUnlinkSubCases,
	validateSubCase as apiValidateSubCase,
} from '../../src/services/deelzaakApi.js'
import { useDeelzaakStore } from '../../src/store/modules/deelzaak.js'

// Stub the network seam: the store talks to deelzaakApi, which itself wraps
// @nextcloud/axios + @nextcloud/router (no runtime under Vitest). Mocking the
// api module keeps these tests pure-logic while still exercising the real
// store actions/getters/state transitions.
vi.mock('../../src/services/deelzaakApi.js', () => ({
	fetchSubCases: vi.fn(),
	fetchParentCase: vi.fn(),
	fetchSubCaseCounts: vi.fn(),
	validateSubCase: vi.fn(),
	unlinkSubCases: vi.fn(),
}))

describe('deelzaak store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useDeelzaakStore()
		vi.clearAllMocks()
	})

	it('starts empty with the documented initial state', () => {
		expect(store.subCases).toEqual([])
		expect(store.parentCase).toBeNull()
		expect(store.subCaseCounts).toEqual({})
		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()
		expect(store.getSubCases).toEqual([])
		expect(store.getSubCaseCount('any-uuid')).toBe(0)
	})

	describe('fetchSubCases', () => {
		it('loads sub-cases into state and exposes them via the getter', async () => {
			const rows = [
				{ id: 'a', title: 'Sub A' },
				{ id: 'b', title: 'Sub B' },
			]
			apiFetchSubCases.mockResolvedValueOnce(rows)

			const result = await store.fetchSubCases('parent-1')

			expect(apiFetchSubCases).toHaveBeenCalledWith('parent-1')
			expect(result).toEqual(rows)
			expect(store.subCases).toEqual(rows)
			expect(store.getSubCases).toEqual(rows)
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})

		it('toggles loading true during the in-flight fetch then back to false', async () => {
			let resolveFn
			apiFetchSubCases.mockReturnValueOnce(
				new Promise((resolve) => {
					resolveFn = resolve
				}),
			)

			const pending = store.fetchSubCases('parent-1')
			expect(store.loading).toBe(true)

			resolveFn([{ id: 'x' }])
			await pending
			expect(store.loading).toBe(false)
		})

		it('clears the list, records the error, and rethrows on failure', async () => {
			store.subCases = [{ id: 'stale' }]
			const boom = new Error('network down')
			apiFetchSubCases.mockRejectedValueOnce(boom)

			await expect(store.fetchSubCases('parent-1')).rejects.toThrow(
				'network down',
			)
			expect(store.subCases).toEqual([])
			expect(store.error).toBe(boom)
			expect(store.loading).toBe(false)
		})
	})

	describe('fetchParentCase', () => {
		it('caches the parent case and surfaces it via the getter', async () => {
			const parent = { id: 'p1', title: 'Parent case' }
			apiFetchParentCase.mockResolvedValueOnce(parent)

			const result = await store.fetchParentCase('p1')

			expect(apiFetchParentCase).toHaveBeenCalledWith('p1')
			expect(result).toEqual(parent)
			expect(store.parentCase).toEqual(parent)
			expect(store.getParentCase).toEqual(parent)
		})

		it('nulls the parent and rethrows on failure', async () => {
			store.parentCase = { id: 'stale' }
			apiFetchParentCase.mockRejectedValueOnce(new Error('404'))

			await expect(store.fetchParentCase('missing')).rejects.toThrow('404')
			expect(store.parentCase).toBeNull()
		})
	})

	describe('fetchSubCaseCounts', () => {
		it('short-circuits on an empty/invalid id list without a network call', async () => {
			expect(await store.fetchSubCaseCounts([])).toEqual({})
			expect(await store.fetchSubCaseCounts(null)).toEqual({})
			expect(apiFetchSubCaseCounts).not.toHaveBeenCalled()
		})

		it('merges returned counts into the cache and exposes them per-uuid', async () => {
			apiFetchSubCaseCounts.mockResolvedValueOnce({ p1: 3, p2: 0 })

			const counts = await store.fetchSubCaseCounts(['p1', 'p2'])

			expect(apiFetchSubCaseCounts).toHaveBeenCalledWith(['p1', 'p2'])
			expect(counts).toEqual({ p1: 3, p2: 0 })
			expect(store.getSubCaseCount('p1')).toBe(3)
			expect(store.getSubCaseCount('p2')).toBe(0)
			expect(store.getSubCaseCount('unknown')).toBe(0)
		})

		it('preserves previously-cached counts across a second batch', async () => {
			apiFetchSubCaseCounts.mockResolvedValueOnce({ p1: 2 })
			await store.fetchSubCaseCounts(['p1'])
			apiFetchSubCaseCounts.mockResolvedValueOnce({ p2: 5 })
			await store.fetchSubCaseCounts(['p2'])

			expect(store.getSubCaseCount('p1')).toBe(2)
			expect(store.getSubCaseCount('p2')).toBe(5)
		})
	})

	describe('validateSubCase', () => {
		it('delegates to the api and returns its verdict verbatim', async () => {
			apiValidateSubCase.mockResolvedValueOnce({
				ok: false,
				reason: 'not_allowed',
			})

			const verdict = await store.validateSubCase({
				parentCaseUuid: 'p1',
				childCaseTypeId: 'ct9',
			})

			expect(apiValidateSubCase).toHaveBeenCalledWith({
				parentCaseUuid: 'p1',
				childCaseTypeId: 'ct9',
			})
			expect(verdict).toEqual({ ok: false, reason: 'not_allowed' })
		})
	})

	describe('unlinkSubCases', () => {
		it('returns the full unlink result and evicts the stale cache entry for the parent', async () => {
			// Warm the cache first.
			apiFetchSubCaseCounts.mockResolvedValueOnce({ p1: 4, p2: 1 })
			await store.fetchSubCaseCounts(['p1', 'p2'])
			expect(store.getSubCaseCount('p1')).toBe(4)

			apiUnlinkSubCases.mockResolvedValueOnce({
				unlinked: 4,
				failed: 0,
				total: 4,
				complete: true,
			})
			const result = await store.unlinkSubCases('p1')

			expect(apiUnlinkSubCases).toHaveBeenCalledWith('p1')
			expect(result).toEqual({
				unlinked: 4,
				failed: 0,
				total: 4,
				complete: true,
			})
			// p1's cached count is dropped so the UI re-reads fresh; p2 untouched.
			expect(store.getSubCaseCount('p1')).toBe(0)
			expect(store.getSubCaseCount('p2')).toBe(1)
			expect(Object.hasOwn(store.subCaseCounts, 'p1')).toBe(false)
		})

		it('is a no-op on the cache when the parent had no cached count', async () => {
			apiUnlinkSubCases.mockResolvedValueOnce({
				unlinked: 0,
				failed: 0,
				total: 0,
				complete: true,
			})
			const result = await store.unlinkSubCases('never-cached')
			expect(result.unlinked).toBe(0)
			expect(store.subCaseCounts).toEqual({})
		})

		// procest#793: a partial unlink must surface as complete:false so the
		// caller refuses to delete the parent. The store must pass it through
		// untouched — swallowing it here would restore the silent-orphan bug.
		it('passes an incomplete unlink through so the caller can abort the delete', async () => {
			apiUnlinkSubCases.mockResolvedValueOnce({
				unlinked: 197,
				failed: 3,
				total: 200,
				complete: false,
			})
			const result = await store.unlinkSubCases('p1')

			expect(result.complete).toBe(false)
			expect(result.failed).toBe(3)
			expect(result.total).toBe(200)
		})
	})
})
