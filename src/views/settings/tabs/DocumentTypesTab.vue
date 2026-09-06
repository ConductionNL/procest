<template>
	<div class="sub-entity-tab">
		<div v-if="isCreate" class="sub-entity-tab__notice">
			<p>
				{{
					t(
						'dossiq',
						'Save the case type first before adding document types.',
					)
				}}
			</p>
		</div>

		<template v-else>
			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<div v-if="items.length > 0" class="sub-entity-tab__list">
					<div v-for="item in items" :key="item.id" class="sub-entity-row">
						<template v-if="editingId !== item.id">
							<span class="sub-entity-row__name">{{ item.name }}</span>
							<span v-if="item.category" class="sub-entity-row__meta">
								{{ item.category }}
							</span>
							<span
								v-if="item.isRequired"
								class="sub-entity-row__badge">
								{{ t('dossiq', 'Required') }}
							</span>
							<span
								v-if="item.confidentiality"
								class="sub-entity-row__meta">
								{{ item.confidentiality }}
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
									<NcTextField
										:modelValue="editForm.category"
										:label="t('dossiq', 'Category')"
										class="edit-field"
										@update:modelValue="
											(v) => (editForm.category = v)
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
										:modelValue="editForm.confidentiality"
										:label="t('dossiq', 'Confidentiality')"
										class="edit-field"
										@update:modelValue="
											(v) => (editForm.confidentiality = v)
										" />
								</div>
								<div class="edit-row">
									<NcCheckboxRadioSwitch
										:modelValue="editForm.isRequired"
										@update:modelValue="
											(v) => (editForm.isRequired = v)
										">
										{{ t('dossiq', 'Required') }}
									</NcCheckboxRadioSwitch>
								</div>
								<p v-if="editError" class="edit-error">
									{{ editError }}
								</p>
								<div class="edit-actions">
									<NcButton type="primary" @click="saveEdit">
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
					{{ t('dossiq', 'No document types configured yet.') }}
				</p>

				<NcButton v-if="editingId === null" @click="startAdd">
					{{ t('dossiq', 'Add Document Type') }}
				</NcButton>
			</template>
		</template>
	</div>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { useObjectStore } from '../../../store/modules/object.js'

export default {
	name: 'DocumentTypesTab',
	components: {
		NcButton,
		NcLoadingIcon,
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
			items: [],
			editingId: null,
			editForm: {
				name: '',
				category: '',
				description: '',
				confidentiality: '',
				isRequired: false,
			},

			editError: '',
		}
	},

	async mounted() {
		if (!this.isCreate) await this.loadItems()
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async loadItems() {
			this.loading = true
			const objectStore = useObjectStore()
			const results = await objectStore.fetchCollection('documentType', {
				caseType: this.caseTypeId,
				_limit: 100,
			})
			this.items = results || []
			this.loading = false
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		startAdd() {
			this.editingId = 'new'
			this.editForm = {
				name: '',
				category: '',
				description: '',
				confidentiality: '',
				isRequired: false,
			}
			this.editError = ''
			this.items.push({ id: 'new', name: '' })
		},

		/**
		 * @param {object} item The item.
		 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md
		 */
		startEdit(item) {
			this.editingId = item.id
			this.editForm = {
				name: item.name,
				category: item.category || '',
				description: item.description || '',
				confidentiality: item.confidentiality || '',
				isRequired: item.isRequired === true || item.isRequired === 'true',
			}
			this.editError = ''
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		cancelEdit() {
			if (this.editingId === 'new')
				this.items = this.items.filter((i) => i.id !== 'new')
			this.editingId = null
			this.editError = ''
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async saveEdit() {
			if (!this.editForm.name.trim()) {
				this.editError = t('dossiq', 'Name is required')
				return
			}
			const objectStore = useObjectStore()
			const data = {
				name: this.editForm.name.trim(),
				category: this.editForm.category.trim(),
				description: this.editForm.description.trim(),
				confidentiality: this.editForm.confidentiality.trim(),
				caseType: this.caseTypeId,
				isRequired: this.editForm.isRequired,
			}
			if (this.editingId !== 'new') data.id = this.editingId
			await objectStore.saveObject('documentType', data)
			this.editingId = null
			await this.loadItems()
		},

		/**
		 * @param {object} item The item.
		 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md
		 */
		async deleteItem(item) {
			if (
				!confirm(
					t(
						'dossiq',
						'Delete document type "{name}"? Existing uploaded files will not be deleted.',
						{ name: item.name },
					),
				)
			)
				return
			const objectStore = useObjectStore()
			await objectStore.deleteObject('documentType', item.id)
			await this.loadItems()
		},
	},
}
</script>

<style scoped>
@import './sub-entity-tab.css';
</style>
