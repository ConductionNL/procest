<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
  -
  - NOTE ON `register="dossiq"` BELOW — it is deliberately NOT `dossiq`.
  - That value is the OpenRegister register SLUG, not this app's id. OpenRegister
  - resolves a register by slug, so renaming it alongside the procest -> dossiq
  - app-id rename would point this page at a register that does not exist and
  - orphan every stored case. The failure is silent: the page still renders and
  - simply shows no work. The l10n domain and the /apps/… URLs on the same
  - component DO move, because those name this app to Nextcloud.
-->
<template>
	<CnIndexPage
		:title="t('dossiq', 'My Work')"
		register="dossiq"
		schema="case"
		:filter="filter"
		viewMode="cards"
		:viewModes="['cards', 'table']"
		:columns="columns"
		:sidebar="sidebar"
		:showViewAction="false"
		:sortKey="sortConfig.key"
		:sortOrder="sortConfig.order"
		@view="openCase"
		@rowClick="openCase">
		<template #below-header>
			<WorkloadSummaryBar :handlers="workloadHandlers" />
			<div
				class="mywork-sort-toggle"
				role="group"
				:aria-label="t('dossiq', 'Sort My Work')">
				<NcButton
					:type="sortMode === 'urgency' ? 'primary' : 'tertiary'"
					@click="setSortMode('urgency')">
					{{ t('dossiq', 'Urgency') }}
				</NcButton>
				<NcButton
					:type="sortMode === 'newest' ? 'primary' : 'tertiary'"
					@click="setSortMode('newest')">
					{{ t('dossiq', 'Newest') }}
				</NcButton>
			</div>
		</template>
		<!-- Custom card so case-type + status render as names, not raw UUIDs
		     (card view does not apply column formatters). -->
		<template #card="{ object, selected }">
			<MyWorkCaseCard
				:object="object"
				:selected="selected"
				:caseTypeMap="caseTypeMap"
				:statusMap="statusMap"
				:urgencyMap="urgencyMap"
				@open="openCase" />
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import MyWorkCaseCard from './MyWorkCaseCard.vue'
import WorkloadSummaryBar from './WorkloadSummaryBar.vue'
import { useObjectStore } from '../store/modules/object.js'
import { initializeStores } from '../store/store.js'
import { buildUrgencyMap, resolveSortConfig } from '../utils/workQueueHelpers.js'

/**
 * My Work — the current user's assigned cases, rendered as a standard
 * CnIndexPage card list. A thin wrapper (rather than a bare manifest
 * `type: index` page) because the stock index base-filter resolves only
 * `@route.*` tokens, not the `@me` current-user token; here we inject the
 * resolved uid into the `assignee` filter so the same self-fetch index path
 * scopes to the signed-in user.
 */
