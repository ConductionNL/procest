<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="sub-entity-tab">
		<div v-if="isCreate" class="sub-entity-tab__notice">
			<p>
				{{
					t(
						'dossiq',
						'Save the case type first before adding decision types.',
					)
				}}
			</p>
		</div>

		<template v-else>
			<!-- Deprecation notice: decision types are now managed by decidesk -->
			<NcNoteCard type="warning">
				{{
					t(
						'dossiq',
						'Decision types are now managed by decidesk (procest-delegate-contract-decision). Local decision type configuration is kept for historical read access only. New decision flows are raised via the decidesk integration (ADR-019).',
					)
				}}
			</NcNoteCard>
			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<div v-if="items.length > 0" class="sub-entity-tab__list">
					<div v-for="item in items" :key="item.id" class="sub-entity-row">
						<template v-if="editingId !== item.id">
							<span class="sub-entity-row__name">{{ item.name }}</span>
							<span v-if="item.isDraft" class="sub-entity-row__badge">
								{{ t('dossiq', 'Draft') }}
							</span>
							<span
								v-if="item.publicationRequired"
								class="sub-entity-row__badge">
								{{ t('dossiq', 'Publication required') }}
							</span>
							<span v-if="item.validFrom" class="sub-entity-row__meta">
								{{ item.validFrom }}
							</span>
							<div class="sub-entity-row__actions">
								<NcButton
									type="tertiary"
									:aria-label="
										t('dossiq', 'Edit {name}', {
											name: item.name,
										})
									"
									@click="startEdit(item)">
									<template #icon>
										<PencilIcon :size="20" />
									</template>
								</NcButton>
								<NcButton
									type="tertiary"
									:aria-label="
										t('dossiq', 'Delete {name}', {
											name: item.name,
										})
									"
									@click="deleteItem(item)">
									<template #icon>
										<DeleteIcon :size="20" />
									</template>
								</NcButton>
							</div>
						</template>

						<template v-else>
							<div class="sub-entity-row__edit-form">
								<div class="edit-row">
									<NcTextField
										:modelValue="editForm.name"
										:label="t('dossiq', 'Name')"
										:error="!!editError"
										class="edit-field"
										@update:modelValue="
											(v) => (editForm.name = v)
										" />
								</div>
								<div class="edit-row">
									<NcTextField
										:modelValue="editForm.description"
										:label="t('dossiq', 'Description')"
										class="edit-field edit-field--full"
										@update:modelValue="
											(v) => (editForm.description = v)
										" />
								</div>
								<div class="edit-row">
									<NcTextField
										:modelValue="editForm.validFrom"
										:label="t('dossiq', 'Valid from')"
										type="date"
										class="edit-field"
										@update:modelValue="
											(v) => (editForm.validFrom = v)
										" />
									<NcTextField
										:modelValue="editForm.validUntil"
										:label="t('dossiq', 'Valid until')"
										type="date"
										class="edit-field"
										@update:modelValue="
											(v) => (editForm.validUntil = v)
										" />
								</div>
								<div class="edit-row">
									<NcCheckboxRadioSwitch
										:modelValue="editForm.isDraft"
										@update:modelValue="
											(v) => (editForm.isDraft = v)
										">
										{{ t('dossiq', 'Draft') }}
									</NcCheckboxRadioSwitch>
									<NcCheckboxRadioSwitch
										:modelValue="editForm.publicationRequired"
										@update:modelValue="
											(v) => (editForm.publicationRequired = v)
										">
										{{ t('dossiq', 'Publication required') }}
									</NcCheckboxRadioSwitch>
								</div>
								<p v-if="editError" class="edit-error">
									{{ editError }}
								</p>
								<div class="edit-actions">
									<NcButton
										type="primary"
										:disabled="saving"
										@click="saveEdit">
										{{ t('dossiq', 'Save') }}
									</NcButton>
									<NcButton @click="cancelEdit">
										{{ t('dossiq', 'Cancel') }}
									</NcButton>
								</div>
							</div>
						</template>
					</div>
				</div>
				<p v-else class="sub-entity-tab__empty">
					{{ t('dossiq', 'No decision types configured yet.') }}
				</p>

				<NcButton v-if="editingId === null" @click="startAdd">
					{{ t('dossiq', 'Add Decision Type') }}
				</NcButton>

				<p v-if="error" class="sub-entity-tab__error">
					{{ error }}
				</p>
			</template>
		</template>
	</div>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { useObjectStore } from '../../../store/modules/object.js'

