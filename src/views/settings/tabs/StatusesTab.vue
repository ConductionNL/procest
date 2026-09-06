<template>
	<div class="statuses-tab">
		<div v-if="isCreate" class="statuses-tab__notice">
			<p>
				{{
					t(
						'dossiq',
						'Save the case type first before adding status types.',
					)
				}}
			</p>
		</div>

		<template v-else>
			<NcLoadingIcon v-if="loading" />

			<template v-else>
				<!-- Status types list -->
				<div v-if="sortedStatusTypes.length > 0" class="statuses-tab__list">
					<div
						v-for="(st, index) in sortedStatusTypes"
						:key="st.id"
						class="status-type-row"
						:class="{
							'status-type-row--dragging': dragIndex === index,
							'status-type-row--drag-over': dragOverIndex === index,
							'status-type-row--editing': editingId === st.id,
						}"
						:draggable="editingId !== st.id"
						@dragstart="onDragStart(index, $event)"
						@dragover.prevent="onDragOver(index)"
						@dragleave="onDragLeave"
						@drop="onDrop(index)"
						@dragend="onDragEnd">
						<!-- View mode -->
						<template v-if="editingId !== st.id">
							<span
								class="status-type-row__handle"
								:title="t('dossiq', 'Drag to reorder')"
								>⠿</span
							>
							<span class="status-type-row__order">{{
								st.order
							}}</span>
							<span class="status-type-row__name">{{ st.name }}</span>
							<span v-if="st.isFinal" class="status-type-row__final">
								{{ t('dossiq', 'Final') }}
							</span>
							<span
								v-if="st.notifyInitiator"
								class="status-type-row__notify">
								{{ t('dossiq', 'Notify') }}
							</span>
							<span
								v-if="st.notifyInitiator && st.notificationText"
								class="status-type-row__notify-text">
								{{ st.notificationText }}
							</span>
							<div class="status-type-row__actions">
								<NcButton
									type="tertiary"
									:aria-label="
										t('dossiq', 'Edit {name}', {
											name: st.name,
										})
									"
									@click="startEdit(st)">
									<template #icon>
										<PencilIcon :size="20" />
									</template>
								</NcButton>
								<NcButton
									type="tertiary"
									:aria-label="
										t('dossiq', 'Delete {name}', {
											name: st.name,
										})
									"
									@click="deleteStatusType(st)">
									<template #icon>
										<DeleteIcon :size="20" />
									</template>
								</NcButton>
							</div>
						</template>

						<!-- Edit mode -->
						<template v-else>
							<div class="status-type-row__edit-form">
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
										:modelValue="String(editForm.order)"
										:label="t('dossiq', 'Order')"
										type="number"
										class="edit-field edit-field--small"
										@update:modelValue="
											(v) =>
												(editForm.order =
													parseInt(v, 10) || 0)
										" />
								</div>
								<div class="edit-row">
									<NcCheckboxRadioSwitch
										:modelValue="editForm.isFinal"
										@update:modelValue="
											(v) => (editForm.isFinal = v)
										">
										{{ t('dossiq', 'Final status') }}
									</NcCheckboxRadioSwitch>
									<NcCheckboxRadioSwitch
										:modelValue="editForm.notifyInitiator"
										@update:modelValue="
											(v) => (editForm.notifyInitiator = v)
										">
										{{ t('dossiq', 'Notify initiator') }}
									</NcCheckboxRadioSwitch>
								</div>
								<div
									v-if="editForm.notifyInitiator"
									class="edit-row">
									<NcTextField
										:modelValue="editForm.notificationText"
										:label="t('dossiq', 'Notification text')"
										class="edit-field"
										@update:modelValue="
											(v) => (editForm.notificationText = v)
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

				<p v-else class="statuses-tab__empty">
					{{
						t(
							'dossiq',
							'No status types defined. Add at least one to publish this case type.',
						)
					}}
				</p>

				<!-- Add new status type form -->
				<div class="statuses-tab__add">
					<h4>{{ t('dossiq', 'Add Status Type') }}</h4>
					<div class="add-form">
						<div class="add-form__row">
							<NcTextField
								:modelValue="newForm.name"
								:label="t('dossiq', 'Name *')"
								class="add-form__field"
								@update:modelValue="(v) => (newForm.name = v)" />
							<NcTextField
								:modelValue="String(newForm.order)"
								:label="t('dossiq', 'Order *')"
								type="number"
								class="add-form__field add-form__field--small"
								@update:modelValue="
									(v) => (newForm.order = parseInt(v, 10) || 0)
								" />
						</div>
						<div class="add-form__row">
							<NcCheckboxRadioSwitch
								:modelValue="newForm.isFinal"
								@update:modelValue="(v) => (newForm.isFinal = v)">
								{{ t('dossiq', 'Final status') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								:modelValue="newForm.notifyInitiator"
								@update:modelValue="
									(v) => (newForm.notifyInitiator = v)
								">
								{{ t('dossiq', 'Notify initiator') }}
							</NcCheckboxRadioSwitch>
						</div>
						<div v-if="newForm.notifyInitiator" class="add-form__row">
							<NcTextField
								:modelValue="newForm.notificationText"
								:label="t('dossiq', 'Notification text')"
								class="add-form__field"
								@update:modelValue="
									(v) => (newForm.notificationText = v)
								" />
						</div>
						<span v-if="addError" class="field-error">{{
							addError
						}}</span>
						<NcButton
							type="primary"
							:disabled="addSaving"
							@click="addStatusType">
							{{ t('dossiq', 'Add') }}
						</NcButton>
					</div>
				</div>
			</template>

			<p v-if="error" class="statuses-tab__error">
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
	name: 'StatusesTab',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcCheckboxRadioSwitch,
		PencilIcon,
		DeleteIcon,
	},

	props: {
		caseTypeId: {
			type: String,
			default: null,
		},

		isCreate: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			statusTypes: [],
			loading: false,
			error: '',
			// Add form
			newForm: this.getEmptyForm(),
			addError: '',
			addSaving: false,
			// Edit form
			editingId: null,
			editForm: {},
			editError: '',
			editSaving: false,
			// Drag state
			dragIndex: null,
			dragOverIndex: null,
		}
	},

	computed: {
		/** @spec openspec/specs/status-transition-engine/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		sortedStatusTypes() {
			return [...this.statusTypes].sort(
				(a, b) => (a.order || 0) - (b.order || 0),
			)
		},
	},

	/** @spec openspec/specs/status-transition-engine/spec.md */
	async mounted() {
		if (!this.isCreate && this.caseTypeId) {
			await this.fetchStatusTypes()
		}
	},

	methods: {
		/** @spec openspec/specs/status-transition-engine/spec.md */
		getEmptyForm() {
			return {
				name: '',
				order: this.statusTypes ? this.statusTypes.length + 1 : 1,
				isFinal: false,
				notifyInitiator: false,
				notificationText: '',
			}
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		async fetchStatusTypes() {
			this.loading = true
			try {
				const result = await this.objectStore.fetchCollection('statusType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})
				this.statusTypes = result || []
			} catch (e) {
				this.error = e.message
			}
			this.loading = false
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		async addStatusType() {
			this.addError = ''

			if (!this.newForm.name || !this.newForm.name.trim()) {
				this.addError = t('dossiq', 'Status type name is required')
				return
			}

			if (!this.newForm.order || this.newForm.order <= 0) {
				this.addError = t('dossiq', 'Order is required')
				return
			}

			const duplicate = this.statusTypes.find(
				(st) => st.order === this.newForm.order,
			)
			if (duplicate) {
				this.addError = t(
					'dossiq',
					'A status type with this order already exists',
				)
				return
			}

			this.addSaving = true
			const data = {
				...this.newForm,
				caseType: this.caseTypeId,
			}

			const result = await this.objectStore.saveObject('statusType', data)
			this.addSaving = false

			if (result) {
				this.statusTypes.push(result)
				this.newForm = {
					name: '',
					order: this.statusTypes.length + 1,
					isFinal: false,
					notifyInitiator: false,
					notificationText: '',
				}
			} else {
				this.addError =
					this.objectStore.getError('statusType')
					|| t('dossiq', 'Failed to add status type')
			}
		},

		/**
		 * @param {object} st The status type.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		startEdit(st) {
			this.editingId = st.id
			this.editForm = { ...st }
			this.editError = ''
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		cancelEdit() {
			this.editingId = null
			this.editForm = {}
			this.editError = ''
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		async saveEdit() {
			this.editError = ''

			if (!this.editForm.name || !this.editForm.name.trim()) {
				this.editError = t('dossiq', 'Status type name is required')
				return
			}

			// Final status enforcement
			if (!this.editForm.isFinal) {
				const otherFinals = this.statusTypes.filter(
					(st) => st.id !== this.editingId && st.isFinal,
				)
				const wasFinal = this.statusTypes.find(
					(st) => st.id === this.editingId,
				)?.isFinal
				if (wasFinal && otherFinals.length === 0) {
					this.editError = t(
						'dossiq',
						'At least one status type must be marked as final',
					)
					return
				}
			}

			// Duplicate order check
			const duplicate = this.statusTypes.find(
				(st) => st.id !== this.editingId && st.order === this.editForm.order,
			)
			if (duplicate) {
				this.editError = t(
					'dossiq',
					'A status type with this order already exists',
				)
				return
			}

			this.editSaving = true
			const result = await this.objectStore.saveObject(
				'statusType',
				this.editForm,
			)
			this.editSaving = false

			if (result) {
				const idx = this.statusTypes.findIndex(
					(st) => st.id === this.editingId,
				)
				if (idx !== -1) {
					this.statusTypes[idx] = result
				}
				this.editingId = null
				this.editForm = {}
			} else {
				this.editError =
					this.objectStore.getError('statusType')
					|| t('dossiq', 'Failed to save')
			}
		},

		/**
		 * @param {object} st The status type.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		async deleteStatusType(st) {
			this.error = ''

			// Final status enforcement
			if (st.isFinal) {
				const otherFinals = this.statusTypes.filter(
					(s) => s.id !== st.id && s.isFinal,
				)
				if (otherFinals.length === 0) {
					this.error = t(
						'dossiq',
						'At least one status type must be marked as final',
					)
					return
				}
			}

			if (
				!confirm(
					t('dossiq', 'Delete status type "{name}"?', { name: st.name }),
				)
			) {
				return
			}

			const ok = await this.objectStore.deleteObject('statusType', st.id)
			if (ok) {
				this.statusTypes = this.statusTypes.filter((s) => s.id !== st.id)
			} else {
				this.error =
					this.objectStore.getError('statusType')
					|| t('dossiq', 'Failed to delete status type')
			}
		},

		// Drag and drop
		/**
		 * @param {number} index Index of the row in the list.
		 * @param {Event} event The originating DOM event.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		onDragStart(index, event) {
			this.dragIndex = index
			event.dataTransfer.effectAllowed = 'move'
		},

		/**
		 * @param {number} index Index of the row in the list.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		onDragOver(index) {
			if (this.dragIndex === null || this.dragIndex === index) return
			this.dragOverIndex = index
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		onDragLeave() {
			this.dragOverIndex = null
		},

		/**
		 * @param {number} targetIndex The target index.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		async onDrop(targetIndex) {
			if (this.dragIndex === null || this.dragIndex === targetIndex) {
				this.dragOverIndex = null
				return
			}

			const sorted = [...this.sortedStatusTypes]
			const [moved] = sorted.splice(this.dragIndex, 1)
			sorted.splice(targetIndex, 0, moved)

			// Recalculate orders
			const updates = []
			for (let i = 0; i < sorted.length; i++) {
				const newOrder = i + 1
				if (sorted[i].order !== newOrder) {
					sorted[i] = { ...sorted[i], order: newOrder }
					updates.push(sorted[i])
				}
			}

			this.statusTypes = sorted
			this.dragOverIndex = null
			this.dragIndex = null

			// Persist changes
			for (const st of updates) {
				await this.objectStore.saveObject('statusType', st)
			}
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		onDragEnd() {
			this.dragIndex = null
			this.dragOverIndex = null
		},
	},
}
</script>

<style scoped>
.statuses-tab__notice {
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	color: var(--color-text-maxcontrast);
}

.statuses-tab__list {
	margin-bottom: 24px;
}

.status-type-row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	transition: background 0.15s;
}

.status-type-row:hover {
	background: var(--color-background-hover);
}

.status-type-row--dragging {
	opacity: 0.4;
}

.status-type-row--drag-over {
	border-top: 2px solid var(--color-primary);
}

.status-type-row--editing {
	background: var(--color-background-dark);
	padding: 12px;
	flex-direction: column;
	align-items: stretch;
}

.status-type-row__handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
	font-size: 18px;
	user-select: none;
}

.status-type-row__handle:active {
	cursor: grabbing;
}

.status-type-row__order {
	min-width: 28px;
	text-align: center;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.status-type-row__name {
	flex: 1;
	font-weight: 500;
}

.status-type-row__final {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 11px;
	font-weight: 500;
	background: var(--color-success);
	color: white;
}

.status-type-row__notify {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 11px;
	font-weight: 500;
	background: var(--color-primary-light);
	color: var(--color-primary-text);
}

.status-type-row__notify-text {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	max-width: 200px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.status-type-row__actions {
	display: flex;
	gap: 2px;
	margin-left: auto;
}

.status-type-row__edit-form {
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

.edit-field--small {
	max-width: 80px;
}

.statuses-tab__add {
	border-top: 2px solid var(--color-border);
	padding-top: 16px;
}

.statuses-tab__add h4 {
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
	max-width: 80px;
}

.statuses-tab__empty {
	color: var(--color-text-maxcontrast);
	padding: 20px;
	text-align: center;
}

.statuses-tab__error {
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
	.status-type-row {
		transition: none;
	}
}
</style>
