<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CaseEmailTab — sidebar tab on CaseDetail that surfaces email
  correspondence linked to the case via the OpenRegister
  integration-email leaf (ADR-022 leaf-first).

  Compose is intentionally NOT built in dossiq — the "New email"
  action opens an NC Mail draft via the case-email backend's
  prefillDraft endpoint. The display is delegated to the existing
  EmailThread component which already wires the leaf link-table.

  The shared-mailbox poller (lib/BackgroundJob/InboundEmailJob.php)
  is the inbound side; this tab is purely outbound/display.

  @spec openspec/changes/case-email-integration/tasks.md#T01
  @spec openspec/changes/case-email-integration/tasks.md#T12
-->
<template>
	<div class="case-email-tab">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="mailNotInstalled"
			:name="t('dossiq', 'Email integration unavailable')"
			:description="
				t(
					'dossiq',
					'Install Nextcloud Mail to enable case email linking. Dossiq does not maintain its own email engine.',
				)
			">
			<template #icon>
				<EmailOffOutline :size="48" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<!-- Toolbar with template-driven compose action -->
			<div class="case-email-tab__toolbar">
				<NcSelect
					v-if="templates.length"
					v-model="selectedTemplate"
					:options="templates"
					:aria-label-combobox="t('dossiq', 'Email template')"
					:inputLabel="t('dossiq', 'Email template')"
					label="name"
					trackBy="id"
					:placeholder="t('dossiq', 'Select a template (optional)…')"
					:clearable="true" />

				<NcButton
					type="primary"
					:disabled="isFinal || drafting"
					:title="
						isFinal
							? t('dossiq', 'Case is closed; email cannot be sent.')
							: ''
					"
					@click="composeDraft">
					<template #icon>
						<EmailEditOutline :size="20" />
					</template>
					{{ draftButtonLabel }}
				</NcButton>
			</div>

			<!-- Server feedback (errors + unresolved variables) -->
			<NcNoteCard
				v-if="error"
				type="error"
				:heading="t('dossiq', 'Could not open draft')"
				role="alert">
				{{ error }}
			</NcNoteCard>

			<NcNoteCard
				v-if="unresolvedVariables.length > 0"
				type="warning"
				role="status">
				<p>
					{{
						t(
							'dossiq',
							'Unresolved template variables — the draft contains raw placeholders that you must fill manually:',
						)
					}}
				</p>
				<ul class="case-email-tab__unresolved">
					<li v-for="v in unresolvedVariables" :key="v">
						<code>{{ formatVariable(v) }}</code>
					</li>
				</ul>
			</NcNoteCard>

			<!-- Email thread (display from the leaf link-table) -->
			<EmailThread
				:caseId="caseId"
				:isReadOnly="isFinal"
				@compose="composeDraft" />
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import EmailEditOutline from 'vue-material-design-icons/EmailEditOutline.vue'
import EmailOffOutline from 'vue-material-design-icons/EmailOffOutline.vue'
import EmailThread from './EmailThread.vue'

