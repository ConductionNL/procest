<template>
	<CnIndexPage
		:title="t('procest', 'Tasks')"
		:description="t('procest', 'Track and manage tasks')"
		:schema="schema"
		:objects="tasks"
		:pagination="pagination"
		:loading="loading"
		:sort-key="sortKey"
		:sort-order="sortOrder"
		:row-class="getRowClass"
		:selectable="true"
		:include-columns="visibleColumns"
		@refresh="fetchTasks"
		@sort="onSort"
		@row-click="openTask"
		@page-changed="goToPage">
		<template #column-case="{ row }">
			<a
				v-if="row.case"
				class="case-link"
				@click.stop="openCase(row.case)">
				{{ getCaseTitle(row.case) }}
			</a>
			<span v-else>&mdash;</span>
		</template>

		<template #column-status="{ row }">
			<span class="status-badge" :class="'status-badge--' + row.status">
				{{ getStatusLabel(row.status) }}
			</span>
		</template>

		<template #column-dueDate="{ row }">
			<span :class="dueDateClass(row)">
				<template v-if="isOverdue(row)">
					{{ getOverdueText(row) }}
				</template>
				<template v-else-if="isDueToday(row)">
					{{ t('procest', 'Due today') }}
				</template>
				<template v-else>
					{{ formatDueDate(row.dueDate) }}
				</template>
			</span>
		</template>

		<template #column-priority="{ row }">
			<span
				v-if="row.priority && row.priority !== 'normal'"
				class="priority-badge"
				:class="'priority-badge--' + row.priority">
				{{ getPriorityLabel(row.priority) }}
			</span>
			<span v-else>&mdash;</span>
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { getStatusLabel } from '../../utils/taskLifecycle.js'
import { isOverdue, isDueToday, getOverdueText, formatDueDate, getPriorityLevels } from '../../utils/taskHelpers.js'

let searchTimeout = null

export default {
	name: 'TaskList',
	components: {
		CnIndexPage,
	},

	inject: {
		sidebarState: { default: null },
	},

	data() {
		return {
			searchTerm: '',
			sortKey: 'dueDate',
			sortOrder: 'asc',
			caseCache: {},
			schema: null,
			visibleColumns: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		loading() {
			return this.objectStore.loading.task || false
		},
		tasks() {
			return this.objectStore.collections.task || []
		},
		pagination() {
			return this.objectStore.pagination.task || { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	async mounted() {
		this.schema = await this.objectStore.fetchSchema('task')
		this.setupSidebar()
		await this.fetchTasks()
	},
	beforeDestroy() {
		this.teardownSidebar()
	},
	methods: {
		isOverdue,
		isDueToday,
		getOverdueText,
		formatDueDate,
		getStatusLabel,

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

		getPriorityLabel(priority) {
			return getPriorityLevels()[priority]?.label || priority
		},

		dueDateClass(task) {
			if (isOverdue(task)) return 'due-date--overdue'
			if (isDueToday(task)) return 'due-date--today'
			return ''
		},

		getRowClass(row) {
			return isOverdue(row) ? 'row--overdue' : ''
		},

		getCaseTitle(caseId) {
			const cached = this.caseCache[caseId]
			if (cached) return cached.title || cached.identifier || caseId
			this.loadCaseTitle(caseId)
			return caseId
		},

		async loadCaseTitle(caseId) {
			if (this.caseCache[caseId] !== undefined) return
			this.caseCache[caseId] = null
			const caseObj = await this.objectStore.fetchObject('case', caseId)
			if (caseObj) {
				this.$set(this.caseCache, caseId, caseObj)
			}
		},

		onSearchInput(value) {
			this.searchTerm = value
			if (this.sidebarState) {
				this.sidebarState.searchValue = value
			}
			clearTimeout(searchTimeout)
			searchTimeout = setTimeout(() => {
				this.fetchTasks()
			}, 300)
		},

		onSort({ key, order }) {
			this.sortKey = key
			this.sortOrder = order
			this.fetchTasks()
		},

		onFacetFilterChange(key, values) {
			if (!this.sidebarState) return
			this.sidebarState.activeFilters = {
				...this.sidebarState.activeFilters,
				[key]: values && values.length > 0 ? values : undefined,
			}
			this.fetchTasks()
		},

		goToPage(page) {
			this.fetchTasks(page)
		},

		async fetchTasks(page = 1) {
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

			await this.objectStore.fetchCollection('task', params)
			if (this.sidebarState) {
				this.sidebarState.facetData = this.objectStore.facets.task || {}
			}
		},

		openTask(row) {
			this.$router.push({ name: 'TaskDetail', params: { id: row.id } })
		},

		openCase(caseId) {
			this.$router.push({ name: 'CaseDetail', params: { id: caseId } })
		},
	},
}
</script>

<style scoped>
.case-link {
	color: var(--color-primary);
	text-decoration: underline;
	cursor: pointer;
}

.case-link:hover {
	color: var(--color-primary-hover);
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
}

.status-badge--available {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.status-badge--active {
	background: var(--color-primary-light);
	color: var(--color-primary-text);
}

.status-badge--completed {
	background: var(--color-success);
	color: white;
}

.status-badge--terminated {
	background: var(--color-error);
	color: white;
}

.status-badge--disabled {
	background: var(--color-text-maxcontrast);
	color: white;
}

.priority-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
}

.priority-badge--urgent {
	background: var(--color-error);
	color: white;
}

.priority-badge--high {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.priority-badge--low {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.due-date--overdue {
	color: var(--color-error);
	font-weight: 500;
}

.due-date--today {
	color: var(--color-warning);
	font-weight: 500;
}
</style>

<style>
/* Unscoped: rowClass applies to CnDataTable's <tr> elements */
.row--overdue {
	border-left: 3px solid var(--color-error);
}
</style>
