<template>
	<div class="roles-tab">
		<div v-if="isCreate" class="roles-tab__notice">
			<p>
				{{
					t('dossiq', 'Save the case type first before adding role types.')
				}}
			</p>
		</div>

		<template v-else>
			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<div v-if="roleTypes.length > 0" class="roles-tab__list">
					<div
						v-for="rt in roleTypes"
						:key="rt.id"
						class="role-type-row"
						:class="{ 'role-type-row--editing': editingId === rt.id }">
						<template v-if="editingId !== rt.id">
							<span class="role-type-row__name">{{ rt.name }}</span>
							<span class="role-type-row__generic">{{
								genericRoleLabel(rt.genericRole)
							}}</span>
							<div class="role-type-row__actions">
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
									@click="deleteRoleType(rt)">
									<template #icon>
										<DeleteIcon :size="20" />
									</template>
								</NcButton>
							</div>
						</template>

						<template v-else>
							<div class="role-type-row__edit-form">
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
											t('dossiq', 'Generic role')
										}}</label>
										<select
											:value="editForm.genericRole"
											class="generic-role-select"
											@change="
												editForm.genericRole =
													$event.target.value
											">
											<option
												v-for="opt in genericRoleOptions"
												:key="opt.value"
												:value="opt.value">
												{{ opt.label }}
											</option>
										</select>
									</div>
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

				<p v-else class="roles-tab__empty">
					{{ t('dossiq', 'No role types defined yet.') }}
				</p>

				<div class="roles-tab__add">
					<h4>{{ t('dossiq', 'Add Role Type') }}</h4>
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
									t('dossiq', 'Generic role *')
								}}</label>
								<select
									:value="newForm.genericRole"
									class="generic-role-select"
									@change="
										newForm.genericRole = $event.target.value
									">
									<option
										v-for="opt in genericRoleOptions"
										:key="opt.value"
										:value="opt.value">
										{{ opt.label }}
									</option>
								</select>
							</div>
						</div>
						<span v-if="addError" class="field-error">{{
							addError
						}}</span>
						<NcButton
							type="primary"
							:disabled="addSaving"
							@click="addRoleType">
							{{ t('dossiq', 'Add') }}
						</NcButton>
					</div>
				</div>
			</template>

			<p v-if="error" class="roles-tab__error">
				{{ error }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { useObjectStore } from '../../../store/modules/object.js'

const GENERIC_ROLES = [
	{ value: 'initiator', label: 'Initiator' },
	{ value: 'handler', label: 'Case handler' },
	{ value: 'advisor', label: 'Advisor' },
	{ value: 'decision_maker', label: 'Decision maker' },
	{ value: 'stakeholder', label: 'Stakeholder' },
	{ value: 'coordinator', label: 'Coordinator' },
	{ value: 'contact', label: 'Contact' },
	{ value: 'co_initiator', label: 'Co-initiator' },
]

export default {
	name: 'RolesTab',
	components: { NcButton, NcLoadingIcon, NcTextField, PencilIcon, DeleteIcon },
	props: {
		caseTypeId: { type: String, default: null },
		isCreate: { type: Boolean, default: false },
	},

	data() {
		return {
			roleTypes: [],
			loading: false,
			error: '',
			newForm: { name: '', description: '', genericRole: 'initiator' },
			addError: '',
			addSaving: false,
			editingId: null,
			editForm: {},
			editError: '',
			editSaving: false,
		}
	},

	computed: {
		/** @spec openspec/specs/role-based-step-routing/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/role-based-step-routing/spec.md */
		genericRoleOptions() {
			return GENERIC_ROLES
		},
	},

	async mounted() {
		if (!this.isCreate && this.caseTypeId) await this.fetchRoleTypes()
	},

	methods: {
		/**
		 * @param {string|number|boolean|object} value The new value.
		 * @spec openspec/specs/role-based-step-routing/spec.md
		 */
		genericRoleLabel(value) {
			const opt = GENERIC_ROLES.find((r) => r.value === value)
			return opt ? opt.label : value || '—'
		},

		/** @spec openspec/specs/role-based-step-routing/spec.md */
		async fetchRoleTypes() {
			this.loading = true
			try {
				const result = await this.objectStore.fetchCollection('roleType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})
				this.roleTypes = result || []
			} catch (e) {
				this.error = e.message
			}
			this.loading = false
		},

		/** @spec openspec/specs/role-based-step-routing/spec.md */
		async addRoleType() {
			this.addError = ''
			if (!this.newForm.name?.trim()) {
				this.addError = t('dossiq', 'Name is required')
				return
			}
			this.addSaving = true
			const result = await this.objectStore.saveObject('roleType', {
				...this.newForm,
				caseType: this.caseTypeId,
			})
			this.addSaving = false
			if (result) {
				this.roleTypes.push(result)
				this.newForm = {
					name: '',
					description: '',
					genericRole: 'initiator',
				}
			} else {
				this.addError =
					this.objectStore.getError('roleType')
					|| t('dossiq', 'Failed to add role type')
			}
		},

		/**
		 * @param {object} rt The type being edited in this tab.
		 * @spec openspec/specs/role-based-step-routing/spec.md
		 */
		startEdit(rt) {
			this.editingId = rt.id
			this.editForm = { ...rt }
			this.editError = ''
		},

		/** @spec openspec/specs/role-based-step-routing/spec.md */
		cancelEdit() {
			this.editingId = null
			this.editForm = {}
			this.editError = ''
		},

		/** @spec openspec/specs/role-based-step-routing/spec.md */
		async saveEdit() {
			this.editError = ''
			if (!this.editForm.name?.trim()) {
				this.editError = t('dossiq', 'Name is required')
				return
			}
			this.editSaving = true
			const result = await this.objectStore.saveObject(
				'roleType',
				this.editForm,
			)
			this.editSaving = false
			if (result) {
				const idx = this.roleTypes.findIndex((r) => r.id === this.editingId)
				if (idx !== -1) this.roleTypes[idx] = result
				this.editingId = null
				this.editForm = {}
			} else {
				this.editError =
					this.objectStore.getError('roleType')
					|| t('dossiq', 'Failed to save')
			}
		},

		/**
		 * @param {object} rt The type being edited in this tab.
		 * @spec openspec/specs/role-based-step-routing/spec.md
		 */
		async deleteRoleType(rt) {
			if (
				!confirm(
					t('dossiq', 'Delete role type "{name}"?', { name: rt.name }),
				)
			)
				return
			const ok = await this.objectStore.deleteObject('roleType', rt.id)
			if (ok) {
				this.roleTypes = this.roleTypes.filter((r) => r.id !== rt.id)
			} else {
				this.error =
					this.objectStore.getError('roleType')
					|| t('dossiq', 'Failed to delete role type')
			}
		},
	},
}
</script>

<style scoped>
.roles-tab__notice {
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	color: var(--color-text-maxcontrast);
}

.roles-tab__list {
	margin-bottom: 24px;
}

.role-type-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	transition: background 0.15s;
}

.role-type-row:hover {
	background: var(--color-background-hover);
}

.role-type-row--editing {
	background: var(--color-background-dark);
	padding: 12px;
	flex-direction: column;
	align-items: stretch;
}

.role-type-row__name {
	flex: 1;
	font-weight: 500;
}

.role-type-row__generic {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 11px;
	font-weight: 500;
	background: var(--color-primary-light);
	color: var(--color-primary-text);
}

.role-type-row__actions {
	display: flex;
	gap: 2px;
	margin-left: auto;
}

.role-type-row__edit-form {
	width: 100%;
}

.generic-role-select {
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

.field-label {
	display: block;
	font-size: 12px;
	font-weight: 500;
	margin-bottom: 4px;
	color: var(--color-text-maxcontrast);
}

.roles-tab__add {
	border-top: 2px solid var(--color-border);
	padding-top: 16px;
}

.roles-tab__add h4 {
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

.roles-tab__empty {
	color: var(--color-text-maxcontrast);
	padding: 20px;
	text-align: center;
}

.roles-tab__error {
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
	.role-type-row {
		transition: none;
	}
}
</style>
