<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcDialog
		v-if="open"
		:name="t('dossiq', 'Issue advice')"
		size="normal"
		:canClose="!submitting"
		@closing="onClose">
		<div class="consultation-response-form">
			<div
				v-if="consultationSubject"
				class="consultation-response-form__subject">
				<span class="consultation-response-form__subject-label">{{
					t('dossiq', 'Subject:')
				}}</span>
				{{ consultationSubject }}
			</div>

			<div class="consultation-response-form__field">
				<label class="consultation-response-form__label">
					{{ t('dossiq', 'Advice') }} *
				</label>
				<NcSelect
					v-model="form.advies"
					:options="adviesOptions"
					:aria-label-combobox="t('dossiq', 'Advice')"
					label="label"
					:reduce="(opt) => opt.value"
					:placeholder="t('dossiq', 'Select advice type')" />
			</div>

			<div v-if="showToelichting" class="consultation-response-form__field">
				<label
					class="consultation-response-form__label"
					for="consultation-response-toelichting">
					{{ t('dossiq', 'Explanation') }}
					<span v-if="toelichtingRequired">*</span>
				</label>
				<textarea
					id="consultation-response-toelichting"
					v-model="form.notes"
					class="consultation-response-form__textarea"
					rows="4"
					:placeholder="
						t('dossiq', 'Provide an explanation for your advice...')
					" />
			</div>

			<!-- Conditions — only shown for positief_met_voorwaarden -->
			<div v-if="showVoorwaarden" class="consultation-response-form__field">
				<label class="consultation-response-form__label">
					{{ t('dossiq', 'Conditions') }}
				</label>
				<div
					v-for="(voorwaarde, idx) in form.terms"
					:key="idx"
					class="consultation-response-form__condition-row">
					<input
						v-model="voorwaarde.description"
						class="consultation-response-form__condition-input"
						:aria-label="
							t('dossiq', 'Condition description {n}', { n: idx + 1 })
						"
						:placeholder="t('dossiq', 'Condition description')"
						type="text" />
					<NcSelect
						v-model="voorwaarde.priority"
						:options="priorityOptions"
						:aria-label-combobox="
							t('dossiq', 'Priority condition {n}', { n: idx + 1 })
						"
						label="label"
						:reduce="(opt) => opt.value"
						class="consultation-response-form__condition-priority"
						:placeholder="t('dossiq', 'Priority')" />
					<NcButton
						type="tertiary"
						:title="t('dossiq', 'Remove condition')"
						@click="removeVoorwaarde(idx)">
						✕
					</NcButton>
				</div>
				<NcButton @click="addVoorwaarde">
					{{ t('dossiq', 'Add condition') }}
				</NcButton>
			</div>

			<div class="consultation-response-form__field">
				<label
					class="consultation-response-form__label"
					for="consultation-response-datum">
					{{ t('dossiq', 'Advice date') }} *
				</label>
				<input
					id="consultation-response-datum"
					v-model="form.date"
					type="date"
					class="consultation-response-form__date-input"
					:max="today" />
			</div>

			<NcNoteCard v-if="validationError" type="error">
				{{ validationError }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="submitting" @click="onClose">
				{{ t('dossiq', 'Annuleren') }}
			</NcButton>
			<NcButton type="primary" :disabled="!canSubmit" @click="onSubmit">
				{{
					submitting
						? t('dossiq', 'Bezig...')
						: t('dossiq', 'Submit advice')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'

export default {
	name: 'ConsultationResponseForm',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		consultationId: {
			type: String,
			required: true,
		},

		consultationSubject: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'submitted'],
	data() {
		return {
			submitting: false,
			validationError: '',
			form: {
				advies: null,
				notes: '',
				terms: [],
				date: '',
			},

			adviesOptions: [
				{ label: this.t('dossiq', 'Positive'), value: 'positive' },
				{
					label: this.t('dossiq', 'Positive with conditions'),
					value: 'positief_with_terms',
				},
				{ label: this.t('dossiq', 'Negative'), value: 'negative' },
				{
					label: this.t('dossiq', 'Not applicable'),
					value: 'non_from_application',
				},
			],

			priorityOptions: [
				{ label: this.t('dossiq', 'High'), value: 'high' },
				{ label: this.t('dossiq', 'Normal'), value: 'normal' },
				{ label: this.t('dossiq', 'Low'), value: 'low' },
			],
		}
	},

	computed: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		today() {
			return new Date().toISOString().slice(0, 10)
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		showVoorwaarden() {
			return this.form.advies === 'positief_with_terms'
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		showToelichting() {
			return this.form.advies !== null
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		toelichtingRequired() {
			return this.form.advies !== 'non_from_application'
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		canSubmit() {
			if (this.submitting) return false
			if (!this.form.advies) return false
			if (this.toelichtingRequired && this.form.notes.trim() === '')
				return false
			if (!this.form.date) return false
			return true
		},
	},

	watch: {
		/**
		 * @param {string|number|boolean|object} value The new value.
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05
		 */
		open(value) {
			if (value) {
				this.validationError = ''
				this.submitting = false
				this.form = {
					advies: null,
					notes: '',
					terms: [],
					date: this.today,
				}
			}
		},
	},

	methods: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		addVoorwaarde() {
			this.form.terms.push({ description: '', priority: 'normal' })
		},

		/**
		 * @param {number} idx The index.
		 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05
		 */
		removeVoorwaarde(idx) {
			this.form.terms.splice(idx, 1)
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		validate() {
			if (!this.form.advies) {
				this.validationError = this.t('dossiq', 'Select an advice type.')
				return false
			}
			if (this.toelichtingRequired && this.form.notes.trim() === '') {
				this.validationError = this.t(
					'dossiq',
					'Explanation is required for this advice type.',
				)
				return false
			}
			if (!this.form.date) {
				this.validationError = this.t('dossiq', 'Date is required.')
				return false
			}
			return true
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		onSubmit() {
			this.validationError = ''
			if (!this.validate()) return
			this.submitting = true
			this.$emit('submitted', {
				consultationId: this.consultationId,
				advies: this.form.advies,
				notes: this.form.notes.trim(),
				terms: this.showVoorwaarden ? [...this.form.terms] : [],
				date: this.form.date,
			})
			this.submitting = false
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		onClose() {
			if (this.submitting) return
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.consultation-response-form {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 12px 0;
}

.consultation-response-form__subject {
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 0.9em;
}

.consultation-response-form__subject-label {
	font-weight: 600;
	margin-right: 4px;
}

.consultation-response-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.consultation-response-form__label {
	font-weight: 600;
	font-size: 0.9em;
}

.consultation-response-form__textarea {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	resize: vertical;
	font-size: 14px;
	font-family: inherit;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.consultation-response-form__date-input {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.consultation-response-form__condition-row {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 6px;
}

.consultation-response-form__condition-input {
	flex: 1;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.consultation-response-form__condition-priority {
	width: 140px;
	flex-shrink: 0;
}
</style>
