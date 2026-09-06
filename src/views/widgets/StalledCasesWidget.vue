<template>
	<CnDataTable
		:rows="items"
		:columns="columns"
		:loading="loading"
		hideHeader
		borderless
		:emptyText="t('dossiq', 'All cases active')"
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
import { getStalledCases } from '../../utils/dashboardHelpers.js'
import { navigateTo, SIGNAL_COLUMNS } from './signalTable.js'

export default {
	name: 'StalledCasesWidget',
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
			stalledCases: [],
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
			return this.stalledCases.slice(0, 5).map((item) => ({
				id: item.id,
				mainText: item.title,
				subText: t('dossiq', '{days} days inactive', {
					days: item.daysSinceActivity,
				}),
				targetUrl: generateUrl(`/apps/dossiq/cases/${item.id}`),
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
		 * @spec openspec/specs/signalering-widgets/spec.md#requirement-stalled-cases-widget-v1
		 */
		onViewAll() {
			navigateTo(generateUrl('/apps/dossiq/cases'))
		},

		/**
		 * Fetch case data and compute stalled cases.
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

				this.stalledCases = getStalledCases(openCases, caseTypes)
			} catch (err) {
				console.error('[StalledCasesWidget] Failed to fetch data:', err)
				this.stalledCases = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
