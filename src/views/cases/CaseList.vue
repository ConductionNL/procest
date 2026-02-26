<template>
	<div class="case-list">
		<div class="case-list__header">
			<h2>{{ t('procest', 'Cases') }}</h2>
			<NcButton type="primary" @click="showCreateDialog = true">
				{{ t('procest', 'New case') }}
			</NcButton>
		</div>

		<!-- Search and filters -->
		<div class="case-list__controls">
			<NcTextField
				:value="searchTerm"
				:label="t('procest', 'Search cases...')"
				class="case-list__search"
				@update:value="onSearchInput" />

			<div class="case-list__filters">
				<NcSelect
					v-model="filters.caseType"
					:options="caseTypeFilterOptions"
					:placeholder="t('procest', 'Case type')"
					class="case-list__filter"
					@input="onFilterChange" />
				<NcSelect
					v-model="filters.status"
					:options="statusFilterOptions"
					:placeholder="t('procest', 'Status')"
					class="case-list__filter"
					@input="onFilterChange" />
				<NcSelect
					v-model="filters.priority"
					:options="priorityFilterOptions"
					:placeholder="t('procest', 'Priority')"
					class="case-list__filter"
					@input="onFilterChange" />
				<NcTextField
					:value="filters.handler"
					:label="t('procest', 'Handler')"
					:placeholder="t('procest', 'Filter by handler')"
					class="case-list__filter"
					@update:value="onHandlerFilter" />
				<label class="case-list__overdue-filter">
					<input
						v-model="filters.overdue"
						type="checkbox"
						@change="onFilterChange">
					{{ t('procest', 'Overdue only') }}
				</label>
			</div>
		</div>

		<!-- Loading state -->
		<NcLoadingIcon v-if="loading" />

		<!-- Empty state -->
		<NcEmptyContent v-else-if="cases.length === 0"
			:name="t('procest', 'No cases found')"
			:description="hasActiveFilters ? t('procest', 'Try adjusting your filters') : t('procest', 'Create a new case to get started')">
			<template #icon>
				<FolderOpen :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Case table -->
		<table v-else class="case-list__table">
			<thead>
				<tr>
					<th
						v-for="col in columns"
						:key="col.key"
						:class="{ 'sortable': col.sortable, 'sorted': sortKey === col.key }"
						@click="col.sortable && toggleSort(col.key)">
						{{ col.label }}
						<span v-if="sortKey === col.key" class="sort-indicator">
							{{ sortOrder === 'asc' ? '▲' : '▼' }}
						</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="caseItem in cases"
					:key="caseItem.id"
					class="case-list__row"
					:class="{ 'case-list__row--overdue': isCaseRowOverdue(caseItem) }"
					@click="openCase(caseItem.id)">
					<td class="case-list__id">
						{{ caseItem.identifier || '—' }}
					</td>
					<td class="case-list__title">
						{{ caseItem.title || '—' }}
					</td>
					<td class="case-list__type">
						{{ getCaseTypeName(caseItem.caseType) }}
					</td>
					<td class="case-list__status" @click.stop>
						<QuickStatusDropdown
							v-if="getStatusTypesForCaseType(caseItem.caseType).length > 0"
							:case-obj="caseItem"
							:status-types="getStatusTypesForCaseType(caseItem.caseType)"
							@changed="onQuickStatusChanged" />
						<span v-else class="status-badge">
							{{ getStatusName(caseItem) }}
						</span>
					</td>
					<td class="case-list__deadline" :class="getDeadlineClass(caseItem)">
						{{ getDeadlineText(caseItem) }}
					</td>
					<td class="case-list__handler">
						{{ caseItem.assignee || '—' }}
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Pagination -->
		<div v-if="pagination.pages > 1" class="case-list__pagination">
			<NcButton
				:disabled="pagination.page <= 1"
				@click="goToPage(pagination.page - 1)">
				{{ t('procest', 'Previous') }}
			</NcButton>
			<span class="case-list__page-info">
				{{ t('procest', 'Page {current} of {total}', { current: pagination.page, total: pagination.pages }) }}
			</span>
			<NcButton
				:disabled="pagination.page >= pagination.pages"
				@click="goToPage(pagination.page + 1)">
				{{ t('procest', 'Next') }}
			</NcButton>
		</div>

		<!-- Create dialog -->
		<CaseCreateDialog
			v-if="showCreateDialog"
			@created="onCaseCreated"
			@close="showCreateDialog = false" />
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatDeadlineCountdown, isCaseOverdue } from '../../utils/caseHelpers.js'
import CaseCreateDialog from './CaseCreateDialog.vue'
import QuickStatusDropdown from './components/QuickStatusDropdown.vue'

