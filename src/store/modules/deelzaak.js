/**
 * Pinia store module for parent-child case (deelzaak) state.
 *
 * Exposes the spec-named actions (`fetchSubCases`, `fetchParentCase`,
 * `fetchSubCaseCounts`) backed by `services/deelzaakApi.js`. Components
 * that need sub-case data should use this store rather than poking the
 * generic object store directly.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T04
 */
import { defineStore } from 'pinia'
import {
	fetchParentCase as apiFetchParentCase,
	fetchSubCaseCounts as apiFetchSubCaseCounts,
	fetchSubCases as apiFetchSubCases,
	unlinkSubCases as apiUnlinkSubCases,
	validateSubCase as apiValidateSubCase,
} from '../../services/deelzaakApi.js'

export const useDeelzaakStore = defineStore('deelzaak', {
	state: () => ({
		subCases: [],
		parentCase: null,
		subCaseCounts: {},
		loading: false,
		error: null,
	}),
	getters: {
		getSubCases: (state) => state.subCases,
		getParentCase: (state) => state.parentCase,
		getSubCaseCount: (state) => (uuid) => state.subCaseCounts[uuid] || 0,
	},
	actions: {
		/**
		 * @param {string} parentCaseUuid UUID of the parent case.
		 * @spec openspec/changes/deelzaak-support/tasks.md#T01
		 */
		async fetchSubCases(parentCaseUuid) {
			this.loading = true
			this.error = null
			try {
				this.subCases = await apiFetchSubCases(parentCaseUuid)
				return this.subCases
			} catch (err) {
				this.error = err
				this.subCases = []
				throw err
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} parentCaseUuid UUID of the parent case.
		 * @spec openspec/changes/deelzaak-support/tasks.md#T02
		 */
		async fetchParentCase(parentCaseUuid) {
			try {
				this.parentCase = await apiFetchParentCase(parentCaseUuid)
				return this.parentCase
			} catch (err) {
				this.parentCase = null
				throw err
			}
		},

		/**
		 * @param {Array} caseUuidArray The case array.
		 * @spec openspec/changes/deelzaak-support/tasks.md#T03
		 */
		async fetchSubCaseCounts(caseUuidArray) {
			if (!Array.isArray(caseUuidArray) || caseUuidArray.length === 0) {
				return {}
			}
			const counts = await apiFetchSubCaseCounts(caseUuidArray)
			this.subCaseCounts = { ...this.subCaseCounts, ...counts }
			return counts
		},

		/**
		 * @param {object} params The params.
		 * @spec openspec/changes/deelzaak-support/tasks.md#T08
		 */
		async validateSubCase(params) {
			return apiValidateSubCase(params)
		},

		/**
		 * Unlink every sub-case of a parent.
		 *
		 * Returns the full `{unlinked, failed, total, complete}` result rather
		 * than a bare count — callers must check `complete` before deleting the
		 * parent (procest#793).
		 *
		 * @param {string} parentCaseUuid Parent UUID.
		 * @return {Promise<{unlinked: number, failed: number, total: number, complete: boolean}>} The unlink outcome.
		 * @spec openspec/specs/deelzaak-support/spec.md
		 */
		async unlinkSubCases(parentCaseUuid) {
			const result = await apiUnlinkSubCases(parentCaseUuid)
			// Drop the local cache count so the UI re-renders fresh on next read.
			if (this.subCaseCounts[parentCaseUuid]) {
				const next = { ...this.subCaseCounts }
				delete next[parentCaseUuid]
				this.subCaseCounts = next
			}
			return result
		},
	},
})
