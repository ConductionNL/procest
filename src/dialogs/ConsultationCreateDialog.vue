<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcDialog
		v-if="open"
		:name="t('dossiq', 'New consultation')"
		size="normal"
		:canClose="!submitting"
		@closing="onClose">
		<div class="consultation-create-dialog">
			<!-- Read-only parent case display -->
			<div class="consultation-create-dialog__field">
				<label class="consultation-create-dialog__label">
					{{ t('dossiq', 'Case') }}
				</label>
				<span class="consultation-create-dialog__readonly">{{
					caseId
				}}</span>
			</div>

			<div class="consultation-create-dialog__field">
				<NcTextField
					:modelValue="form.adviceAuthority"
					:label="t('dossiq', 'Advisory body')"
					:placeholder="
						t('dossiq', 'e.g. Fire brigade, Aesthetics committee')
					"
					required
					@update:modelValue="(v) => (form.adviceAuthority = v)" />
			</div>

			<div class="consultation-create-dialog__field">
				<NcTextField
					:modelValue="form.subject"
					:label="t('dossiq', 'Onderwerp')"
					required
					@update:modelValue="(v) => (form.subject = v)" />
			</div>

			<div class="consultation-create-dialog__field">
				<label
					class="consultation-create-dialog__label"
					for="consultation-create-question">
					{{ t('dossiq', 'Question') }} *
				</label>
				<textarea
					id="consultation-create-question"
					v-model="form.question_formulation"
					class="consultation-create-dialog__textarea"
					rows="4" />
			</div>

			<div class="consultation-create-dialog__field">
				<label
					class="consultation-create-dialog__label"
					for="consultation-create-response-date">
					{{ t('dossiq', 'Latest response date') }} *
				</label>
				<input
					id="consultation-create-response-date"
					v-model="form.latestResponseDate"
					type="date"
					class="consultation-create-dialog__date-input"
					:min="today" />
			</div>

			<div class="consultation-create-dialog__field">
				<label class="consultation-create-dialog__label">
					{{ t('dossiq', 'Priority') }}
				</label>
				<NcSelect
					v-model="form.priority"
					:options="prioriteitOptions"
					:aria-label-combobox="t('dossiq', 'Priority')"
					label="label"
					:reduce="(opt) => opt.value"
					:placeholder="t('dossiq', 'Select priority')" />
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
						: t('dossiq', 'Create consultation')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

export default {
	name: 'ConsultationCreateDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		caseId: {
			type: String,
			required: true,
		},

		parentZaakTitle: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'created'],
	data() {
		return {
			submitting: false,
			validationError: '',
			form: {
				adviceAuthority: '',
				subject: '',
				question_formulation: '',
				latestResponseDate: '',
				priority: 'normal',
			},

			prioriteitOptions: [
				{ label: this.t('dossiq', 'Normal'), value: 'normal' },
				{ label: this.t('dossiq', 'Urgent'), value: 'spoed' },
			],
		}
	},

	computed: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		today() {
			return new Date().toISOString().slice(0, 10)
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		defaultDeadline() {
			const d = new Date()
			d.setDate(d.getDate() + 28)
			return d.toISOString().slice(0, 10)
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		canSubmit() {
			return (
				!this.submitting
				&& this.form.adviceAuthority.trim() !== ''
				&& this.form.subject.trim() !== ''
				&& this.form.question_formulation.trim() !== ''
				&& this.form.latestResponseDate !== ''
			)
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
					adviceAuthority: '',
					subject: this.parentZaakTitle,
					question_formulation: '',
					latestResponseDate: this.defaultDeadline,
					priority: 'normal',
				}
			}
		},
	},

	methods: {
		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		validate() {
			if (this.form.adviceAuthority.trim() === '') {
				this.validationError = this.t('dossiq', 'Advisory body is required.')
				return false
			}
			if (this.form.subject.trim() === '') {
				this.validationError = this.t('dossiq', 'Subject is required.')
				return false
			}
			if (this.form.question_formulation.trim() === '') {
				this.validationError = this.t('dossiq', 'Question is required.')
				return false
			}
			if (this.form.latestResponseDate === '') {
				this.validationError = this.t(
					'dossiq',
					'Latest response date is required.',
				)
				return false
			}
			return true
		},

		/** @spec openspec/changes/consultation-management/tasks.md#TASK-CN-05 */
		onSubmit() {
			this.validationError = ''
			if (!this.validate()) return
			this.submitting = true
			this.$emit('created', {
				parentCase: this.caseId,
				adviceAuthority: this.form.adviceAuthority.trim(),
				subject: this.form.subject.trim(),
				question_formulation: this.form.question_formulation.trim(),
				latestResponseDate: this.form.latestResponseDate,
				priority: this.form.priority,
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
.consultation-create-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px 0;
}

.consultation-create-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.consultation-create-dialog__label {
	font-weight: 600;
	font-size: 0.9em;
}

.consultation-create-dialog__readonly {
	padding: 6px 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.consultation-create-dialog__textarea {
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

.consultation-create-dialog__date-input {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	font-size: 14px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}
</style>