let searchTimeout = null
let handlerTimeout = null

export default {
	name: 'CaseList',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
		NcEmptyContent,
		FolderOpen,
		CaseCreateDialog,
		QuickStatusDropdown,
	},
	data() {
		return {
			searchTerm: '',
			filters: {
				caseType: null,
				status: null,
				priority: null,
				handler: '',
				overdue: false,
			},
			sortKey: 'deadline',
			sortOrder: 'asc',
			columns: [
				{ key: 'identifier', label: t('procest', 'ID'), sortable: true },
				{ key: 'title', label: t('procest', 'Title'), sortable: true },
				{ key: 'caseType', label: t('procest', 'Type'), sortable: false },
				{ key: 'status', label: t('procest', 'Status'), sortable: false },
				{ key: 'deadline', label: t('procest', 'Deadline'), sortable: true },
				{ key: 'assignee', label: t('procest', 'Handler'), sortable: true },
			],
			showCreateDialog: false,
			caseTypeCache: {},
			statusTypeCache: {},
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		loading() {
			return this.objectStore.isLoading('case')
		},
		cases() {
			return this.objectStore.getCollection('case')
		},
		pagination() {
			return this.objectStore.getPagination('case')
		},
		hasActiveFilters() {
			return !!this.searchTerm
				|| !!this.filters.caseType
				|| !!this.filters.status
				|| !!this.filters.priority
				|| !!this.filters.handler
				|| this.filters.overdue
		},
		caseTypeFilterOptions() {
			const types = Object.values(this.caseTypeCache)
			return [
				{ id: '', label: t('procest', 'All types') },
				...types.map(ct => ({ id: ct.id, label: ct.title || ct.id })),
			]
		},
		statusFilterOptions() {
			const statuses = Object.values(this.statusTypeCache)
			const uniqueNames = [...new Set(statuses.map(st => st.name))].sort()
			return [
				{ id: '', label: t('procest', 'All statuses') },
				...uniqueNames.map(name => {
					const st = statuses.find(s => s.name === name)
					return { id: st?.id || name, label: name }
				}),
			]
		},
		priorityFilterOptions() {
			return [
				{ id: '', label: t('procest', 'All priorities') },
				{ id: 'urgent', label: t('procest', 'Urgent') },
				{ id: 'high', label: t('procest', 'High') },
				{ id: 'normal', label: t('procest', 'Normal') },
				{ id: 'low', label: t('procest', 'Low') },
			]
		},
	},
	async mounted() {
		await Promise.all([
			this.loadCaseTypes(),
			this.loadStatusTypes(),
		])
		await this.fetchCases()
	},
	methods: {
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
			if (!caseTypeId) return '—'
			return this.caseTypeCache[caseTypeId]?.title || '—'
		},

		getStatusName(caseItem) {
			if (!caseItem.status) return '—'
			return this.statusTypeCache[caseItem.status]?.name || '—'
		},

		getStatusTypesForCaseType(caseTypeId) {
			if (!caseTypeId) return []
			return Object.values(this.statusTypeCache)
				.filter(st => st.caseType === caseTypeId)
				.sort((a, b) => (a.order || 0) - (b.order || 0))
		},

		isCaseRowOverdue(caseItem) {
			const isFinal = this.isAtFinalStatus(caseItem)
			return isCaseOverdue(caseItem, isFinal)
		},

		isAtFinalStatus(caseItem) {
			if (!caseItem.status) return false
			const statusType = this.statusTypeCache[caseItem.status]
			return statusType?.isFinal === true || statusType?.isFinal === 'true'
		},

		getDeadlineText(caseItem) {
			const isFinal = this.isAtFinalStatus(caseItem)
			const result = formatDeadlineCountdown(caseItem, isFinal)
			return result.text
		},

		getDeadlineClass(caseItem) {
			const isFinal = this.isAtFinalStatus(caseItem)
			const result = formatDeadlineCountdown(caseItem, isFinal)
			return result.style
		},

		onSearchInput(value) {
			this.searchTerm = value
			clearTimeout(searchTimeout)
			searchTimeout = setTimeout(() => {
				this.fetchCases()
			}, 300)
		},

		onHandlerFilter(value) {
			this.filters.handler = value
			clearTimeout(handlerTimeout)
			handlerTimeout = setTimeout(() => {
				this.fetchCases()
			}, 300)
		},

		onFilterChange() {
			this.fetchCases()
		},

		toggleSort(key) {
			if (this.sortKey === key) {
				this.sortOrder = this.sortOrder === 'asc' ? 'desc' : 'asc'
			} else {
				this.sortKey = key
				this.sortOrder = 'asc'
			}
			this.fetchCases()
		},

		goToPage(page) {
			this.fetchCases(page)
		},

		async fetchCases(page = 1) {
			const params = {
				_limit: 20,
				_offset: (page - 1) * 20,
			}

			if (this.searchTerm) {
				params._search = this.searchTerm
			}

			if (this.sortKey) {
				params._order = JSON.stringify({ [this.sortKey]: this.sortOrder })
			}

			const caseTypeId = this.filters.caseType?.id || this.filters.caseType
			if (caseTypeId) {
				params['_filters[caseType]'] = caseTypeId
			}

			const statusId = this.filters.status?.id || this.filters.status
			if (statusId) {
				params['_filters[status]'] = statusId
			}

			const priorityId = this.filters.priority?.id || this.filters.priority
			if (priorityId) {
				params['_filters[priority]'] = priorityId
			}

			if (this.filters.handler) {
				params['_filters[assignee]'] = this.filters.handler
			}

			await this.objectStore.fetchCollection('case', params)
		},

		onQuickStatusChanged() {
			this.fetchCases()
		},

		onCaseCreated(caseId) {
			this.showCreateDialog = false
			this.$emit('navigate', 'case-detail', caseId)
		},

		openCase(id) {
			this.$emit('navigate', 'case-detail', id)
		},
	},
}
</script>

<style scoped>
.case-list {
	padding: 20px;
}

.case-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.case-list__controls {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 16px;
	align-items: flex-end;
}

.case-list__search {
	flex: 1;
	min-width: 200px;
}

.case-list__filters {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	align-items: center;
}

.case-list__filter {
	min-width: 140px;
}

.case-list__overdue-filter {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 14px;
	cursor: pointer;
	white-space: nowrap;
}

.case-list__table {
	width: 100%;
	border-collapse: collapse;
}

.case-list__table th {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 2px solid var(--color-border);
	font-weight: bold;
	white-space: nowrap;
	user-select: none;
}

.case-list__table th.sortable {
	cursor: pointer;
}

.case-list__table th.sortable:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.sort-indicator {
	font-size: 10px;
	margin-left: 4px;
}

.case-list__table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.case-list__row {
	cursor: pointer;
}

.case-list__row:hover {
	background: var(--color-background-hover);
}

.case-list__row--overdue {
	border-left: 3px solid var(--color-error);
}

.case-list__id {
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

.case-list__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	margin-top: 20px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.case-list__page-info {
	color: var(--color-text-maxcontrast);
}
</style>
