<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
  @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-redaction-proposals-require-explicit-human-review-before-any-hand-off
-->
<!--
  LLM-assisted redaction-span proposal review (woo-llm-anonymisation).

  ASSISTS the existing rule-based/Docudesk/manual redaction flow — never
  replaces it. Every proposal is human-reviewed here: the case worker sees
  the merged rule-floor + LLM-proposed spans, can deselect individual LLM
  spans (rule-floor spans are always applied and cannot be deselected — the
  UI mirrors the backend invariant), and must explicitly Approve or Reject
  before anything is handed to the redaction pipeline. Nothing here marks a
  document "anonymised" or publishes anything.
-->
<template>
	<NcDialog
		v-if="open"
		:name="t('dossiq', 'AI-assisted redaction suggestions')"
		size="large"
		:canClose="!submitting"
		@closing="$emit('close')">
		<div class="redaction-assist-dialog">
			<p class="redaction-assist-dialog__intro">
				{{
					t(
						'dossiq',
						'Paste or confirm the document text below, then request redaction suggestions. Rule-based matches (BSN, IBAN, phone, postcode) are always applied; AI-proposed spans can be reviewed and deselected before approval.',
					)
				}}
			</p>

			<div v-if="!proposal" class="redaction-assist-dialog__field">
				<label
					class="redaction-assist-dialog__label"
					for="redaction-assist-text">
					{{ t('dossiq', 'Document text') }}
				</label>
				<textarea
					id="redaction-assist-text"
					v-model="text"
					class="redaction-assist-dialog__textarea"
					:disabled="detecting"
					:placeholder="
						t(
							'dossiq',
							'Paste the document text to scan for redaction candidates…',
						)
					" />
				<span class="redaction-assist-dialog__hint">
					{{
						t('dossiq', '{count} / {max} characters', {
							count: text.length,
							max: maxLength,
						})
					}}
				</span>
			</div>

			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>

			<template v-if="proposal">
				<NcNoteCard v-if="proposal.source === 'rules_only'" type="info">
					{{
						t(
							'dossiq',
							'AI assist is currently unavailable — showing rule-based matches only.',
						)
					}}
				</NcNoteCard>
				<NcNoteCard
					v-else-if="proposal.source === 'rules_only_fallback'"
					type="warning">
					{{
						t(
							'dossiq',
							'AI-assisted detection failed ({error}) — falling back to rule-based matches only.',
							{ error: proposal.llmError || '' },
						)
					}}
				</NcNoteCard>

				<table
					v-if="proposal.spans.length > 0"
					class="redaction-assist-dialog__table">
					<thead>
						<tr>
							<th />
							<th scope="col">{{ t('dossiq', 'Category') }}</th>
							<th scope="col">{{ t('dossiq', 'Source') }}</th>
							<th scope="col">{{ t('dossiq', 'Preview') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(span, index) in proposal.spans" :key="index">
							<td>
								<input
									type="checkbox"
									:aria-label="
										t(
											'dossiq',
											'Select redaction candidate {category}',
											{ category: span.category },
										)
									"
									:checked="selections[index]"
									:disabled="!isToggleable(span)"
									@change="
										(e) => toggleSpan(index, e.target.checked)
									" />
							</td>
							<td>{{ span.category }}</td>
							<td>
								<span
									:class="
										'redaction-assist-dialog__badge redaction-assist-dialog__badge--'
										+ span.source
									">
									{{
										span.source === 'rule'
											? t('dossiq', 'Rule (always applied)')
											: t('dossiq', 'AI-proposed')
									}}
								</span>
							</td>
							<td class="redaction-assist-dialog__preview">
								{{ previewFor(span) }}
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="redaction-assist-dialog__empty">
					{{ t('dossiq', 'No redaction candidates found.') }}
				</p>
			</template>
		</div>

		<template #actions>
			<NcButton
				v-if="!proposal"
				type="primary"
				:disabled="!canDetect || detecting"
				@click="detect">
				{{
					detecting
						? t('dossiq', 'Scanning…')
						: t('dossiq', 'Detect redaction candidates')
				}}
			</NcButton>
			<template v-else>
				<NcButton type="secondary" :disabled="submitting" @click="reject">
					{{ t('dossiq', 'Reject') }}
				</NcButton>
				<NcButton type="primary" :disabled="submitting" @click="approve">
					{{
						submitting
							? t('dossiq', 'Applying…')
							: t('dossiq', 'Approve selected')
					}}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import {
	buildInitialSelections,
	buildSpanPreview,
	filterSelectedSpans,
	isSpanToggleable,
} from '../utils/redactionAssistHelpers.js'

export default {
	name: 'RedactionAssistDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
	},

	props: {
		// Defaults to true deliberately: nothing passes this, and the dialog is
		// mounted to be shown. Flipping the default to satisfy the rule would
		// change behaviour, not style, and the dialog would mount closed.
		open: {
			type: Boolean,
			// eslint-disable-next-line vue/no-boolean-default
			default: true,
		},

		caseId: {
			type: String,
			required: true,
		},

		documentRef: {
			type: String,
			required: true,
		},

		initialText: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'reviewed'],
	data() {
		return {
			text: this.initialText,
			maxLength: 12000,
			detecting: false,
			submitting: false,
			errorMessage: '',
			proposal: null,
			/** Per-span index → whether it is currently selected for approval. */
			selections: {},
		}
	},

	computed: {
		/** @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-redaction-proposals-require-explicit-human-review-before-any-hand-off */
		canDetect() {
			return this.text.trim().length > 0 && this.text.length <= this.maxLength
		},
	},

	methods: {
		/**
		 * Build a short, safe preview snippet for a span (see
		 * `buildSpanPreview()` in redactionAssistHelpers.js — purely a
		 * display aid, never used for the actual redaction).
		 *
		 * @param {object} span The span `{start, end, category, source}`.
		 * @return {string}
		 * @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-redaction-proposals-require-explicit-human-review-before-any-hand-off
		 */
		previewFor(span) {
			return buildSpanPreview(this.text, span)
		},

		/**
		 * @param {object} span The span `{source}`.
		 * @return {boolean}
		 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-4-1
		 */
		isToggleable(span) {
			return isSpanToggleable(span)
		},

		/**
		 * @param {number} index The span's index in `proposal.spans`.
		 * @param {boolean} checked Whether the span is now selected.
		 * @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-redaction-proposals-require-explicit-human-review-before-any-hand-off
		 */
		toggleSpan(index, checked) {
			this.selections = { ...this.selections, [index]: checked }
		},

		/**
		 * Request a merged rules-floor + LLM-assisted proposal.
		 *
		 * @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-redaction-proposals-require-explicit-human-review-before-any-hand-off
		 */
		async detect() {
			this.detecting = true
			this.errorMessage = ''
			try {
				const url = generateUrl(
					'/apps/dossiq/api/cases/'
						+ encodeURIComponent(this.caseId)
						+ '/woo/documents/'
						+ encodeURIComponent(this.documentRef)
						+ '/redaction-proposal',
				)
				const { data } = await axios.post(url, { text: this.text })
				this.proposal = data
				// Rule spans are always selected (and their checkbox is
				// disabled) — mirrors the backend rules-floor invariant.
				this.selections = buildInitialSelections(data.spans)
			} catch (err) {
				this.errorMessage = err.response?.data?.error || err.message
			} finally {
				this.detecting = false
			}
		},

		/**
		 * Approve the currently-selected spans — hands them to the existing
		 * redaction pipeline as guidance (never performs redaction itself).
		 *
		 * @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-redaction-proposals-require-explicit-human-review-before-any-hand-off
		 */
		async approve() {
			await this.review('approve')
		},

		/**
		 * Reject the proposal — discards it; the pre-existing manual/Docudesk
		 * fallback is unaffected.
		 *
		 * @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-redaction-proposals-require-explicit-human-review-before-any-hand-off
		 */
		async reject() {
			await this.review('reject')
		},

		/**
		 * @param {string} decision 'approve' or 'reject'.
		 * @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-redaction-proposals-require-explicit-human-review-before-any-hand-off
		 */
		async review(decision) {
			this.submitting = true
			this.errorMessage = ''
			try {
				const url = generateUrl(
					'/apps/dossiq/api/cases/'
						+ encodeURIComponent(this.caseId)
						+ '/woo/documents/'
						+ encodeURIComponent(this.documentRef)
						+ '/redaction-proposal/review',
				)
				const spans = filterSelectedSpans(
					this.proposal.spans,
					this.selections,
				)
				const { data } = await axios.post(url, { decision, spans })
				this.$emit('reviewed', data)
				this.$emit('close')
			} catch (err) {
				this.errorMessage = err.response?.data?.error || err.message
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.redaction-assist-dialog {
	padding: 8px 0;
}

.redaction-assist-dialog__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.redaction-assist-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.redaction-assist-dialog__label {
	font-weight: bold;
}

.redaction-assist-dialog__textarea {
	min-height: 160px;
	width: 100%;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	padding: 8px;
	font-family: inherit;
	resize: vertical;
}

.redaction-assist-dialog__hint {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	align-self: flex-end;
}

.redaction-assist-dialog__table {
	width: 100%;
	border-collapse: collapse;
}

.redaction-assist-dialog__table th,
.redaction-assist-dialog__table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.redaction-assist-dialog__preview {
	color: var(--color-text-maxcontrast);
	font-family: monospace;
	font-size: 0.85rem;
}

.redaction-assist-dialog__badge {
	padding: 2px 6px;
	border-radius: var(--border-radius);
	font-size: 0.75rem;
}

.redaction-assist-dialog__badge--rule {
	background: var(--color-background-dark);
}

.redaction-assist-dialog__badge--llm {
	background: var(--color-primary-light);
	color: var(--color-primary-element);
}

.redaction-assist-dialog__empty {
	color: var(--color-text-maxcontrast);
	padding: 16px 0;
}
</style>
