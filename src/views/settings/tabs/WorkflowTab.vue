<template>
	<div class="workflow-tab">
		<!-- Version selector and actions -->
		<div class="workflow-tab__toolbar">
			<div class="workflow-tab__version-selector">
				<label>{{ t('dossiq', 'Version:') }}</label>
				<select
					v-model="selectedVersionId"
					class="workflow-tab__select"
					@change="onVersionChange">
					<option v-for="v in versions" :key="v.id" :value="v.id">
						v{{ v.version }}
						{{ v.isActive ? '(active)' : '' }}
						{{ v.isDraft ? '(draft)' : '' }}
					</option>
				</select>
			</div>

			<div class="workflow-tab__actions">
				<!-- Create new workflow if none exists -->
				<NcButton
					v-if="versions.length === 0"
					type="primary"
					@click="createWorkflow">
					{{ t('dossiq', 'Create workflow') }}
				</NcButton>

				<!-- Publish draft -->
				<NcButton
					v-if="currentIsDraft"
					type="secondary"
					:disabled="publishing"
					@click="publish">
					<template v-if="publishing" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('dossiq', 'Publish') }}
				</NcButton>

				<!-- Edit published (create draft copy) -->
				<NcButton
					v-if="currentIsPublished && !hasDraft"
					type="secondary"
					@click="editPublished">
					{{ t('dossiq', 'Edit') }}
				</NcButton>

				<!-- Save -->
				<NcButton
					v-if="currentIsDraft && dirty"
					type="primary"
					:disabled="saving"
					@click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('dossiq', 'Save') }}
				</NcButton>

				<!-- Import / Export -->
				<NcButton type="tertiary" @click="exportWorkflow">
					{{ t('dossiq', 'Export') }}
				</NcButton>
				<NcButton type="tertiary" @click="triggerImport">
					{{ t('dossiq', 'Import') }}
				</NcButton>
				<input
					ref="importInput"
					type="file"
					accept=".json"
					style="display: none"
					@change="handleImport" />
			</div>
		</div>

		<!-- Publish errors -->
		<div v-if="publishErrors.length > 0" class="workflow-tab__errors">
			<p>
				<strong>{{ t('dossiq', 'Cannot publish:') }}</strong>
			</p>
			<ul>
				<li v-for="(err, i) in publishErrors" :key="i">
					{{ err.message }}
				</li>
			</ul>
		</div>

		<!-- Import report -->
		<div v-if="importReport" class="workflow-tab__import-report">
			<p>
				<strong>{{ t('dossiq', 'Import validation:') }}</strong>
			</p>
			<ul>
				<li v-for="(type, i) in importReport.statusTypes" :key="'s' + i">
					{{ t('dossiq', 'Missing status type: {name}', { name: type }) }}
				</li>
				<li v-for="(type, i) in importReport.roleTypes" :key="'r' + i">
					{{ t('dossiq', 'Missing role type: {name}', { name: type }) }}
				</li>
			</ul>
			<NcButton type="secondary" @click="importReport = null">
				{{ t('dossiq', 'Cancel import') }}
			</NcButton>
		</div>

		<!-- Version notice -->
		<div v-if="versionNotice" class="workflow-tab__notice">
			{{ versionNotice }}
		</div>

		<!-- Editor -->
		<WorkflowEditor
			v-if="selectedVersionId"
			ref="editor"
			:caseTypeId="caseTypeId"
			:templateId="selectedVersionId"
			@dirty="dirty = true" />

		<!-- Empty state -->
		<div v-if="versions.length === 0 && !loading" class="workflow-tab__empty">
			<p>{{ t('dossiq', 'No workflow defined for this case type yet.') }}</p>
			<p>
				{{
					t(
						'dossiq',
						'Create a workflow to define process steps and status transitions.',
					)
				}}
			</p>
		</div>

		<NcLoadingIcon v-if="loading" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import WorkflowEditor from '../WorkflowEditor.vue'
import { useObjectStore } from '../../../store/modules/object.js'
import { useWorkflowStore } from '../../../store/modules/workflow.js'

