<template>
	<CnDataTable
		:rows="items"
		:columns="columns"
		:loading="loading"
		hideHeader
		borderless
		:emptyText="t('dossiq', 'No task reminders')"
		@rowClick="onRowClick">
		<template #footer>
			<a
				class="cn-data-table__view-all"
				:href="viewAllUrl"
				@click.prevent="onViewAll">
				{{ t('dossiq', 'View all') }} →
			</a>
		</template>
	</CnDataTable>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { getTaskDueReminders } from '../../utils/dashboardHelpers.js'
import { navigateTo, SIGNAL_COLUMNS } from './signalTable.js'

/**
 * How many rows the widget shows. Large enough that overdue tasks, which are
 * listed first, cannot crowd out every task that is merely due soon.
 *
 * @type {number}
 */
const MAX_REMINDERS = 10

export default {
	name: 'TaskRemindersWidget',
	components: {
		CnDataTable,
	},

	props: {
		// Declared on purpose. The mount script passes `title: widget.title`, and
		// the Nextcloud dashboard host renders the heading; rendering it here too
		// is the dashboard-in-dashboard antipattern (hydra#316). Dropping the
		// declaration would not remove the prop, it would make it a fallthrough
		// attribute and put a title="" tooltip on the root element.
		// eslint-disable-next-line vue/no-unused-properties
		title: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: false,
			reminders: { overdue: [], dueSoon: [] },
			columns: SIGNAL_COLUMNS,
		}
	},

	computed: {
		/** @spec openspec/specs/signalering-widgets/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * Real destination URL for the "View all" link (gate-32: an `<a>`
		 * with a real `href` is a genuine link, not a mouse-only click
		 * target).
		 *
		 * @spec openspec/specs/signalering-widgets/spec.md
		 */
		viewAllUrl() {
			return generateUrl('/apps/dossiq/tasks')
		},

		/** @spec openspec/specs/signalering-widgets/spec.md */
		items() {
			const overdueItems = this.reminders.overdue.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: t('dossiq', '{days} days overdue', {
					days: item.daysOverdue,
				}),
				targetUrl: generateUrl(`/apps/dossiq/tasks/${item.id}`),
			}))
			const dueSoonItems = this.reminders.dueSoon.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText:
					item.daysRemaining === 0
						? t('dossiq', 'Due today')
						: t('dossiq', '{days} days remaining', {
								days: item.daysRemaining,
							}),
				targetUrl: generateUrl(`/apps/dossiq/tasks/${item.id}`),
			}))
			// Same cap rule as DeadlineAlertsWidget: overdue tasks are listed
			// first, so too small a cap hides every task that is merely due soon.
			return [...overdueItems, ...dueSoonItems].slice(0, MAX_REMINDERS)
		},
	},

	async mounted() {
		// Ensure object types are registered before fetching. App.vue's
		// async created() does not block child mounting, so this widget can
		// mount before initializeStores() has resolved; the same applies
		// when the widget runs standalone on the Nextcloud Dashboard.
		await initializeStores()
		this.fetchData()
	},

	methods: {
		/**
		 * Navigate to a clicked task in the same tab.
		 *
		 * @param {object} row The clicked row (a shaped task item).
		 * @return {void}
		 */
		onRowClick(row) {
			navigateTo(row.targetUrl)
		},

		/**
		 * Navigate to the full tasks list.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/signalering-widgets/spec.md#requirement-task-due-reminders-widget-v1
		 */
		onViewAll() {
			navigateTo(generateUrl('/apps/dossiq/tasks'))
		},

		/**
		 * Fetch task data and compute due reminders.
		 *
		 * @return {Promise<void>}
		 */
		/** @spec openspec/specs/signalering-widgets/spec.md */
		async fetchData() {
			this.loading = true
			try {
				const currentUser = getCurrentUser()?.uid || ''
				// Bare field names, not `_filters[x]`: that form is inert and this
				// widget was reading every user's tasks. See MyTasksWidget.
				const tasks = await this.objectStore.fetchCollection('caseTask', {
					assignee: currentUser,
					isTerminalStatus: false,
					_limit: 100,
				})
				const activeTasks = (tasks || []).filter(
					(t) => t.status === 'available' || t.status === 'active',
				)
				this.reminders = getTaskDueReminders(activeTasks)
			} catch (err) {
				console.error('[TaskRemindersWidget] Failed to fetch data:', err)
				this.reminders = { overdue: [], dueSoon: [] }
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
