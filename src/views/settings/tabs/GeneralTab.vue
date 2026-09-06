<template>
	<div class="general-tab">
		<!-- Title -->
		<div class="form-group">
			<label class="required" for="general-tab-title">{{
				t('dossiq', 'Title')
			}}</label>
			<NcTextField
				id="general-tab-title"
				:modelValue="form.title"
				:error="!!errors.title"
				:helperText="errors.title"
				@update:modelValue="(v) => $emit('update', 'title', v)" />
		</div>

		<!-- Description -->
		<div class="form-group">
			<label for="general-tab-description">{{
				t('dossiq', 'Description')
			}}</label>
			<textarea
				id="general-tab-description"
				class="general-tab__textarea"
				:value="form.description"
				@input="$emit('update', 'description', $event.target.value)" />
		</div>

		<!-- Purpose -->
		<div class="form-group">
			<label class="required" for="general-tab-purpose">{{
				t('dossiq', 'Purpose')
			}}</label>
			<NcTextField
				id="general-tab-purpose"
				:modelValue="form.purpose"
				:error="!!errors.purpose"
				:helperText="errors.purpose"
				@update:modelValue="(v) => $emit('update', 'purpose', v)" />
		</div>

		<!-- Trigger -->
		<div class="form-group">
			<label class="required" for="general-tab-trigger">{{
				t('dossiq', 'Trigger')
			}}</label>
			<NcTextField
				id="general-tab-trigger"
				:modelValue="form.trigger"
				:error="!!errors.trigger"
				:helperText="errors.trigger"
				@update:modelValue="(v) => $emit('update', 'trigger', v)" />
		</div>

		<!-- Subject -->
		<div class="form-group">
			<label class="required" for="general-tab-subject">{{
				t('dossiq', 'Subject')
			}}</label>
			<NcTextField
				id="general-tab-subject"
				:modelValue="form.subject"
				:error="!!errors.subject"
				:helperText="errors.subject"
				@update:modelValue="(v) => $emit('update', 'subject', v)" />
		</div>

		<!-- Initiator Action -->
		<div class="form-group">
			<label for="general-tab-initiator-action">{{
				t('dossiq', 'Initiator action')
			}}</label>
			<NcTextField
				id="general-tab-initiator-action"
				:modelValue="form.initiatorAction"
				@update:modelValue="(v) => $emit('update', 'initiatorAction', v)" />
		</div>

		<!-- Handler Action -->
		<div class="form-group">
			<label for="general-tab-handler-action">{{
				t('dossiq', 'Handler action')
			}}</label>
			<NcTextField
				id="general-tab-handler-action"
				:modelValue="form.handlerAction"
				@update:modelValue="(v) => $emit('update', 'handlerAction', v)" />
		</div>

		<!-- Origin -->
		<div class="form-group">
			<label class="required">{{ t('dossiq', 'Origin') }}</label>
			<NcSelect
				:modelValue="selectedOrigin"
				:options="originOptions"
				:aria-label-combobox="t('dossiq', 'Origin')"
				@update:modelValue="
					(v) => $emit('update', 'origin', v ? v.id : '')
				" />
			<span v-if="errors.origin" class="field-error">{{ errors.origin }}</span>
		</div>

		<!-- Processing Deadline -->
		<div class="form-group">
			<label class="required">{{ t('dossiq', 'Processing deadline') }}</label>
			<DurationPicker
				:value="form.processingDeadline"
				presetType="deadline"
				@input="(v) => $emit('update', 'processingDeadline', v)" />
			<span v-if="errors.processingDeadline" class="field-error">{{
				errors.processingDeadline
			}}</span>
		</div>

		<!-- Service Target -->
		<div class="form-group">
			<label>{{ t('dossiq', 'Service target') }}</label>
			<DurationPicker
				:value="form.serviceTarget"
				presetType="deadline"
				@input="(v) => $emit('update', 'serviceTarget', v)" />
			<span v-if="errors.serviceTarget" class="field-error">{{
				errors.serviceTarget
			}}</span>
		</div>

		<!-- Extension Allowed -->
		<div class="form-group form-group--inline">
			<NcCheckboxRadioSwitch
				:modelValue="form.extensionAllowed"
				@update:modelValue="(v) => $emit('update', 'extensionAllowed', v)">
				{{ t('dossiq', 'Extension allowed') }}
			</NcCheckboxRadioSwitch>
		</div>

		<!-- Extension Period (conditional) -->
		<div v-if="form.extensionAllowed" class="form-group">
			<label class="required">{{ t('dossiq', 'Extension period') }}</label>
			<DurationPicker
				:value="form.extensionPeriod"
				presetType="extension"
				@input="(v) => $emit('update', 'extensionPeriod', v)" />
			<span v-if="errors.extensionPeriod" class="field-error">{{
				errors.extensionPeriod
			}}</span>
		</div>

		<!-- Confidentiality -->
		<div class="form-group">
			<label class="required">{{ t('dossiq', 'Confidentiality') }}</label>
			<NcSelect
				:modelValue="selectedConfidentiality"
				:options="confidentialityOptions"
				:aria-label-combobox="t('dossiq', 'Confidentiality')"
				@update:modelValue="
					(v) => $emit('update', 'confidentiality', v ? v.id : '')
				" />
			<span v-if="errors.confidentiality" class="field-error">{{
				errors.confidentiality
			}}</span>
		</div>

		<!-- IV3 taakveld -->
		<div class="form-group">
			<label>{{ t('dossiq', 'IV3 taakveld') }}</label>
			<NcSelect
				:modelValue="selectedIv3Taakveld"
				:options="iv3TaakveldOptions"
				:loading="iv3TaakveldenLoading"
				:placeholder="t('dossiq', 'No IV3 classification')"
				:aria-label-combobox="t('dossiq', 'IV3 taakveld')"
				@update:modelValue="
					(v) => $emit('update', 'iv3TaskField', v ? v.id : '')
				" />
			<p class="general-tab__hint">
				{{
					t(
						'dossiq',
						'Classifies cases of this type for the quarterly IV3 (Informatie voor Derden) cost report to CBS. Leave empty if this case type has no taakveld — such cases are reported as uncategorized.',
					)
				}}
			</p>
		</div>

		<!-- Publication Required -->
		<div class="form-group form-group--inline">
			<NcCheckboxRadioSwitch
				:modelValue="form.publicationRequired"
				@update:modelValue="
					(v) => $emit('update', 'publicationRequired', v)
				">
				{{ t('dossiq', 'Publication required') }}
			</NcCheckboxRadioSwitch>
		</div>

		<!-- Publication Text (conditional) -->
		<div v-if="form.publicationRequired" class="form-group">
			<label for="general-tab-publication-text">{{
				t('dossiq', 'Publication text')
			}}</label>
			<textarea
				id="general-tab-publication-text"
				class="general-tab__textarea"
				:value="form.publicationText"
				@input="$emit('update', 'publicationText', $event.target.value)" />
		</div>

		<!-- Responsible Unit -->
		<div class="form-group">
			<label class="required" for="general-tab-responsible-unit">{{
				t('dossiq', 'Responsible unit')
			}}</label>
			<NcTextField
				id="general-tab-responsible-unit"
				:modelValue="form.responsibleUnit"
				:error="!!errors.responsibleUnit"
				:helperText="errors.responsibleUnit"
				@update:modelValue="(v) => $emit('update', 'responsibleUnit', v)" />
		</div>

		<!-- Reference Process -->
		<div class="form-group">
			<label for="general-tab-reference-process">{{
				t('dossiq', 'Reference process')
			}}</label>
			<NcTextField
				id="general-tab-reference-process"
				:modelValue="form.referenceProcess"
				@update:modelValue="(v) => $emit('update', 'referenceProcess', v)" />
		</div>

		<!-- Keywords -->
		<div class="form-group">
			<label for="general-tab-keywords">{{ t('dossiq', 'Keywords') }}</label>
			<NcTextField
				id="general-tab-keywords"
				:modelValue="form.keywords"
				:placeholder="t('dossiq', 'Comma-separated keywords')"
				@update:modelValue="(v) => $emit('update', 'keywords', v)" />
		</div>

		<!-- Valid From -->
		<div class="form-group">
			<label class="required" for="general-tab-valid-from">{{
				t('dossiq', 'Valid from')
			}}</label>
			<input
				id="general-tab-valid-from"
				type="date"
				class="general-tab__date"
				:value="form.validFrom"
				@input="$emit('update', 'validFrom', $event.target.value)" />
		</div>

		<!-- Valid Until -->
		<div class="form-group">
			<label for="general-tab-valid-until">{{
				t('dossiq', 'Valid until')
			}}</label>
			<input
				id="general-tab-valid-until"
				type="date"
				class="general-tab__date"
				:value="form.validUntil"
				@input="$emit('update', 'validUntil', $event.target.value)" />
			<span v-if="errors.validUntil" class="field-error">{{
				errors.validUntil
			}}</span>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import DurationPicker from '../components/DurationPicker.vue'
