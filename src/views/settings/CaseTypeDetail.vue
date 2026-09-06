<template>
	<div class="case-type-detail">
		<div class="case-type-detail__header">
			<NcButton type="tertiary" @click="$emit('back')">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
				{{ t('dossiq', 'Back to list') }}
			</NcButton>

			<h3 class="case-type-detail__title">
				{{
					isCreate
						? t('dossiq', 'New Case Type')
						: form.title || t('dossiq', 'Case Type')
				}}
			</h3>

			<div class="case-type-detail__actions">
				<NcButton
					v-if="!isCreate && form.isDraft"
					type="secondary"
					@click="publish">
					{{ t('dossiq', 'Publish') }}
				</NcButton>
				<NcButton
					v-if="!isCreate && !form.isDraft"
					type="secondary"
					@click="unpublish">
					{{ t('dossiq', 'Unpublish') }}
				</NcButton>
				<NcButton
					v-if="!isCreate"
					type="secondary"
					:disabled="duplicating"
					@click="duplicate">
					<template v-if="duplicating" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('dossiq', 'Duplicate') }}
				</NcButton>
				<NcButton type="primary" :disabled="saving" @click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('dossiq', 'Save') }}
				</NcButton>
			</div>
		</div>

		<!-- Active case warning -->
		<div
			v-if="activeCaseCount > 0 && !isCreate"
			class="case-type-detail__warning">
			<p>
				{{
					t(
						'dossiq',
						'There are {count} active cases of this type. Changes will only apply to new cases.',
						{ count: activeCaseCount },
					)
				}}
			</p>
		</div>

		<!-- Publish errors -->
		<div
			v-if="publishErrors.length > 0"
			class="case-type-detail__publish-errors">
			<p>
				<strong>{{ t('dossiq', 'Cannot publish:') }}</strong>
			</p>
			<ul>
				<li v-for="(err, i) in publishErrors" :key="i">
					{{ err }}
				</li>
			</ul>
		</div>

		<!-- Save feedback -->
		<p v-if="saveError" class="case-type-detail__error">
			{{ saveError }}
		</p>
		<p v-if="saveSuccess" class="case-type-detail__success">
			{{ t('dossiq', 'Saved successfully') }}
		</p>

		<NcLoadingIcon v-if="loadingDetail" />

		<template v-else>
			<!-- Tabs -->
			<div class="case-type-detail__tabs">
				<button
					v-for="tab in tabs"
					:key="tab.id"
					class="case-type-detail__tab"
					:class="{
						'case-type-detail__tab--active': activeTab === tab.id,
					}"
					@click="activeTab = tab.id">
					{{ tab.label }}
				</button>
			</div>

			<!-- Tab content -->
			<div class="case-type-detail__tab-content">
				<GeneralTab
					v-if="activeTab === 'general'"
					:form="form"
					:errors="validationErrors"
					@update="onFieldUpdate" />
				<StatusesTab
					v-else-if="activeTab === 'statuses'"
					:caseTypeId="caseTypeId"
					:isCreate="isCreate" />
				<ResultsTab
					v-else-if="activeTab === 'results'"
					:caseTypeId="caseTypeId"
					:isCreate="isCreate" />
				<RolesTab
					v-else-if="activeTab === 'roles'"
					:caseTypeId="caseTypeId"
					:isCreate="isCreate" />
				<PropertiesTab
					v-else-if="activeTab === 'properties'"
					:caseTypeId="caseTypeId"
					:isCreate="isCreate" />
				<DocumentTypesTab
					v-else-if="activeTab === 'documents'"
					:caseTypeId="caseTypeId"
					:isCreate="isCreate" />
				<DecisionTypesTab
					v-else-if="activeTab === 'decisions'"
					:caseTypeId="caseTypeId"
					:isCreate="isCreate" />
				<SubCaseTypesTab
					v-else-if="activeTab === 'subCaseTypes'"
					:caseTypeId="caseTypeId" />
				<WorkflowTab
					v-else-if="activeTab === 'workflow'"
					:caseTypeId="caseTypeId" />
				<EmailTemplateAdmin
					v-else-if="activeTab === 'emailTemplates'"
					:caseTypeId="caseTypeId" />
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import EmailTemplateAdmin from '../casetypes/components/EmailTemplateAdmin.vue'
import DecisionTypesTab from './tabs/DecisionTypesTab.vue'
import DocumentTypesTab from './tabs/DocumentTypesTab.vue'
import GeneralTab from './tabs/GeneralTab.vue'
import PropertiesTab from './tabs/PropertiesTab.vue'
import ResultsTab from './tabs/ResultsTab.vue'
import RolesTab from './tabs/RolesTab.vue'
import StatusesTab from './tabs/StatusesTab.vue'
import SubCaseTypesTab from './tabs/SubCaseTypesTab.vue'
import WorkflowTab from './tabs/WorkflowTab.vue'
import { useObjectStore } from '../../store/modules/object.js'
import {
	validateCaseType,
	validateForPublish,
} from '../../utils/caseTypeValidation.js'

