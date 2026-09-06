<!--
  Dossiq VTH Checklist Admin Tab
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
  @spec openspec/changes/vth-module/tasks.md#task-5
-->
<template>
	<div class="checklists-tab">
		<div class="checklists-tab__header">
			<h3>{{ t('dossiq', 'VTH Inspection Checklists') }}</h3>
			<NcButton type="primary" @click="openEditor(null)">
				{{ t('dossiq', 'New checklist') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-if="!loading && checklists.length === 0 && !editing"
			:name="t('dossiq', 'No checklists')"
			:description="
				t('dossiq', 'Create an inspection checklist to get started.')
			">
			<template #icon>
				<NcIconSvgWrapper :svg="clipboardIcon" />
			</template>
		</NcEmptyContent>

		<div v-if="!loading && !editing" class="checklists-tab__list">
			<div
				v-for="checklist in checklists"
				:key="checklist.id"
				class="checklists-tab__item">
				<div class="checklists-tab__item-info">
					<strong>{{ checklist.name }}</strong>
					<span
						class="checklists-tab__badge"
						:class="
							checklist.active
								? 'checklists-tab__badge--active'
								: 'checklists-tab__badge--inactive'
						">
						{{
							checklist.active
								? t('dossiq', 'Active')
								: t('dossiq', 'Inactive')
						}}
					</span>
					<span class="checklists-tab__meta"
						>v{{ checklist.version || 1 }} &bull;
						{{ (checklist.items || []).length }}
						{{ t('dossiq', 'items') }}</span
					>
				</div>
				<div class="checklists-tab__item-actions">
					<NcButton @click="openEditor(checklist)">
						{{ t('dossiq', 'Edit') }}
					</NcButton>
					<NcButton type="error" @click="confirmDelete(checklist)">
						{{ t('dossiq', 'Delete') }}
					</NcButton>
				</div>
			</div>
		</div>

		<InspectionChecklistEditor
			v-if="editing"
			:checklist="editingChecklist"
			:saving="saving"
			@save="saveChecklist"
			@cancel="closeEditor" />

		<CnConfirmDialog
			v-if="showDeleteConfirm"
			ref="deleteConfirmDialog"
			:dialogTitle="t('dossiq', 'Delete checklist')"
			:message="
				t('dossiq', 'Delete checklist “{name}”?', {
					name: pendingDeleteChecklist && pendingDeleteChecklist.name,
				})
			"
			variant="error"
			:confirmLabel="t('dossiq', 'Delete')"
			@confirm="onConfirmDelete"
			@close="showDeleteConfirm = false" />
	</div>
</template>

<script>
import { CnConfirmDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcIconSvgWrapper,
	NcLoadingIcon,
} from '@nextcloud/vue'
import InspectionChecklistEditor from '../../../components/InspectionChecklistEditor.vue'

/**
 * Checklist CRUD for the admin surface goes through dossiq's OWN controller.
 *
 * `InspectionChecklistController` already serves exactly the four verbs this tab
 * uses — `GET/POST /api/vth/checklists` and `PUT/DELETE /api/vth/checklists/{id}`
 * (appinfo/routes.php) — and every one of them is guarded with
 * `#[AuthorizedAdminSetting(settings: AdminSettings::class)]`, which is the right
 * posture for a control that lives in the admin settings page. It also routes
 * through `InspectionChecklistService`, which owns checklist versioning and
 * `caseTypeRef` filtering.
 *
 * The old URL, `/apps/dossiq/api/objects/inspectionChecklist`, matched NO route:
 * dossiq's auto-exposed `/api/objects/<register>/<schema>` endpoints were deleted
 * (appinfo/routes.php, "only engine routes remain"). Nextcloud answers an
 * unmatched app URL with its own HTML page under **HTTP 200**, so axios never
 * threw and the body was a 45,031-character string — see `asChecklistArray`.
 *
 * Addressing OpenRegister's generic object route instead would also have worked
 * mechanically, and was this fix's first attempt. It is the wrong choice: it
 * bypasses dossiq's admin-setting authorization, bypasses the service that owns
 * versioning, and adds a second write path for a resource that already has one.
 */
const COLLECTION_URL = '/apps/dossiq/api/vth/checklists'

/**
 * Coerce an API response into the checklist array the template iterates.
 *
 * This guard is load-bearing, not defensive padding. `v-for` over a STRING
 * iterates one item per character, so assigning an unvalidated response body
 * straight to `checklists` rendered one table row — two NcButtons — per
 * character of whatever came back. A 45,031-byte HTML error page produced
 * 45,031 rows, 90,122 buttons and a 50 MB DOM on which Playwright's
 * accessible-name computation never terminated (procest#784).
 *
 * @param {unknown} body Parsed response body, of any shape.
 * @return {Array} The checklist rows, or an empty array when the body is not
 *                 a recognised collection shape.
 */
function asChecklistArray(body) {
	if (Array.isArray(body)) {
		return body
	}
	if (body !== null && typeof body === 'object' && Array.isArray(body.results)) {
		return body.results
	}
	return []
}

export default {
	name: 'ChecklistsTab',

	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcIconSvgWrapper,
		InspectionChecklistEditor,
		CnConfirmDialog,
	},

	data() {
		return {
			checklists: [],
			loading: false,
			editing: false,
			saving: false,
			editingChecklist: null,
			showDeleteConfirm: false,
			pendingDeleteChecklist: null,
			clipboardIcon:
				'<svg viewBox="0 0 24 24"><path fill="currentColor" d="M19,3H14.82C14.25,1.44 12.53,0.64 11,1.2C10.14,1.5 9.5,2.16 9.18,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M12,3A1,1 0 0,1 13,4A1,1 0 0,1 12,5A1,1 0 0,1 11,4A1,1 0 0,1 12,3M7,7H17V5H19V19H5V5H7V7Z" /></svg>',
		}
	},

	mounted() {
		this.loadChecklists()
	},

	methods: {
		/**
		 * Load the checklist collection for the admin list.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/inspection-checklists/spec.md#inspection-checklist-admin-ui
		 */
		async loadChecklists() {
			this.loading = true
			try {
				const response = await axios.get(generateUrl(COLLECTION_URL))
				this.checklists = asChecklistArray(response.data)
			} catch (e) {
				showError(t('dossiq', 'Failed to load checklists'))
			} finally {
				this.loading = false
			}
		},

		openEditor(checklist) {
			this.editingChecklist = checklist
				? { ...checklist }
				: {
						name: '',
						version: 1,
						caseTypeRef: '',
						items: [],
						active: false,
						validFrom: new Date().toISOString().split('T')[0],
					}
			this.editing = true
		},

		/**
		 * Close the checklist editor and drop the working copy.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/inspection-checklists/spec.md#inspection-checklist-admin-ui
		 */
		closeEditor() {
			this.editing = false
			this.editingChecklist = null
		},

		/**
		 * Create or update a checklist, then reload the list.
		 *
		 * @param {object} checklist The checklist being saved; an `id` selects
		 *                           update (PUT) over create (POST).
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/inspection-checklists/spec.md#inspection-checklist-admin-ui
		 */
		async saveChecklist(checklist) {
			this.saving = true
			try {
				const url = checklist.id
					? generateUrl(
							COLLECTION_URL + '/' + encodeURIComponent(checklist.id),
						)
					: generateUrl(COLLECTION_URL)
				const method = checklist.id ? 'put' : 'post'
				await axios[method](url, checklist)
				showSuccess(t('dossiq', 'Checklist saved'))
				this.closeEditor()
				await this.loadChecklists()
			} catch (e) {
				showError(t('dossiq', 'Failed to save checklist'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Open the delete-confirmation dialog for a checklist.
		 *
		 * @param {object} checklist Checklist row.
		 * @return {void}
		 *
		 * @spec openspec/changes/vth-module/tasks.md#task-5
		 */
		confirmDelete(checklist) {
			this.pendingDeleteChecklist = checklist
			this.showDeleteConfirm = true
		},

		/**
		 * Confirm-handler for the CnConfirmDialog opened by confirmDelete().
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/vth-module/tasks.md#task-5
		 */
		async onConfirmDelete() {
			const checklist = this.pendingDeleteChecklist
			try {
				const url = generateUrl(
					COLLECTION_URL + '/' + encodeURIComponent(checklist.id),
				)
				await axios.delete(url)
				showSuccess(t('dossiq', 'Checklist deleted'))
				this.showDeleteConfirm = false
				await this.loadChecklists()
			} catch (e) {
				showError(t('dossiq', 'Failed to delete checklist'))
				this.$refs.deleteConfirmDialog.setResult({
					error: t('dossiq', 'Failed to delete checklist'),
				})
			}
		},
	},
}
</script>

<style scoped>
.checklists-tab__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.checklists-tab__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
}

.checklists-tab__item-info {
	display: flex;
	align-items: center;
	gap: 8px;
}

.checklists-tab__item-actions {
	display: flex;
	gap: 8px;
}

.checklists-tab__badge {
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.8em;
}

.checklists-tab__badge--active {
	background: var(--color-success);
	color: white;
}

.checklists-tab__badge--inactive {
	background: var(--color-warning);
	color: white;
}

.checklists-tab__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