export default {
	name: 'MyWorkCards',

	components: { CnIndexPage, MyWorkCaseCard, WorkloadSummaryBar, NcButton },

	data() {
		return {
			/** { caseTypeUuid: humanName } for the card's Case type chip. */
			caseTypeMap: {},
			/** { statusTypeUuid: humanName } for the card's Status chip. */
			statusMap: {},
			/** 'urgency' (default) or 'newest' — drives the sort toggle. */
			sortMode: 'urgency',
			/** { caseId: { tier, score, daysUntilDeadline } } from GET /api/work-queue. */
			urgencyMap: {},
			/**
			 * Per-handler open-case counts from GET /api/work-queue/workload.
			 * Stays empty (no error UI) for non-coordinators, who get a 403.
			 */
			workloadHandlers: [],
		}
	},

	computed: {
		/**
		 * CnIndexPage sortKey/sortOrder for the active sort mode.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		sortConfig() {
			return resolveSortConfig(this.sortMode)
		},

		/**
		 * Base filter scoping the case list to the current user's assignments.
		 *
		 * @spec openspec/specs/my-work/spec.md
		 */
		filter() {
			const uid = (getCurrentUser() && getCurrentUser().uid) || ''
			return { assignee: uid }
		},

		/**
		 * Curated card/table columns, mirroring the Cases index.
		 *
		 * @spec openspec/specs/my-work/spec.md
		 */
		columns() {
			return [
				'identifier',
				'title',
				{
					key: 'caseType',
					label: this.t('dossiq', 'Case type'),
					formatter: 'caseTypeName',
				},
				{
					key: 'status',
					label: this.t('dossiq', 'Status'),
					formatter: 'statusTypeName',
				},
				'deadline',
			]
		},

		/**
		 * Enable the embedded filter/search sidebar (search box + per-field facet
		 * filters derived from the case schema), mirroring the Cases index so
		 * users can narrow their assigned cases by status, case type, priority,
		 * etc. Metadata column group is hidden to keep it focused.
		 */
		sidebar() {
			return { enabled: true, showMetadata: false }
		},
	},

	/**
	 * Load the caseType / statusType collections up front and build UUID→name
	 * maps so the cards show human names (card view does not apply the column
	 * formatters, and the lazy formatter self-load is unreliable through a
	 * scoped-slot child's computed).
	 */
	async mounted() {
		await initializeStores()
		const store = useObjectStore()
		try {
			const [caseTypes, statuses] = await Promise.all([
				store.fetchCollection('caseType', { _limit: 200 }),
				store.fetchCollection('statusType', { _limit: 200 }),
			])
			this.caseTypeMap = this.buildNameMap(caseTypes)
			this.statusMap = this.buildNameMap(statuses)
		} catch (e) {
			// Names simply fall back to hidden chips; never block the list.
		}

		// Urgency chips + coordinator workload never block the list rendering.
		this.fetchWorkQueue()
		this.fetchWorkload()
	},

	methods: {
		/**
		 * Build a UUID→name map from an OpenRegister collection.
		 *
		 * @param {Array<object>} collection The fetched objects.
		 * @return {{[key: string]: string}} id to title or name.
		 */
		buildNameMap(collection) {
			const map = {}
			for (const o of collection || []) {
				const id = o.id || (o['@self'] && o['@self'].id)
				if (id) {
					map[id] = o.title || o.name || String(id)
				}
			}
			return map
		},

		/**
		 * Fetch the current user's urgency-scored work queue and build the
		 * caseId → { tier, score, daysUntilDeadline } chip map. Never blocks
		 * the card list on failure.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		async fetchWorkQueue() {
			try {
				const response = await axios.get(
					generateUrl('/apps/dossiq/api/work-queue'),
				)
				this.urgencyMap = buildUrgencyMap(
					response.data && response.data.items,
				)
			} catch (e) {
				// Chips simply stay hidden; never block the list.
			}
		},

		/**
		 * Fetch the coordinator workload summary. A 403 (non-coordinator) is
		 * expected and silently swallowed — no error UI, no summary rendered.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		async fetchWorkload() {
			try {
				const response = await axios.get(
					generateUrl('/apps/dossiq/api/work-queue/workload'),
				)
				this.workloadHandlers =
					(response.data && response.data.handlers) || []
			} catch (e) {
				this.workloadHandlers = []
			}
		},

		/**
		 * Switch the active sort mode.
		 *
		 * @param {string} mode 'urgency' or 'newest'.
		 *
		 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
		 */
		setSortMode(mode) {
			this.sortMode = mode
		},

		/**
		 * Open a case detail page from a clicked row/card.
		 *
		 * @param {object} row The case object emitted by CnIndexPage.
		 *
		 * @spec openspec/specs/my-work/spec.md
		 */
		openCase(row) {
			const id =
				(row && (row.id || row.uuid))
				|| (row && row['@self'] && row['@self'].id)
			if (id) {
				this.$router.push({ name: 'CaseDetail', params: { id: String(id) } })
			}
		},
	},
}
</script>

<style scoped lang="scss">
.mywork-sort-toggle {
	display: flex;
	gap: 8px;
	margin-bottom: 8px;
}
</style>
