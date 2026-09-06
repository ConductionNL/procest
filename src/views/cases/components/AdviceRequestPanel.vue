<template>
	<div class="advice-request">
		<h4 class="advice-request__title">
			{{ t('dossiq', 'Advice Requests') }}
		</h4>

		<!-- Existing requests -->
		<div v-if="requests.length > 0" class="advice-request__list">
			<div
				v-for="req in requests"
				:key="req.id"
				class="advice-request__item"
				:class="{ 'advice-request__item--overdue': isOverdue(req) }">
				<div class="advice-request__item-header">
					<span class="advice-request__department">{{
						req.department
					}}</span>
					<span
						class="advice-request__status"
						:class="'advice-request__status--' + req.status">
						{{ getStatusLabel(req.status) }}
					</span>
				</div>
				<p class="advice-request__subject">
					{{ req.subject }}
				</p>
				<div class="advice-request__meta">
					<span>{{
						t('dossiq', 'Deadline: {date}', {
							date: formatDate(req.deadline),
						})
					}}</span>
					<span v-if="req.response">
						{{
							t('dossiq', 'Response: {type}', {
								type: getResponseLabel(req.response),
							})
						}}
					</span>
				</div>
			</div>
		</div>

		<div v-else class="advice-request__empty">
			{{ t('dossiq', 'No advice requests yet.') }}
		</div>

		<!-- New request form -->
		<div v-if="showForm" class="advice-request__form">
			<div class="form-group">
				<label for="advice-request-department"
					>{{ t('dossiq', 'Department / Organization') }} *</label
				>
				<NcTextField
					id="advice-request-department"
					:modelValue="form.department"
					:placeholder="t('dossiq', 'e.g., Brandweer, Welstandscommissie')"
					@update:modelValue="(v) => (form.department = v)" />
			</div>
			<div class="form-group">
				<label for="advice-request-subject"
					>{{ t('dossiq', 'Subject') }} *</label
				>
				<NcTextField
					id="advice-request-subject"
					:modelValue="form.subject"
					@update:modelValue="(v) => (form.subject = v)" />
			</div>
			<div class="form-group">
				<label for="advice-request-question">{{
					t('dossiq', 'Question')
				}}</label>
				<textarea
					id="advice-request-question"
					v-model="form.question"
					rows="3" />
			</div>
			<div class="form-group">
				<label for="advice-request-deadline"
					>{{ t('dossiq', 'Deadline') }} *</label
				>
				<NcTextField
					id="advice-request-deadline"
					:modelValue="form.deadline"
					type="date"
					@update:modelValue="(v) => (form.deadline = v)" />
			</div>
			<div class="advice-request__form-actions">
				<NcButton @click="showForm = false">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!isFormValid"
					@click="submitRequest">
					{{ t('dossiq', 'Send Request') }}
				</NcButton>
			</div>
		</div>

		<NcButton v-if="!showForm && !isReadOnly" @click="showForm = true">
			{{ t('dossiq', 'Request Advice') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'

export default {
	name: 'AdviceRequestPanel',
	components: {
		NcButton,
		NcTextField,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},

		requests: {
			type: Array,
			default: () => [],
		},

		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['create'],

	data() {
		return {
			showForm: false,
			form: {
				department: '',
				subject: '',
				question: '',
				deadline: '',
			},
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md */
		isFormValid() {
			return (
				this.form.department.trim() !== ''
				&& this.form.subject.trim() !== ''
				&& this.form.deadline !== ''
			)
		},
	},

	methods: {
		/**
		 * @param {string} status The status.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		getStatusLabel(status) {
			const labels = {
				open: this.t('dossiq', 'Open'),
				in_handling: this.t('dossiq', 'In progress'),
				advice_uitgebracht: this.t('dossiq', 'Advice received'),
				closed: this.t('dossiq', 'Closed'),
			}
			return labels[status] || status
		},

		/**
		 * @param {object} response The response.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		getResponseLabel(response) {
			const labels = {
				positive: this.t('dossiq', 'Positive'),
				positief_with_terms: this.t('dossiq', 'Positive with conditions'),

				negative: this.t('dossiq', 'Negative'),
				non_from_application: this.t('dossiq', 'Not applicable'),
			}
			return labels[response] || response
		},

		/**
		 * @param {string} dateStr The date str, as a string.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		formatDate(dateStr) {
			if (!dateStr) return '---'
			const date = new Date(dateStr)
			if (isNaN(date.getTime())) return dateStr
			return date.toLocaleDateString('nl-NL')
		},

		/**
		 * @param {object} req The req.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		isOverdue(req) {
			if (
				!req.deadline
				|| req.status === 'closed'
				|| req.status === 'advice_uitgebracht'
			) {
				return false
			}
			return new Date(req.deadline) < new Date()
		},

		/** @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md */
		submitRequest() {
			this.$emit('create', {
				caseId: this.caseId,
				...this.form,
				status: 'open',
			})
			this.form = { department: '', subject: '', question: '', deadline: '' }
			this.showForm = false
		},
	},
}
</script>

<style scoped>
.advice-request__title {
	margin-bottom: 12px;
}

.advice-request__empty {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}

.advice-request__item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 8px;
}

.advice-request__item--overdue {
	border-color: var(--color-error);
}

.advice-request__item-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 4px;
}

.advice-request__department {
	font-weight: 600;
}

.advice-request__status {
	font-size: 0.75rem;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
}

.advice-request__status--open {
	background: var(--color-background-dark);
}

.advice-request__status--in_behandeling {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.advice-request__status--advies_uitgebracht {
	background: var(--color-success-light, #e8f5e9);
	color: var(--color-success, #2e7d32);
}

.advice-request__status--afgesloten {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.advice-request__subject {
	margin: 4px 0;
}

.advice-request__meta {
	display: flex;
	gap: 16px;
	font-size: 0.8125rem;
	color: var(--color-text-maxcontrast);
}

.advice-request__form {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
	margin-bottom: 12px;
}

.advice-request__form-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	font-weight: 600;
	font-size: 0.875rem;
	margin-bottom: 4px;
}
</style>
