<template>
	<div class="procest-dashboard">
		<!-- Header: quick actions + refresh -->
		<div class="procest-dashboard__header">
			<NcButton v-if="!showEmptyState" type="primary" @click="showCreateDialog = true">
				{{ t('procest', '+ New Case') }}
			</NcButton>
			<span v-else />
			<NcButton type="tertiary" :disabled="globalLoading" @click="loadDashboardData">
				<template v-if="globalLoading">
					<NcLoadingIcon :size="20" />
				</template>
				<template v-else>
					&#8635;
				</template>
			</NcButton>
		</div>

		<!-- KPI Cards — always visible, shows "0" in empty state per REQ-DASH-009 -->
		<KpiCards
			:open-cases="kpis.openCount"
			:new-today="kpis.newToday"
			:overdue-cases="kpis.overdueCount"
			:completed-this-month="kpis.completedCount"
			:avg-processing-days="kpis.avgDays"
			:my-tasks="kpis.taskCount"
			:tasks-due-today="kpis.tasksDueToday"
			:loading="sections.kpi.loading"
			@click-card="onCardClick" />

		<!-- Empty state: fresh install with no case types -->
		<div v-if="showEmptyState" class="procest-dashboard__empty">
			<h2>{{ t('procest', 'Welcome to Procest') }}</h2>
			<p>{{ t('procest', 'Case management for Nextcloud') }}</p>
			<div v-if="isAdmin" class="procest-dashboard__empty-action">
				<p>{{ t('procest', 'Get started by creating your first case type in Settings.') }}</p>
				<NcButton type="primary" @click="$emit('navigate', 'settings')">
					{{ t('procest', 'Go to Settings') }}
				</NcButton>
			</div>
			<div v-else>
				<p>{{ t('procest', 'The app needs to be configured by an administrator.') }}</p>
			</div>
		</div>

		<!-- Dashboard content -->
		<div v-else>
			<!-- Two-column layout -->
			<div class="procest-dashboard__grid">
				<!-- Left column -->
				<div class="procest-dashboard__left">
					<StatusChart
						:status-data="statusData"
						:loading="sections.chart.loading"
						:error="sections.chart.error"
						@retry="retrySection('chart')" />

					<MyWorkPreview
						:items="myWorkItems"
						:loading="sections.mywork.loading"
						:error="sections.mywork.error"
						@click-item="onWorkItemClick"
						@view-all="$emit('navigate', 'my-work')"
						@retry="retrySection('mywork')" />
				</div>

				<!-- Right column -->
				<div class="procest-dashboard__right">
					<OverduePanel
						:cases="overdueCases"
						:loading="sections.overdue.loading"
						:error="sections.overdue.error"
						@click-case="onCaseClick"
						@view-all="onViewAllOverdue"
						@retry="retrySection('overdue')" />

					<ActivityFeed
						:entries="activityEntries"
						:loading="sections.activity.loading"
						:error="sections.activity.error"
						@view-all="$emit('navigate', 'cases')"
						@retry="retrySection('activity')" />
				</div>
			</div>
		</div>

		<!-- Case Create Dialog -->
		<CaseCreateDialog
			v-if="showCreateDialog"
			@created="onCaseCreated"
			@close="showCreateDialog = false" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../store/modules/object.js'
import {
	computeKpis,
	aggregateByStatus,
	getOverdueCases,
	getRecentActivity,
	getMyWorkItems,
} from '../utils/dashboardHelpers.js'
import KpiCards from './dashboard/KpiCards.vue'
import StatusChart from './dashboard/StatusChart.vue'
import OverduePanel from './dashboard/OverduePanel.vue'
import MyWorkPreview from './dashboard/MyWorkPreview.vue'
import ActivityFeed from './dashboard/ActivityFeed.vue'
import CaseCreateDialog from './cases/CaseCreateDialog.vue'

