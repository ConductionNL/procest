<template>
	<CnIndexPage
		:title="t('procest', 'Tasks')"
		:description="t('procest', 'Track and manage tasks')"
		:schema="schema"
		:objects="objects"
		:pagination="pagination"
		:loading="loading"
		:sort-key="sortKey"
		:sort-order="sortOrder"
		:row-class="getRowClass"
		:selectable="true"
		:include-columns="visibleColumns"
		@refresh="refresh"
		@sort="onSort"
		@row-click="openTask"
		@page-changed="onPageChange">
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
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { getStatusLabel } from '../../utils/taskLifecycle.js'
import { isOverdue, isDueToday, getOverdueText, formatDueDate, getPriorityLevels } from '../../utils/taskHelpers.js'

export default {
	name: 'TaskList',
	components: {
		CnIndexPage,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		return useListView('task', {
			sidebarState,
			defaultSort: { key: 'dueDate', order: 'asc' },
		})
	},

	data() {
		return {
			caseCache: {},
		}
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
			const objectStore = useObjectStore()
			const caseObj = await objectStore.fetchObject('case', caseId)
			if (caseObj) {
				this.$set(this.caseCache, caseId, caseObj)
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
