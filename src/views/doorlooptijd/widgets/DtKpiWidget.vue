<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Processing-time KPI row.

	This widget also carries the dashboard's three guidance states (no cases /
	no SLA targets configured / nothing in the selected range). They were
	page-level `v-else-if` branches that blanked the entire dashboard; as a
	widget they replace only the KPI row, so the other widgets still render
	their own honest empty states instead of the page showing one message and
	hiding four panels that had things to say.

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<div class="dt-kpi-widget">
		<NcLoadingIcon v-if="dtLoading" :size="24" />

		<NcEmptyContent
			v-else-if="noCases"
			:name="
				t('dossiq', 'No case data available for processing time analysis.')
			" />

		<div v-else-if="noSla" class="dt-kpi-widget__guidance">
			<p>
				{{
					t(
						'procest',
						'No SLA targets configured. Set processing deadlines on case types in Settings to enable compliance tracking.',
					)
				}}
			</p>
			<NcButton variant="primary" @click="goToSettings">
				{{ t('dossiq', 'Go to Settings') }}
			</NcButton>
		</div>

		<NcEmptyContent
			v-else-if="noDataInRange"
			:name="t('dossiq', 'No completed cases in the selected date range.')" />

		<DeadlineKpiRow
			v-else
			:slaData="slaData"
			:atRiskCount="atRisk.length"
			:completedCount="completed.length" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import DeadlineKpiRow from '../components/DeadlineKpiRow.vue'
import { dtWidgetMixin } from './dtWidgetMixin.js'

export default {
	name: 'DtKpiWidget',
	components: { DeadlineKpiRow, NcButton, NcEmptyContent, NcLoadingIcon },
	mixins: [dtWidgetMixin],
	computed: {
		/**
		 * @return {Array<object>} Completed cases in the current scope.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		completed() {
			return this.dtStore.filteredCompleted(this.dtPreset, this.dtCaseType)
		},

		/**
		 * @return {Array<object>} At-risk open cases in the current scope.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		atRisk() {
			return this.dtStore.atRiskCases(this.dtCaseType)
		},

		/**
		 * @return {object} SLA compliance block.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		slaData() {
			return this.dtStore.slaData(this.dtPreset, this.dtCaseType)
		},

		/**
		 * @return {boolean} No cases exist at all.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		noCases() {
			return this.dtStore.allCases.length === 0
		},

		/**
		 * @return {boolean} Cases exist but no case type declares a deadline.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		noSla() {
			return (
				this.dtStore.allCases.length > 0
				&& this.dtStore.caseTypesWithSla.length === 0
			)
		},

		/**
		 * @return {boolean} SLA is configured but this scope is empty.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		noDataInRange() {
			return (
				this.dtStore.caseTypesWithSla.length > 0
				&& this.completed.length === 0
				&& this.atRisk.length === 0
			)
		},
	},

	methods: {
		t,
		/**
		 * Send the user to the app's administration surface.
		 *
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		goToSettings() {
			window.location.href = '/settings/admin/procest'
		},
	},
}
</script>

<style scoped>
.dt-kpi-widget__guidance {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;
}
</style>
