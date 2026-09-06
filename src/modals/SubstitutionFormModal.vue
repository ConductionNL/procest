<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Substitution (vervanging/waarneming) create modal — isolated NcDialog form
  per ADR-004 (modal isolation). Lets a handler register their own waarnemer,
  or (when allowCoordinator is true) a coordinator register on behalf of an
  absent handler. The backend enforces all authorisation.

  @spec openspec/specs/handler-vervanging-waarneming/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Register substitution')"
		:open="true"
		size="normal"
		@update:open="onDialogClose"
		@closing="$emit('close')">
		<div class="substitution-form">
			<!-- Absentee (coordinator only; self otherwise) -->
			<div v-if="allowCoordinator" class="form-group">
				<NcTextField
					v-model="form.absentee"
					:label="t('dossiq', 'Absent handler (user id)')"
					:placeholder="t('dossiq', 'Handler being covered…')" />
			</div>

			<div class="form-group">
				<NcTextField
					v-model="form.substitute"
					:label="t('dossiq', 'Substitute (user id)')"
					:placeholder="t('dossiq', 'Waarnemer who covers the work…')"
					:error="!!errors.substitute" />
				<p v-if="errors.substitute" class="form-error" role="alert">
					{{ errors.substitute }}
				</p>
			</div>

			<div class="form-row">
				<div class="form-group">
					<label for="sub-start">{{ t('dossiq', 'Start date') }} *</label>
					<input
						id="sub-start"
						v-model="form.startDate"
						type="date"
						class="substitution-form__date" />
				</div>
				<div class="form-group">
					<label for="sub-end">{{ t('dossiq', 'End date') }} *</label>
					<input
						id="sub-end"
						v-model="form.endDate"
						type="date"
						class="substitution-form__date" />
				</div>
			</div>

			<div class="form-group">
				<NcSelect
					v-model="selectedReason"
					:options="reasonOptions"
					:inputLabel="t('dossiq', 'Reason')"
					:aria-label-combobox="t('dossiq', 'Reason')"
					label="label"
					trackBy="value" />
			</div>

			<div class="form-group">
				<NcSelect
					v-model="selectedScope"
					:options="scopeOptions"
					:inputLabel="t('dossiq', 'Scope')"
					:aria-label-combobox="t('dossiq', 'Scope')"
					label="label"
					trackBy="value" />
			</div>

			<div
				v-if="selectedScope && selectedScope.value === 'caseTypes'"
				class="form-group">
				<NcSelect
					v-model="selectedCaseTypes"
					:options="caseTypes"
					:multiple="true"
					:inputLabel="t('dossiq', 'Case types')"
					:aria-label-combobox="t('dossiq', 'Case types')"
					label="title"
					trackBy="id" />
			</div>

			<div class="form-group">
				<label for="sub-comment">{{ t('dossiq', 'Comment') }}</label>
				<textarea
					id="sub-comment"
					v-model="form.comment"
					class="substitution-form__textarea"
					rows="2" />
			</div>

			<p v-if="serverError" class="form-error form-error--server" role="alert">
				{{ serverError }}
			</p>
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving" @click="submit">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('dossiq', 'Register substitution') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { createSubstitution } from '../services/substitutionApi.js'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'SubstitutionFormModal',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	props: {
		/** When true the absentee field is shown (coordinator acts for others). */
		allowCoordinator: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['created', 'close'],
	data() {
		return {
			form: {
				absentee: '',
				substitute: '',
				startDate: '',
				endDate: '',
				comment: '',
			},

			selectedReason: { value: 'verlof', label: t('dossiq', 'Leave') },
			selectedScope: { value: 'all', label: t('dossiq', 'All work') },
			selectedCaseTypes: [],
			caseTypes: [],
			errors: {},
			saving: false,
			serverError: '',
		}
	},

	computed: {
		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		reasonOptions() {
			return [
				{ value: 'verlof', label: t('dossiq', 'Leave') },
				{ value: 'ziekte', label: t('dossiq', 'Illness') },
				{ value: 'anders', label: t('dossiq', 'Other') },
			]
		},

		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		scopeOptions() {
			return [
				{ value: 'all', label: t('dossiq', 'All work') },
				{ value: 'caseTypes', label: t('dossiq', 'Specific case types') },
			]
		},
	},

	async mounted() {
		await this.loadCaseTypes()
	},

	methods: {
		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		async loadCaseTypes() {
			try {
				const results = await this.objectStore.fetchCollection('caseType', {
					_limit: 200,
				})
				this.caseTypes = Array.isArray(results) ? results : []
			} catch (err) {
				this.caseTypes = []
			}
		},

		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		validate() {
			const errs = {}
			if (!this.form.substitute || !this.form.substitute.trim()) {
				errs.substitute = t('dossiq', 'A substitute is required')
			}
			this.errors = errs
			return Object.keys(errs).length === 0
		},

		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		async submit() {
			this.serverError = ''
			if (!this.validate()) {
				return
			}
			this.saving = true
			try {
				const scope = this.selectedScope ? this.selectedScope.value : 'all'
				const payload = {
					substitute: this.form.substitute.trim(),
					startDate: this.form.startDate,
					endDate: this.form.endDate,
					reason: this.selectedReason
						? this.selectedReason.value
						: 'verlof',

					scope,
					scopeRefs:
						scope === 'caseTypes'
							? this.selectedCaseTypes.map((ct) => ct.id)
							: [],

					comment: this.form.comment,
				}
				if (this.allowCoordinator && this.form.absentee.trim()) {
					payload.absentee = this.form.absentee.trim()
				}
				const created = await createSubstitution(payload)
				this.$emit('created', created)
			} catch (err) {
				this.serverError =
					err?.response?.data?.error
					|| err?.message
					|| t('dossiq', 'Failed to register substitution.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param {boolean} open Whether the open is set.
		 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
		 */
		onDialogClose(open) {
			if (!open) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.substitution-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
}

.form-row {
	display: flex;
	gap: 12px;
}

.form-row .form-group {
	flex: 1;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.form-group label {
	font-weight: 500;
	color: var(--color-main-text);
}

.substitution-form__date {
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.substitution-form__textarea {
	width: 100%;
	resize: vertical;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
}

.form-error {
	color: var(--color-error);
	font-size: 0.85rem;
	margin: 0;
}

.form-error--server {
	padding: 8px;
	background: var(--color-error-hover);
	border-radius: var(--border-radius);
}
</style>