export default {
	name: 'WorkflowTab',
	components: {
		NcButton,
		NcLoadingIcon,
		WorkflowEditor,
	},

	props: {
		caseTypeId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			selectedVersionId: null,
			loading: false,
			saving: false,
			publishing: false,
			dirty: false,
			publishErrors: [],
			importReport: null,
		}
	},

	computed: {
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		workflowStore() {
			return useWorkflowStore()
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		versions() {
			return this.workflowStore.versions
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		currentTemplate() {
			return this.workflowStore.currentTemplate
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		currentIsDraft() {
			return this.currentTemplate?.isDraft === true
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		currentIsPublished() {
			return this.currentTemplate && !this.currentTemplate.isDraft
		},

		hasDraft() {
			return this.versions.some((v) => v.isDraft)
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		versionNotice() {
			if (!this.currentTemplate) return null
			const active = this.workflowStore.activeVersion
			if (
				active
				&& this.currentTemplate.id !== active.id
				&& !this.currentTemplate.isDraft
			) {
				return t(
					'dossiq',
					'Viewing version {version}. Active version is {active}.',
					{
						version: this.currentTemplate.version,
						active: active.version,
					},
				)
			}
			return null
		},
	},

	async mounted() {
		await this.loadVersions()
	},

	methods: {
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async loadVersions() {
			this.loading = true
			await this.workflowStore.listVersions(this.caseTypeId)

			// Auto-select: prefer draft, then active, then latest
			if (this.versions.length > 0) {
				const draft = this.workflowStore.draftVersion
				const active = this.workflowStore.activeVersion
				const target = draft || active || this.versions[0]
				this.selectedVersionId = target.id
				await this.workflowStore.getTemplate(target.id)
			}
			this.loading = false
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async onVersionChange() {
			if (this.selectedVersionId) {
				await this.workflowStore.getTemplate(this.selectedVersionId)
				this.dirty = false
				this.publishErrors = []
			}
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async createWorkflow() {
			this.loading = true
			const template = await this.workflowStore.createTemplate(
				this.caseTypeId,
				t('dossiq', 'Workflow'),
			)
			if (template) {
				this.selectedVersionId = template.id
				await this.workflowStore.listVersions(this.caseTypeId)
			}
			this.loading = false
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async save() {
			this.saving = true
			await this.workflowStore.saveTemplate(this.currentTemplate)
			this.dirty = false
			this.saving = false
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async publish() {
			this.publishErrors = []
			this.publishing = true

			// Validate via editor
			if (this.$refs.editor && !this.$refs.editor.validate()) {
				this.publishErrors = this.workflowStore.validationErrors
				this.publishing = false
				return
			}

			// Save first if dirty
			if (this.dirty) {
				await this.save()
			}

			const result = await this.workflowStore.publishVersion(
				this.selectedVersionId,
				this.$refs.editor?.statusNodes || [],
			)
			if (!result) {
				this.publishErrors = this.workflowStore.validationErrors
			} else {
				await this.workflowStore.listVersions(this.caseTypeId)
			}
			this.publishing = false
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async editPublished() {
			this.loading = true
			const draft = await this.workflowStore.createDraftFromVersion(
				this.selectedVersionId,
			)
			if (draft) {
				this.selectedVersionId = draft.id
				await this.workflowStore.listVersions(this.caseTypeId)
			}
			this.loading = false
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async exportWorkflow() {
			if (!this.currentTemplate) return

			// Fetch type data for name mapping
			const statusTypes =
				(await this.objectStore.fetchCollection('statusType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})) || []
			const roleTypes =
				(await this.objectStore.fetchCollection('roleType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})) || []
			const docTypes =
				(await this.objectStore.fetchCollection('documentType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})) || []

			const exportData = this.workflowStore.exportWorkflow(
				this.currentTemplate,
				statusTypes,
				roleTypes,
				docTypes,
			)

			const blob = new Blob([JSON.stringify(exportData, null, 2)], {
				type: 'application/json',
			})
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = `${this.currentTemplate.title.toLowerCase().replace(/\s+/g, '-')}-v${this.currentTemplate.version}-workflow.json`
			a.click()
			URL.revokeObjectURL(url)
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		triggerImport() {
			this.$refs.importInput.click()
		},

		/**
		 * @param {Event} event The originating DOM event.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async handleImport(event) {
			const file = event.target.files[0]
			if (!file) return

			try {
				const text = await file.text()
				const importData = JSON.parse(text)

				// Fetch type data for UUID mapping
				const statusTypes =
					(await this.objectStore.fetchCollection('statusType', {
						caseType: this.caseTypeId,
						_limit: 100,
					})) || []
				const roleTypes =
					(await this.objectStore.fetchCollection('roleType', {
						caseType: this.caseTypeId,
						_limit: 100,
					})) || []
				const docTypes =
					(await this.objectStore.fetchCollection('documentType', {
						caseType: this.caseTypeId,
						_limit: 100,
					})) || []

				const result = await this.workflowStore.importWorkflow(
					importData,
					this.caseTypeId,
					statusTypes,
					roleTypes,
					docTypes,
				)

				if (!result.success) {
					this.importReport = result.missingTypes
				} else {
					this.selectedVersionId = result.template.id
					await this.workflowStore.listVersions(this.caseTypeId)
					this.importReport = null
				}
			} catch (err) {
				console.error('Import failed:', err)
			}

			// Reset file input
			event.target.value = ''
		},
	},
}
</script>

<style scoped>
.workflow-tab__toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
	flex-wrap: wrap;
	gap: 8px;
}

.workflow-tab__version-selector {
	display: flex;
	align-items: center;
	gap: 8px;
}

.workflow-tab__version-selector label {
	font-weight: 600;
	font-size: 13px;
}

.workflow-tab__select {
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
}

.workflow-tab__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.workflow-tab__errors {
	background: var(--color-error-light, rgba(var(--color-error-rgb), 0.1));
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 12px;
}

.workflow-tab__errors ul {
	margin: 4px 0 0 16px;
}

.workflow-tab__import-report {
	background: var(--color-warning-light, rgba(var(--color-warning-rgb), 0.1));
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 12px;
}

.workflow-tab__notice {
	background: var(--color-primary-element-light);
	border-radius: var(--border-radius);
	padding: 8px 12px;
	margin-bottom: 12px;
	font-size: 13px;
}

.workflow-tab__empty {
	text-align: center;
	padding: 40px;
	color: var(--color-text-maxcontrast);
}
</style>
