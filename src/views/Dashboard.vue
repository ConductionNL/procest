<template>
	<div class="procest-dashboard">
		<!-- Header with quick actions -->
		<div class="dashboard-header">
			<h2>{{ t('procest', 'Dashboard') }}</h2>
			<div class="quick-actions">
				<NcButton type="primary" @click="showCreateDialog = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('procest', 'New Case') }}
				</NcButton>
				<NcButton @click="showTaskDialog = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('procest', 'New Task') }}
				</NcButton>
				<NcButton :disabled="globalLoading"
					:aria-label="t('procest', 'Refresh dashboard')"
					@click="loadDashboardData">
					<template #icon>
						<Refresh :size="20" :class="{ 'icon-spinning': globalLoading }" />
					</template>
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="globalLoading && !hasData" />

		<template v-else>
			<!-- KPI Cards -->
			<div class="kpi-row">
				<div class="kpi-card">
					<div class="kpi-icon">
						<FolderOpen :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-value">{{ kpis.openCount }}</span>
						<span class="kpi-label">{{ t('procest', 'Open Cases') }}</span>
					</div>
				</div>
				<div class="kpi-card" :class="{ 'kpi-card--warning': kpis.overdueCount > 0 }">
					<div class="kpi-icon" :class="{ 'kpi-icon--warning': kpis.overdueCount > 0 }">
						<AlertCircle :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-value">{{ kpis.overdueCount }}</span>
						<span class="kpi-label">{{ t('procest', 'Overdue') }}</span>
					</div>
				</div>
				<div class="kpi-card">
					<div class="kpi-icon kpi-icon--success">
						<CheckCircle :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-value">{{ kpis.completedCount }}</span>
						<span class="kpi-label">{{ t('procest', 'Completed This Month') }}</span>
					</div>
				</div>
				<div class="kpi-card">
					<div class="kpi-icon">
						<ClipboardCheckOutline :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-value">{{ kpis.taskCount }}</span>
						<span class="kpi-label">{{ t('procest', 'My Tasks') }}</span>
					</div>
				</div>
			</div>

			<!-- Charts row -->
			<div class="charts-row">
				<!-- Cases by Status -->
				<div class="chart-card">
					<h3>{{ t('procest', 'Cases by Status') }}</h3>
					<div v-if="statusData.length === 0" class="chart-empty">
						{{ t('procest', 'No open cases') }}
					</div>
					<div v-else class="status-chart">
						<div
							v-for="(item, index) in statusData"
							:key="item.name"
							class="status-bar-row">
							<span class="status-bar-label">{{ item.name }}</span>
							<div class="status-bar-track">
								<div
									class="status-bar-fill"
									:style="{ width: barWidth(item.count), background: barColor(index) }" />
							</div>
							<span class="status-bar-count">{{ item.count }}</span>
						</div>
					</div>
				</div>

				<!-- My Work Preview -->
				<div class="chart-card">
					<h3>
						{{ t('procest', 'My Work') }}
						<span v-if="myWorkItems.length > 0" class="my-work-count">({{ myWorkItems.length }})</span>
					</h3>
					<div v-if="myWorkItems.length === 0" class="chart-empty">
						{{ t('procest', 'No items assigned to you') }}
					</div>
					<div v-else class="my-work-list">
						<div
							v-for="item in myWorkItems"
							:key="`${item.type}-${item.id}`"
							class="my-work-item"
							:class="{ 'my-work-item--overdue': item.isOverdue }"
							@click="onWorkItemClick(item.type, item.id)">
							<span class="entity-badge" :class="'badge--' + item.type">
								{{ item.type === 'case' ? 'CASE' : 'TASK' }}
							</span>
							<span class="my-work-title">{{ item.title }}</span>
							<span class="my-work-stage">{{ item.reference }}</span>
							<span v-if="item.daysText" class="my-work-due" :class="{ overdue: item.isOverdue }">
								{{ item.daysText }}
							</span>
						</div>
						<NcButton
							v-if="myWorkItems.length >= 5"
							type="tertiary"
							class="view-all-link"
							@click="$router.push({ name: 'MyWork' })">
							{{ t('procest', 'View all my work') }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Welcome message for fresh installs -->
			<div v-if="showEmptyState" class="welcome-message">
				<p v-if="isAdmin">
					{{ t('procest', 'Welcome to Procest! Get started by creating your first case type in Settings.') }}
				</p>
				<p v-else>
					{{ t('procest', 'Welcome to Procest! Get started by creating your first case or task using the buttons above.') }}
				</p>
			</div>

			<!-- Error display -->
			<div v-if="error" class="dashboard-error">
				<p>{{ error }}</p>
				<NcButton @click="loadDashboardData">
					{{ t('procest', 'Retry') }}
				</NcButton>
			</div>
		</template>

		<!-- Case Create Dialog -->
		<CaseCreateDialog
			v-if="showCreateDialog"
			@created="onCaseCreated"
			@close="showCreateDialog = false" />

		<!-- Task Create Dialog -->
		<TaskCreateDialog
			v-if="showTaskDialog"
			@created="onTaskCreated"
			@close="showTaskDialog = false" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import { useObjectStore } from '../store/modules/object.js'
import {
	computeKpis,
	aggregateByStatus,
	getMyWorkItems,
} from '../utils/dashboardHelpers.js'
import CaseCreateDialog from './cases/CaseCreateDialog.vue'
import TaskCreateDialog from './tasks/TaskCreateDialog.vue'

const BAR_COLORS = [
	'var(--color-primary)',
	'var(--color-primary-element-light)',
	'var(--color-warning)',
	'var(--color-success)',
	'var(--color-error)',
	'var(--color-text-maxcontrast)',
]

export default {
	name: 'Dashboard',
	components: {
		NcButton,
		NcLoadingIcon,
		Plus,
		Refresh,
		FolderOpen,
		AlertCircle,
		CheckCircle,
		ClipboardCheckOutline,
		CaseCreateDialog,
		TaskCreateDialog,
	},
	emits: ['navigate'],
	data() {
		return {
			showCreateDialog: false,
			showTaskDialog: false,
			openCases: [],
			completedCases: [],
			myTasks: [],
			caseTypes: [],
			statusTypes: [],
			kpis: { openCount: 0, newToday: 0, overdueCount: 0, completedCount: 0, avgDays: null, taskCount: 0, tasksDueToday: 0 },
			statusData: [],
			myWorkItems: [],
			globalLoading: false,
			error: null,
			refreshTimer: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isAdmin() {
			return window._oc_isadmin === true
		},
		hasData() {
			return this.openCases.length > 0
				|| this.completedCases.length > 0
				|| this.caseTypes.length > 0
		},
		showEmptyState() {
			return !this.globalLoading
				&& this.openCases.length === 0
				&& this.completedCases.length === 0
				&& this.caseTypes.length === 0
				&& !this.error
		},
	},
	async mounted() {
		await this.loadDashboardData()
		this.refreshTimer = setInterval(() => {
			this.loadDashboardData()
		}, 5 * 60 * 1000)
	},
	beforeDestroy() {
		if (this.refreshTimer) {
			clearInterval(this.refreshTimer)
			this.refreshTimer = null
		}
	},
	methods: {
		async loadDashboardData() {
			this.globalLoading = true
			this.error = null

			const currentUser = OC?.currentUser || ''
			const today = new Date()
			const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().slice(0, 10)

			try {
				const results = await Promise.allSettled([
					this.objectStore.fetchCollection('case', { _limit: 1000 }),
					this.objectStore.fetchCollection('caseType', { _limit: 100 }),
					this.objectStore.fetchCollection('statusType', { _limit: 500 }),
					this.objectStore.fetchCollection('task', {
						'_filters[assignee]': currentUser,
						_limit: 100,
					}),
				])

				const allCases = results[0].status === 'fulfilled' ? (results[0].value || []) : []
				this.caseTypes = results[1].status === 'fulfilled' ? (results[1].value || []) : []
				this.statusTypes = results[2].status === 'fulfilled' ? (results[2].value || []) : []
				this.myTasks = results[3].status === 'fulfilled' ? (results[3].value || []) : []

				const statusTypeMap = new Map()
				for (const st of this.statusTypes) {
					statusTypeMap.set(st.id, st)
				}

				this.openCases = allCases.filter(c => {
					const st = statusTypeMap.get(c.status)
					return !st?.isFinal
				})
				this.completedCases = allCases.filter(c => {
					const st = statusTypeMap.get(c.status)
					return st?.isFinal && c.endDate && c.endDate.slice(0, 10) >= firstOfMonth
				})

				this.myTasks = this.myTasks.filter(t =>
					t.status === 'available' || t.status === 'active',
				)

				this.kpis = computeKpis(this.openCases, this.completedCases, this.myTasks)
				this.statusData = aggregateByStatus(this.openCases, this.statusTypes)

				const myCases = this.openCases.filter(c => c.assignee === currentUser)
				this.myWorkItems = getMyWorkItems(myCases, this.myTasks, 5)
			} catch (err) {
				this.error = err.message || t('procest', 'Failed to load dashboard data')
				console.error('Dashboard fetch error:', err)
			} finally {
				this.globalLoading = false
			}
		},

		barWidth(count) {
			const max = Math.max(1, ...this.statusData.map(s => s.count))
			const pct = (count / max) * 100
			return `max(20px, ${pct}%)`
		},

		barColor(index) {
			return BAR_COLORS[index % BAR_COLORS.length]
		},

		onWorkItemClick(type, id) {
			if (type === 'case') {
				this.$router.push({ name: 'CaseDetail', params: { id } })
			} else {
				this.$router.push({ name: 'TaskDetail', params: { id } })
			}
		},

		onViewAllOverdue() {
			this.$router.push({ name: 'Cases', query: { overdue: 'true' } })
		},

		onCaseCreated(caseId) {
			this.showCreateDialog = false
			this.$router.push({ name: 'CaseDetail', params: { id: caseId } })
		},

		onTaskCreated(taskId) {
			this.showTaskDialog = false
			this.$router.push({ name: 'TaskDetail', params: { id: taskId } })
		},
	},
}
</script>

<style scoped>
.procest-dashboard {
	padding: 20px;
	max-width: 1200px;
}

/* Header */
.dashboard-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 24px;
	flex-wrap: wrap;
	gap: 12px;
}

.quick-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

/* KPI Cards */
.kpi-row {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 16px;
	margin-bottom: 24px;
}

@media (max-width: 900px) {
	.kpi-row {
		grid-template-columns: repeat(2, 1fr);
	}
}

@media (max-width: 500px) {
	.kpi-row {
		grid-template-columns: 1fr;
	}
}

.kpi-card {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.kpi-card--warning {
	border-color: var(--color-warning);
	background: var(--color-warning-hover, rgba(233, 163, 0, 0.05));
}

.kpi-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	border-radius: 50%;
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.1));
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.kpi-icon--success {
	background: rgba(70, 186, 97, 0.1);
	color: #46ba61;
}

