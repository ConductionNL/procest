<template>
	<CnDataTable
		:rows="items"
		:columns="columns"
		:loading="loading"
		hideHeader
		borderless
		:emptyText="t('dossiq', 'No deadline alerts')"
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
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { getDeadlineAlerts } from '../../utils/dashboardHelpers.js'
import { navigateTo, SIGNAL_COLUMNS } from './signalTable.js'

/**
 * How many rows the widget shows. Large enough that overdue cases, which are
 * listed first, cannot crowd out every at-risk case: the spec requires this
 * widget to show both.
 *
 * @type {number}
 */
const MAX_ALERTS = 10

export default {
	name: 'DeadlineAlertsWidget',
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
			alerts: { overdue: [], atRisk: [] },
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
			return generateUrl('/apps/dossiq/cases')
		},

		/** @spec openspec/specs/signalering-widgets/spec.md */
		items() {
			const overdueItems = this.alerts.overdue.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: t('dossiq', '{days} days overdue', {
					days: item.daysOverdue,
				}),
				targetUrl: generateUrl(`/apps/dossiq/cases/${item.id}`),
			}))
			const atRiskItems = this.alerts.atRisk.map((item) => ({
				id: item.id,
				mainText: item.title,
				subText:
					item.daysRemaining === 0
						? t('dossiq', 'Due today')
						: t('dossiq', '{days} days remaining', {
								days: item.daysRemaining,
							}),
				targetUrl: generateUrl(`/apps/dossiq/cases/${item.id}`),
			}))
			// 🔴 THE CAP MUST CLEAR BOTH GROUPS, NOT JUST THE FIRST ONE. Overdue
			// items are concatenated ahead of at-risk ones, so a cap of 5 meant
			// that from the fifth overdue case onward NO at-risk case could ever
			// be shown, and this widget rendered the same rows as Overdue Cases
			// beside it. The spec requires both groups here.
			return [...overdueItems, ...atRiskItems].slice(0, MAX_ALERTS)
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
		 * Navigate to a clicked case in the same tab.
		 *
		 * @param {object} row The clicked row (a shaped case item).
		 * @return {void}
		 */
		onRowClick(row) {
			navigateTo(row.targetUrl)
		},

		/**
		 * Navigate to the full cases list.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/signalering-widgets/spec.md#requirement-deadline-alerts-widget-v1
		 */
		onViewAll() {
			navigateTo(generateUrl('/apps/dossiq/cases'))
		},

		/**
		 * Fetch case data and compute deadline alerts.
		 *
		 * @return {Promise<void>}
		 */
		/** @spec openspec/specs/signalering-widgets/spec.md */
		async fetchData() {
			this.loading = true
			try {
				const results = await Promise.allSettled([
					this.objectStore.fetchCollection('case', { _limit: 1000 }),
					this.objectStore.fetchCollection('caseType', { _limit: 100 }),
					this.objectStore.fetchCollection('statusType', { _limit: 500 }),
				])

				const allCases =
					results[0].status === 'fulfilled' ? results[0].value || [] : []
				const caseTypes =
					results[1].status === 'fulfilled' ? results[1].value || [] : []
				const statusTypes =
					results[2].status === 'fulfilled' ? results[2].value || [] : []

				const statusTypeMap = new Map()
				for (const st of statusTypes) {
					statusTypeMap.set(st.id, st)
				}
				const openCases = allCases.filter((c) => {
					const st = statusTypeMap.get(c.status)
					return !st?.isFinal
				})

				this.alerts = getDeadlineAlerts(openCases, caseTypes)
			} catch (err) {
				console.error('[DeadlineAlertsWidget] Failed to fetch data:', err)
				this.alerts = { overdue: [], atRisk: [] }
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
