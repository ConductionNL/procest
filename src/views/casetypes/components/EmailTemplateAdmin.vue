<template>
	<div class="email-template-admin">
		<NcNoteCard type="info">
			{{ introText }}
		</NcNoteCard>

		<div class="email-template-admin__layout">
			<!-- Template list -->
			<div class="email-template-admin__list">
				<div class="email-template-admin__list-header">
					<h4>{{ t('dossiq', 'Templates') }}</h4>
					<NcButton
						type="primary"
						:disabled="!caseTypeId"
						@click="startCreate">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('dossiq', 'New') }}
					</NcButton>
				</div>

				<NcLoadingIcon v-if="loading" :size="24" />
				<ul v-else-if="templates.length > 0">
					<li
						v-for="tpl in templates"
						:key="tpl.id || tpl.name"
						class="email-template-admin__list-item"
						:class="{
							'email-template-admin__list-item--active':
								isSelected(tpl),
						}"
						role="button"
						tabindex="0"
						@click="selectTemplate(tpl)"
						@keydown.enter="selectTemplate(tpl)"
						@keydown.space.prevent="selectTemplate(tpl)">
						<span class="email-template-admin__list-name">{{
							tpl.name
						}}</span>
						<span class="email-template-admin__list-version"
							>v{{ tpl.version || 1 }}</span
						>
					</li>
				</ul>
				<p v-else class="email-template-admin__empty">
					{{ t('dossiq', 'No templates yet for this case type.') }}
				</p>
			</div>

			<!-- Editor -->
			<div v-if="editing" class="email-template-admin__editor">
				<div class="setting-row">
					<label for="etpl-name">{{ t('dossiq', 'Name') }}</label>
					<NcInputField id="etpl-name" v-model="draft.name" />
				</div>

				<div class="setting-row">
					<label for="etpl-subject">{{ t('dossiq', 'Subject') }}</label>
					<NcInputField
						id="etpl-subject"
						v-model="draft.subject"
						@focus="activeField = 'subject'" />
				</div>

				<div class="setting-row">
					<label for="etpl-body">{{ t('dossiq', 'Body') }}</label>
					<NcTextArea
						id="etpl-body"
						v-model="draft.body"
						rows="8"
						@focus="activeField = 'body'" />
				</div>

				<!-- Live preview -->
				<div class="setting-row">
					<label>{{ t('dossiq', 'Preview') }}</label>
					<!-- renderPreview() escapes &, < and > in the body and emits
					     only its own fixed-class <span> and <br>, so no
					     caller-supplied markup can reach the DOM. -->
					<!-- eslint-disable vue/no-v-html -->
					<div
						class="email-template-admin__preview"
						v-html="previewHtml" />
					<!-- eslint-enable vue/no-v-html -->
					<p
						v-if="unresolved.length > 0"
						class="email-template-admin__warning">
						{{
							t('dossiq', 'Unresolved variables: {names}', {
								names: unresolved.join(', '),
							})
						}}
					</p>
				</div>

				<div class="email-template-admin__actions">
					<NcButton type="primary" :disabled="saving" @click="save">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
						</template>
						{{ saveLabel }}
					</NcButton>
					<NcButton type="tertiary" @click="cancelEdit">
						{{ t('dossiq', 'Cancel') }}
					</NcButton>
				</div>
			</div>

			<!-- Variable sidebar -->
			<div v-if="editing" class="email-template-admin__variables">
				<h4>{{ t('dossiq', 'Variables') }}</h4>
				<p class="email-template-admin__hint">
					{{ t('dossiq', 'Click to insert into the focused field') }}
				</p>
				<div
					v-for="(names, group) in variables"
					:key="group"
					class="email-template-admin__var-group">
					<h5>{{ groupLabel(group) }}</h5>
					<button
						v-for="name in names"
						:key="group + '.' + name"
						type="button"
						class="email-template-admin__var"
						@click="insertVariable(name)">
						{{ varToken(name) }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcInputField,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
} from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import {
	collectUnresolved,
	renderPreview,
} from '../../../utils/emailTemplatePreview.js'

