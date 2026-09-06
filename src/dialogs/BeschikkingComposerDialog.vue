<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<NcDialog
		v-if="open"
		:name="t('dossiq', 'Beschikking opstellen')"
		size="large"
		:canClose="!submitting"
		@closing="onClose">
		<div class="beschikking-composer">
			<div v-if="!composed" class="beschikking-composer__form">
				<div class="beschikking-composer__field">
					<NcSelect
						v-model="templateId"
						:options="templateOptions"
						:inputLabel="t('dossiq', 'Sjabloon')"
						label="label"
						:reduce="(opt) => opt.value"
						:placeholder="t('dossiq', 'Select a template')" />
				</div>
				<div class="beschikking-composer__field">
					<NcTextField
						:modelValue="geadresseerdeNaam"
						:label="t('dossiq', 'Geadresseerde')"
						@update:modelValue="(v) => (geadresseerdeNaam = v)" />
				</div>
				<div class="beschikking-composer__field">
					<NcTextArea
						:modelValue="rationale"
						:label="t('dossiq', 'Motivering')"
						@update:modelValue="(v) => (rationale = v)" />
				</div>
				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>
			</div>

			<div v-else class="beschikking-composer__preview">
				<NcNoteCard type="success">
					{{ t('dossiq', 'The decision has been composed as a draft.') }}
				</NcNoteCard>
				<dl class="beschikking-composer__meta">
					<dt>{{ t('dossiq', 'Kenmerk') }}</dt>
					<dd>{{ composed.reference || '—' }}</dd>
					<dt>{{ t('dossiq', 'Sjabloon') }}</dt>
					<dd>{{ composed.templateId }}</dd>
					<dt>{{ t('dossiq', 'Status') }}</dt>
					<dd>{{ composed.currentStatus }}</dd>
				</dl>
				<NcNoteCard v-if="composed.motivering_required" type="warning">
					{{
						t(
							'dossiq',
							'The rationale is still missing and is required.',
						)
					}}
				</NcNoteCard>
				<NcNoteCard v-if="composed.geadresseerde_required" type="warning">
					{{
						t(
							'dossiq',
							'The addressee is still missing and is required.',
						)
					}}
				</NcNoteCard>
			</div>
		</div>

		<template #actions>
			<NcButton :disabled="submitting" @click="onClose">
				{{ t('dossiq', 'Annuleren') }}
			</NcButton>
			<NcButton
				v-if="!composed"
				type="primary"
				:disabled="submitting"
				@click="onCompose">
				{{ t('dossiq', 'Opstellen') }}
			</NcButton>
			<NcButton v-else type="primary" @click="onDone">
				{{ t('dossiq', 'Klaar') }}
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
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { compose } from '../services/beschikkingApi.js'

export default {
	name: 'BeschikkingComposerDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextArea,
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

		templateOptions: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close', 'composed'],
	data() {
		return {
			templateId: null,
			geadresseerdeNaam: '',
			rationale: '',
			composed: null,
			submitting: false,
			error: '',
		}
	},

	methods: {
		/**
		 * Compose a concept beschikking from the case data plus overrides.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/beschikking-generatie/spec.md#requirement-conceptbeschikking-vanuit-zaakgegevens-samenstellen-req-bes-001
		 */
		async onCompose() {
			this.submitting = true
			this.error = ''
			try {
				const overrides = {}
				if (this.geadresseerdeNaam) {
					overrides.addressee = { name: this.geadresseerdeNaam }
				}
				if (this.rationale) {
					overrides.rationale = this.rationale
				}
				this.composed = await compose(
					this.caseId,
					this.templateId,
					overrides,
				)
				this.$emit('composed', this.composed)
			} catch (e) {
				this.error = t('dossiq', 'The decision could not be drafted.')
			} finally {
				this.submitting = false
			}
		},

		onDone() {
			this.$emit('composed', this.composed)
			this.onClose()
		},

		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.beschikking-composer__field {
	margin-block-end: 12px;
}

.beschikking-composer__meta {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin-block-start: 12px;
}

.beschikking-composer__meta dt {
	font-weight: bold;
}
</style>
