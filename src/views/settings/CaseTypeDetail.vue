<template>
	<div class="case-type-detail">
		<div class="case-type-detail__header">
			<NcButton type="tertiary" @click="$emit('back')">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
				{{ t('procest', 'Back to list') }}
			</NcButton>

			<h3 class="case-type-detail__title">
				{{ isCreate ? t('procest', 'New Case Type') : (form.title || t('procest', 'Case Type')) }}
			</h3>

			<div class="case-type-detail__actions">
				<NcButton
					v-if="!isCreate && form.isDraft"
					type="secondary"
					@click="publish">
					{{ t('procest', 'Publish') }}
				</NcButton>
				<NcButton
					v-if="!isCreate && !form.isDraft"
					type="secondary"
					@click="unpublish">
					{{ t('procest', 'Unpublish') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving"
					@click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('procest', 'Save') }}
				</NcButton>
			</div>
		</div>

		<!-- Publish errors -->
		<div v-if="publishErrors.length > 0" class="case-type-detail__publish-errors">
			<p><strong>{{ t('procest', 'Cannot publish:') }}</strong></p>
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
			{{ t('procest', 'Saved successfully') }}
		</p>

		<NcLoadingIcon v-if="loadingDetail" />

		<template v-else>
			<!-- Tabs -->
			<div class="case-type-detail__tabs">
				<button
					v-for="tab in tabs"
					:key="tab.id"
					class="case-type-detail__tab"
					:class="{ 'case-type-detail__tab--active': activeTab === tab.id }"
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
					:case-type-id="caseTypeId"
					:is-create="isCreate" />
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import GeneralTab from './tabs/GeneralTab.vue'
import StatusesTab from './tabs/StatusesTab.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { validateCaseType, validateForPublish } from '../../utils/caseTypeValidation.js'

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
	},
	props: {
		caseTypeId: {
			type: String,
			default: null,
		},
	},
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
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isCreate() {
			return !this.caseTypeId
		},
		tabs() {
			return [
				{ id: 'general', label: t('procest', 'General') },
				{ id: 'statuses', label: t('procest', 'Statuses') },
			]
		},
	},
	async mounted() {
		if (!this.isCreate) {
			await this.loadCaseType()
		} else {
			this.form.identifier = 'CT-' + Date.now()
		}
	},
	methods: {
		async loadCaseType() {
			this.loadingDetail = true
			const data = await this.objectStore.fetchObject('caseType', this.caseTypeId)
			if (data) {
				this.form = { ...EMPTY_FORM, ...data }
			}
			this.loadingDetail = false
		},

		onFieldUpdate(field, value) {
			this.form[field] = value
			// Clear validation error for this field
			if (this.validationErrors[field]) {
				const errors = { ...this.validationErrors }
				delete errors[field]
				this.validationErrors = errors
			}
		},

		async save() {
			this.saveError = ''
			this.saveSuccess = false
			this.publishErrors = []

			const validation = validateCaseType(this.form)
			this.validationErrors = validation.errors

			if (!validation.valid) {
				this.saveError = t('procest', 'Please fix the validation errors')
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
				setTimeout(() => { this.saveSuccess = false }, 3000)
			} else {
				this.saveError = this.objectStore.getError('caseType')
					|| t('procest', 'Failed to save case type')
			}
		},

		async publish() {
			this.publishErrors = []
			this.saveError = ''

			// Fetch status types for validation
			const statusTypes = await this.objectStore.fetchCollection('statusType', {
				'_filters[caseType]': this.caseTypeId,
				_limit: 100,
			})

			const result = validateForPublish(this.form, statusTypes || [])
			if (!result.valid) {
				this.publishErrors = result.errors
				// Re-fetch case type data since fetchCollection may have changed state
				return
			}

			this.form.isDraft = false
			await this.save()
		},

		async unpublish() {
			const confirmed = confirm(
				t('procest', 'Unpublishing this case type will prevent new cases from being created. Existing cases will continue to function. Continue?'),
			)
			if (!confirmed) return

			this.form.isDraft = true
			await this.save()
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
