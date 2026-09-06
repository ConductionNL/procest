/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared wiring for the four process-mining dashboard widgets.
 *
 * Each widget is rendered into a `#widget-<id>` slot of CnDashboardPage, which
 * `provide()`s a reactive `cnWorkspaceContext`. The page's date-range preset and
 * case-type filter land in there, so a widget reads its query from the context
 * rather than owning filter controls of its own — that is what lets one header
 * drive four widgets instead of each widget growing its own toolbar.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
 */
import { useProcessMiningStore } from '../../store/modules/processMining.js'

export const pmWidgetMixin = {
	inject: {
		workspace: { from: 'cnWorkspaceContext', default: () => ({}) },
	},

	computed: {
		/**
		 * @return {object} The shared process-mining store.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		pmStore() {
			return useProcessMiningStore()
		},
		/**
		 * @return {string} Selected period preset, defaulting to 12 months.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		pmPreset() {
			return this.workspace?.period || '12m'
		},
		/**
		 * @return {string|null} Selected case-type id, or null for all.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		pmCaseType() {
			return this.workspace?.caseType || null
		},
		/**
		 * @return {boolean} Whether the shared report is being fetched.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		pmLoading() {
			return this.pmStore.loading
		},
		/**
		 * @return {Array<object>} Per-case-type blocks from the report.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		pmCaseTypes() {
			return this.pmStore.caseTypesList
		},
		/**
		 * The case type a single-series visualisation should scope to: the
		 * filtered one, else the busiest (the report is ordered by volume).
		 *
		 * @return {object|null}
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		pmPrimaryCaseType() {
			if (this.pmCaseType) {
				return (
					this.pmCaseTypes.find((ct) => ct.id === this.pmCaseType) || null
				)
			}
			return this.pmCaseTypes[0] || null
		},
	},

	watch: {
		pmPreset: 'pmLoad',
		pmCaseType: 'pmLoad',
	},

	mounted() {
		this.pmLoad()
	},

	methods: {
		/**
		 * Ask the shared store for the current query; it deduplicates.
		 *
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		pmLoad() {
			this.pmStore.load({ preset: this.pmPreset, caseType: this.pmCaseType })
		},
	},
}
