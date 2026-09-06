<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sidebar tab: documents linked to a parent case.

 Lists caseDocument relation records where caseDocument.case === the
 parent case id, with create/edit/delete via the schema-driven
 CnFormDialog. Receives `objectId` from CnObjectSidebar's
 sharedTabProps, with a route fallback for standalone use.
-->
<template>
	<div class="case-tab case-tab--documents">
		<div class="case-tab__header">
			<h3 class="case-tab__title">
				{{ t('dossiq', 'Documents') }}
				<span v-if="documents.length > 0" class="case-tab__count"
					>({{ documents.length }})</span
				>
			</h3>
			<NcButton type="primary" @click="openCreate">
				{{ t('dossiq', 'Add document') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="documents.length === 0"
			:title="t('dossiq', 'No documents yet')"
			:description="
				t('dossiq', 'Register a document to link it to this case.')
			" />

		<ul v-else class="case-tab__list">
			<li
				v-for="doc in documents"
				:key="doc.id"
				class="case-tab__item"
				role="button"
				tabindex="0"
				@click="openEdit(doc)"
				@keydown.enter="openEdit(doc)"
				@keydown.space.prevent="openEdit(doc)">
				<div class="case-tab__row">
					<strong class="case-tab__item-title">{{
						doc.title || '—'
					}}</strong>
					<NcActions :inline="0" @click.stop>
						<NcActionButton @click="openEdit(doc)">
							{{ t('dossiq', 'Edit') }}
						</NcActionButton>
						<NcActionButton @click="openDelete(doc)">
							{{ t('dossiq', 'Delete') }}
						</NcActionButton>
					</NcActions>
				</div>
				<div class="case-tab__meta">
					<span v-if="doc.registrationDate">
						{{
							t('dossiq', 'Registered: {date}', {
								date: formatDate(doc.registrationDate),
							})
						}}
					</span>
					<span v-if="doc.description" class="case-tab__description">{{
						doc.description
					}}</span>
				</div>
			</li>
		</ul>

		<CnFormDialog
			v-if="showFormDialog"
			ref="formDialog"
			:fields="formFields"
			:item="editItem"
			:dialogTitle="
				editItem ? t('dossiq', 'Edit document') : t('dossiq', 'Add document')
			"
			@confirm="onFormConfirm"
			@close="showFormDialog = false" />

		<CnDeleteDialog
			v-if="deleteItem"
			ref="deleteDialog"
			:item="deleteItem"
			@confirm="onDeleteConfirm"
			@close="deleteItem = null" />
	</div>
</template>

<script>
import { CnDeleteDialog, CnFormDialog } from '@conduction/nextcloud-vue'
import {
	NcActionButton,
	NcActions,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { formatDate } from '../../utils/caseHelpers.js'

export default {
	name: 'CaseDocumentsTab',
	components: {
		NcActionButton,
		NcActions,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		CnDeleteDialog,
		CnFormDialog,
	},

	props: {
		/** Case UUID — passed by CnObjectSidebar as a shared tab prop. */
		objectId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			documents: [],
			loading: true,
			showFormDialog: false,
			editItem: null,
			deleteItem: null,
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		resolvedCaseId() {
			return this.objectId || this.$route?.params?.id || null
		},

		/**
		 * Field descriptors for the case-document create/edit form.
		 *
		 * @return {Array<object>} The form field descriptors.
		 *
		 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-005-upload-must-present-a-metadata-dialog-and-require-informatieobjecttype-and-vertrouwelijkheidaanduiding
		 */
		formFields() {
			return [
				{ key: 'title', label: t('dossiq', 'Title'), required: true },
				{
					key: 'description',
					label: t('dossiq', 'Description'),
					widget: 'textarea',
				},
				{
					key: 'registrationDate',
					label: t('dossiq', 'Registration date'),
					widget: 'datetime',
				},
			]
		},
	},

	watch: {
		resolvedCaseId() {
			this.reload()
		},
	},

	async mounted() {
		await initializeStores()
		await this.reload()
	},

	methods: {
		formatDate,
		/**
		 * Load the documents belonging to THIS case.
		 *
		 * Filters on a bare `case` field name: the `_filters[case]` form this
		 * used is inert, so the tab was reading every case's documents.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-001-zaak-objects-must-support-linked-documents-via-zgw-informatieobject-and-zaakinformatieobject
		 */
		async reload() {
			if (!this.resolvedCaseId) {
				this.loading = false
				return
			}
			this.loading = true
			try {
				const results = await this.objectStore.fetchCollection(
					'caseDocument',
					{
						case: this.resolvedCaseId,
						_limit: 100,
					},
				)
				this.documents = results || []
			} catch (err) {
				console.error('[CaseDocumentsTab] failed to fetch documents', err)
				this.documents = []
			} finally {
				this.loading = false
			}
		},

		openCreate() {
			this.editItem = null
			this.showFormDialog = true
		},

		openEdit(doc) {
			this.editItem = doc
			this.showFormDialog = true
		},

		openDelete(doc) {
			this.deleteItem = doc
		},

		/**
		 * Persist the case document through the canonical object store.
		 *
		 * @param {object} formData The submitted form values.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-001-zaak-objects-must-support-linked-documents-via-zgw-informatieobject-and-zaakinformatieobject
		 */
		async onFormConfirm(formData) {
			try {
				const result = await this.objectStore.saveObject('caseDocument', {
					...formData,
					case: this.resolvedCaseId,
				})
				if (result) {
					this.$refs.formDialog.setResult({ success: true })
					await this.reload()
				} else {
					this.$refs.formDialog.setResult({
						error: t('dossiq', 'Could not save document'),
					})
				}
			} catch (err) {
				this.$refs.formDialog.setResult({
					error: err.message || t('dossiq', 'Could not save document'),
				})
			}
		},

		/**
		 * Delete the case document through the canonical object store.
		 *
		 * @param {string} id The caseDocument object id.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/document-zaakdossier/spec.md#requirement-req-zak-001-zaak-objects-must-support-linked-documents-via-zgw-informatieobject-and-zaakinformatieobject
		 */
		async onDeleteConfirm(id) {
			try {
				await this.objectStore.deleteObject('caseDocument', id)
				this.$refs.deleteDialog.setResult({ success: true })
				await this.reload()
			} catch (err) {
				this.$refs.deleteDialog.setResult({
					error: err.message || t('dossiq', 'Could not delete document'),
				})
			}
		},
	},
}
</script>

<style scoped>
.case-tab__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.case-tab__title {
	margin: 0;
	font-size: 16px;
}

.case-tab__count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.case-tab__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.case-tab__item {
	padding: 8px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.case-tab__item:hover {
	background: var(--color-background-hover);
}

.case-tab__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.case-tab__item-title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.case-tab__meta {
	display: flex;
	flex-direction: column;
	gap: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.case-tab__description {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
