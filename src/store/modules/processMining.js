/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared state for the process-mining dashboard.
 *
 * The dashboard used to be one 527-line component that fetched the report and
 * rendered every visualisation itself. It is now a manifest `type: "dashboard"`
 * page composed of four widgets (ADR-036/ADR-049), and four independent widget
 * components must not each issue their own `/api/reports/process-mining` call
 * for the same period. This store is the single fetch: the page's filters write
 * into it, every widget reads from it.
 *
 * `load()` is deduplicated on the resolved query, so the four widgets mounting
 * in the same tick produce one request, and a filter change produces exactly
 * one more.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
 */
import { defineStore } from 'pinia'
import { fetchProcessMiningReport } from '../../services/processMiningApi.js'

/**
 * Resolve a period preset key into a `{ from, to }` pair of `Y-m-d` strings.
 *
 * `all` yields a null `from`, which the API reads as "no lower bound".
 *
 * @param {string} preset One of `3m`, `6m`, `12m`, `all`.
 * @return {{from: string|null, to: string}} The resolved range.
 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
 */
export function resolvePeriod(preset) {
	const now = new Date()
	const iso = (d) => d.toISOString().slice(0, 10)
	let from
	switch (preset) {
		case '3m':
			from = new Date(now.getFullYear(), now.getMonth() - 3, 1)
			break
		case '6m':
			from = new Date(now.getFullYear(), now.getMonth() - 6, 1)
			break
		case 'all':
			from = null
			break
		default:
			from = new Date(now.getFullYear(), now.getMonth() - 12, 1)
	}
	return { from: from ? iso(from) : null, to: iso(now) }
}

export const useProcessMiningStore = defineStore('processMining', {
	state: () => ({
		report: null,
		loading: false,
		error: null,
		/** The query the current `report` belongs to, so a repeat is a no-op. */
		loadedKey: null,
		/** In-flight promise, shared by every widget that asks while it runs. */
		inflight: null,
	}),

	getters: {
		/**
		 * @param {object} state The Pinia store state.
		 * @return {Array<object>} Per-case-type report blocks, never null.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		caseTypesList: (state) => state.report?.caseTypes || [],
		/**
		 * @param {object} state The Pinia store state.
		 * @return {Array<object>} Weekly throughput points, never null.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		throughputTrend: (state) => state.report?.throughputTrend || [],
	},

	actions: {
		/**
		 * Fetch the report for a period preset and optional case-type filter.
		 *
		 * Concurrent callers with the same query share one request; a repeat of
		 * the query already loaded returns immediately. `force` bypasses both,
		 * for the page's Refresh action.
		 *
		 * @param {object}      opts            Query.
		 * @param {string}      [opts.preset]   Period preset key.
		 * @param {string|null} [opts.caseType] CaseType id to scope on.
		 * @param {boolean}     [opts.force]    Refetch even if already loaded.
		 * @return {Promise<void>}
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		async load({ preset = '12m', caseType = null, force = false } = {}) {
			const { from, to } = resolvePeriod(preset)
			const key = `${from || '*'}|${to}|${caseType || '*'}`
			if (!force && this.loadedKey === key && this.report) return
			if (!force && this.inflight && this.loadedKey === key)
				return this.inflight

			this.loading = true
			this.error = null
			this.loadedKey = key
			this.inflight = (async () => {
				try {
					this.report = await fetchProcessMiningReport({
						from,
						to,
						caseType,
					})
				} catch (err) {
					// Surface the message the page shows; keep the previous report
					// visible rather than blanking the dashboard on a transient error.
					this.error =
						err?.response?.data?.message
						|| err?.message
						|| 'Could not load the process-mining report.'
				} finally {
					this.loading = false
					this.inflight = null
				}
			})()
			return this.inflight
		},
	},
})
