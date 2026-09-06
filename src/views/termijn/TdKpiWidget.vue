<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Headline KPI tiles for the deadline-monitoring dashboard.

	These were six hand-rolled `.kpi-card` divs with their own grid and colour
	rules. They are now CnKpiGrid + CnStatsBlock — the same leafs the other two
	analytics dashboards use, which is what actually makes the three pages look
	alike (one render path, not three aligned stylesheets).

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<div class="td-kpi-widget">
		<NcLoadingIcon v-if="tdStore.loading" :size="24" />
		<NcNoteCard v-else-if="tdStore.error" type="error">
			{{ tdStore.error }}
		</NcNoteCard>
		<CnKpiGrid v-else-if="kpi" :columns="3">
			<CnStatsBlock
				:title="t('dossiq', 'Total cases (in period)')"
				:count="kpi.totalZaken"
				:showZeroCount="true"
				:countLabel="t('dossiq', 'cases')"
				variant="default" />
			<CnStatsBlock
				:title="t('dossiq', 'Within term')"
				countLabel=""
				variant="success">
				<template #value>
					{{ percent(kpi.withinTermijnPercent) }}
				</template>
			</CnStatsBlock>
			<CnStatsBlock
				:title="t('dossiq', 'Avg duration (days)')"
				:count="kpi.avgDurationDays"
				:showZeroCount="true"
				:countLabel="t('dossiq', 'days')"
				variant="default" />
			<CnStatsBlock
				:title="t('dossiq', 'Overruns')"
				:count="kpi.overrunCount"
				:showZeroCount="true"
				:countLabel="t('dossiq', 'cases')"
				variant="warning" />
			<CnStatsBlock
				:title="t('dossiq', 'Dwangsom total (€)')"
				countLabel=""
				variant="error">
				<template #value>
					{{ euro(kpi.dwangsomTotal) }}
				</template>
			</CnStatsBlock>
			<CnStatsBlock
				:title="t('dossiq', 'Last updated')"
				countLabel=""
				variant="default">
				<template #value>
					{{ kpi.lastUpdated || '—' }}
				</template>
			</CnStatsBlock>
		</CnKpiGrid>
	</div>
</template>

<script>
import { CnKpiGrid, CnStatsBlock } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { tdWidgetMixin } from './tdWidgetMixin.js'

export default {
	name: 'TdKpiWidget',
	components: { CnKpiGrid, CnStatsBlock, NcLoadingIcon, NcNoteCard },
	mixins: [tdWidgetMixin],
	computed: {
		/**
		 * @return {object|null} The KPI block from the shared store.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		kpi() {
			return this.tdStore.kpi
		},
	},

	watch: {
		tdCaseType: 'reload',
	},

	mounted() {
		this.reload()
	},

	methods: {
		t,
		/**
		 * Ask the store for the KPI block scoped to the header's case type.
		 *
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		reload() {
			this.tdStore.loadKpi({ caseType: this.tdCaseType })
		},
	},
}
</script>
