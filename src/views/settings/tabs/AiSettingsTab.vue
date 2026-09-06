<template>
	<div class="ai-settings-tab">
		<h2>{{ t('dossiq', 'AI-Assisted Processing') }}</h2>

		<!-- Global toggle -->
		<div class="ai-settings-tab__section">
			<NcCheckboxRadioSwitch
				:modelValue="settings.ai_enabled"
				@update:modelValue="(v) => updateSetting('ai_enabled', v)">
				{{ t('dossiq', 'Enable AI-assisted processing') }}
			</NcCheckboxRadioSwitch>
		</div>

		<template v-if="settings.ai_enabled">
			<!-- Model configuration -->
			<div class="ai-settings-tab__section">
				<h3>{{ t('dossiq', 'Model Configuration') }}</h3>

				<div class="form-group">
					<label>{{ t('dossiq', 'Model type') }}</label>
					<NcCheckboxRadioSwitch
						:modelValue="settings.ai_model_type === 'local'"
						type="radio"
						name="model_type"
						@update:modelValue="
							() => updateSetting('ai_model_type', 'local')
						">
						{{ t('dossiq', 'Local (Ollama)') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:modelValue="settings.ai_model_type === 'cloud'"
						type="radio"
						name="model_type"
						@update:modelValue="
							() => updateSetting('ai_model_type', 'cloud')
						">
						{{ t('dossiq', 'Cloud') }}
					</NcCheckboxRadioSwitch>
				</div>

				<NcNoteCard v-if="settings.ai_model_type === 'cloud'" type="warning">
					{{
						t(
							'dossiq',
							'Warning: Case data will be sent to an external service. Ensure this complies with your data processing agreements.',
						)
					}}
				</NcNoteCard>

				<div class="form-group">
					<NcTextField
						:modelValue="settings.ai_model_url"
						:label="t('dossiq', 'Model endpoint URL')"
						@update:modelValue="
							(v) => updateSetting('ai_model_url', v)
						" />
				</div>

				<div class="form-group">
					<NcTextField
						:modelValue="settings.ai_model_name"
						:label="t('dossiq', 'Model name')"
						placeholder="llama3.1"
						@update:modelValue="
							(v) => updateSetting('ai_model_name', v)
						" />
				</div>

				<div v-if="settings.ai_model_type === 'cloud'" class="form-group">
					<NcPasswordField
						:modelValue="settings.ai_api_key"
						:label="t('dossiq', 'API Key')"
						@update:modelValue="(v) => updateSetting('ai_api_key', v)" />
				</div>
			</div>

			<!-- Feature toggles -->
			<div class="ai-settings-tab__section">
				<h3>{{ t('dossiq', 'Features') }}</h3>
				<NcCheckboxRadioSwitch
					v-for="feature in featureToggles"
					:key="feature.key"
					:modelValue="settings[feature.key]"
					@update:modelValue="(v) => updateSetting(feature.key, v)">
					{{ feature.label }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Privacy -->
			<div class="ai-settings-tab__section">
				<h3>{{ t('dossiq', 'Privacy & Compliance') }}</h3>
				<NcCheckboxRadioSwitch
					:modelValue="settings.ai_pii_stripping"
					@update:modelValue="(v) => updateSetting('ai_pii_stripping', v)">
					{{
						t(
							'dossiq',
							'Strip PII (BSN, financial data) from AI prompts',
						)
					}}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:modelValue="settings.ai_dpia_acknowledged"
					@update:modelValue="
						(v) => updateSetting('ai_dpia_acknowledged', v)
					">
					{{
						t(
							'dossiq',
							'DPIA (Data Protection Impact Assessment) has been completed',
						)
					}}
				</NcCheckboxRadioSwitch>
				<NcNoteCard v-if="!settings.ai_dpia_acknowledged" type="warning">
					{{
						t(
							'dossiq',
							'A DPIA is required before using AI features with personal data. This must be acknowledged before AI features can be activated.',
						)
					}}
				</NcNoteCard>
			</div>

			<!-- Health check -->
			<div class="ai-settings-tab__section">
				<h3>{{ t('dossiq', 'Connection Test') }}</h3>
				<NcButton :disabled="healthLoading" @click="testHealth">
					{{ t('dossiq', 'Test connection') }}
				</NcButton>
				<NcLoadingIcon v-if="healthLoading" :size="20" />
				<NcNoteCard
					v-if="healthResult"
					:type="healthResult.healthy ? 'success' : 'error'">
					{{ healthResult.message }}
					<template v-if="healthResult.responseTimeMs">
						({{ healthResult.responseTimeMs }}ms)
					</template>
				</NcNoteCard>
			</div>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcPasswordField,
	NcTextField,
} from '@nextcloud/vue'
import {
	getAiSettings,
	testAiHealth,
	updateAiSettings,
} from '../../../services/aiApi.js'

export default {
	name: 'AiSettingsTab',
	components: {
		NcButton,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
	},

	data() {
		return {
			settings: {
				ai_enabled: false,
				ai_model_type: 'local',
				ai_model_url: '',
				ai_model_name: '',
				ai_api_key: '',
				ai_feature_classification: true,
				ai_feature_extraction: true,
				ai_feature_qa: true,
				ai_feature_summary: true,
				ai_feature_routing: true,
				ai_feature_decision_support: true,
				ai_pii_stripping: true,
				ai_dpia_acknowledged: false,
			},

			healthLoading: false,
			healthResult: null,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		featureToggles() {
			return [
				{
					key: 'ai_feature_classification',
					label: t('dossiq', 'Document classification'),
				},
				{
					key: 'ai_feature_extraction',
					label: t('dossiq', 'Data extraction'),
				},
				{ key: 'ai_feature_qa', label: t('dossiq', 'Knowledge base Q&A') },
				{
					key: 'ai_feature_summary',
					label: t('dossiq', 'Auto-summarization'),
				},
				{
					key: 'ai_feature_routing',
					label: t('dossiq', 'Routing suggestions'),
				},
				{
					key: 'ai_feature_decision_support',
					label: t('dossiq', 'Decision support'),
				},
			]
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
	async mounted() {
		try {
			const response = await getAiSettings()
			this.settings = { ...this.settings, ...(response.settings || {}) }
		} catch (e) {
			// Use defaults
		}
	},

	methods: {
		t,
		/**
		 * @param {string} key The key.
		 * @param {string|number|boolean|object} value The new value.
		 * @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md
		 */
		async updateSetting(key, value) {
			this.settings[key] = value
			try {
				await updateAiSettings({ [key]: value })
			} catch (e) {
				// Revert on failure would go here
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-ai-assistance/tasks.md */
		async testHealth() {
			this.healthLoading = true
			this.healthResult = null
			try {
				this.healthResult = await testAiHealth()
			} catch (e) {
				this.healthResult = {
					healthy: false,
					message:
						e.response?.data?.error || t('dossiq', 'Connection failed'),
				}
			} finally {
				this.healthLoading = false
			}
		},
	},
}
</script>

<style scoped>
.ai-settings-tab__section {
	margin-bottom: 24px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
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
