<template>
	<div>
		<CaseCreateDialog
			v-if="showCreateDialog"
			@created="onCaseCreated"
			@close="showCreateDialog = false" />

		<CnIndexPage
			:title="t('procest', 'Cases')"
			:description="t('procest', 'Manage cases and workflows')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:row-class="getRowClass"
			:selectable="true"
			:include-columns="visibleColumns"
			@add="showCreateDialog = true"
			@refresh="refresh"
			@sort="onSort"
			@row-click="openCase"
			@page-changed="onPageChange">
			<template #column-identifier="{ value }">
				<span class="case-id">{{ value || '\u2014' }}</span>
			</template>

			<template #column-caseType="{ value }">
				{{ getCaseTypeName(value) }}
			</template>

			<template #column-status="{ row }">
				<div @click.stop>
					<QuickStatusDropdown
						v-if="getStatusTypesForCaseType(row.caseType).length > 0"
						:case-obj="row"
						:status-types="getStatusTypesForCaseType(row.caseType)"
						@changed="onQuickStatusChanged" />
					<span v-else class="status-badge">
						{{ getStatusName(row) }}
					</span>
				</div>
			</template>

			<template #column-deadline="{ row }">
				<span :class="getDeadlineClass(row)">
					{{ getDeadlineText(row) }}
				</span>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatDeadlineCountdown, isCaseOverdue } from '../../utils/caseHelpers.js'
import CaseCreateDialog from './CaseCreateDialog.vue'
import QuickStatusDropdown from './components/QuickStatusDropdown.vue'

export default {
	name: 'CaseList',
	components: {
		CnIndexPage,
		CaseCreateDialog,
		QuickStatusDropdown,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		return useListView('case', {
			sidebarState,
			defaultSort: { key: 'deadline', order: 'asc' },
		})
	},

	data() {
		return {
			showCreateDialog: false,
			caseTypeCache: {},
			statusTypeCache: {},
		}
	},

	mounted() {
		// Load supplementary reference data (composable already handles schema + fetch)
		this.loadCaseTypes()
		this.loadStatusTypes()
	},

	methods: {
		async loadCaseTypes() {
			const objectStore = useObjectStore()
			const results = await objectStore.fetchCollection('caseType', { _limit: 100 })
			if (results) {
				for (const ct of results) {
					this.$set(this.caseTypeCache, ct.id, ct)
				}
			}
		},

		async loadStatusTypes() {
			const objectStore = useObjectStore()
			const results = await objectStore.fetchCollection('statusType', { _limit: 200 })
			if (results) {
				for (const st of results) {
					this.$set(this.statusTypeCache, st.id, st)
				}
			}
		},

		getCaseTypeName(caseTypeId) {
			if (!caseTypeId) return '\u2014'
			return this.caseTypeCache[caseTypeId]?.title || '\u2014'
		},

		getStatusName(caseItem) {
			if (!caseItem.status) return '\u2014'
			return this.statusTypeCache[caseItem.status]?.name || '\u2014'
		},

		getStatusTypesForCaseType(caseTypeId) {
			if (!caseTypeId) return []
			return Object.values(this.statusTypeCache)
				.filter(st => st.caseType === caseTypeId)
				.sort((a, b) => (a.order || 0) - (b.order || 0))
		},

		getRowClass(row) {
			const isFinal = this.isAtFinalStatus(row)
			return isCaseOverdue(row, isFinal) ? 'row--overdue' : ''
		},

		isAtFinalStatus(caseItem) {
			if (!caseItem.status) return false
			const statusType = this.statusTypeCache[caseItem.status]
			return statusType?.isFinal === true || statusType?.isFinal === 'true'
		},

		getDeadlineText(caseItem) {
			const isFinal = this.isAtFinalStatus(caseItem)
			return formatDeadlineCountdown(caseItem, isFinal).text
		},

		getDeadlineClass(caseItem) {
			const isFinal = this.isAtFinalStatus(caseItem)
			return formatDeadlineCountdown(caseItem, isFinal).style
		},

		onQuickStatusChanged() {
			this.refresh()
		},

		onCaseCreated(caseId) {
			this.showCreateDialog = false
			this.$router.push({ name: 'CaseDetail', params: { id: caseId } })
		},

		openCase(row) {
			this.$router.push({ name: 'CaseDetail', params: { id: row.id } })
		},
	},
}
</script>

<style scoped>
.case-id {
	font-family: monospace;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
	background: var(--color-background-dark);
}

.deadline--overdue {
	color: var(--color-error);
	font-weight: 500;
}

.deadline--today,
.deadline--tomorrow {
	color: var(--color-warning);
	font-weight: 500;
}

.deadline--ok {
	color: var(--color-success);
}

.deadline--final {
	color: var(--color-text-maxcontrast);
}
</style>

<style>
/* Unscoped: rowClass applies to CnDataTable's <tr> elements */
.row--overdue {
	border-left: 3px solid var(--color-error);
}
</style>
