<template>
	<div class="properties-tab">
		<div v-if="isCreate" class="properties-tab__notice">
			<p>
				{{
					t(
						'dossiq',
						'Save the case type first before adding property definitions.',
					)
				}}
			</p>
		</div>

		<template v-else>
			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<div v-if="propertyDefs.length > 0" class="properties-tab__list">
					<div
						v-for="pd in propertyDefs"
						:key="pd.id"
						class="property-row"
						:class="{ 'property-row--editing': editingId === pd.id }">
						<template v-if="editingId !== pd.id">
							<span class="property-row__name">{{ pd.name }}</span>
							<span class="property-row__format">{{
								pd.propertyType || 'string'
							}}</span>
							<span v-if="pd.maxLength" class="property-row__max">
								{{ t('dossiq', 'max {n}', { n: pd.maxLength }) }}
							</span>
							<span class="property-row__required">
								{{ requiredLabel(pd) }}
							</span>
							<div class="property-row__actions">
								<NcButton
									variant="tertiary"
									:aria-label="
										t('dossiq', 'Edit {name}', {
											name: pd.name,
										})
									"
									@click="startEdit(pd)">
									<template #icon>
										<PencilIcon :size="20" />
									</template>
								</NcButton>
								<NcButton
									variant="tertiary"
									:aria-label="
										t('dossiq', 'Delete {name}', {
											name: pd.name,
										})
									"
									@click="deleteProperty(pd)">
									<template #icon>
										<DeleteIcon :size="20" />
									</template>
								</NcButton>
							</div>
						</template>

						<template v-else>
							<div class="property-row__edit-form">
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
										:modelValue="editForm.definition"
										:label="t('dossiq', 'Definition')"
										class="edit-field"
										@update:modelValue="
											(v) => (editForm.definition = v)
										" />
								</div>
								<div class="edit-row">
									<div class="edit-field">
										<label class="field-label">{{
											t('dossiq', 'Type')
										}}</label>
										<select
											:value="editForm.propertyType"
											class="format-select"
											@change="
												editForm.propertyType =
													$event.target.value
											">
											<option value="string">
												{{ t('dossiq', 'Text') }}
											</option>
											<option value="number">
												{{ t('dossiq', 'Number') }}
											</option>
											<option value="boolean">
												{{ t('dossiq', 'Yes or no') }}
											</option>
											<option value="date">
												{{ t('dossiq', 'Date') }}
											</option>
											<option value="email">
												{{ t('dossiq', 'Email address') }}
											</option>
											<option value="url">
												{{ t('dossiq', 'Link') }}
											</option>
											<option value="enum">
												{{
													t('dossiq', 'Choice from a list')
												}}
											</option>
											<option value="json">
												{{ t('dossiq', 'Structured data') }}
											</option>
										</select>
									</div>
									<NcTextField
										:modelValue="
											editForm.maxLength
												? String(editForm.maxLength)
												: ''
										"
										:label="t('dossiq', 'Max length')"
										type="number"
										class="edit-field edit-field--small"
										@update:modelValue="
											(v) =>
												(editForm.maxLength =
													parseInt(v, 10) || null)
										" />
								</div>
								<div class="edit-row">
									<NcCheckboxRadioSwitch
										:modelValue="!!editForm.isRequired"
										type="switch"
										class="edit-field"
										@update:modelValue="
											(v) => (editForm.isRequired = v)
										">
										{{ t('dossiq', 'Always required') }}
									</NcCheckboxRadioSwitch>
								</div>
								<div class="edit-row">
									<div class="edit-field">
										<label class="field-label">{{
											t('dossiq', 'Required from status')
										}}</label>
										<select
											:value="editForm.requiredAtStatus || ''"
											class="format-select"
											@change="
												editForm.requiredAtStatus =
													$event.target.value || null
											">
											<option value="">
												{{ t('dossiq', 'Optional') }}
											</option>
											<option
												v-for="st in statusTypes"
												:key="st.id"
												:value="st.id">
												{{ st.name }}
											</option>
										</select>
									</div>
								</div>
								<span v-if="editError" class="field-error">{{
									editError
								}}</span>
								<div class="edit-row edit-row--actions">
									<NcButton
										variant="primary"
										:disabled="editSaving"
										@click="saveEdit">
										{{ t('dossiq', 'Save') }}
									</NcButton>
									<NcButton variant="tertiary" @click="cancelEdit">
										{{ t('dossiq', 'Cancel') }}
									</NcButton>
								</div>
							</div>
						</template>
					</div>
				</div>

				<p v-else class="properties-tab__empty">
					{{ t('dossiq', 'No property definitions yet.') }}
				</p>

				<div class="properties-tab__add">
					<h4>{{ t('dossiq', 'Add Property Definition') }}</h4>
					<div class="add-form">
						<div class="add-form__row">
							<NcTextField
								:modelValue="newForm.name"
								:label="t('dossiq', 'Name *')"
								class="add-form__field"
								@update:modelValue="(v) => (newForm.name = v)" />
						</div>
						<div class="add-form__row">
							<NcTextField
								:modelValue="newForm.definition"
								:label="t('dossiq', 'Definition')"
								class="add-form__field"
								@update:modelValue="
									(v) => (newForm.definition = v)
								" />
						</div>
						<div class="add-form__row">
							<div class="add-form__field">
								<label class="field-label">{{
									t('dossiq', 'Type')
								}}</label>
								<select
									:value="newForm.propertyType"
									class="format-select"
									@change="
										newForm.propertyType = $event.target.value
									">
									<option value="string">
										{{ t('dossiq', 'Text') }}
									</option>
									<option value="number">
										{{ t('dossiq', 'Number') }}
									</option>
									<option value="boolean">
										{{ t('dossiq', 'Yes or no') }}
									</option>
									<option value="date">
										{{ t('dossiq', 'Date') }}
									</option>
									<option value="email">
										{{ t('dossiq', 'Email address') }}
									</option>
									<option value="url">
										{{ t('dossiq', 'Link') }}
									</option>
									<option value="enum">
										{{ t('dossiq', 'Choice from a list') }}
									</option>
									<option value="json">
										{{ t('dossiq', 'Structured data') }}
									</option>
								</select>
							</div>
							<NcTextField
								:modelValue="
									newForm.maxLength
										? String(newForm.maxLength)
										: ''
								"
								:label="t('dossiq', 'Max length')"
								type="number"
								class="add-form__field add-form__field--small"
								@update:modelValue="
									(v) =>
										(newForm.maxLength = parseInt(v, 10) || null)
								" />
						</div>
						<div class="add-form__row">
							<NcCheckboxRadioSwitch
								:modelValue="!!newForm.isRequired"
								type="switch"
								class="add-form__field"
								@update:modelValue="(v) => (newForm.isRequired = v)">
								{{ t('dossiq', 'Always required') }}
							</NcCheckboxRadioSwitch>
						</div>
						<div class="add-form__row">
							<div class="add-form__field">
								<label class="field-label">{{
									t('dossiq', 'Required from status')
								}}</label>
								<select
									:value="newForm.requiredAtStatus || ''"
									class="format-select"
									@change="
										newForm.requiredAtStatus =
											$event.target.value || null
									">
									<option value="">
										{{ t('dossiq', 'Optional') }}
									</option>
									<option
										v-for="st in statusTypes"
										:key="st.id"
										:value="st.id">
										{{ st.name }}
									</option>
								</select>
							</div>
						</div>
						<span v-if="addError" class="field-error">{{
							addError
						}}</span>
						<NcButton
							variant="primary"
							:disabled="addSaving"
							@click="addProperty">
							{{ t('dossiq', 'Add') }}
						</NcButton>
					</div>
				</div>
			</template>

			<p v-if="error" class="properties-tab__error">
				{{ error }}
			</p>
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
	name: 'PropertiesTab',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcTextField,
		PencilIcon,
		DeleteIcon,
	},

	props: {
		caseTypeId: { type: String, default: null },
		isCreate: { type: Boolean, default: false },
	},

	data() {
		return {
			propertyDefs: [],
			statusTypes: [],
			loading: false,
			error: '',
			newForm: {
				name: '',
				definition: '',
				propertyType: 'string',
				maxLength: null,
				isRequired: false,
				requiredAtStatus: null,
			},

			addError: '',
			addSaving: false,
			editingId: null,
			editForm: {},
			editError: '',
			editSaving: false,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		objectStore() {
			return useObjectStore()
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
	async mounted() {
		if (!this.isCreate && this.caseTypeId) {
			await Promise.all([this.fetchPropertyDefs(), this.fetchStatusTypes()])
		}
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async fetchPropertyDefs() {
			this.loading = true
			try {
				const result = await this.objectStore.fetchCollection(
					'propertyDefinition',
					{
						caseType: this.caseTypeId,
						_limit: 100,
					},
				)
				this.propertyDefs = result || []
			} catch (e) {
				this.error = e.message
			}
			this.loading = false
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async fetchStatusTypes() {
			try {
				const result = await this.objectStore.fetchCollection('statusType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})
				this.statusTypes = result || []
			} catch (e) {
				/* ignore — status types are optional for property definitions */
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async addProperty() {
			this.addError = ''
			if (!this.newForm.name?.trim()) {
				this.addError = t('dossiq', 'Name is required')
				return
			}
			this.addSaving = true
			const result = await this.objectStore.saveObject('propertyDefinition', {
				...this.newForm,
				caseType: this.caseTypeId,
			})
			this.addSaving = false
			if (result) {
				this.propertyDefs.push(result)
				this.newForm = {
					name: '',
					definition: '',
					propertyType: 'string',
					maxLength: null,
					isRequired: false,
					requiredAtStatus: null,
				}
			} else {
				this.addError =
					this.objectStore.getError('propertyDefinition')
					|| t('dossiq', 'Failed to add property')
			}
		},

		/**
		 * How a property definition's obligation reads in the list.
		 *
		 * `requiredAtStatus` holds a status REFERENCE, so rendering it raw
		 * puts a UUID in the column. It is also distinct from `isRequired`:
		 * one demands an answer at intake, the other from a later status on.
		 *
		 * @param {object} pd The property definition.
		 * @return {string} The label for the obligation column.
		 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md
		 */
		requiredLabel(pd) {
			if (pd.isRequired) return t('dossiq', 'Always required')
			if (!pd.requiredAtStatus) return t('dossiq', 'Optional')
			const status = this.statusTypes.find(
				(st) => st.id === pd.requiredAtStatus,
			)
			return status
				? t('dossiq', 'Required from {status}', { status: status.name })
				: t('dossiq', 'Required from a later status')
		},

		/**
		 * @param {object} pd The property definition to open for editing.
		 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md
		 */
		startEdit(pd) {
			this.editingId = pd.id
			this.editForm = { ...pd }
			this.editError = ''
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		cancelEdit() {
			this.editingId = null
			this.editForm = {}
			this.editError = ''
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async saveEdit() {
			this.editError = ''
			if (!this.editForm.name?.trim()) {
				this.editError = t('dossiq', 'Name is required')
				return
			}
			this.editSaving = true
			const result = await this.objectStore.saveObject(
				'propertyDefinition',
				this.editForm,
			)
			this.editSaving = false
			if (result) {
				const idx = this.propertyDefs.findIndex(
					(p) => p.id === this.editingId,
				)
				if (idx !== -1) this.propertyDefs[idx] = result
				this.editingId = null
				this.editForm = {}
			} else {
				this.editError =
					this.objectStore.getError('propertyDefinition')
					|| t('dossiq', 'Failed to save')
			}
		},

		/**
		 * @param {object} pd The property definition to delete.
		 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md
		 */
		async deleteProperty(pd) {
			if (
				!confirm(t('dossiq', 'Delete property "{name}"?', { name: pd.name }))
			)
				return
			const ok = await this.objectStore.deleteObject(
				'propertyDefinition',
				pd.id,
			)
			if (ok) {
				this.propertyDefs = this.propertyDefs.filter((p) => p.id !== pd.id)
			} else {
				this.error =
					this.objectStore.getError('propertyDefinition')
					|| t('dossiq', 'Failed to delete property')
			}
		},
	},
}
</script>

<style scoped>
.properties-tab__notice {
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	color: var(--color-text-maxcontrast);
}

.properties-tab__list {
	margin-bottom: 24px;
}

.property-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	transition: background 0.15s;
}

.property-row:hover {
	background: var(--color-background-hover);
}

.property-row--editing {
	background: var(--color-background-dark);
	padding: 12px;
	flex-direction: column;
	align-items: stretch;
}

.property-row__name {
	flex: 1;
	font-weight: 500;
}

.property-row__format {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 11px;
	font-weight: 500;
	background: var(--color-background-dark);
}

.property-row__max {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.property-row__required {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.property-row__actions {
	display: flex;
	gap: 2px;
	margin-left: auto;
}

.property-row__edit-form {
	width: 100%;
}

.format-select {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.edit-row {
	display: flex;
	gap: 12px;
	margin-bottom: 8px;
	align-items: center;
}

.edit-row--actions {
	margin-top: 8px;
}

.edit-field {
	flex: 1;
}

.edit-field--small {
	max-width: 100px;
}

.field-label {
	display: block;
	font-size: 12px;
	font-weight: 500;
	margin-bottom: 4px;
	color: var(--color-text-maxcontrast);
}

.properties-tab__add {
	border-top: 2px solid var(--color-border);
	padding-top: 16px;
}

.properties-tab__add h4 {
	margin-bottom: 12px;
}

.add-form__row {
	display: flex;
	gap: 12px;
	margin-bottom: 8px;
	align-items: center;
}

.add-form__field {
	flex: 1;
}

.add-form__field--small {
	max-width: 100px;
}

.properties-tab__empty {
	color: var(--color-text-maxcontrast);
	padding: 20px;
	text-align: center;
}

.properties-tab__error {
	color: var(--color-error);
	margin-top: 12px;
}

.field-error {
	display: block;
	color: var(--color-error);
	font-size: 12px;
	margin-bottom: 8px;
}

@media (prefers-reduced-motion: reduce) {
	.property-row {
		transition: none;
	}
}
</style>
