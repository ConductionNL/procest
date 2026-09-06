<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  DeelzaakList — full-page list of sub-cases (deelzaken) for a given
  parent case. Mounted under /cases/:id/deelzaken so the manifest can
  surface it as a tab/quick-link from the parent case detail.

  Wires the deelzaak Pinia store, renders a NL Design System table,
  exposes the "Create sub-case" action via DeelzaakCreateModal (own
  file under src/modals/ per ADR-004), and links each row to the
  DeelzaakDetail view.

  @spec openspec/changes/deelzaak-support/tasks.md#T05
  @spec openspec/changes/deelzaak-support/tasks.md#T09
-->
<template>
	<div class="deelzaak-list">
		<div class="deelzaak-list__header">
			<div class="deelzaak-list__title">
				<NcButton
					type="tertiary"
					:aria-label="t('dossiq', 'Back to parent case')"
					@click="goToParent">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
				</NcButton>
				<div>
					<h2>{{ t('dossiq', 'Sub-cases') }}</h2>
					<p v-if="parent" class="deelzaak-list__subtitle">
						<router-link :to="parentRoute">
							{{ parent.title || parent.identifier }}
						</router-link>
						<span class="deelzaak-list__rollup">
							{{ rollUpText }}
						</span>
					</p>
				</div>
			</div>
			<div class="deelzaak-list__actions">
				<NcButton
					v-if="canCreate"
					type="primary"
					:aria-label="t('dossiq', 'Create sub-case')"
					@click="showCreate = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('dossiq', 'Create sub-case') }}
				</NcButton>
				<NcButton
					v-if="parent"
					type="error"
					:aria-label="t('dossiq', 'Delete parent case')"
					@click="onDeleteParent">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('dossiq', 'Delete case') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="subCases.length === 0"
			:name="t('dossiq', 'No sub-cases yet')"
			:description="emptyDescription">
			<template #icon>
				<FolderMultipleOutline :size="48" />
			</template>
			<template #action>
				<NcButton v-if="canCreate" type="primary" @click="showCreate = true">
					{{ t('dossiq', 'Create first sub-case') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<div v-else class="viewTableContainer">
			<table class="viewTable">
				<thead>
					<tr>
						<th scope="col">{{ t('dossiq', 'Identifier') }}</th>
						<th scope="col">{{ t('dossiq', 'Title') }}</th>
						<th scope="col">{{ t('dossiq', 'Status') }}</th>
						<th scope="col">{{ t('dossiq', 'Assignee') }}</th>
						<th scope="col">{{ t('dossiq', 'Deadline') }}</th>
						<th scope="col">{{ t('dossiq', 'Completed') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="subCase in sortedSubCases"
						:key="subCase.id"
						class="viewTableRow"
						@click="openSubCase(subCase)">
						<td>{{ subCase.identifier || '—' }}</td>
						<td>{{ subCase.title || '—' }}</td>
						<td>
							<span
								class="status-badge"
								:class="getStatusClass(subCase)">
								{{ getStatusName(subCase) }}
							</span>
						</td>
						<td>{{ subCase.assignee || '—' }}</td>
						<td>{{ formatDate(subCase.deadline) }}</td>
						<td>
							{{ subCase.endDate ? formatDate(subCase.endDate) : '—' }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<DeelzaakCreateModal
			v-if="showCreate && parentCaseId"
			:parentCase="parentCaseId"
			:parentCaseType="parentCaseType"
			@created="onSubCaseCreated"
			@close="showCreate = false" />

		<DeelzaakDeleteWarningModal
			v-if="showDeleteWarning && parentCaseId"
			:parentCaseId="parentCaseId"
			:subCaseCount="totalCount"
			@deleted="onParentDeleted"
			@close="showDeleteWarning = false" />

		<CnConfirmDialog
			v-if="showDeleteConfirm"
			ref="deleteConfirmDialog"
			:dialogTitle="t('dossiq', 'Delete case')"
			:message="t('dossiq', 'Are you sure you want to delete this case?')"
			variant="error"
			:confirmLabel="t('dossiq', 'Delete')"
			@confirm="onConfirmDeleteParent"
			@close="showDeleteConfirm = false" />
	</div>
</template>

<script>
import { CnConfirmDialog } from '@conduction/nextcloud-vue'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FolderMultipleOutline from 'vue-material-design-icons/FolderMultipleOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import DeelzaakCreateModal from '../../modals/DeelzaakCreateModal.vue'
import DeelzaakDeleteWarningModal from '../../modals/DeelzaakDeleteWarningModal.vue'
import { useDeelzaakStore } from '../../store/modules/deelzaak.js'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { formatDate } from '../../utils/caseHelpers.js'
import { requiresOrphanWarning } from '../../utils/deelzaakHelpers.js'

export default {
	name: 'DeelzaakList',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		ArrowLeft,
		Delete,
		FolderMultipleOutline,
		Plus,
		DeelzaakCreateModal,
		DeelzaakDeleteWarningModal,
		CnConfirmDialog,
	},

	props: {
		/** Optional override for the parent case UUID (otherwise read from $route.params.id). */
		caseId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			parent: null,
			parentCaseType: null,
			statusTypeCache: {},
			loading: true,
			showCreate: false,
			showDeleteWarning: false,
			showDeleteConfirm: false,
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		deelzaakStore() {
			return useDeelzaakStore()
		},

		parentCaseId() {
			return this.caseId || this.$route?.params?.id || null
		},

		subCases() {
			return this.deelzaakStore.getSubCases || []
		},

		sortedSubCases() {
			return [...this.subCases].sort((a, b) => {
				// Open before closed; within each group sort by deadline asc.
				const aOpen = !a.endDate
				const bOpen = !b.endDate
				if (aOpen !== bOpen) {
					return aOpen ? -1 : 1
				}
				const aDeadline = a.deadline
					? new Date(a.deadline).getTime()
					: Number.POSITIVE_INFINITY
				const bDeadline = b.deadline
					? new Date(b.deadline).getTime()
					: Number.POSITIVE_INFINITY
				return aDeadline - bDeadline
			})
		},

		completedCount() {
			return this.subCases.filter((sc) => sc.endDate).length
		},

		totalCount() {
			return this.subCases.length
		},

		/**
		 * Progress roll-up summary across the parent case's sub-cases.
		 *
		 * @return {string} The translated roll-up text.
		 *
		 * @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-case-progress-roll-up-on-parent-case
		 */
		rollUpText() {
			return t('dossiq', '({completed}/{total} completed)', {
				completed: this.completedCount,
				total: this.totalCount,
			})
		},

		canCreate() {
			if (!this.parent) {
				return false
			}
			if (this.parent.endDate) {
				return false
			}
			if (this.parent.parentCase) {
				// Already a sub-case — no grandchildren.
				return false
			}
			const allowed = this.parentCaseType?.subCaseTypes || []
			return Array.isArray(allowed) && allowed.length > 0
		},

		/**
		 * Empty-state copy for the sub-cases section.
		 *
		 * @return {string} The translated description.
		 *
		 * @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-cases-section-on-parent-case-detail
		 */
		emptyDescription() {
			if (this.canCreate) {
				return t(
					'dossiq',
					'This case has no sub-cases yet. Use the button above to create the first one.',
				)
			}
			if (this.parent?.parentCase) {
				return t('dossiq', 'Sub-cases cannot themselves have sub-cases.')
			}
			if (this.parent?.endDate) {
				return t(
					'dossiq',
					'This case is closed; sub-cases can no longer be added.',
				)
			}
			return t('dossiq', 'The parent case type does not allow any sub-cases.')
		},

		parentRoute() {
			return this.parent
				? { name: 'CaseDetail', params: { id: this.parent.id } }
				: { name: 'Cases' }
		},
	},

	watch: {
		parentCaseId: {
			immediate: false,
			handler() {
				this.reload()
			},
		},
	},

	async mounted() {
		// Object types are registered by App.vue's async created(), which
		// does not block child mounting — on a deep link this tab can
		// mount first, so wait for the registry before fetching.
		await initializeStores()
		await this.reload()
	},

	methods: {
		formatDate,
		async reload() {
			if (!this.parentCaseId) {
				this.loading = false
				return
			}
			this.loading = true
			try {
				const results = await Promise.all([
					this.objectStore
						.fetchObject('case', this.parentCaseId)
						.catch(() => null),
					this.deelzaakStore
						.fetchSubCases(this.parentCaseId)
						.catch(() => []),
					this.objectStore
						.fetchCollection('statusType', { _limit: 200 })
						.catch(() => []),
				])
				const parent = results[0]
				const statusTypes = results[2]
				this.parent = parent
				if (parent?.caseType) {
					this.parentCaseType = await this.objectStore
						.fetchObject('caseType', parent.caseType)
						.catch(() => null)
				}
				const cache = {}
				for (const st of statusTypes || []) {
					cache[st.id] = st
				}
				this.statusTypeCache = cache
			} catch (err) {
				console.error('[DeelzaakList] reload failed', err)
			} finally {
				this.loading = false
			}
		},

		getStatusName(subCase) {
			if (!subCase.status) {
				return '—'
			}
			return this.statusTypeCache[subCase.status]?.name || '—'
		},

		getStatusClass(subCase) {
			if (!subCase.status) {
				return ''
			}
			const st = this.statusTypeCache[subCase.status]
			if (st?.isFinal === true || st?.isFinal === 'true') {
				return 'status-badge--final'
			}
			return 'status-badge--active'
		},

		openSubCase(subCase) {
			this.$router.push({
				name: 'DeelzaakDetail',
				params: { parentId: this.parentCaseId, id: subCase.id },
			})
		},

		goToParent() {
			this.$router.push(this.parentRoute)
		},

		async onSubCaseCreated(newId) {
			this.showCreate = false
			await this.reload()
			if (newId) {
				this.$router.push({
					name: 'DeelzaakDetail',
					params: { parentId: this.parentCaseId, id: newId },
				})
			}
		},

		/**
		 * Delete the parent case. When it still has sub-cases, open the
		 * orphan-warning modal (which unlinks the children, then deletes);
		 * otherwise take the standard confirm-and-delete path (REQ-DZS-006).
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T11
		 */
		onDeleteParent() {
			if (requiresOrphanWarning(this.totalCount)) {
				this.showDeleteWarning = true
				return
			}
			this.showDeleteConfirm = true
		},

		/**
		 * Confirm-handler for the CnConfirmDialog opened by onDeleteParent().
		 * Runs the actual delete and reports the outcome back to the dialog.
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T11
		 */
		async onConfirmDeleteParent() {
			try {
				await this.objectStore.deleteObject('case', this.parentCaseId)
				this.showDeleteConfirm = false
				this.onParentDeleted(this.parentCaseId)
			} catch (err) {
				console.error('[DeelzaakList] parent delete failed', err)
				this.$refs.deleteConfirmDialog.setResult({
					error: t(
						'dossiq',
						'The case could not be deleted. Please try again.',
					),
				})
			}
		},

		/**
		 * @param {string} deletedId Identifier of the deleted id.
		 * @spec openspec/changes/deelzaak-support/tasks.md#T11
		 */
		onParentDeleted(deletedId) {
			this.showDeleteWarning = false
			this.$router.push({ name: 'Cases' })
		},
	},
}
</script>

<style scoped>
.deelzaak-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
}

.deelzaak-list__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
	flex-wrap: wrap;
}

.deelzaak-list__title {
	display: flex;
	gap: 12px;
	align-items: flex-start;
}

.deelzaak-list__title h2 {
	margin: 0;
}

.deelzaak-list__subtitle {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.95em;
}

.deelzaak-list__subtitle a {
	color: var(--color-primary-element);
	text-decoration: none;
}

.deelzaak-list__subtitle a:hover {
	text-decoration: underline;
}

.deelzaak-list__rollup {
	margin-left: 8px;
	color: var(--color-text-lighter);
}

.viewTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 2px 4px var(--color-box-shadow);
	border: 1px solid var(--color-border);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
	background-color: var(--color-main-background);
}

.viewTable th,
.viewTable td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.viewTable th {
	background-color: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.viewTableRow {
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.viewTableRow:hover {
	background: var(--color-background-hover);
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
}

.status-badge--active {
	background: var(--color-primary-light);
	color: var(--color-primary-text);
}

.status-badge--final {
	background: var(--color-success);
	color: white;
}

@media (prefers-reduced-motion: reduce) {
	.viewTableRow {
		transition: none;
	}
}
</style>