export default {
	name: 'CaseEmailTab',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		EmailEditOutline,
		EmailOffOutline,
		EmailThread,
	},

	props: {
		/**
		 * Case UUID. Manifest passes this as :id; CaseDetail also injects it
		 * when the tab is rendered inline.
		 */
		caseId: {
			type: String,
			default: null,
		},

		/** Inline case object — short-circuit for caseType + endDate lookups. */
		caseObject: {
			type: Object,
			default: null,
		},
	},

	emits: ['drafted'],

	data() {
		return {
			loading: true,
			drafting: false,
			templates: [],
			selectedTemplate: null,
			error: '',
			unresolvedVariables: [],
			mailNotInstalled: false,
			caseTypeId: null,
			isFinal: false,
		}
	},

	computed: {
		resolvedCaseId() {
			return this.caseId || this.$route?.params?.id || null
		},

		/**
		 * Label for the "compose draft" button.
		 *
		 * @return {string} The translated button label.
		 *
		 * @spec openspec/specs/case-email-integration/spec.md#requirement-the-system-shall-prefill-an-nc-mail-draft-from-a-template-it-shall-not-send-mail-itself
		 */
		draftButtonLabel() {
			return this.selectedTemplate
				? t('dossiq', 'Open draft from template')
				: t('dossiq', 'Open empty draft')
		},
	},

	watch: {
		resolvedCaseId: {
			immediate: false,
			handler() {
				this.reload()
			},
		},

		caseObject: {
			immediate: false,
			deep: false,
			handler(val) {
				this.applyCaseObject(val)
			},
		},
	},

	async mounted() {
		await this.reload()
	},

	methods: {
		/** @spec openspec/specs/case-email-integration/spec.md */
		async reload() {
			if (!this.resolvedCaseId) {
				this.loading = false
				return
			}
			this.loading = true
			this.error = ''
			this.unresolvedVariables = []
			try {
				// NC Mail availability is signalled by the leaf-link endpoint:
				// a 404 on compose means the leaf is absent. We avoid a hard
				// capability check so the tab still renders linked-message
				// history even when Mail is not installed.

				if (this.caseObject) {
					this.applyCaseObject(this.caseObject)
				} else {
					// OR per-object endpoint needs both register and schema
					// slugs: /objects/{register}/{schema}/{id}.
					const url = generateUrl(
						`/apps/openregister/api/objects/dossiq/case/${encodeURIComponent(this.resolvedCaseId)}`,
					)
					const { data } = await axios
						.get(url)
						.catch(() => ({ data: null }))
					this.applyCaseObject(data)
				}

				if (this.caseTypeId) {
					await this.loadTemplates()
				}
			} catch (err) {
				console.error('[CaseEmailTab] reload failed', err)
			} finally {
				this.loading = false
			}
		},

		applyCaseObject(caseObj) {
			if (!caseObj) {
				return
			}
			const inner = caseObj['@self'] ? caseObj : caseObj
			this.caseTypeId = inner.caseType || inner['@self']?.caseType || null
			this.isFinal = !!(inner.endDate || inner['@self']?.endDate)
		},

		/**
		 * Load the email templates available for this case's zaaktype.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/case-email-integration/spec.md#requirement-the-system-shall-provide-per-zaaktype-email-templates-as-a-leaf-extension
		 */
		async loadTemplates() {
			try {
				const url = generateUrl(
					`/apps/dossiq/api/casetypes/${encodeURIComponent(this.caseTypeId)}/email-templates`,
				)
				const { data } = await axios.get(url)
				this.templates = Array.isArray(data?.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
			} catch (err) {
				// Template fetch is non-fatal: still allow empty-draft compose.
				console.warn('[CaseEmailTab] template fetch failed', err)
				this.templates = []
			}
		},

		/**
		 * Compose action.
		 *
		 * Dossiq never sends mail — we call /api/cases/:id/email-templates/:tid/draft
		 * which the backend (EmailTemplateService::prefillDraft) translates into
		 * an NC Mail draft via the configured Mail account. The response carries
		 * unresolved placeholders + the URL to the new NC Mail draft.
		 *
		 * @spec openspec/specs/case-email-integration/spec.md#requirement-the-system-shall-prefill-an-nc-mail-draft-from-a-template-it-shall-not-send-mail-itself
		 */
		async composeDraft() {
			if (this.isFinal) {
				this.error = t(
					'dossiq',
					'Case is closed; new emails cannot be drafted.',
				)
				return
			}
			this.drafting = true
			this.error = ''
			this.unresolvedVariables = []
			try {
				const templateId = this.selectedTemplate?.id || 'blank'
				const url = generateUrl(
					`/apps/dossiq/api/cases/${encodeURIComponent(this.resolvedCaseId)}/email-templates/${encodeURIComponent(templateId)}/draft`,
				)
				const { data } = await axios.post(url, {})
				this.unresolvedVariables = Array.isArray(data?.unresolved)
					? data.unresolved
					: []
				// Navigate the user to the newly created NC Mail draft if a URL was returned.
				if (data?.draftUrl) {
					window.open(data.draftUrl, '_blank', 'noopener')
				}
				this.$emit('drafted', data)
			} catch (err) {
				const status = err?.response?.status
				if (status === 404) {
					this.mailNotInstalled = true
				}
				this.error =
					err?.response?.data?.message
					|| err?.message
					|| t('dossiq', 'Failed to open the draft.')
			} finally {
				this.drafting = false
			}
		},

		formatVariable(name) {
			return `{{${name}}}`
		},
	},
}
</script>

<style scoped>
.case-email-tab {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 12px;
}

.case-email-tab__toolbar {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: flex-end;
}

.case-email-tab__toolbar > * {
	flex: 1 1 auto;
	min-width: 200px;
}

.case-email-tab__toolbar > button {
	flex: 0 0 auto;
}

.case-email-tab__unresolved {
	margin: 4px 0 0;
	padding-left: 20px;
}

.case-email-tab__unresolved code {
	background: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: var(--border-radius);
	font-family: var(--font-face-mono, monospace);
}
</style>
