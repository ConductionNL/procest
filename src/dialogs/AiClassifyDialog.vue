<template>
	<NcDialog
		v-if="show"
		:name="t('dossiq', 'AI Document Classification')"
		size="normal"
		@close="$emit('close')">
		<div class="ai-classify-dialog">
			<NcLoadingIcon v-if="loading" :size="32" />

			<div v-else-if="error" class="ai-classify-dialog__error">
				<NcNoteCard type="error">
					{{ error }}
				</NcNoteCard>
			</div>

			<div v-else-if="result" class="ai-classify-dialog__result">
				<div class="ai-classify-dialog__header">
					<AiConfidenceBadge
						:confidence="result.confidence || 0"
						size="medium" />
				</div>

				<div class="form-group">
					<label for="ai-classify-document-type">{{
						t('dossiq', 'Suggested document type')
					}}</label>
					<NcTextField
						id="ai-classify-document-type"
						:modelValue="modifiedType"
						@update:modelValue="(v) => (modifiedType = v)" />
				</div>

				<div v-if="result.metadata" class="ai-classify-dialog__metadata">
					<h4>{{ t('dossiq', 'Extracted metadata') }}</h4>
					<div
						v-for="(value, key) in result.metadata"
						:key="key"
						class="form-group">
						<label :for="`ai-classify-metadata-${key}`">{{ key }}</label>
						<NcTextField
							:id="`ai-classify-metadata-${key}`"
							:modelValue="modifiedMetadata[key] || value"
							@update:modelValue="
								(v) => (modifiedMetadata[key] = v)
							" />
					</div>
				</div>

				<div class="ai-classify-dialog__actions">
					<NcButton type="primary" @click="apply">
						{{ t('dossiq', 'Apply classification') }}
					</NcButton>
					<NcButton type="error" @click="reject">
						{{ t('dossiq', 'Reject') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import AiConfidenceBadge from '../views/cases/components/AiConfidenceBadge.vue'
import { classifyDocument } from '../services/aiApi.js'

export default {
	name: 'AiClassifyDialog',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcLoadingIcon,
		NcNoteCard,
		AiConfidenceBadge,
	},

	props: {
		caseId: { type: String, required: true },
		documentId: { type: String, required: true },
		show: { type: Boolean, default: false },
	},

	emits: ['close', 'applied'],
	data() {
		return {
			loading: false,
			error: null,
			result: null,
			modifiedType: '',
			modifiedMetadata: {},
		}
	},

	watch: {
		/**
		 * @param {string|number|boolean|object} val The new value.
		 * @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md
		 */
		show(val) {
			if (val) this.classify()
		},
	},

	methods: {
		t,
		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		async classify() {
			this.loading = true
			this.error = null
			try {
				const response = await classifyDocument(this.caseId, this.documentId)
				this.result = response.result || response
				this.modifiedType = this.result.documentType || ''
				this.modifiedMetadata = { ...(this.result.metadata || {}) }
			} catch (e) {
				this.error =
					e.response?.data?.error || t('dossiq', 'Classification failed')
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		apply() {
			this.$emit('applied', {
				documentType: this.modifiedType,
				metadata: this.modifiedMetadata,
				confidence: this.result.confidence,
			})
			this.$emit('close')
		},

		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		reject() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.ai-classify-dialog__actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}

.ai-classify-dialog__metadata {
	margin-top: 16px;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}
</style>