const EMPTY_FORM = {
	title: '',
	description: '',
	identifier: '',
	purpose: '',
	trigger: '',
	subject: '',
	initiatorAction: '',
	handlerAction: '',
	origin: '',
	processingDeadline: '',
	serviceTarget: '',
	extensionAllowed: false,
	extensionPeriod: '',
	suspensionAllowed: false,
	confidentiality: '',
	iv3TaskField: '',
	publicationRequired: false,
	publicationText: '',
	responsibleUnit: '',
	referenceProcess: '',
	isDraft: true,
	validFrom: '',
	validUntil: '',
	keywords: '',
}

export default {
	name: 'CaseTypeDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		ArrowLeftIcon,
		GeneralTab,
		StatusesTab,
		WorkflowTab,
		ResultsTab,
		RolesTab,
		PropertiesTab,
		DocumentTypesTab,
		DecisionTypesTab,
		SubCaseTypesTab,
		EmailTemplateAdmin,
	},

	props: {
		caseTypeId: {
			type: String,
			default: null,
		},
	},

	emits: ['back', 'duplicated', 'saved'],

	data() {
		return {
			form: { ...EMPTY_FORM },
			activeTab: 'general',
			saving: false,
			saveError: '',
			saveSuccess: false,
			loadingDetail: false,
			validationErrors: {},
			publishErrors: [],
			statusTypes: [],
			activeCaseCount: 0,
			duplicating: false,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		objectStore() {
			return useObjectStore()
		},

		isCreate() {
			return !this.caseTypeId
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		tabs() {
			return [
				{ id: 'general', label: t('dossiq', 'General') },
				{ id: 'statuses', label: t('dossiq', 'Statuses') },
				{ id: 'results', label: t('dossiq', 'Results') },
				{ id: 'roles', label: t('dossiq', 'Roles') },
				{ id: 'properties', label: t('dossiq', 'Properties') },
				{ id: 'documents', label: t('dossiq', 'Docs') },
				{ id: 'decisions', label: t('dossiq', 'Decisions') },
				{ id: 'subCaseTypes', label: t('dossiq', 'Sub-cases') },
				{ id: 'workflow', label: t('dossiq', 'Workflow') },
				{ id: 'emailTemplates', label: t('dossiq', 'Email') },
			]
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
	async mounted() {
		if (!this.isCreate) {
			await this.loadCaseType()
		} else {
			this.form.identifier = 'CT-' + Date.now()
		}
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		async loadCaseType() {
			this.loadingDetail = true
			const data = await this.objectStore.fetchObject(
				'caseType',
				this.caseTypeId,
			)
			if (data) {
				this.form = { ...EMPTY_FORM, ...data }
			}
			// Count active cases of this type
			try {
				const cases = await this.objectStore.fetchCollection('case', {
					caseType: this.caseTypeId,
					_limit: 1,
				})
				this.activeCaseCount = cases?.length || 0
			} catch (e) {
				this.activeCaseCount = 0
			}
			this.loadingDetail = false
		},

		/**
		 * @param {object} field The field.
		 * @param {string|number|boolean|object} value The new value.
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		onFieldUpdate(field, value) {
			this.form[field] = value
			// Clear validation error for this field
			if (this.validationErrors[field]) {
				const errors = { ...this.validationErrors }
				delete errors[field]
				this.validationErrors = errors
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		async save() {
			this.saveError = ''
			this.saveSuccess = false
			this.publishErrors = []

			const validation = validateCaseType(this.form)
			this.validationErrors = validation.errors

			if (!validation.valid) {
				this.saveError = t('dossiq', 'Please fix the validation errors')
				return
			}

			this.saving = true
			const result = await this.objectStore.saveObject('caseType', this.form)
			this.saving = false

			if (result) {
				this.saveSuccess = true
				if (this.isCreate && result.id) {
					this.form = { ...EMPTY_FORM, ...result }
					this.$emit('saved', result.id)
				} else {
					this.form = { ...EMPTY_FORM, ...result }
				}
				setTimeout(() => {
					this.saveSuccess = false
				}, 3000)
			} else {
				this.saveError =
					this.objectStore.getError('caseType')
					|| t('dossiq', 'Failed to save case type')
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		async publish() {
			this.publishErrors = []
			this.saveError = ''

			// Fetch status types for validation
			const statusTypes = await this.objectStore.fetchCollection(
				'statusType',
				{
					caseType: this.caseTypeId,
					_limit: 100,
				},
			)

			const result = validateForPublish(this.form, statusTypes || [])
			if (!result.valid) {
				this.publishErrors = result.errors
				// Re-fetch case type data since fetchCollection may have changed state
				return
			}

			this.form.isDraft = false
			await this.save()
		},

		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		async unpublish() {
			const confirmed = confirm(
				t(
					'dossiq',
					'Unpublishing this case type will prevent new cases from being created. Existing cases will continue to function. Continue?',
				),
			)
			if (!confirmed) return

			this.form.isDraft = true
			await this.save()
		},

		/**
		 * Deep-copy this case type into a new draft, then navigate to it.
		 *
		 * @spec openspec/changes/zaaktype-copy/tasks.md#T11
		 */
		async duplicate() {
			this.saveError = ''
			this.duplicating = true
			try {
				const response = await axios.post(
					generateUrl('/apps/dossiq/api/case-definitions/{id}/copy', {
						id: this.caseTypeId,
					}),
				)
				const newId = response.data?.id
				if (newId) {
					this.$emit('duplicated', newId)
				}
			} catch (err) {
				this.saveError =
					err.response?.data?.error
					|| t('dossiq', 'Failed to duplicate case type')
			} finally {
				this.duplicating = false
			}
		},
	},
}
</script>

<style scoped>
.case-type-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.case-type-detail__title {
	flex: 1;
	margin: 0;
}

.case-type-detail__actions {
	display: flex;
	gap: 8px;
}

.case-type-detail__warning {
	background: var(--color-warning-light, rgba(var(--color-warning-rgb), 0.1));
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 16px;
	color: var(--color-warning-text);
}

.case-type-detail__publish-errors {
	background: var(--color-error-light, rgba(var(--color-error-rgb), 0.1));
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 16px;
}

.case-type-detail__publish-errors ul {
	margin: 8px 0 0 16px;
	padding: 0;
}

.case-type-detail__publish-errors li {
	color: var(--color-error);
}

.case-type-detail__error {
	color: var(--color-error);
	margin-bottom: 12px;
}

.case-type-detail__success {
	color: var(--color-success);
	margin-bottom: 12px;
}

.case-type-detail__tabs {
	display: flex;
	gap: 0;
	border-bottom: 2px solid var(--color-border);
	margin-bottom: 20px;
}

.case-type-detail__tab {
	padding: 8px 16px;
	border: none;
	background: none;
	cursor: pointer;
	font-size: 14px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	border-bottom: 2px solid transparent;
	margin-bottom: -2px;
}

.case-type-detail__tab:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.case-type-detail__tab--active {
	color: var(--color-primary);
	border-bottom-color: var(--color-primary);
}
</style>
