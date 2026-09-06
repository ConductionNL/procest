<template>
	<div class="results-tab">
		<div v-if="isCreate" class="results-tab__notice">
			<p>
				{{
					t(
						'dossiq',
						'Save the case type first before adding result types.',
					)
				}}
			</p>
		</div>

		<template v-else>
			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<!-- Result types list -->
				<div v-if="resultTypes.length > 0" class="results-tab__list">
					<div
						v-for="rt in resultTypes"
						:key="rt.id"
						class="result-type-row"
						:class="{ 'result-type-row--editing': editingId === rt.id }">
						<!-- View mode -->
						<template v-if="editingId !== rt.id">
							<span class="result-type-row__name">{{ rt.name }}</span>
							<span
								class="result-type-row__badge"
								:class="'badge--' + rt.archivalAction">
								{{
									rt.archivalAction === 'retain'
										? t('dossiq', 'Retain')
										: t('dossiq', 'Destroy')
								}}
							</span>
							<span class="result-type-row__period">
								{{ formatPeriod(rt.archivalPeriod) }}
							</span>
							<div class="result-type-row__actions">
								<NcButton
									type="tertiary"
									:aria-label="
										t('dossiq', 'Edit {name}', {
											name: rt.name,
										})
									"
									@click="startEdit(rt)">
									<template #icon>
										<PencilIcon :size="20" />
									</template>
								</NcButton>
								<NcButton
									type="tertiary"
									:aria-label="
										t('dossiq', 'Delete {name}', {
											name: rt.name,
										})
									"
									@click="deleteResultType(rt)">
									<template #icon>
										<DeleteIcon :size="20" />
									</template>
								</NcButton>
							</div>
						</template>

						<!-- Edit mode -->
						<template v-else>
							<div class="result-type-row__edit-form">
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
										class="edit-field"
										@update:modelValue="
											(v) => (editForm.description = v)
										" />
								</div>
								<div class="edit-row">
									<div class="edit-field">
										<label class="field-label">{{
											t('dossiq', 'Archive action')
										}}</label>
										<NcCheckboxRadioSwitch
											:modelValue="editForm.archivalAction"
											value="retain"
											name="edit-archive-action"
											type="radio"
											@update:modelValue="
												(v) => (editForm.archivalAction = v)
											">
											{{ t('dossiq', 'Retain') }}
										</NcCheckboxRadioSwitch>
										<NcCheckboxRadioSwitch
											:modelValue="editForm.archivalAction"
											value="destroy"
											name="edit-archive-action"
											type="radio"
											@update:modelValue="
												(v) => (editForm.archivalAction = v)
											">
											{{ t('dossiq', 'Destroy') }}
										</NcCheckboxRadioSwitch>
									</div>
									<NcTextField
										:modelValue="editForm.archivalPeriod"
										:label="
											t(
												'dossiq',
												'Retention period (ISO 8601, e.g. P20Y)',
											)
										"
										class="edit-field"
										@update:modelValue="
											(v) => (editForm.archivalPeriod = v)
										" />
								</div>
								<span v-if="editError" class="field-error">{{
									editError
								}}</span>
								<div class="edit-row edit-row--actions">
									<NcButton
										type="primary"
										:disabled="editSaving"
										@click="saveEdit">
										{{ t('dossiq', 'Save') }}
									</NcButton>
									<NcButton type="tertiary" @click="cancelEdit">
										{{ t('dossiq', 'Cancel') }}
									</NcButton>
								</div>
							</div>
						</template>
					</div>
				</div>

				<p v-else class="results-tab__empty">
					{{ t('dossiq', 'No result types defined yet.') }}
				</p>

				<!-- Add new result type form -->
				<div class="results-tab__add">
					<h4>{{ t('dossiq', 'Add Result Type') }}</h4>
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
								:modelValue="newForm.description"
								:label="t('dossiq', 'Description')"
								class="add-form__field"
								@update:modelValue="
									(v) => (newForm.description = v)
								" />
						</div>
						<div class="add-form__row">
							<div class="add-form__field">
								<label class="field-label">{{
									t('dossiq', 'Archive action')
								}}</label>
								<NcCheckboxRadioSwitch
									:modelValue="newForm.archivalAction"
									value="retain"
									name="new-archive-action"
									type="radio"
									@update:modelValue="
										(v) => (newForm.archivalAction = v)
									">
									{{ t('dossiq', 'Retain') }}
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch
									:modelValue="newForm.archivalAction"
									value="destroy"
									name="new-archive-action"
									type="radio"
									@update:modelValue="
										(v) => (newForm.archivalAction = v)
									">
									{{ t('dossiq', 'Destroy') }}
								</NcCheckboxRadioSwitch>
							</div>
							<NcTextField
								:modelValue="newForm.archivalPeriod"
								:label="t('dossiq', 'Retention period (e.g. P20Y)')"
								class="add-form__field"
								@update:modelValue="
									(v) => (newForm.archivalPeriod = v)
								" />
						</div>
						<span v-if="addError" class="field-error">{{
							addError
						}}</span>
						<NcButton
							type="primary"
							:disabled="addSaving"
							@click="addResultType">
							{{ t('dossiq', 'Add') }}
						</NcButton>
					</div>
				</div>
			</template>

			<p v-if="error" class="results-tab__error">
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
	name: 'ResultsTab',
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
			resultTypes: [],
			loading: false,
			error: '',
			newForm: {
				name: '',
				description: '',
				archivalAction: 'retain',
				archivalPeriod: '',
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
			await this.fetchResultTypes()
		}
	},

	methods: {
		/**
		 * @param {object} period The period.
		 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md
		 */
		formatPeriod(period) {
			if (!period) return '—'
			const match = period.match(/^P(\d+)([YDMW])$/)
			if (!match) return period
			const units = { Y: 'years', M: 'months', W: 'weeks', D: 'days' }
			return `${match[1]} ${units[match[2]] || match[2]}`
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async fetchResultTypes() {
			this.loading = true
			try {
				const result = await this.objectStore.fetchCollection('resultType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})
				this.resultTypes = result || []
			} catch (e) {
				this.error = e.message
			}
			this.loading = false
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		async addResultType() {
			this.addError = ''
			if (!this.newForm.name || !this.newForm.name.trim()) {
				this.addError = t('dossiq', 'Name is required')
				return
			}
			this.addSaving = true
			const data = { ...this.newForm, caseType: this.caseTypeId }
			const result = await this.objectStore.saveObject('resultType', data)
			this.addSaving = false
			if (result) {
				this.resultTypes.push(result)
				this.newForm = {
					name: '',
					description: '',
					archivalAction: 'retain',
					archivalPeriod: '',
				}
			} else {
				this.addError =
					this.objectStore.getError('resultType')
					|| t('dossiq', 'Failed to add result type')
			}
		},

		/**
		 * @param {object} rt The type being edited in this tab.
		 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md
		 */
		startEdit(rt) {
			this.editingId = rt.id
			this.editForm = { ...rt }
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
			if (!this.editForm.name || !this.editForm.name.trim()) {
				this.editError = t('dossiq', 'Name is required')
				return
			}
			this.editSaving = true
			const result = await this.objectStore.saveObject(
				'resultType',
				this.editForm,
			)
			this.editSaving = false
			if (result) {
				const idx = this.resultTypes.findIndex(
					(r) => r.id === this.editingId,
				)
				if (idx !== -1) this.resultTypes[idx] = result
				this.editingId = null
				this.editForm = {}
			} else {
				this.editError =
					this.objectStore.getError('resultType')
					|| t('dossiq', 'Failed to save')
			}
		},

		/**
		 * @param {object} rt The type being edited in this tab.
		 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md
		 */
		async deleteResultType(rt) {
			if (
				!confirm(
					t('dossiq', 'Delete result type "{name}"?', { name: rt.name }),
				)
			)
				return
			const ok = await this.objectStore.deleteObject('resultType', rt.id)
			if (ok) {
				this.resultTypes = this.resultTypes.filter((r) => r.id !== rt.id)
			} else {
				this.error =
					this.objectStore.getError('resultType')
					|| t('dossiq', 'Failed to delete result type')
			}
		},
	},
}
</script>

<style scoped>
.results-tab__notice {
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	color: var(--color-text-maxcontrast);
}

.results-tab__list {
	margin-bottom: 24px;
}

.result-type-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	transition: background 0.15s;
}

.result-type-row:hover {
	background: var(--color-background-hover);
}

.result-type-row--editing {
	background: var(--color-background-dark);
	padding: 12px;
	flex-direction: column;
	align-items: stretch;
}

.result-type-row__name {
	flex: 1;
	font-weight: 500;
}

.result-type-row__badge {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 11px;
	font-weight: 500;
}

.badge--retain {
	background: var(--color-success);
	color: white;
}

.badge--destroy {
	background: var(--color-warning);
	color: white;
}

.result-type-row__period {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.result-type-row__actions {
	display: flex;
	gap: 2px;
	margin-left: auto;
}

.result-type-row__edit-form {
	width: 100%;
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

.field-label {
	display: block;
	font-size: 12px;
	font-weight: 500;
	margin-bottom: 4px;
	color: var(--color-text-maxcontrast);
}

.results-tab__add {
	border-top: 2px solid var(--color-border);
	padding-top: 16px;
}

.results-tab__add h4 {
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

.results-tab__empty {
	color: var(--color-text-maxcontrast);
	padding: 20px;
	text-align: center;
}

.results-tab__error {
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
	.result-type-row {
		transition: none;
	}
}
</style>
