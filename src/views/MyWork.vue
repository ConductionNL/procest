<template>
	<div class="my-work">
		<div class="my-work__header">
			<h2>
				{{ t('procest', 'My Work') }}
				<span v-if="!loading" class="my-work__count">({{ totalCount }})</span>
			</h2>
		</div>

		<!-- Filter tabs -->
		<div class="my-work__tabs">
			<button
				v-for="tab in tabs"
				:key="tab.key"
				class="my-work__tab"
				:class="{ 'my-work__tab--active': activeTab === tab.key }"
				@click="activeTab = tab.key">
				{{ tab.label }} ({{ tab.count }})
			</button>

			<label class="my-work__completed-toggle">
				<input
					v-model="showCompleted"
					type="checkbox"
					@change="onToggleCompleted">
				{{ t('procest', 'Show completed') }}
			</label>
		</div>

		<!-- Loading state -->
		<NcLoadingIcon v-if="loading" />

		<!-- Empty state -->
		<NcEmptyContent
			v-else-if="totalCount === 0 && !showCompleted"
			:name="t('procest', 'No items assigned to you')"
			:description="t('procest', 'Cases and tasks assigned to you will appear here')">
			<template #icon>
				<AccountCheck :size="64" />
			</template>
		</NcEmptyContent>

		<!-- All caught up message -->
		<NcEmptyContent
			v-else-if="totalCount === 0 && showCompleted && filteredCompletedItems.length > 0"
			:name="t('procest', 'All caught up!')"
			:description="t('procest', 'All your items are completed')">
			<template #icon>
				<CheckCircle :size="64" />
			</template>
		</NcEmptyContent>

		<!-- Grouped sections -->
		<template v-else>
			<!-- Overdue -->
			<div v-if="filteredGroups.overdue.length > 0" class="my-work__section my-work__section--overdue">
				<h3 class="my-work__section-header my-work__section-header--overdue">
					{{ t('procest', 'Overdue') }}
					<span class="my-work__section-count">({{ filteredGroups.overdue.length }})</span>
				</h3>
				<div
					v-for="item in filteredGroups.overdue"
					:key="`${item.type}-${item.id}`"
					class="my-work__row my-work__row--overdue"
					@click="onItemClick(item)">
					<span class="my-work__badge" :class="`my-work__badge--${item.type}`">
						{{ item.type === 'case' ? t('procest', 'CASE') : t('procest', 'TASK') }}
					</span>
					<div class="my-work__info">
						<span class="my-work__item-title">{{ item.title }}</span>
						<span v-if="item.reference" class="my-work__reference">{{ item.reference }}</span>
					</div>
					<div class="my-work__deadline">
						<span class="my-work__overdue-text">{{ item.daysText }}</span>
						<span v-if="item.priority === 'urgent' || item.priority === 'high'" class="my-work__priority">
							{{ item.priority === 'urgent' ? '!!' : '!' }}
						</span>
					</div>
				</div>
			</div>

			<!-- Due this week -->
			<div v-if="filteredGroups.dueThisWeek.length > 0" class="my-work__section">
				<h3 class="my-work__section-header">
					{{ t('procest', 'Due this week') }}
					<span class="my-work__section-count">({{ filteredGroups.dueThisWeek.length }})</span>
				</h3>
				<div
					v-for="item in filteredGroups.dueThisWeek"
					:key="`${item.type}-${item.id}`"
					class="my-work__row"
					@click="onItemClick(item)">
					<span class="my-work__badge" :class="`my-work__badge--${item.type}`">
						{{ item.type === 'case' ? t('procest', 'CASE') : t('procest', 'TASK') }}
					</span>
					<div class="my-work__info">
						<span class="my-work__item-title">{{ item.title }}</span>
						<span v-if="item.reference" class="my-work__reference">{{ item.reference }}</span>
					</div>
					<div class="my-work__deadline">
						<span>{{ item.daysText }}</span>
						<span v-if="item.priority === 'urgent' || item.priority === 'high'" class="my-work__priority">
							{{ item.priority === 'urgent' ? '!!' : '!' }}
						</span>
					</div>
				</div>
			</div>

			<!-- Upcoming -->
			<div v-if="filteredGroups.upcoming.length > 0" class="my-work__section">
				<h3 class="my-work__section-header">
					{{ t('procest', 'Upcoming') }}
					<span class="my-work__section-count">({{ filteredGroups.upcoming.length }})</span>
				</h3>
				<div
					v-for="item in filteredGroups.upcoming"
					:key="`${item.type}-${item.id}`"
					class="my-work__row"
					@click="onItemClick(item)">
					<span class="my-work__badge" :class="`my-work__badge--${item.type}`">
						{{ item.type === 'case' ? t('procest', 'CASE') : t('procest', 'TASK') }}
					</span>
					<div class="my-work__info">
						<span class="my-work__item-title">{{ item.title }}</span>
						<span v-if="item.reference" class="my-work__reference">{{ item.reference }}</span>
					</div>
					<div class="my-work__deadline">
						<span>{{ item.daysText }}</span>
						<span v-if="item.priority === 'urgent' || item.priority === 'high'" class="my-work__priority">
							{{ item.priority === 'urgent' ? '!!' : '!' }}
						</span>
					</div>
				</div>
			</div>

			<!-- No deadline -->
			<div v-if="filteredGroups.noDeadline.length > 0" class="my-work__section">
				<h3 class="my-work__section-header">
					{{ t('procest', 'No deadline') }}
					<span class="my-work__section-count">({{ filteredGroups.noDeadline.length }})</span>
				</h3>
				<div
					v-for="item in filteredGroups.noDeadline"
					:key="`${item.type}-${item.id}`"
					class="my-work__row"
					@click="onItemClick(item)">
					<span class="my-work__badge" :class="`my-work__badge--${item.type}`">
						{{ item.type === 'case' ? t('procest', 'CASE') : t('procest', 'TASK') }}
					</span>
					<div class="my-work__info">
						<span class="my-work__item-title">{{ item.title }}</span>
						<span v-if="item.reference" class="my-work__reference">{{ item.reference }}</span>
					</div>
					<div class="my-work__deadline">
						<span>{{ item.daysText }}</span>
						<span v-if="item.priority === 'urgent' || item.priority === 'high'" class="my-work__priority">
							{{ item.priority === 'urgent' ? '!!' : '!' }}
						</span>
					</div>
				</div>
			</div>

			<!-- Completed section (when toggle is on) -->
			<div v-if="showCompleted && filteredCompletedItems.length > 0" class="my-work__section my-work__section--completed">
				<h3 class="my-work__section-header my-work__section-header--completed">
					{{ t('procest', 'Completed') }}
					<span class="my-work__section-count">({{ filteredCompletedItems.length }})</span>
				</h3>
				<div
					v-for="item in filteredCompletedItems"
					:key="`${item.type}-${item.id}-done`"
					class="my-work__row my-work__row--completed"
					@click="onItemClick(item)">
					<span class="my-work__badge" :class="`my-work__badge--${item.type}`">
						{{ item.type === 'case' ? t('procest', 'CASE') : t('procest', 'TASK') }}
					</span>
					<div class="my-work__info">
						<span class="my-work__item-title">{{ item.title }}</span>
						<span v-if="item.reference" class="my-work__reference">{{ item.reference }}</span>
					</div>
					<div class="my-work__deadline">
						<span>{{ item.daysText }}</span>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import AccountCheck from 'vue-material-design-icons/AccountCheck.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import { useObjectStore } from '../store/modules/object.js'
