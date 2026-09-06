<template>
	<NcDialog
		v-if="open"
		:name="t('dossiq', 'Edit ZGW Mapping: {key}', { key: resourceKey })"
		size="large"
		@closing="$emit('close')">
		<div class="mapping-editor">
			<div class="form-group">
				<label>{{ t('dossiq', 'Enabled') }}</label>
				<NcCheckboxRadioSwitch
					:modelValue="form.enabled"
					@update:modelValue="(v) => (form.enabled = v)">
					{{ t('dossiq', 'Enable this mapping') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="form-group">
				<label>{{ t('dossiq', 'Source Register') }}</label>
				<NcTextField
					:modelValue="form.sourceRegister"
					:label="t('dossiq', 'Register ID')"
					@update:modelValue="(v) => (form.sourceRegister = v)" />
			</div>

			<div class="form-group">
				<label>{{ t('dossiq', 'Source Schema') }}</label>
				<NcTextField
					:modelValue="form.sourceSchema"
					:label="t('dossiq', 'Schema ID')"
					@update:modelValue="(v) => (form.sourceSchema = v)" />
			</div>

			<div class="form-group">
				<label for="zgw-mapping-property">{{
					t('dossiq', 'Property Mapping (outbound: English → Dutch)')
				}}</label>
				<textarea
					id="zgw-mapping-property"
					v-model="propertyMappingJson"
					class="mapping-textarea"
					rows="10" />
			</div>

			<div class="form-group">
				<label for="zgw-mapping-reverse">{{
					t('dossiq', 'Reverse Mapping (inbound: Dutch → English)')
				}}</label>
				<textarea
					id="zgw-mapping-reverse"
					v-model="reverseMappingJson"
					class="mapping-textarea"
					rows="10" />
			</div>

			<div class="form-group">
				<label for="zgw-mapping-value">{{
					t('dossiq', 'Value Mappings (enum translations)')
				}}</label>
				<textarea
					id="zgw-mapping-value"
					v-model="valueMappingJson"
					class="mapping-textarea"
					rows="6" />
			</div>

			<div class="form-group">
				<label for="zgw-mapping-query-param">{{
					t('dossiq', 'Query Parameter Mapping')
				}}</label>
				<textarea
					id="zgw-mapping-query-param"
					v-model="queryParamMappingJson"
					class="mapping-textarea"
					rows="6" />
			</div>

			<p v-if="jsonError" class="error-message">
				{{ jsonError }}
			</p>
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" @click="onSave">
				{{ t('dossiq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcTextField,
} from '@nextcloud/vue'

export default {
	name: 'ZgwMappingDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		resourceKey: {
			type: String,
			default: '',
		},

		mapping: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['save', 'close'],
	data() {
		return {
			form: {
				enabled: true,
				sourceRegister: '',
				sourceSchema: '',
			},

			propertyMappingJson: '{}',
			reverseMappingJson: '{}',
			valueMappingJson: '{}',
			queryParamMappingJson: '{}',
			jsonError: null,
		}
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * @param {string|number|boolean|object} value The new value.
			 * @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md
			 */
			handler(value) {
				if (value) {
					this.initFromMapping()
				}
			},
		},
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md */
		initFromMapping() {
			const mapping = this.mapping || {}
			this.form = {
				enabled: mapping.enabled ?? true,
				sourceRegister: mapping.sourceRegister || '',
				sourceSchema: mapping.sourceSchema || '',
			}
			this.propertyMappingJson = JSON.stringify(
				mapping.propertyMapping || {},
				null,
				2,
			)
			this.reverseMappingJson = JSON.stringify(
				mapping.reverseMapping || {},
				null,
				2,
			)
			this.valueMappingJson = JSON.stringify(
				mapping.valueMapping || {},
				null,
				2,
			)
			this.queryParamMappingJson = JSON.stringify(
				mapping.queryParameterMapping || {},
				null,
				2,
			)
			this.jsonError = null
		},

		/** @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md */
		onSave() {
			this.jsonError = null

			let propertyMapping, reverseMapping, valueMapping, queryParameterMapping
			try {
				propertyMapping = JSON.parse(this.propertyMappingJson)
				reverseMapping = JSON.parse(this.reverseMappingJson)
				valueMapping = JSON.parse(this.valueMappingJson)
				queryParameterMapping = JSON.parse(this.queryParamMappingJson)
			} catch (e) {
				this.jsonError = t(
					'dossiq',
					'Invalid JSON in one of the mapping fields: {error}',
					{ error: e.message },
				)
				return
			}

			const config = {
				...this.form,
				zgwResource: this.resourceKey,
				zgwApiVersion: '1',
				propertyMapping,
				reverseMapping,
				valueMapping,
				queryParameterMapping,
			}

			this.$emit('save', config)
		},
	},
}
</script>

<style scoped>
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
</style>
