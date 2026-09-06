<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Quarterly deadline report. Its period input and export are widget-scoped
	controls — they select what THIS report covers, not what the page covers —
	so they stay inside the widget while the case-type filter lives in the page
	header. The `<h3>` comes from the widget frame.

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<div class="td-report-widget">
		<div class="td-report-widget__controls">
			<NcTextField
				:modelValue="quarter"
				:label="t('dossiq', 'Quarter (YYYY-Qn)')"
				:placeholder="t('dossiq', 'e.g. 2026-Q2')"
				@update:modelValue="(v) => (quarter = v)" />
			<NcButton variant="primary" @click="load">
				{{ t('dossiq', 'Load report') }}
			</NcButton>
			<NcButton
				:disabled="!quarterly"
				variant="secondary"
				@click="downloadCsv">
				<template #icon>
					<FileExport :size="18" />
				</template>
				{{ t('dossiq', 'Export CSV') }}
			</NcButton>
		</div>

		<div v-if="quarterly && quarterly.perType" class="td-report-widget__scroll">
			<table class="td-report-widget__table">
				<thead>
					<tr>
						<th scope="col">{{ t('dossiq', 'Zaaktype') }}</th>
						<th scope="col">{{ t('dossiq', 'Total') }}</th>
						<th scope="col">{{ t('dossiq', 'Within deadline') }}</th>
						<th scope="col">{{ t('dossiq', 'Overruns') }}</th>
						<th scope="col">{{ t('dossiq', 'Avg. duration') }}</th>
						<th scope="col">{{ t('dossiq', 'Extensions') }}</th>
						<th scope="col">{{ t('dossiq', 'Notices of default') }}</th>
						<th scope="col">
							{{ t('dossiq', 'Total penalty payment') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, key) in quarterly.perType" :key="key">
						<td>{{ key }}</td>
						<td>{{ row.totaal || 0 }}</td>
						<td>{{ percent(row.binnenTermijnPercent || 0) }}</td>
						<td>{{ row.overschrijdingen || 0 }}</td>
						<td>{{ row.gemiddeldeDoorlooptijd || 0 }}</td>
						<td>{{ row.verlengingen || 0 }}</td>
						<td>{{ row.ingebrekestellingen || 0 }}</td>
						<td>{{ euro(row.dwangsomTotal || 0) }}</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-else class="td-report-widget__empty">
			{{ t('dossiq', 'Choose a quarter and load the report.') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FileExport from 'vue-material-design-icons/FileExport.vue'
import { currentQuarter } from '../../store/modules/termijnDashboard.js'
import { tdWidgetMixin } from './tdWidgetMixin.js'

const CSV_HEADERS = [
	'case_type',
	'totaal',
	'binnenTermijnPercent',
	'overschrijdingen',
	'gemDoorlooptijd',
	'verlengingen',
	'ingebrekestellingen',
	'dwangsomTotal',
]

export default {
	name: 'TdQuarterlyWidget',
	components: { NcButton, NcTextField, FileExport },
	mixins: [tdWidgetMixin],
	data() {
		return { quarter: currentQuarter() }
	},

	computed: {
		/**
		 * @return {object|null} The loaded quarterly report.
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		quarterly() {
			return this.tdStore.quarterly
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		/**
		 * Load the report for the entered quarter.
		 *
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		load() {
			this.tdStore.loadQuarterly(this.quarter)
		},

		/**
		 * Build and download the per-case-type CSV for the loaded quarter.
		 *
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		downloadCsv() {
			const perType = this.quarterly?.perType
			if (!perType) return
			const lines = [CSV_HEADERS.join(',')]
			for (const [k, row] of Object.entries(perType)) {
				lines.push(
					[
						k,
						row.totaal || 0,
						row.binnenTermijnPercent || 0,
						row.overschrijdingen || 0,
						row.gemiddeldeDoorlooptijd || 0,
						row.verlengingen || 0,
						row.ingebrekestellingen || 0,
						row.dwangsomTotal || 0,
					].join(','),
				)
			}
			const blob = new Blob([lines.join('\n')], {
				type: 'text/csv;charset=utf-8',
			})
			const url = window.URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = `termijn-${this.quarter}.csv`
			document.body.appendChild(a)
			a.click()
			document.body.removeChild(a)
			window.URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.td-report-widget__controls {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
	margin-bottom: 12px;
}

/* Eight columns: scroll inside the widget rather than widening the grid. */
.td-report-widget__scroll {
	overflow-x: auto;
}

.td-report-widget__table {
	width: 100%;
	border-collapse: collapse;
}

.td-report-widget__table th,
.td-report-widget__table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.td-report-widget__empty {
	color: var(--color-text-maxcontrast);
}
</style>