import { getGroupedMyWorkItems } from '../utils/dashboardHelpers.js'
import { fetchTasksForCases } from '../services/taskApi.js'

export default {
	name: 'MyWork',
	components: {
		NcLoadingIcon,
		NcEmptyContent,
		AccountCheck,
		CheckCircle,
	},
	data() {
		return {
			loading: true,
			activeTab: 'all',
			showCompleted: false,
			cases: [],
			normalizedTasks: [],
			completedCases: [],
			completedTasks: [],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		grouped() {
			return getGroupedMyWorkItems(this.cases, this.normalizedTasks)
		},
		totalCount() {
			return this.grouped.totalCount
		},
		caseCount() {
			const all = [
				...this.grouped.overdue,
				...this.grouped.dueThisWeek,
				...this.grouped.upcoming,
				...this.grouped.noDeadline,
			]
			return all.filter(i => i.type === 'case').length
		},
		taskCount() {
			const all = [
				...this.grouped.overdue,
				...this.grouped.dueThisWeek,
				...this.grouped.upcoming,
				...this.grouped.noDeadline,
			]
			return all.filter(i => i.type === 'task').length
		},
		tabs() {
			return [
				{ key: 'all', label: t('procest', 'All'), count: this.totalCount },
				{ key: 'cases', label: t('procest', 'Cases'), count: this.caseCount },
				{ key: 'tasks', label: t('procest', 'Tasks'), count: this.taskCount },
			]
		},
		filteredGroups() {
			if (this.activeTab === 'all') return this.grouped
			const filterType = this.activeTab === 'cases' ? 'case' : 'task'
			return {
				overdue: this.grouped.overdue.filter(i => i.type === filterType),
				dueThisWeek: this.grouped.dueThisWeek.filter(i => i.type === filterType),
				upcoming: this.grouped.upcoming.filter(i => i.type === filterType),
				noDeadline: this.grouped.noDeadline.filter(i => i.type === filterType),
			}
		},
		completedItems() {
			const items = []
			for (const c of this.completedCases) {
				items.push({
					type: 'case',
					id: c.id,
					title: c.title || '—',
					reference: c.identifier ? `#${c.identifier}` : '',
					deadline: c.deadline || null,
					daysText: '—',
					isOverdue: false,
					isCompleted: true,
					priority: c.priority || 'normal',
				})
			}
			for (const task of this.completedTasks) {
				items.push(task)
			}
			return items
		},
		filteredCompletedItems() {
			if (this.activeTab === 'all') return this.completedItems
			const filterType = this.activeTab === 'cases' ? 'case' : 'task'
			return this.completedItems.filter(i => i.type === filterType)
		},
	},
	async mounted() {
		await this.fetchData()
	},
	methods: {
		async fetchData() {
			this.loading = true
			try {
				const currentUser = OC?.currentUser || ''

				// Fetch active cases assigned to current user.
				const caseResults = await this.objectStore.fetchCollection('case', {
					_limit: 100,
					'_filters[assignee]': currentUser,
				})
				this.cases = caseResults || []

				// Fetch CalDAV tasks for those cases.
				try {
					this.normalizedTasks = await fetchTasksForCases(this.cases)
				} catch (err) {
					console.warn('Failed to fetch CalDAV tasks, showing cases only:', err)
					this.normalizedTasks = []
				}
			} catch (err) {
				console.error('Failed to fetch my work data:', err)
			} finally {
				this.loading = false
			}
		},
		async onToggleCompleted() {
			if (!this.showCompleted) {
				this.completedCases = []
				this.completedTasks = []
				return
			}

			try {
				const currentUser = OC?.currentUser || ''

				// Find final status IDs by fetching statusTypes with isFinal.
				const statusTypes = await this.objectStore.fetchCollection('statusType', { _limit: 200 })
				const finalStatusIds = (statusTypes || [])
					.filter(st => st.isFinal === true || st.isFinal === 'true')
					.map(st => st.id)

				// Fetch completed cases by filtering on each final status ID.
				// OpenRegister _filters[status] accepts a single value, so fetch per status.
				let completedResults = []
				for (const statusId of finalStatusIds) {
					const results = await this.objectStore.fetchCollection('case', {
						_limit: 50,
						'_filters[assignee]': currentUser,
						'_filters[status]': statusId,
					})
					if (results) {
						completedResults.push(...results)
					}
				}
				// Deduplicate by ID.
				completedResults = completedResults.filter((c, i, arr) =>
					arr.findIndex(x => x.id === c.id) === i,
				)
				this.completedCases = completedResults

				// Fetch completed CalDAV tasks — use the same cases batch strategy.
				// Completed tasks from CalDAV have status === 'completed'.
				const allCases = [...this.cases, ...this.completedCases]
				const uniqueCases = allCases.filter((c, i, arr) =>
					arr.findIndex(x => x.id === c.id) === i,
				)
				try {
					const allTasks = await fetchTasksForCases(uniqueCases)
					this.completedTasks = allTasks.filter(t => t.isCompleted)
					// Also ensure active tasks list excludes completed ones.
					this.normalizedTasks = allTasks.filter(t => !t.isCompleted)
				} catch (err) {
					console.warn('Failed to fetch completed tasks:', err)
				}
			} catch (err) {
				console.warn('Failed to fetch completed items:', err)
			}
		},
		onItemClick(item) {
			if (item.type === 'case') {
				this.$router.push({ name: 'CaseDetail', params: { id: item.id } })
			} else if (item.type === 'task' && item.objectUuid) {
				// Navigate to the linked case.
				this.$router.push({ name: 'CaseDetail', params: { id: item.objectUuid } })
			}
		},
	},
}
</script>

<style scoped>
.my-work {
	padding: 20px;
}

.my-work__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.my-work__count {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

/* Tabs */
.my-work__tabs {
	display: flex;
	gap: 4px;
	align-items: center;
	margin-bottom: 20px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.my-work__tab {
	padding: 6px 14px;
	border: none;
	background: none;
	font-size: 14px;
	cursor: pointer;
	border-radius: var(--border-radius-pill);
	color: var(--color-text-maxcontrast);
	transition: background 0.15s ease, color 0.15s ease;
}

.my-work__tab:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.my-work__tab--active {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: 600;
}

.my-work__completed-toggle {
	margin-left: auto;
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 13px;
	cursor: pointer;
	white-space: nowrap;
	color: var(--color-text-maxcontrast);
}

/* Sections */
.my-work__section {
	margin-bottom: 24px;
}

.my-work__section-header {
	font-size: 13px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px;
	padding-bottom: 4px;
	border-bottom: 1px solid var(--color-border);
}

.my-work__section-header--overdue {
	color: var(--color-error);
	border-bottom-color: var(--color-error);
}

.my-work__section-header--completed {
	color: var(--color-text-maxcontrast);
	opacity: 0.7;
}

.my-work__section-count {
	font-weight: normal;
}

/* Rows */
.my-work__row {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 12px;
	margin-bottom: 2px;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: background 0.15s ease;
}

.my-work__row:hover {
	background: var(--color-background-hover);
}

.my-work__row--overdue {
	border-left: 3px solid var(--color-error);
}

.my-work__row--completed {
	opacity: 0.6;
}

/* Badge */
.my-work__badge {
	font-size: 10px;
	font-weight: bold;
	padding: 2px 6px;
	border-radius: 4px;
	white-space: nowrap;
	flex-shrink: 0;
}

.my-work__badge--case {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.my-work__badge--task {
	background: rgba(var(--color-success-rgb, 76, 175, 80), 0.15);
	color: var(--color-success);
}

/* Info */
.my-work__info {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.my-work__item-title {
	font-size: 14px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.my-work__reference {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

/* Deadline */
.my-work__deadline {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.my-work__overdue-text {
	color: var(--color-error);
	font-weight: 600;
}

.my-work__priority {
	color: var(--color-warning);
	font-weight: bold;
}
</style>