.kpi-icon--warning {
	background: rgba(233, 50, 45, 0.1);
	color: var(--color-error);
}

.kpi-content {
	display: flex;
	flex-direction: column;
}

.kpi-value {
	font-size: 24px;
	font-weight: 700;
	line-height: 1.2;
}

.kpi-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

/* Charts row */
.charts-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
	margin-bottom: 24px;
}

@media (max-width: 700px) {
	.charts-row {
		grid-template-columns: 1fr;
	}
}

.chart-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
}

.chart-card h3 {
	margin: 0 0 12px;
	font-size: 15px;
	font-weight: 600;
}

.chart-empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

/* Status bar chart */
.status-chart {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.status-bar-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.status-bar-label {
	width: 110px;
	font-size: 13px;
	text-align: right;
	flex-shrink: 0;
}

.status-bar-track {
	flex: 1;
	height: 22px;
	background: var(--color-background-dark);
	border-radius: 4px;
	overflow: hidden;
}

.status-bar-fill {
	height: 100%;
	border-radius: 4px;
	min-width: 2px;
	transition: width 0.3s ease;
}

.status-bar-count {
	width: 30px;
	font-size: 13px;
	font-weight: 600;
	text-align: right;
	flex-shrink: 0;
}

/* My Work list */
.my-work-count {
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.my-work-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.my-work-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.my-work-item:hover {
	background: var(--color-background-hover);
}

.my-work-item--overdue {
	background: rgba(233, 50, 45, 0.04);
}

.entity-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.5px;
	flex-shrink: 0;
}

.badge--case {
	background: #dbeafe;
	color: #1d4ed8;
	border: 1px solid #93c5fd;
}

.badge--task {
	background: #dcfce7;
	color: #16a34a;
	border: 1px solid #86efac;
}

.my-work-title {
	flex: 1;
	font-size: 13px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.my-work-stage {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.my-work-due {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.my-work-due.overdue {
	color: var(--color-error);
	font-weight: 600;
}

.view-all-link {
	margin-top: 4px;
	align-self: flex-start;
}

/* Welcome / empty / error */
.welcome-message {
	text-align: center;
	padding: 40px 20px;
	color: var(--color-text-maxcontrast);
	font-size: 15px;
}

.dashboard-error {
	text-align: center;
	padding: 20px;
	color: var(--color-error);
}

.dashboard-error p {
	margin-bottom: 12px;
}

/* Refresh button spinning animation */
.icon-spinning {
	animation: spin 1s linear infinite;
}

@keyframes spin {
	from { transform: rotate(0deg); }
	to { transform: rotate(360deg); }
}
</style>
