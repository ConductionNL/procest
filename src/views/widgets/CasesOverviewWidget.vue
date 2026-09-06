<template>
	<CnDataTable
		:rows="items"
		:columns="columns"
		:loading="loading"
		hideHeader
		borderless
		:emptyText="t('dossiq', 'No cases found')"
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
import { navigateTo, SIGNAL_COLUMNS } from './signalTable.js'

export default {
	name: 'CasesOverviewWidget',
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
			cases: [],
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
			return this.cases.map((caseObj) => ({
				id: caseObj.id,
				mainText:
					caseObj.title
					|| caseObj.identifier
					|| t('dossiq', 'Unnamed case'),
				subText: caseObj.identifier ? `#${caseObj.identifier}` : '',
				targetUrl: generateUrl(`/apps/dossiq/cases/${caseObj.id}`),
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
		 * @spec openspec/specs/signalering-widgets/spec.md#requirement-nextcloud-dashboard-signalering-widgets-v1
		 */
		onViewAll() {
			navigateTo(generateUrl('/apps/dossiq/cases'))
		},

		/**
		 * Fetch case data.
		 *
		 * @return {Promise<void>}
		 */
		/** @spec openspec/specs/signalering-widgets/spec.md */
		async fetchData() {
			this.loading = true
			try {
				// Open cases only. Without this the widget listed CLOSED cases
				// too, and since it orders by startDate desc a burst of recently
				// created-and-closed rows (an e2e run leaves them behind: cases
				// are archival-immutable and cannot be cleaned up) pushed every
				// live case out of all 7 slots. Every sibling case widget already
				// filters on the materialised isFinalStatus.
				const results = await this.objectStore.fetchCollection('case', {
					isFinalStatus: false,
					_limit: 7,
					_order: { startDate: 'desc' },
				})
				this.cases = results || []
			} catch (err) {
				console.error('[CasesOverviewWidget] Failed to fetch cases:', err)
				this.cases = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
