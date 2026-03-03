<template>
	<CnSettingsSection
		:name="t('procest', 'Configuration')"
		:description="t('procest', 'Register and schema settings')"
		doc-url="https://procest.app"
		:loading="loading">
		<template #actions>
			<NcButton type="primary" @click="save">
				{{ t('procest', 'Save') }}
			</NcButton>
		</template>

		<div class="settings-form">
			<div class="form-group">
				<label>{{ t('procest', 'Register') }}</label>
				<NcTextField
					:value="form.register"
					:label="t('procest', 'Register')"
					@update:value="v => form.register = v" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Case schema') }}</label>
				<NcTextField
					:value="form.case_schema"
					:label="t('procest', 'Case schema')"
					@update:value="v => form.case_schema = v" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Task schema') }}</label>
				<NcTextField
					:value="form.task_schema"
					:label="t('procest', 'Task schema')"
					@update:value="v => form.task_schema = v" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Status schema') }}</label>
				<NcTextField
					:value="form.status_schema"
					:label="t('procest', 'Status schema')"
					@update:value="v => form.status_schema = v" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Role schema') }}</label>
				<NcTextField
					:value="form.role_schema"
					:label="t('procest', 'Role schema')"
					@update:value="v => form.role_schema = v" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Result schema') }}</label>
				<NcTextField
					:value="form.result_schema"
					:label="t('procest', 'Result schema')"
					@update:value="v => form.result_schema = v" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Decision schema') }}</label>
				<NcTextField
					:value="form.decision_schema"
					:label="t('procest', 'Decision schema')"
					@update:value="v => form.decision_schema = v" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Case type schema') }}</label>
				<NcTextField
					:value="form.case_type_schema"
					:label="t('procest', 'Case type schema')"
					@update:value="v => form.case_type_schema = v" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Status type schema') }}</label>
				<NcTextField
					:value="form.status_type_schema"
					:label="t('procest', 'Status type schema')"
					@update:value="v => form.status_type_schema = v" />
			</div>
		</div>

		<p v-if="saved" class="success-message">
			{{ t('procest', 'Configuration saved') }}
		</p>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcTextField } from '@nextcloud/vue'
import { useSettingsStore } from '../../store/modules/settings.js'

export default {
	name: 'Settings',
	components: {
		CnSettingsSection,
		NcButton,
		NcTextField,
	},
	data() {
		return {
			form: {
				register: '',
				case_schema: '',
				task_schema: '',
				status_schema: '',
				role_schema: '',
				result_schema: '',
				decision_schema: '',
				case_type_schema: '',
				status_type_schema: '',
			},
			saved: false,
		}
	},
	computed: {
		settingsStore() {
			return useSettingsStore()
		},
		loading() {
			return this.settingsStore.isLoading
		},
	},
	async mounted() {
		const config = await this.settingsStore.fetchSettings()
		if (config) {
			this.form = { ...this.form, ...config }
		}
	},
	methods: {
		async save() {
			this.saved = false
			const result = await this.settingsStore.saveSettings(this.form)
			if (result) {
				this.saved = true
				setTimeout(() => { this.saved = false }, 3000)
			}
		},
	},
}
</script>

<style scoped>
.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.success-message {
	color: var(--color-success);
	margin-top: 12px;
}
</style>
