<template>
	<div class="task-list">
		<div class="task-list__header">
			<h2>{{ t('procest', 'Tasks') }}</h2>
		</div>

		<!-- Search and filters -->
		<div class="task-list__controls">
			<NcTextField
				:value="searchTerm"
				:label="t('procest', 'Search tasks...')"
				class="task-list__search"
				@update:value="onSearchInput" />

			<div class="task-list__filters">
				<NcSelect
					v-model="filters.status"
					:options="statusFilterOptions"
					:placeholder="t('procest', 'Status')"
					class="task-list__filter"
					@input="fetchTasks" />
				<NcSelect
					v-model="filters.priority"
					:options="priorityFilterOptions"
					:placeholder="t('procest', 'Priority')"
					class="task-list__filter"
					@input="fetchTasks" />
				<NcTextField
					:value="filters.assignee"
					:label="t('procest', 'Assignee')"
					:placeholder="t('procest', 'Filter by assignee')"
					class="task-list__filter"
					@update:value="onAssigneeFilter" />
			</div>
		</div>

		<!-- Loading state -->
		<NcLoadingIcon v-if="loading" />

		<!-- Empty state -->
		<NcEmptyContent v-else-if="tasks.length === 0"
			:name="t('procest', 'No tasks found')"
			:description="hasActiveFilters ? t('procest', 'Try adjusting your filters') : t('procest', 'Tasks will appear here when created from a case')">
			<template #icon>
				<ClipboardCheckOutline :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Task table -->
		<div v-else class="viewTableContainer">
			<table class="viewTable">
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
					v-for="task in tasks"
					:key="task.id"
					class="viewTableRow"
					:class="{ 'viewTableRow--overdue': isOverdue(task) }"
					@click="openTask(task.id)">
					<td class="task-list__title">
						{{ task.title || '—' }}
					</td>
					<td class="task-list__case">
						<a
							v-if="task.case"
							class="case-link"
							@click.stop="openCase(task.case)">
							{{ getCaseTitle(task.case) }}
						</a>
						<span v-else>—</span>
					</td>
					<td>
						<span class="status-badge" :class="'status-badge--' + task.status">
							{{ getStatusLabel(task.status) }}
						</span>
					</td>
					<td>{{ task.assignee || '—' }}</td>
					<td :class="dueDateClass(task)">
						<template v-if="isOverdue(task)">
							{{ getOverdueText(task) }}
						</template>
						<template v-else-if="isDueToday(task)">
							{{ t('procest', 'Due today') }}
						</template>
						<template v-else>
							{{ formatDueDate(task.dueDate) }}
						</template>
					</td>
					<td>
						<span
							v-if="task.priority && task.priority !== 'normal'"
							class="priority-badge"
							:class="'priority-badge--' + task.priority">
							{{ getPriorityLabel(task.priority) }}
						</span>
						<span v-else>—</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

		<!-- Pagination -->
		<div v-if="pagination.pages > 1" class="task-list__pagination">
			<NcButton
				:disabled="pagination.page <= 1"
				@click="goToPage(pagination.page - 1)">
				{{ t('procest', 'Previous') }}
			</NcButton>
			<span class="task-list__page-info">
				{{ t('procest', 'Page {current} of {total}', { current: pagination.page, total: pagination.pages }) }}
			</span>
			<NcButton
				:disabled="pagination.page >= pagination.pages"
				@click="goToPage(pagination.page + 1)">
				{{ t('procest', 'Next') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { getStatusLabel } from '../../utils/taskLifecycle.js'
import { isOverdue, isDueToday, getOverdueText, formatDueDate, getPriorityLevels } from '../../utils/taskHelpers.js'

let searchTimeout = null
let assigneeTimeout = null

export default {
	name: 'TaskList',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
		NcEmptyContent,
		ClipboardCheckOutline,
	},
	data() {
		return {
			searchTerm: '',
			filters: {
				status: null,
				priority: null,
				assignee: '',
			},
			sortKey: 'dueDate',
			sortOrder: 'asc',
			columns: [
				{ key: 'title', label: t('procest', 'Title'), sortable: true },
				{ key: 'case', label: t('procest', 'Case'), sortable: false },
				{ key: 'status', label: t('procest', 'Status'), sortable: true },
				{ key: 'assignee', label: t('procest', 'Assignee'), sortable: true },
				{ key: 'dueDate', label: t('procest', 'Due date'), sortable: true },
				{ key: 'priority', label: t('procest', 'Priority'), sortable: true },
			],
			caseCache: {},
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		loading() {
			return this.objectStore.isLoading('task')
		},
		tasks() {
			return this.objectStore.getCollection('task')
		},
		pagination() {
			return this.objectStore.getPagination('task')
		},
		hasActiveFilters() {
			return !!this.searchTerm || !!this.filters.status || !!this.filters.priority || !!this.filters.assignee
		},
		statusFilterOptions() {
			return [
				{ id: '', label: t('procest', 'All statuses') },
				{ id: 'available', label: t('procest', 'Available') },
				{ id: 'active', label: t('procest', 'Active') },
				{ id: 'completed', label: t('procest', 'Completed') },
				{ id: 'terminated', label: t('procest', 'Terminated') },
				{ id: 'disabled', label: t('procest', 'Disabled') },
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
		await this.fetchTasks()
	},
	methods: {
		isOverdue,
		isDueToday,
		getOverdueText,
		formatDueDate,
		getStatusLabel,

		getPriorityLabel(priority) {
			return getPriorityLevels()[priority]?.label || priority
		},

		dueDateClass(task) {
			if (isOverdue(task)) return 'due-date--overdue'
			if (isDueToday(task)) return 'due-date--today'
			return ''
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
			clearTimeout(searchTimeout)
			searchTimeout = setTimeout(() => {
				this.fetchTasks()
			}, 300)
		},

		onAssigneeFilter(value) {
			this.filters.assignee = value
			clearTimeout(assigneeTimeout)
			assigneeTimeout = setTimeout(() => {
				this.fetchTasks()
			}, 300)
		},

		toggleSort(key) {
			if (this.sortKey === key) {
				this.sortOrder = this.sortOrder === 'asc' ? 'desc' : 'asc'
			} else {
				this.sortKey = key
				this.sortOrder = 'asc'
			}
			this.fetchTasks()
		},

		goToPage(page) {
			this.fetchTasks(page)
		},

		async fetchTasks(page = 1) {
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

			const statusId = this.filters.status?.id || this.filters.status
			if (statusId) {
				params['_filters[status]'] = statusId
			}

			const priorityId = this.filters.priority?.id || this.filters.priority
			if (priorityId) {
				params['_filters[priority]'] = priorityId
			}

			if (this.filters.assignee) {
				params['_filters[assignee]'] = this.filters.assignee
			}

			await this.objectStore.fetchCollection('task', params)
		},

		openTask(id) {
			this.$emit('navigate', 'task-detail', id)
		},

		openCase(caseId) {
			this.$emit('navigate', 'case-detail', caseId)
		},
	},
}
</script>

<style scoped>
.task-list {
	padding: 20px;
}

.task-list__header {
	margin-bottom: 16px;
}

.task-list__controls {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 16px;
	align-items: flex-end;
}

.task-list__search {
	flex: 1;
	min-width: 200px;
}

.task-list__filters {
	display: flex;
	gap: 8px;
}

.task-list__filter {
	min-width: 150px;
}

.viewTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 2px 4px var(--color-box-shadow);
	border: 1px solid var(--color-border);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
	background-color: var(--color-main-background);
}

.viewTable th,
.viewTable td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.viewTable th {
	background-color: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	user-select: none;
}

.viewTable th.sortable {
	cursor: pointer;
}

.viewTable th.sortable:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.sort-indicator {
	font-size: 10px;
	margin-left: 4px;
}

.viewTableRow {
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.viewTableRow:hover {
	background: var(--color-background-hover);
}

.viewTableRow--overdue {
	border-left: 3px solid var(--color-error);
}

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

.task-list__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	margin-top: 20px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.task-list__page-info {
	color: var(--color-text-maxcontrast);
}
</style>
