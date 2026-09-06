<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Workflow Board — a Kanban board with one column per non-final status type,
	open cases grouped into their current status, and status transitions
	operable by both drag-and-drop AND keyboard alone (each CaseCard's "Move
	to…" menu). Both paths call the same onDrop() -> saveObject('case', …),
	which is RBAC-enforced server-side; on failure the card reverts and an
	error toast shows. Also holds the column-scoped bulk-selection state: a
	case card's checkbox toggles selection via toggleSelection() (cross-column
	selection resets), and a bulk-actions bar opens BulkTransitionDialog to
	preview/execute one status transition across every selected case.

	Spec: openspec/changes/kanban-board-keyboard-status-transition/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
	Spec: openspec/changes/case-bulk-status-transition/specs/case-bulk-status-transition/spec.md
-->
<template>
	<div class="workflow-board">
		<div class="workflow-board__header">
			<div>
				<h2>{{ t('dossiq', 'Workflow Board') }}</h2>
				<p class="workflow-board__subtitle">
					{{
						t(
							'dossiq',
							'Drag cases between statuses, or use a case card\'s "Move to…" menu, to advance their workflow',
						)
					}}
				</p>
			</div>
			<NcButton type="tertiary" @click="$router.push({ name: 'Dashboard' })">
				{{ t('dossiq', 'Dashboard') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" class="workflow-board__loading" />

		<div v-else-if="error" class="workflow-board__error">
			<p>{{ error }}</p>
			<NcButton type="tertiary" @click="fetchData">
				{{ t('dossiq', 'Retry') }}
			</NcButton>
		</div>

		<div v-else-if="columns.length === 0" class="workflow-board__empty">
			<p>
				{{
					t(
						'dossiq',
						'No workflow statuses configured. Define status types in Settings to use the board.',
					)
				}}
			</p>
		</div>

		<template v-else>
			<div
				v-if="selection.caseIds.length > 0"
				class="workflow-board__bulk-bar">
				<span class="workflow-board__bulk-bar__count">
					{{
						n(
							'dossiq',
							'%n case selected',
							'%n cases selected',
							selection.caseIds.length,
						)
					}}
				</span>
				<div class="workflow-board__bulk-bar__actions">
					<NcButton @click="openBulkDialog">
						{{ t('dossiq', 'Change status…') }}
					</NcButton>
					<NcButton type="tertiary" @click="clearSelectionHandler">
						{{ t('dossiq', 'Cancel') }}
					</NcButton>
				</div>
			</div>

			<div
				class="workflow-board__columns"
				tabindex="0"
				role="region"
				:aria-label="t('dossiq', 'Workflow board columns')">
				<BoardColumn
					v-for="col in columns"
					:key="col.id"
					:statusType="col"
					:cases="casesByStatus[col.id] || []"
					:caseTypeMap="caseTypeMap"
					:allColumns="columns"
					:loading="false"
					:selectedCaseIds="selection.caseIds.map((id) => String(id))"
					:selectionColumnId="selection.columnId"
					@drop="onDrop"
					@move="onDrop"
					@clickCase="goToCase"
					@dragstart="onDragStart"
					@toggleSelect="onToggleSelect" />
			</div>
		</template>

		<BulkTransitionDialog
			v-if="showBulkDialog"
			:caseIds="selection.caseIds"
			@close="onBulkDialogClose"
			@completed="onBulkDialogCompleted" />
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import BulkTransitionDialog from '../../dialogs/BulkTransitionDialog.vue'
import BoardColumn from './BoardColumn.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import {
	clearSelection,
	emptySelection,
	toggleSelection,
} from '../../utils/bulkTransitionHelpers.js'

export default {
	name: 'WorkflowBoard',
	components: {
		NcButton,
		NcLoadingIcon,
		BoardColumn,
		BulkTransitionDialog,
	},

	data() {
		return {
			loading: true,
			error: null,
			/**
			 * Merged columns, sorted by order — one per distinct non-final
			 * status NAME. Each column's `id` is the status name (status types
			 * are per-case-type, so names recur across workflows and are merged).
			 */
			columns: [],
			/** Map of column name → array of open cases whose status has that name. */
			casesByStatus: {},
			/** Map of caseType id → display name. */
			caseTypeMap: {},
			/** Map of statusType id → the statusType object. */
			statusById: {},
			/** Map of `${caseType}::${statusName}` → statusType id (drop resolution). */
			statusIdByTypeAndName: {},
			/** Id of the case currently being dragged. */
			draggedCaseId: null,
			/**
			 * Column-scoped bulk-selection state: `{ columnId, caseIds }`.
			 * Selecting a case in a different column resets the selection
			 * (case-bulk-status-transition).
			 */
			selection: emptySelection(),
			/** Whether the bulk-transition dialog is open. */
			showBulkDialog: false,
			/**
			 * Live-updates handle for the or-collection-{register}-{schema}
			 * subscription on the `case` type (nc-vue liveUpdatesPlugin,
			 * default-on since beta.212). Managed by syncLiveSubscription().
			 * livePending marks an in-flight subscribe so a concurrent call
			 * doesn't double-subscribe; liveEpoch invalidates in-flight
			 * resolutions after a release (destroy). Events are refetch
			 * HINTS only: the board re-runs fetchData() (debounced via
			 * liveRefetchTimer), never patching from a payload.
			 */
			liveHandle: null,
			livePending: false,
			liveEpoch: 0,
			liveRefetchTimer: null,
			/** Whether a non-blanking background refresh is in flight. */
			liveRefreshing: false,
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},
	},

	watch: {
		/**
		 * Live event hint received on the store (or-collection event →
		 * liveUpdatesPlugin) — refresh the board through the existing
		 * fetch path, debounced, never patched from a payload.
		 *
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 */
		'objectStore.liveLastEventAt': function () {
			this.onLiveEvent()
		},
	},

	// @spec exclude Boot-order guard (register OR object types before fetch); no spec scenario.
	async mounted() {
		// Register the OR object types before fetching — this page may mount
		// (via direct navigation) before the app-boot initializeStores() has
		// resolved, otherwise fetchCollection() throws "type not registered".
		await initializeStores()
		await this.fetchData()
		this.syncLiveSubscription()
	},

	/**
	 * Release the live collection subscription on unmount.
	 *
	 * @spec openspec/specs/realtime-updates-ui/spec.md
	 */
	beforeUnmount() {
		clearTimeout(this.liveRefetchTimer)
		this.releaseLiveSubscription()
	},

	methods: {
		/**
		 * Subscribe to live updates for the `case` collection scope
		 * (or-collection-{register}-{schema} via notify_push, with
		 * visibility-gated polling fallback). Idempotent; guarded with a
		 * pending marker plus an epoch counter so a release during an
		 * in-flight subscribe drops the stale handle instead of leaking it.
		 *
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 * @return {Promise<void>}
		 */
		async syncLiveSubscription() {
			const store = this.objectStore
			if (
				typeof store.subscribe !== 'function'
				|| this.liveHandle
				|| this.livePending
			) {
				return
			}
			if (!store.objectTypeRegistry?.case) {
				return
			}
			this.livePending = true
			const epoch = this.liveEpoch
			try {
				const handle = await store.subscribe('case')
				if (this.liveEpoch !== epoch) {
					// Released while awaiting (destroy) — drop the stale
					// subscription instead of leaking it.
					store.unsubscribe(handle)
					return
				}
				this.liveHandle = handle
			} catch (err) {
				console.warn(
					'[WorkflowBoard] live subscription failed:',
					err?.message ?? err,
				)
			} finally {
				if (this.liveEpoch === epoch) {
					this.livePending = false
				}
			}
		},

		/**
		 * Release the live collection subscription, if any, and invalidate
		 * any in-flight subscribe (its resolution unsubscribes itself via
		 * the epoch check).
		 *
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 */
		releaseLiveSubscription() {
			this.liveEpoch += 1
			this.livePending = false
			if (
				this.liveHandle
				&& typeof this.objectStore.unsubscribe === 'function'
			) {
				this.objectStore.unsubscribe(this.liveHandle)
			}
			this.liveHandle = null
		},

		/**
		 * Debounced refetch on a live event hint. The board keeps its own
		 * column/grouping model, so re-run the existing fetchData() path
		 * instead of patching from a payload. Skipped while a fetch is
		 * already in flight.
		 *
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 */
		onLiveEvent() {
			if (!this.liveHandle) {
				return
			}
			clearTimeout(this.liveRefetchTimer)
			this.liveRefetchTimer = setTimeout(async () => {
				// Skip while an initial load / another refresh is running, or
				// while the user is mid-drag or mid-bulk-transition — the
				// post-save server event triggers a fresh hint anyway.
				if (
					this.loading
					|| this.liveRefreshing
					|| this.draggedCaseId
					|| this.showBulkDialog
				) {
					return
				}
				// Non-blanking: the template swaps the whole board for a
				// spinner on `loading`, so a background refresh must not
				// toggle it.
				this.liveRefreshing = true
				try {
					await this.fetchData({ background: true })
				} finally {
					this.liveRefreshing = false
				}
			}, 500)
		},

		/**
		 * Load status types, case types and open cases in parallel, then build
		 * the column model and the status → cases grouping.
		 *
		 * @param {object} [options] Fetch options
		 * @param {boolean} [options.background] When true (live-update refresh),
		 *   don't toggle `loading` — the template blanks the whole board for a
		 *   spinner on it, which would flash on every push event.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/dashboard/spec.md#requirement-req-dash-v1-006-workflow-board-view-v1
		 */
		async fetchData({ background = false } = {}) {
			if (!background) {
				this.loading = true
			}
			this.error = null
			try {
				const [statusTypes, caseTypes, cases] = await Promise.all([
					this.objectStore.fetchCollection('statusType', { _limit: 200 }),
					this.objectStore.fetchCollection('caseType', { _limit: 100 }),
					this.objectStore.fetchCollection('case', { _limit: 500 }),
				])

				const typeMap = {}
				for (const ct of caseTypes || []) {
					typeMap[ct.id] = ct.title || ct.name || ''
				}
				this.caseTypeMap = typeMap

				// Index every status type by id, and build a (caseType, name) →
				// id lookup so a drop can resolve the status id belonging to the
				// dropped case's own workflow.
				const byId = {}
				const byTypeAndName = {}
				for (const st of statusTypes || []) {
					byId[st.id] = st
					byTypeAndName[`${st.caseType}::${st.name || ''}`] = st.id
				}
				this.statusById = byId
				this.statusIdByTypeAndName = byTypeAndName

				const isFinal = (st) => st.isFinal === true || st.isFinal === 'true'

				// Merge all non-final status types that share a name into one
				// column. Status types are defined per case type, so the same
				// name (e.g. "Received") recurs across every workflow; without
				// this merge the board renders one near-empty column per type.
				const colByName = new Map()
				for (const st of statusTypes || []) {
					if (isFinal(st)) continue
					const name = st.name || ''
					const order = st.order ?? 999
					const existing = colByName.get(name)
					if (existing) {
						existing.order = Math.min(existing.order, order)
					} else {
						colByName.set(name, { id: name, name, order })
					}
				}
				this.columns = [...colByName.values()].sort(
					(a, b) => a.order - b.order || a.name.localeCompare(b.name),
				)

				// Group open cases into their merged column by resolving each
				// case's status id to its status-type name. Cases in a final
				// status (or with an unknown/foreign status id) are omitted.
				const grouped = {}
				for (const col of this.columns) {
					grouped[col.id] = []
				}
				for (const c of cases || []) {
					const st = byId[c.status]
					if (!st || isFinal(st)) continue
					if (grouped[st.name]) {
						grouped[st.name].push(c)
					}
				}
				this.casesByStatus = grouped
			} catch (err) {
				console.error('[dossiq] failed to load workflow board', err)
				this.error = this.t('dossiq', 'Failed to load the workflow board.')
			} finally {
				if (!background) {
					this.loading = false
				}
			}
		},

		/**
		 * Track the in-flight card id.
		 *
		 * @param {string} caseId The dragged case id
		 * @return {void}
		 */
		onDragStart(caseId) {
			this.draggedCaseId = caseId
		},

		/**
		 * Move a case card to a new status column. Optimistically moves the card
		 * in local state, persists via saveObject('case', …) (RBAC-enforced),
		 * and reverts + toasts on failure.
		 *
		 * @param {string} caseId The dropped case id
		 * @param {string} newColumn The target column's name (merged status name)
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/status-transition-engine/spec.md#requirement-transition-execution
		 */
		async onDrop(caseId, newColumn) {
			this.draggedCaseId = null

			// Locate the card and its current column.
			let fromColumn = null
			let caseObj = null
			for (const [colName, list] of Object.entries(this.casesByStatus)) {
				const found = list.find((c) => String(c.id) === String(caseId))
				if (found) {
					fromColumn = colName
					caseObj = found
					break
				}
			}

			if (!caseObj || fromColumn === null) return
			if (String(fromColumn) === String(newColumn)) return

			// Columns are merged by status name, but the case's status must be a
			// concrete status-type id from its OWN workflow. Resolve the id that
			// carries this column's name within the case's case type; refuse the
			// move when that workflow has no status by that name.
			const targetStatusId =
				this.statusIdByTypeAndName[`${caseObj.caseType}::${newColumn}`]
			if (!targetStatusId) {
				showError(
					this.t(
						'dossiq',
						"That status is not part of this case's workflow.",
					),
				)
				return
			}

			// Optimistic move.
			const fromList = this.casesByStatus[fromColumn].filter(
				(c) => String(c.id) !== String(caseId),
			)
			this.casesByStatus[fromColumn] = fromList
			const movedCase = { ...caseObj, status: targetStatusId }
			this.casesByStatus[newColumn] = [
				...(this.casesByStatus[newColumn] || []),
				movedCase,
			]

			try {
				const result = await this.objectStore.saveObject('case', movedCase)
				if (!result) {
					throw new Error('save returned no result')
				}
			} catch (err) {
				console.error('[dossiq] failed to advance case status', err)
				// Revert: pull from the new column, restore in the old one.
				const revertedNew = (this.casesByStatus[newColumn] || []).filter(
					(c) => String(c.id) !== String(caseId),
				)
				this.casesByStatus[newColumn] = revertedNew
				this.casesByStatus[fromColumn] = [
					...this.casesByStatus[fromColumn],
					caseObj,
				]
				showError(
					this.t(
						'dossiq',
						'Could not move the case. You may not have permission, or the change failed.',
					),
				)
			}
		},

		/**
		 * Navigate to a case detail view.
		 *
		 * @param {string} caseId The case id
		 * @return {void}
		 */
		goToCase(caseId) {
			this.$router
				.push({ name: 'CaseDetail', params: { id: caseId } })
				.catch(() => {})
		},

		/**
		 * Toggle a case's bulk-selection membership, scoped to its column.
		 * Selecting in a different column than the current selection resets
		 * the selection to only the newly selected case.
		 *
		 * @param {string} caseId The case id
		 * @param {string} columnId The column (merged status name) the case belongs to
		 * @return {void}
		 */
		onToggleSelect(caseId, columnId) {
			this.selection = toggleSelection(this.selection, caseId, columnId)
		},

		/**
		 * Clear the bulk selection.
		 *
		 * @return {void}
		 */
		clearSelectionHandler() {
			this.selection = clearSelection()
		},

		/**
		 * Open the bulk-transition dialog for the current selection.
		 *
		 * @return {void}
		 */
		openBulkDialog() {
			this.showBulkDialog = true
		},

		/**
		 * Close the bulk-transition dialog without clearing the selection.
		 *
		 * @return {void}
		 */
		onBulkDialogClose() {
			this.showBulkDialog = false
		},

		/**
		 * Handle a completed bulk transition: close the dialog, clear the
		 * selection, and refresh the board so moved cases land in their new
		 * columns.
		 *
		 * @return {Promise<void>}
		 */
		async onBulkDialogCompleted() {
			this.showBulkDialog = false
			this.selection = clearSelection()
			await this.fetchData()
		},
	},
}
</script>

<style scoped>
.workflow-board {
	padding: 16px;
}

.workflow-board__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 16px;
	gap: 16px;
}

.workflow-board__header h2 {
	margin: 0;
}

.workflow-board__subtitle {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.workflow-board__columns {
	display: flex;
	gap: 12px;
	overflow-x: auto;
	align-items: flex-start;
	padding-bottom: 8px;
}

.workflow-board__bulk-bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	margin-bottom: 12px;
}

.workflow-board__bulk-bar__actions {
	display: flex;
	gap: 8px;
}

.workflow-board__loading {
	margin: 48px auto;
}

.workflow-board__error,
.workflow-board__empty {
	text-align: center;
	padding: 48px 16px;
	color: var(--color-text-maxcontrast);
}

.workflow-board__error {
	color: var(--color-error);
}
</style>
