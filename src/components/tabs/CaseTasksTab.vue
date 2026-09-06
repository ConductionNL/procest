<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: tasks linked to a parent case.

 Lists tasks where task.case === the parent case id (ported from the
 pre-manifest CaseDetail.vue task card). Clicking a task opens the
 TaskDetail page; "New task" opens TaskNew pre-linked to this case.
 Receives `objectId` from CnObjectSidebar's sharedTabProps, with a
 route fallback for standalone use.
-->
<template>
	<div class="case-tab case-tab--tasks">
		<div class="case-tab__header">
			<h3 class="case-tab__title">
				{{ t('dossiq', 'Tasks') }}
				<span v-if="tasks.length > 0" class="case-tab__count"
					>({{ completedCount }}/{{ tasks.length }})</span
				>
			</h3>
			<NcButton type="primary" @click="onNewTask">
				{{ t('dossiq', 'New task') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="tasks.length === 0"
			:title="t('dossiq', 'No tasks yet')"
			:description="
				t('dossiq', 'Create a task to track work on this case.')
			" />

		<ul v-else class="case-tab__list">
			<li
				v-for="task in sortedTasks"
				:key="task.id"
				class="case-tab__item"
				:class="{ 'case-tab__item--overdue': isOverdue(task) }"
				role="button"
				tabindex="0"
				@click="openTask(task)"
				@keydown.enter="openTask(task)"
				@keydown.space.prevent="openTask(task)">
				<div class="case-tab__row">
					<strong class="case-tab__item-title">{{
						task.title || '—'
					}}</strong>
					<CnStatusBadge
						:status="statusLabel(task.status)"
						:type="statusBadgeType(task.status)" />
				</div>
				<div class="case-tab__meta">
					<span v-if="task.assignee">{{ task.assignee }}</span>
					<span
						v-if="task.dueDate"
						:class="{ 'case-tab__meta--overdue': isOverdue(task) }">
						{{ dueLabel(task) }}
					</span>
					<span
						v-if="task.priority && task.priority !== 'normal'"
						class="case-tab__priority">
						{{ priorityLabel(task.priority) }}
					</span>
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { formatDate } from '../../utils/caseHelpers.js'

export default {
	name: 'CaseTasksTab',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		CnStatusBadge,
	},

	props: {
		/** Case UUID — passed by CnObjectSidebar as a shared tab prop. */
		objectId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			tasks: [],
			loading: true,
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		resolvedCaseId() {
			return this.objectId || this.$route?.params?.id || null
		},

		completedCount() {
			return this.tasks.filter((t) => t.status === 'completed').length
		},

		/** Open tasks first, then by due date ascending (no due date last). */
		sortedTasks() {
			return [...this.tasks].sort((a, b) => {
				const doneA = a.status === 'completed' ? 1 : 0
				const doneB = b.status === 'completed' ? 1 : 0
				if (doneA !== doneB) return doneA - doneB
				if (!a.dueDate) return b.dueDate ? 1 : 0
				if (!b.dueDate) return -1
				return a.dueDate.localeCompare(b.dueDate)
			})
		},
	},

	watch: {
		resolvedCaseId() {
			this.reload()
		},
	},

	async mounted() {
		await initializeStores()
		await this.reload()
	},

	methods: {
		/**
		 * Load the tasks belonging to THIS case.
		 *
		 * Filters on a bare `case` field name: the `_filters[case]` form this
		 * used is inert, so the tab was reading every case's tasks.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/task-management/spec.md#requirement-task-list-must-be-reached-via-mijn-werk-not-a-sibling-top-level-menu
		 */
		async reload() {
			if (!this.resolvedCaseId) {
				this.loading = false
				return
			}
			this.loading = true
			try {
				const results = await this.objectStore.fetchCollection('caseTask', {
					case: this.resolvedCaseId,
					_limit: 50,
				})
				this.tasks = results || []
			} catch (err) {
				console.error('[CaseTasksTab] failed to fetch tasks', err)
				this.tasks = []
			} finally {
				this.loading = false
			}
		},

		onNewTask() {
			this.$router.push({
				name: 'TaskNew',
				query: { caseId: this.resolvedCaseId },
			})
		},

		openTask(task) {
			this.$router.push({ name: 'TaskDetail', params: { id: task.id } })
		},

		isOverdue(task) {
			if (!task.dueDate || task.status === 'completed') return false
			return task.dueDate.slice(0, 10) < new Date().toISOString().slice(0, 10)
		},

		/**
		 * Human-readable due-date label for one task.
		 *
		 * @param {object} task The task object.
		 * @return {string} The translated due-date label.
		 *
		 * @spec openspec/specs/task-management/spec.md#requirement-task-list-must-be-reached-via-mijn-werk-not-a-sibling-top-level-menu
		 */
		dueLabel(task) {
			if (this.isOverdue(task)) {
				return t('dossiq', 'Overdue: {date}', {
					date: formatDate(task.dueDate),
				})
			}
			if (
				task.dueDate.slice(0, 10) === new Date().toISOString().slice(0, 10)
			) {
				return t('dossiq', 'Due today')
			}
			return t('dossiq', 'Due: {date}', { date: formatDate(task.dueDate) })
		},

		/**
		 * Human-readable label for a task status.
		 *
		 * @param {string} status The raw status value.
		 * @return {string} The translated status label.
		 *
		 * @spec openspec/specs/task-management/spec.md#requirement-task-list-must-be-reached-via-mijn-werk-not-a-sibling-top-level-menu
		 */
		statusLabel(status) {
			const labels = {
				available: t('dossiq', 'Open'),
				active: t('dossiq', 'In progress'),
				completed: t('dossiq', 'Completed'),
				blocked: t('dossiq', 'Blocked'),
			}
			return labels[status] || status || '—'
		},

		statusBadgeType(status) {
			if (status === 'completed') return 'success'
			if (status === 'blocked') return 'error'
			if (status === 'active') return 'primary'
			return 'default'
		},

		/**
		 * Human-readable label for a task priority.
		 *
		 * @param {string} priority The raw priority value.
		 * @return {string} The translated priority label.
		 *
		 * @spec openspec/specs/task-management/spec.md#requirement-task-list-must-be-reached-via-mijn-werk-not-a-sibling-top-level-menu
		 */
		priorityLabel(priority) {
			const labels = {
				low: t('dossiq', 'Low'),
				high: t('dossiq', 'High'),
				urgent: t('dossiq', 'Urgent'),
			}
			return labels[priority] || priority
		},
	},
}
</script>

<style scoped>
.case-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.case-tab__title {
	margin: 0;
	font-size: 16px;
}

.case-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.case-tab__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.case-tab__item {
	padding: 8px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.case-tab__item:hover {
	background: var(--color-background-hover);
}

.case-tab__item--overdue {
	border-inline-start: 3px solid var(--color-error);
}

.case-tab__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.case-tab__item-title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.case-tab__meta {
	display: flex;
	gap: 12px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.case-tab__meta--overdue {
	color: var(--color-error);
}

.case-tab__priority {
	text-transform: uppercase;
	font-size: 11px;
	font-weight: bold;
}
</style>
