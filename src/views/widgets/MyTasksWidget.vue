<template>
	<CnDataTable
		:rows="items"
		:columns="columns"
		:loading="loading"
		hideHeader
		borderless
		:emptyText="t('dossiq', 'No tasks found')"
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
import { navigateTo, SIGNAL_COLUMNS } from './signalTable.js'

export default {
	name: 'MyTasksWidget',
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
			tasks: [],
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
			return this.tasks.map((task) => ({
				id: task.id,
				mainText: task.title || t('dossiq', 'Unnamed task'),
				subText: task.dueDate
					? t('dossiq', 'Deadline: {date}', {
							date: task.dueDate.slice(0, 10),
						})
					: t('dossiq', 'No deadline'),
				targetUrl: generateUrl(`/apps/dossiq/tasks/${task.id}`),
			}))
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
		 * @spec openspec/specs/my-work/spec.md#requirement-personal-workload-dashboard-widgets-mvp
		 */
		onViewAll() {
			navigateTo(generateUrl('/apps/dossiq/tasks'))
		},

		/**
		 * Fetch task data for current user.
		 *
		 * @return {Promise<void>}
		 */
		/** @spec openspec/specs/signalering-widgets/spec.md */
		async fetchData() {
			this.loading = true
			try {
				const currentUser = getCurrentUser()?.uid || ''
				// 🔴 `_filters[x]` IS INERT. The store passes params straight to the
				// query string, and OpenRegister reads a BARE field name; measured
				// against the live API, `_filters[assignee]=rbac-editor` returned all
				// 32 tasks while `assignee=rbac-editor` returned the 2 that match. So
				// this widget was fetching EVERY user's tasks and filtering only by
				// status, which is not what a widget called "My Tasks" may show.
				const results = await this.objectStore.fetchCollection('caseTask', {
					assignee: currentUser,
					isTerminalStatus: false,
					_limit: 7,
				})
				// Filter to active/available tasks only.
				this.tasks = (results || []).filter(
					(t) => t.status === 'available' || t.status === 'active',
				)
			} catch (err) {
				console.error('[MyTasksWidget] Failed to fetch tasks:', err)
				this.tasks = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