/**
 * Per-case-type email template CRUD with click-to-insert variables and a
 * live preview that highlights unresolved {{placeholders}} in red. No
 * compose/thread/queue components — display, compose and link come from the
 * email leaf + Nextcloud Mail.
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */
export default {
	name: 'EmailTemplateAdmin',
	components: {
		NcButton,
		NcInputField,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		Plus,
	},

	props: {
		caseTypeId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			loading: false,
			saving: false,
			editing: false,
			activeField: 'body',
			templates: [],
			variables: {},
			draft: { id: null, name: '', subject: '', body: '' },
		}
	},

	computed: {
		/** @spec openspec/specs/case-email-integration/spec.md */
		introText() {
			return t(
				'dossiq',
				'Per-case-type email templates with placeholder variables. Editing a template creates a new version — old versions are retained. Templates prefill a Nextcloud Mail draft; Dossiq never sends mail itself.',
			)
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		flatVariableNames() {
			return Object.values(this.variables).flat()
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		unresolved() {
			return collectUnresolved(
				`${this.draft.subject} ${this.draft.body}`,
				this.flatVariableNames,
			)
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		previewHtml() {
			return renderPreview(this.draft.body, this.flatVariableNames)
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		saveLabel() {
			if (this.saving) return t('dossiq', 'Saving...')
			return this.draft.id
				? t('dossiq', 'Save as new version')
				: t('dossiq', 'Create template')
		},
	},

	watch: {
		caseTypeId: {
			immediate: true,
			handler() {
				if (this.caseTypeId) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * @param {string} name The name.
		 * @spec openspec/specs/case-email-integration/spec.md
		 */
		varToken(name) {
			return '{{' + name + '}}'
		},

		/**
		 * @param {object} group The group.
		 * @spec openspec/specs/case-email-integration/spec.md
		 */
		groupLabel(group) {
			const labels = {
				case: t('dossiq', 'Case'),
				contact: t('dossiq', 'Contact'),
				caseType: t('dossiq', 'Case type'),
			}
			return labels[group] || group
		},

		isSelected(tpl) {
			return this.draft.id && tpl.id === this.draft.id
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		async load() {
			this.loading = true
			try {
				const [tplRes, varRes] = await Promise.all([
					fetch(
						generateUrl(
							`/apps/dossiq/api/casetypes/${encodeURIComponent(this.caseTypeId)}/email-templates`,
						),
						{
							headers: { requesttoken: OC.requestToken },
						},
					),
					fetch(
						generateUrl(
							`/apps/dossiq/api/casetypes/${encodeURIComponent(this.caseTypeId)}/email-templates/variables`,
						),
						{
							headers: { requesttoken: OC.requestToken },
						},
					),
				])
				if (tplRes.ok) {
					const data = await tplRes.json()
					this.templates = Array.isArray(data)
						? data
						: data.results || data.templates || []
				}
				if (varRes.ok) {
					this.variables = await varRes.json()
				}
			} catch (error) {
				// Non-fatal — empty editor still works for create.
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		startCreate() {
			this.draft = { id: null, name: '', subject: '', body: '' }
			this.editing = true
			this.activeField = 'body'
		},

		/**
		 * @param {object} tpl The tpl.
		 * @spec openspec/specs/case-email-integration/spec.md
		 */
		selectTemplate(tpl) {
			this.draft = {
				id: tpl.id || null,
				name: tpl.name || '',
				subject: tpl.subject || '',
				body: tpl.body || '',
			}
			this.editing = true
			this.activeField = 'body'
		},

		cancelEdit() {
			this.editing = false
		},

		/**
		 * @param {string} name The name.
		 * @spec openspec/specs/case-email-integration/spec.md
		 */
		insertVariable(name) {
			const token = `{{${name}}}`
			if (this.activeField === 'subject') {
				this.draft.subject = `${this.draft.subject || ''}${token}`
			} else {
				this.draft.body = `${this.draft.body || ''}${token}`
			}
		},

		/** @spec openspec/specs/case-email-integration/spec.md */
		async save() {
			this.saving = true
			try {
				const isUpdate = !!this.draft.id
				const url = isUpdate
					? generateUrl(
							`/apps/dossiq/api/email-templates/${encodeURIComponent(this.draft.id)}`,
						)
					: generateUrl(
							`/apps/dossiq/api/casetypes/${encodeURIComponent(this.caseTypeId)}/email-templates`,
						)
				await fetch(url, {
					method: isUpdate ? 'PUT' : 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						name: this.draft.name,
						subject: this.draft.subject,
						body: this.draft.body,
					}),
				})
				this.editing = false
				await this.load()
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.email-template-admin__layout {
	display: grid;
	grid-template-columns: 220px 1fr 200px;
	gap: 16px;
	margin-top: 12px;
}

.email-template-admin__list-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.email-template-admin__list-item {
	display: flex;
	justify-content: space-between;
	padding: 6px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.email-template-admin__list-item:hover,
.email-template-admin__list-item--active {
	background: var(--color-background-hover);
}

.email-template-admin__list-version {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
}

.email-template-admin__empty,
.email-template-admin__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.setting-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.email-template-admin__preview {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	min-height: 80px;
	background: var(--color-main-background);
}

.email-template-admin__preview :deep(.etpl-var-bad) {
	background: var(--color-error, #e9322d);
	color: var(--color-primary-element-text, #fff);
	border-radius: 3px;
	padding: 0 2px;
}

.email-template-admin__preview :deep(.etpl-var-ok) {
	background: var(--color-success, #2e7d32);
	color: #fff;
	border-radius: 3px;
	padding: 0 2px;
}

.email-template-admin__warning {
	color: var(--color-error, #e9322d);
	font-size: 0.85em;
}

.email-template-admin__actions {
	display: flex;
	gap: 8px;
}

.email-template-admin__var-group {
	margin-bottom: 8px;
}

.email-template-admin__var {
	display: block;
	width: 100%;
	text-align: left;
	background: var(--color-background-dark);
	border: none;
	border-radius: var(--border-radius);
	padding: 4px 6px;
	margin-bottom: 4px;
	cursor: pointer;
	font-family: monospace;
	font-size: 0.85em;
}

.email-template-admin__var:hover {
	background: var(--color-background-hover);
}
</style>
