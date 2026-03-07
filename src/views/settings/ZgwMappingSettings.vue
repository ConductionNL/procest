<template>
	<div class="zgw-mapping-settings">
		<div class="mapping-list">
			<table>
				<thead>
					<tr>
						<th>{{ t('procest', 'ZGW Resource') }}</th>
						<th>{{ t('procest', 'Status') }}</th>
						<th>{{ t('procest', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="key in resourceKeys" :key="key">
						<td>{{ key }}</td>
						<td>
							<span v-if="mappings[key] && mappings[key].enabled" class="status-enabled">
								{{ t('procest', 'Enabled') }}
							</span>
							<span v-else-if="mappings[key]" class="status-disabled">
								{{ t('procest', 'Disabled') }}
							</span>
							<span v-else class="status-unconfigured">
								{{ t('procest', 'Not configured') }}
							</span>
						</td>
						<td>
							<NcButton type="secondary" @click="editMapping(key)">
								{{ t('procest', 'Edit') }}
							</NcButton>
							<NcButton type="tertiary" @click="resetMapping(key)">
								{{ t('procest', 'Reset') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<NcDialog v-if="editingKey"
			:name="t('procest', 'Edit ZGW Mapping: {key}', { key: editingKey })"
			size="large"
			@closing="editingKey = null">
			<div class="mapping-editor">
				<div class="form-group">
					<label>{{ t('procest', 'Enabled') }}</label>
					<NcCheckboxRadioSwitch
						:checked="editForm.enabled"
						@update:checked="v => editForm.enabled = v">
						{{ t('procest', 'Enable this mapping') }}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Source Register') }}</label>
					<NcTextField
						:value="editForm.sourceRegister"
						:label="t('procest', 'Register ID')"
						@update:value="v => editForm.sourceRegister = v" />
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Source Schema') }}</label>
					<NcTextField
						:value="editForm.sourceSchema"
						:label="t('procest', 'Schema ID')"
						@update:value="v => editForm.sourceSchema = v" />
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Property Mapping (outbound: English → Dutch)') }}</label>
					<textarea
						v-model="propertyMappingJson"
						class="mapping-textarea"
						rows="10" />
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Reverse Mapping (inbound: Dutch → English)') }}</label>
					<textarea
						v-model="reverseMappingJson"
						class="mapping-textarea"
						rows="10" />
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Value Mappings (enum translations)') }}</label>
					<textarea
						v-model="valueMappingJson"
						class="mapping-textarea"
						rows="6" />
				</div>

				<div class="form-group">
					<label>{{ t('procest', 'Query Parameter Mapping') }}</label>
					<textarea
						v-model="queryParamMappingJson"
						class="mapping-textarea"
						rows="6" />
				</div>

				<p v-if="jsonError" class="error-message">
					{{ jsonError }}
				</p>
			</div>

			<template #actions>
				<NcButton type="tertiary" @click="editingKey = null">
					{{ t('procest', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" @click="saveMapping">
					{{ t('procest', 'Save') }}
				</NcButton>
			</template>
		</NcDialog>

		<p v-if="saved" class="success-message">
			{{ t('procest', 'Mapping saved successfully') }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcTextField } from '@nextcloud/vue'
import { useZgwMappingStore } from '../../store/modules/zgwMapping.js'

export default {
	name: 'ZgwMappingSettings',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcTextField,
	},
	data() {
		return {
			editingKey: null,
			editForm: {
				enabled: true,
				sourceRegister: '',
				sourceSchema: '',
			},
			propertyMappingJson: '{}',
			reverseMappingJson: '{}',
			valueMappingJson: '{}',
			queryParamMappingJson: '{}',
			jsonError: null,
			saved: false,
		}
	},
	computed: {
		store() {
			return useZgwMappingStore()
		},
		mappings() {
			return this.store.mappings
		},
		resourceKeys() {
			return [
				'zaak', 'zaaktype', 'status', 'statustype',
				'resultaat', 'resultaattype', 'rol', 'roltype',
				'eigenschap', 'besluit', 'besluittype', 'informatieobjecttype',
			]
		},
	},
	async mounted() {
		await this.store.fetchMappings()
	},
	methods: {
		editMapping(key) {
			const mapping = this.mappings[key] || {}
			this.editingKey = key
			this.editForm = {
				enabled: mapping.enabled ?? true,
				sourceRegister: mapping.sourceRegister || '',
				sourceSchema: mapping.sourceSchema || '',
			}
			this.propertyMappingJson = JSON.stringify(mapping.propertyMapping || {}, null, 2)
			this.reverseMappingJson = JSON.stringify(mapping.reverseMapping || {}, null, 2)
			this.valueMappingJson = JSON.stringify(mapping.valueMapping || {}, null, 2)
			this.queryParamMappingJson = JSON.stringify(mapping.queryParameterMapping || {}, null, 2)
			this.jsonError = null
		},

		async saveMapping() {
			this.jsonError = null

			let propertyMapping, reverseMapping, valueMapping, queryParameterMapping
			try {
				propertyMapping = JSON.parse(this.propertyMappingJson)
				reverseMapping = JSON.parse(this.reverseMappingJson)
				valueMapping = JSON.parse(this.valueMappingJson)
				queryParameterMapping = JSON.parse(this.queryParamMappingJson)
			} catch (e) {
				this.jsonError = t('procest', 'Invalid JSON in one of the mapping fields: {error}', { error: e.message })
				return
			}

			const config = {
				...this.editForm,
				zgwResource: this.editingKey,
				zgwApiVersion: '1',
				propertyMapping,
				reverseMapping,
				valueMapping,
				queryParameterMapping,
			}

			const result = await this.store.saveMapping(this.editingKey, config)
			if (result) {
				this.editingKey = null
				this.saved = true
				setTimeout(() => { this.saved = false }, 3000)
			}
		},

		async resetMapping(key) {
			await this.store.resetMapping(key)
			this.saved = true
			setTimeout(() => { this.saved = false }, 3000)
		},
	},
}
</script>

<style scoped>
.zgw-mapping-settings table {
	width: 100%;
	border-collapse: collapse;
}

.zgw-mapping-settings th,
.zgw-mapping-settings td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.zgw-mapping-settings th {
	font-weight: bold;
}

.status-enabled {
	color: var(--color-success);
}

.status-disabled {
	color: var(--color-warning);
}

.status-unconfigured {
	color: var(--color-text-maxcontrast);
}

.mapping-editor .form-group {
	margin-bottom: 16px;
}

.mapping-editor label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.mapping-textarea {
	width: 100%;
	font-family: monospace;
	font-size: 13px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.error-message {
	color: var(--color-error);
	margin-top: 8px;
}

.success-message {
	color: var(--color-success);
	margin-top: 12px;
}
</style>