export default {
	name: 'DecisionTypesTab',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		NcCheckboxRadioSwitch,
		PencilIcon,
		DeleteIcon,
	},

	props: {
		caseTypeId: { type: String, default: null },
		isCreate: { type: Boolean, default: false },
	},

	data() {
		return {
			loading: false,
			error: '',
			items: [],
			editingId: null,
			editForm: this.emptyForm(),
			editError: '',
			saving: false,
		}
	},

	async mounted() {
		if (!this.isCreate && this.caseTypeId) await this.loadItems()
	},

	methods: {
		emptyForm() {
			return {
				name: '',
				description: '',
				isDraft: true,
				publicationRequired: false,
				validFrom: '',
				validUntil: '',
			}
		},

		/**
		 * Load the configured decision types.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/roles-decisions/spec.md
		 */
		async loadItems() {
			this.loading = true
			this.error = ''
			try {
				const objectStore = useObjectStore()
				const results = await objectStore.fetchCollection('decisionType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})
				this.items = results || []
			} catch (e) {
				this.error =
					e.message || t('dossiq', 'Failed to load decision types')
			}
			this.loading = false
		},

		startAdd() {
			this.editingId = 'new'
			this.editForm = this.emptyForm()
			this.editError = ''
			this.items.push({ id: 'new', name: '' })
		},

		/**
		 * @param item the decision type row being edited
		 */
		startEdit(item) {
			this.editingId = item.id
			this.editForm = {
				name: item.name || '',
				description: item.description || '',
				isDraft: item.isDraft === true || item.isDraft === 'true',
				publicationRequired:
					item.publicationRequired === true
					|| item.publicationRequired === 'true',

				validFrom: item.validFrom || '',
				validUntil: item.validUntil || '',
			}
			this.editError = ''
		},

		cancelEdit() {
			if (this.editingId === 'new')
				this.items = this.items.filter((i) => i.id !== 'new')
			this.editingId = null
			this.editError = ''
		},

		/**
		 * Persist the edited decision type.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/roles-decisions/spec.md
		 */
		async saveEdit() {
			this.editError = ''
			if (!this.editForm.name.trim()) {
				this.editError = t('dossiq', 'Name is required')
				return
			}
			this.saving = true
			try {
				const objectStore = useObjectStore()
				const data = {
					name: this.editForm.name.trim(),
					description: this.editForm.description.trim(),
					caseType: this.caseTypeId,
					isDraft: this.editForm.isDraft,
					publicationRequired: this.editForm.publicationRequired,
					validFrom: this.editForm.validFrom,
					validUntil: this.editForm.validUntil,
				}
				if (this.editingId !== 'new') data.id = this.editingId
				const result = await objectStore.saveObject('decisionType', data)
				if (!result) {
					this.editError =
						objectStore.getError('decisionType')
						|| t('dossiq', 'Failed to save decision type')
					return
				}
				this.editingId = null
				await this.loadItems()
			} catch (e) {
				this.editError =
					e.message || t('dossiq', 'Failed to save decision type')
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param item the decision type row being deleted
		 *
		 * @spec openspec/specs/roles-decisions/spec.md
		 */
		async deleteItem(item) {
			if (
				!confirm(
					t('dossiq', 'Delete decision type "{name}"?', {
						name: item.name,
					}),
				)
			)
				return
			try {
				const objectStore = useObjectStore()
				const ok = await objectStore.deleteObject('decisionType', item.id)
				if (!ok) {
					this.error =
						objectStore.getError('decisionType')
						|| t('dossiq', 'Failed to delete decision type')
					return
				}
				await this.loadItems()
			} catch (e) {
				this.error =
					e.message || t('dossiq', 'Failed to delete decision type')
			}
		},
	},
}
</script>

<style scoped>
@import './sub-entity-tab.css';
</style>