import {
	getConfidentialityOptions,
	getOriginOptions,
} from '../../../utils/caseTypeValidation.js'
import { formatDuration } from '../../../utils/durationHelpers.js'

export default {
	name: 'GeneralTab',
	components: {
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		DurationPicker,
	},

	props: {
		form: {
			type: Object,
			required: true,
		},

		errors: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update'],

	data() {
		return {
			iv3Taakvelden: [],
			iv3TaakveldenLoading: false,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		originOptions() {
			return getOriginOptions()
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		confidentialityOptions() {
			return getConfidentialityOptions()
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		selectedOrigin() {
			if (!this.form.origin) return null
			return this.originOptions.find((o) => o.id === this.form.origin) || null
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		selectedConfidentiality() {
			if (!this.form.confidentiality) return null
			return (
				this.confidentialityOptions.find(
					(o) => o.id === this.form.confidentiality,
				) || null
			)
		},

		/** @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#5.3 */
		iv3TaakveldOptions() {
			return this.iv3Taakvelden.map((tv) => ({
				id: tv.code,
				label: `${tv.code} — ${tv.label}`,
			}))
		},

		/** @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#5.3 */
		selectedIv3Taakveld() {
			if (!this.form.iv3TaskField) return null
			return (
				this.iv3TaakveldOptions.find((o) => o.id === this.form.iv3TaskField)
				|| null
			)
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		deadlinePreview() {
			return this.form.processingDeadline
				? formatDuration(this.form.processingDeadline)
				: ''
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		serviceTargetPreview() {
			return this.form.serviceTarget
				? formatDuration(this.form.serviceTarget)
				: ''
		},

		/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
		extensionPreview() {
			return this.form.extensionPeriod
				? formatDuration(this.form.extensionPeriod)
				: ''
		},
	},

	mounted() {
		this.loadIv3Taakvelden()
	},

	methods: {
		/**
		 * Load the IV3 taakveld reference list once, for the picker's options.
		 *
		 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#5.3
		 */
		async loadIv3Taakvelden() {
			this.iv3TaakveldenLoading = true
			try {
				const res = await axios.get(
					generateUrl('/apps/dossiq/api/reports/iv3/taakvelden'),
				)
				this.iv3Taakvelden = (res.data && res.data.taakvelden) || []
			} catch (e) {
				// Non-fatal: the picker just shows no options when this fails.
				this.iv3Taakvelden = []
			} finally {
				this.iv3TaakveldenLoading = false
			}
		},
	},
}
</script>

<style scoped>
.general-tab {
	max-width: 600px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group--inline {
	margin-bottom: 8px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 500;
}

.form-group label.required::after {
	content: ' *';
	color: var(--color-error);
}

.general-tab__textarea {
	width: 100%;
	min-height: 80px;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-family: inherit;
	font-size: inherit;
	resize: vertical;
}

.general-tab__textarea:focus {
	border-color: var(--color-primary);
	outline: none;
}

.general-tab__date {
	width: 100%;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-family: inherit;
	font-size: inherit;
}

.general-tab__date:focus {
	border-color: var(--color-primary);
	outline: none;
}

.field-error {
	display: block;
	color: var(--color-error);
	font-size: 12px;
	margin-top: 4px;
}

.general-tab__hint {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	margin-top: 4px;
}
</style>
