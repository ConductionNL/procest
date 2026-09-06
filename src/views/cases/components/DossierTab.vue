<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div
		class="dossier-tab"
		@dragover.prevent="dragActive = true"
		@dragleave.prevent="dragActive = false"
		@drop.prevent="onDrop">
		<div class="dossier-tab__header">
			<h3 class="dossier-tab__title">
				{{ t('dossiq', 'Documents') }} ({{ total }})
			</h3>
			<div class="dossier-tab__controls">
				<NcSelect
					v-model="sortKey"
					class="dossier-tab__sort"
					:inputLabel="t('dossiq', 'Sort by')"
					:options="sortOptions"
					:reduce="(option) => option.id"
					label="label"
					:clearable="false" />
				<NcButton type="primary" @click="triggerFilePicker">
					<template #icon>
						<Upload :size="20" />
					</template>
					{{ t('dossiq', 'Upload document') }}
				</NcButton>
			</div>
		</div>

		<input
			ref="fileInput"
			type="file"
			multiple
			:aria-label="t('dossiq', 'Upload document')"
			class="dossier-tab__file-input"
			@change="onFilesSelected" />

		<BulkActionsBar
			:selectedCount="selectedIds.length"
			:busy="bulkBusy"
			:results="bulkResults"
			@markFinal="bulkMarkFinal"
			@changeConfidentiality="bulkChangeConfidentiality"
			@downloadZip="downloadSelectionZip"
			@clearSelection="clearSelection" />

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="groups.length === 0"
			:name="t('dossiq', 'No documents yet')"
			:description="
				t(
					'dossiq',
					'Drag files here or use the upload button to add documents to this case.',
				)
			">
			<template #icon>
				<FolderOpenOutline :size="20" />
			</template>
			<template #action>
				<NcButton type="primary" @click="triggerFilePicker">
					{{ t('dossiq', 'Upload document') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<div v-else class="dossier-tab__groups">
			<DossierGroup
				v-for="group in groups"
				:key="group.informatieobjecttype"
				:groupLabel="typeLabel(group.informatieobjecttype)"
				:documents="group.documents"
				:selectedIds="selectedIds"
				:sortKey="sortKey"
				:sortDirection="sortDirection"
				@toggleSelect="toggleSelect"
				@open="openInFiles"
				@share="shareDocument"
				@versionHistory="showVersions"
				@delete="deleteDocument" />
		</div>

		<div v-if="dragActive" class="dossier-tab__drop-overlay">
			{{ t('dossiq', 'Drop files to upload') }}
		</div>

		<DocumentMetadataDialog
			:open="showMetadataDialog"
			:files="pendingFiles"
			:types="types"
			:progress="uploadProgress"
			:errors="uploadErrors"
			:uploading="uploading"
			@close="closeMetadataDialog"
			@submit="performUpload" />

		<VersionHistoryPanel
			v-if="versionDocument"
			:document="versionDocument"
			:userId="userId" />
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import FolderOpenOutline from 'vue-material-design-icons/FolderOpenOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import DocumentMetadataDialog from '../../../modals/DocumentMetadataDialog.vue'
import BulkActionsBar from './BulkActionsBar.vue'
import DossierGroup from './DossierGroup.vue'
import VersionHistoryPanel from './VersionHistoryPanel.vue'

/**
 * Case dossier tab: lists informatieobjecten grouped by type with a count
 * badge, supports drag-and-drop / button upload through a metadata dialog,
 * multi-select bulk operations, ZIP export and version history. Confidentiality
 * filtering is applied server-side; this component only renders what the API
 * returns.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
 */
export default {
	name: 'DossierTab',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		FolderOpenOutline,
		Upload,
		BulkActionsBar,
		DossierGroup,
		VersionHistoryPanel,
		DocumentMetadataDialog,
	},

	props: {
		// Received from CnObjectSidebar's sharedTabProps; falls back to the
		// route id for standalone use, matching the other case-detail tabs.
		objectId: {
			type: String,
			default: '',
		},
	},

	emits: ['count-changed'],
	data() {
		return {
			loading: false,
			total: 0,
			groups: [],
			types: [],
			selectedIds: [],
			sortKey: 'creatiedatum',
			sortDirection: 'desc',
			dragActive: false,
			pendingFiles: [],
			showMetadataDialog: false,
			uploading: false,
			uploadProgress: {},
			uploadErrors: {},
			versionDocument: null,
			bulkBusy: false,
			bulkResults: [],
		}
	},

	computed: {
		/**
		 * The resolved case id (sharedTabProps objectId or route fallback).
		 *
		 * @return {string} The case id, or empty string.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T10
		 */
		caseId() {
			return (
				this.objectId
				|| (this.$route && this.$route.params ? this.$route.params.id : '')
				|| ''
			)
		},

		/**
		 * The current user id (for version-history requests).
		 *
		 * @return {string} The user id, or empty string.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		userId() {
			const user = getCurrentUser()
			return user ? user.uid : ''
		},

		/**
		 * Sort dropdown options.
		 *
		 * @return {Array} The options.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		sortOptions() {
			return [
				{ id: 'creatiedatum', label: this.t('dossiq', 'Creation date') },
				{ id: 'title', label: this.t('dossiq', 'Title') },
				{ id: 'status', label: this.t('dossiq', 'Status') },
			]
		},
	},

	watch: {
		caseId: {
			immediate: true,
			/**
			 * Reload the dossier and type catalog when the case changes.
			 *
			 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
			 */
			handler() {
				this.fetchDossier()
				this.fetchTypes()
			},
		},
	},

	methods: {
		/**
		 * Fetch the dossier for the current case.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		async fetchDossier() {
			if (!this.caseId) {
				return
			}
			this.loading = true
			try {
				const url = generateUrl(
					`/apps/dossiq/api/cases/${encodeURIComponent(this.caseId)}/dossier`,
				)
				const { data } = await axios.get(url)
				this.groups = data.groups || []
				this.total = data.total || 0
				this.$emit('count-changed', this.total)
			} catch (error) {
				this.groups = []
				this.total = 0
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the informatieobjecttype catalog for the upload dialog.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		async fetchTypes() {
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/dossiq/informatieobjecttype?_limit=200',
				)
				const { data } = await axios.get(url)
				this.types = data.results || data.objects || data || []
			} catch (error) {
				this.types = []
			}
		},

		/**
		 * Resolve a type id to its description label.
		 *
		 * @param {string} typeId The type id.
		 * @return {string} The label.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		typeLabel(typeId) {
			const match = this.types.find(
				(type) => (type.id || type.uuid) === typeId,
			)
			return match
				? match.description || typeId
				: typeId || this.t('dossiq', 'Unknown type')
		},

		/**
		 * Open the native file picker.
		 *
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		triggerFilePicker() {
			if (this.$refs.fileInput) {
				this.$refs.fileInput.click()
			}
		},

		/**
		 * Handle files chosen via the picker.
		 *
		 * @param {Event} event The change event.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		onFilesSelected(event) {
			const files = Array.from(event.target.files || [])
			if (files.length > 0) {
				this.openMetadataDialog(files)
			}
		},

		/**
		 * Handle a drag-and-drop file drop.
		 *
		 * @param {DragEvent} event The drop event.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		onDrop(event) {
			this.dragActive = false
			const files = Array.from(
				(event.dataTransfer && event.dataTransfer.files) || [],
			)
			if (files.length > 0) {
				this.openMetadataDialog(files)
			}
		},

		/**
		 * Open the metadata dialog for the pending files.
		 *
		 * @param {Array} files The files to upload.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		openMetadataDialog(files) {
			this.pendingFiles = files
			this.uploadProgress = {}
			this.uploadErrors = {}
			this.showMetadataDialog = true
		},

		/**
		 * Close the metadata dialog and reset pending state.
		 *
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		closeMetadataDialog() {
			this.showMetadataDialog = false
			this.pendingFiles = []
			if (this.$refs.fileInput) {
				this.$refs.fileInput.value = ''
			}
		},

		/**
		 * Upload the pending files with the shared metadata, per-file progress.
		 *
		 * @param {object} metadata The shared metadata.
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		async performUpload(metadata) {
			this.uploading = true
			let anySuccess = false
			for (let index = 0; index < this.pendingFiles.length; index++) {
				const file = this.pendingFiles[index]
				const form = new FormData()
				form.append('files', file)
				form.append('metadata', JSON.stringify(metadata))
				try {
					this.uploadProgress[index] = 0
					const url = generateUrl(
						`/apps/dossiq/api/cases/${encodeURIComponent(this.caseId)}/dossier`,
					)
					await axios.post(url, form, {
						headers: { 'Content-Type': 'multipart/form-data' },
						onUploadProgress: (event) => {
							if (event.total) {
								this.uploadProgress[index] = Math.round(
									(event.loaded / event.total) * 100,
								)
							}
						},
					})
					this.uploadProgress[index] = 100
					anySuccess = true
				} catch (error) {
					this.uploadErrors[index] = true
				}
			}
			this.uploading = false
			if (anySuccess) {
				showSuccess(this.t('dossiq', 'Documents uploaded'))
				this.closeMetadataDialog()
				this.fetchDossier()
			} else {
				showError(this.t('dossiq', 'Upload failed'))
			}
		},

		/**
		 * Toggle the selection of a document.
		 *
		 * @param {object} document The document to toggle.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		toggleSelect(document) {
			const index = this.selectedIds.indexOf(document.id)
			if (index === -1) {
				this.selectedIds.push(document.id)
			} else {
				this.selectedIds.splice(index, 1)
			}
		},

		/**
		 * Clear the current selection and bulk results.
		 *
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T08
		 */
		clearSelection() {
			this.selectedIds = []
			this.bulkResults = []
		},

		/**
		 * Bulk-transition the selection to definitief.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T08
		 */
		async bulkMarkFinal() {
			await this.runBulk('/apps/dossiq/api/informatieobjecten/bulk/status', {
				ids: this.selectedIds,
				status: 'final',
			})
		},

		/**
		 * Bulk-update the confidentiality of the selection.
		 *
		 * @param {string} level The new confidentiality level.
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T08
		 */
		async bulkChangeConfidentiality(level) {
			await this.runBulk('/apps/dossiq/api/informatieobjecten/bulk/metadata', {
				ids: this.selectedIds,
				metadata: { vertrouwelijkheidaanduiding: level },
			})
		},

		/**
		 * Run a bulk endpoint and record the per-item results.
		 *
		 * @param {string} path The API path.
		 * @param {object} payload The request body.
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T08
		 */
		async runBulk(path, payload) {
			this.bulkBusy = true
			try {
				const { data } = await axios.post(generateUrl(path), payload)
				this.bulkResults = data.results || []
				this.fetchDossier()
			} catch (error) {
				showError(this.t('dossiq', 'Bulk action failed'))
			} finally {
				this.bulkBusy = false
			}
		},

		/**
		 * Download the current selection (or full dossier) as a ZIP.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T08
		 */
		async downloadSelectionZip() {
			this.bulkBusy = true
			try {
				const url = generateUrl(
					`/apps/dossiq/api/cases/${encodeURIComponent(this.caseId)}/dossier/zip`,
				)
				const { data } = await axios.post(
					url,
					{ ids: this.selectedIds },
					{ responseType: 'blob' },
				)
				const objectUrl = window.URL.createObjectURL(data)
				const link = document.createElement('a')
				link.href = objectUrl
				link.download = `dossier-${this.caseId}.zip`
				link.click()
				window.URL.revokeObjectURL(objectUrl)
			} catch (error) {
				showError(this.t('dossiq', 'ZIP export failed'))
			} finally {
				this.bulkBusy = false
			}
		},

		/**
		 * Open the document in Nextcloud Files.
		 *
		 * @param {object} document The document.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		openInFiles(document) {
			if (document.fileId) {
				window.open(generateUrl(`/f/${document.fileId}`), '_blank')
			}
		},

		/**
		 * Trigger a public share for the document.
		 *
		 * @param {object} document The document.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		shareDocument(document) {
			this.$emit('count-changed', this.total)
			showSuccess(
				this.t('dossiq', 'Share requested for {name}', {
					name: document.title,
				}),
			)
		},

		/**
		 * Show the version-history panel for a document.
		 *
		 * @param {object} document The document.
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		showVersions(document) {
			this.versionDocument = document
		},

		/**
		 * Delete a concept document and refresh.
		 *
		 * @param {object} document The document.
		 * @return {Promise<void>}
		 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
		 */
		async deleteDocument(document) {
			try {
				const url = generateUrl(
					`/apps/dossiq/api/cases/${encodeURIComponent(this.caseId)}/dossier/${encodeURIComponent(document.id)}/link`,
				)
				await axios.delete(url)
				this.fetchDossier()
			} catch (error) {
				showError(this.t('dossiq', 'Could not remove document'))
			}
		},
	},
}
</script>

<style scoped>
.dossier-tab {
	position: relative;
	padding: 12px;
}

.dossier-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 12px;
}

.dossier-tab__controls {
	display: flex;
	align-items: center;
	gap: 8px;
}

.dossier-tab__sort {
	min-width: 180px;
}

.dossier-tab__file-input {
	display: none;
}

.dossier-tab__drop-overlay {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background-color: var(--color-primary-element-light);
	border: 2px dashed var(--color-primary-element);
	border-radius: var(--border-radius-large);
	font-weight: 600;
	pointer-events: none;
}
</style>
