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
			:objects="cases"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:row-class="getRowClass"
			:selectable="true"
			:include-columns="visibleColumns"
			@add="showCreateDialog = true"
			@refresh="fetchCases"
			@sort="onSort"
			@row-click="openCase"
			@page-changed="goToPage">

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
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatDeadlineCountdown, isCaseOverdue } from '../../utils/caseHelpers.js'
import CaseCreateDialog from './CaseCreateDialog.vue'
import QuickStatusDropdown from './components/QuickStatusDropdown.vue'

let searchTimeout = null

export default {
	name: 'CaseList',
	components: {
		CnIndexPage,
		CaseCreateDialog,
		QuickStatusDropdown,
	},

	inject: {
		sidebarState: { default: null },
	},

	data() {
		return {
			searchTerm: '',
			sortKey: 'deadline',
			sortOrder: 'asc',
			showCreateDialog: false,
			caseTypeCache: {},
			statusTypeCache: {},
			schema: null,
			visibleColumns: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		loading() {
			return this.objectStore.loading.case || false
		},
		cases() {
			return this.objectStore.collections.case || []
		},
		pagination() {
			return this.objectStore.pagination.case || { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	async mounted() {
		this.schema = await this.objectStore.fetchSchema('case')
		this.setupSidebar()
		await Promise.all([
			this.loadCaseTypes(),
			this.loadStatusTypes(),
		])
		await this.fetchCases()
	},
	beforeDestroy() {
		this.teardownSidebar()
	},
	methods: {
		setupSidebar() {
			if (!this.sidebarState) return
			this.sidebarState.active = true
			this.sidebarState.schema = this.schema
			this.sidebarState.searchValue = this.searchTerm
			this.sidebarState.activeFilters = {}
			this.sidebarState.onSearch = (value) => {
				this.onSearchInput(value)
			}
			this.sidebarState.onColumnsChange = (columns) => {
				this.visibleColumns = columns
			}
			this.sidebarState.onFilterChange = ({ key, values }) => {
				this.onFacetFilterChange(key, values)
			}
		},
		teardownSidebar() {
			if (!this.sidebarState) return
			this.sidebarState.active = false
			this.sidebarState.schema = null
			this.sidebarState.activeFilters = {}
			this.sidebarState.facetData = {}
			this.sidebarState.onSearch = null
			this.sidebarState.onColumnsChange = null
			this.sidebarState.onFilterChange = null
		},
		async loadCaseTypes() {
			const results = await this.objectStore.fetchCollection('caseType', { _limit: 100 })
			if (results) {
				for (const ct of results) {
					this.$set(this.caseTypeCache, ct.id, ct)
				}
			}
		},

		async loadStatusTypes() {
			const results = await this.objectStore.fetchCollection('statusType', { _limit: 200 })
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

		onSearchInput(value) {
			this.searchTerm = value
			if (this.sidebarState) {
				this.sidebarState.searchValue = value
			}
			clearTimeout(searchTimeout)
			searchTimeout = setTimeout(() => {
				this.fetchCases()
			}, 300)
		},

		onFacetFilterChange(key, values) {
			if (!this.sidebarState) return
			this.sidebarState.activeFilters = {
				...this.sidebarState.activeFilters,
				[key]: values && values.length > 0 ? values : undefined,
			}
			this.fetchCases()
		},

		onSort({ key, order }) {
			this.sortKey = key
			this.sortOrder = order
			this.fetchCases()
		},

		goToPage(page) {
			this.fetchCases(page)
		},

		async fetchCases(page = 1) {
			const params = {
				_limit: 20,
				_page: page,
			}

			if (this.searchTerm) {
				params._search = this.searchTerm
			}

			if (this.sortKey) {
				params._order = JSON.stringify({ [this.sortKey]: this.sortOrder })
			}

			if (this.sidebarState?.activeFilters) {
				for (const [key, values] of Object.entries(this.sidebarState.activeFilters)) {
					if (values && values.length > 0) {
						params[key] = values.length === 1 ? values[0] : values
					}
				}
			}

			await this.objectStore.fetchCollection('case', params)
			if (this.sidebarState) {
				this.sidebarState.facetData = this.objectStore.facets.case || {}
			}
		},

		onQuickStatusChanged() {
			this.fetchCases()
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