export default {
	name: 'Dashboard',
	components: {
		NcButton,
		NcLoadingIcon,
		KpiCards,
		StatusChart,
		OverduePanel,
		MyWorkPreview,
		ActivityFeed,
		CaseCreateDialog,
	},
	emits: ['navigate'],
	data() {
		return {
			showCreateDialog: false,
			openCases: [],
			completedCases: [],
			myTasks: [],
			caseTypes: [],
			statusTypes: [],
			kpis: { openCount: 0, newToday: 0, overdueCount: 0, completedCount: 0, avgDays: null, taskCount: 0, tasksDueToday: 0 },
			statusData: [],
			overdueCases: [],
			myWorkItems: [],
			activityEntries: [],
			globalLoading: false,
			sections: {
				kpi: { loading: false, error: null },
				chart: { loading: false, error: null },
				overdue: { loading: false, error: null },
				mywork: { loading: false, error: null },
				activity: { loading: false, error: null },
			},
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isAdmin() {
			return OC?.isAdmin || false
		},
		showEmptyState() {
			return !this.globalLoading
				&& this.openCases.length === 0
				&& this.completedCases.length === 0
				&& this.caseTypes.length === 0
		},
	},
	async mounted() {
		await this.loadDashboardData()
	},
	methods: {
		async loadDashboardData() {
			this.globalLoading = true
			Object.keys(this.sections).forEach(k => {
				this.sections[k].loading = true
				this.sections[k].error = null
			})

			const currentUser = OC?.currentUser || ''
			const today = new Date()
			const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().slice(0, 10)

			const results = await Promise.allSettled([
				this.objectStore.fetchCollection('case', { _limit: 1000 }),
				this.objectStore.fetchCollection('caseType', { _limit: 100 }),
				this.objectStore.fetchCollection('statusType', { _limit: 500 }),
				this.objectStore.fetchCollection('task', {
					'_filters[assignee]': currentUser,
					_limit: 100,
				}),
			])

			// Process cases
			const allCases = results[0].status === 'fulfilled' ? (results[0].value || []) : []
			this.caseTypes = results[1].status === 'fulfilled' ? (results[1].value || []) : []
			this.statusTypes = results[2].status === 'fulfilled' ? (results[2].value || []) : []
			this.myTasks = results[3].status === 'fulfilled' ? (results[3].value || []) : []

			// Build status type lookup for isFinal detection
			const statusTypeMap = new Map()
			for (const st of this.statusTypes) {
				statusTypeMap.set(st.id, st)
			}

			// Split cases into open vs completed
			this.openCases = allCases.filter(c => {
				const st = statusTypeMap.get(c.status)
				return !st?.isFinal
			})
			this.completedCases = allCases.filter(c => {
				const st = statusTypeMap.get(c.status)
				return st?.isFinal && c.endDate && c.endDate.slice(0, 10) >= firstOfMonth
			})

			// Filter tasks to only available/active
			this.myTasks = this.myTasks.filter(t =>
				t.status === 'available' || t.status === 'active',
			)

			// Compute derived data
			try {
				this.kpis = computeKpis(this.openCases, this.completedCases, this.myTasks)
				this.sections.kpi.loading = false
			} catch (e) {
				this.sections.kpi.error = e.message
				this.sections.kpi.loading = false
			}

			try {
				this.statusData = aggregateByStatus(this.openCases, this.statusTypes)
				this.sections.chart.loading = false
			} catch (e) {
				this.sections.chart.error = e.message
				this.sections.chart.loading = false
			}

			try {
				this.overdueCases = getOverdueCases(this.openCases, this.caseTypes)
				this.sections.overdue.loading = false
			} catch (e) {
				this.sections.overdue.error = e.message
				this.sections.overdue.loading = false
			}

			try {
				// My work: cases assigned to current user + their tasks
				const myCases = this.openCases.filter(c => c.assignee === currentUser)
				this.myWorkItems = getMyWorkItems(myCases, this.myTasks, 5)
				this.sections.mywork.loading = false
			} catch (e) {
				this.sections.mywork.error = e.message
				this.sections.mywork.loading = false
			}

			try {
				this.activityEntries = getRecentActivity(allCases, 10)
				this.sections.activity.loading = false
			} catch (e) {
				this.sections.activity.error = e.message
				this.sections.activity.loading = false
			}

			this.globalLoading = false
		},

		async retrySection(section) {
			this.sections[section].loading = true
			this.sections[section].error = null
			await this.loadDashboardData()
		},

		onCardClick(cardId) {
			switch (cardId) {
			case 'open':
				this.$emit('navigate', 'cases')
				break
			case 'overdue':
				window.location.hash = '#/cases?overdue=true'
				this.$emit('navigate', 'cases')
				break
			case 'completed':
				this.$emit('navigate', 'cases')
				break
			case 'tasks':
				this.$emit('navigate', 'tasks')
				break
			}
		},

		onCaseClick(caseId) {
			this.$emit('navigate', 'case-detail', caseId)
		},

		onWorkItemClick(type, id) {
			if (type === 'case') {
				this.$emit('navigate', 'case-detail', id)
			} else {
				this.$emit('navigate', 'task-detail', id)
			}
		},

		onViewAllOverdue() {
			window.location.hash = '#/cases?overdue=true'
			this.$emit('navigate', 'cases')
		},

		onCaseCreated(caseId) {
			this.showCreateDialog = false
			this.$emit('navigate', 'case-detail', caseId)
		},
	},
}
</script>

<style scoped>
.procest-dashboard {
	padding: 20px;
	max-width: 1200px;
}

.procest-dashboard__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.procest-dashboard__grid {
	display: grid;
	grid-template-columns: 3fr 2fr;
	gap: 16px;
}

@media (max-width: 768px) {
	.procest-dashboard__grid {
		grid-template-columns: 1fr;
	}
}

.procest-dashboard__left,
.procest-dashboard__right {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.procest-dashboard__empty {
	text-align: center;
	padding: 60px 20px;
}

.procest-dashboard__empty h2 {
	margin-bottom: 8px;
}

.procest-dashboard__empty p {
	color: var(--color-text-maxcontrast);
}

.procest-dashboard__empty-action {
	margin-top: 24px;
}
</style>
