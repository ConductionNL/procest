<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="financial-integration-tab">
		<p class="financial-integration-tab__description">
			{{
				t(
					'dossiq',
					'Configure the shared secret used to validate the X-Procest-Signature HMAC-SHA256 header on the public dwangsom payment-confirmation callback ({endpoint}). Without a configured secret, every callback request is rejected (HTTP 401) — an unconfigured secret is never treated as an implicit pass.',
					{ endpoint: '/apps/dossiq/api/dwangsom/payment-callback' },
				)
			}}
		</p>

		<NcNoteCard v-if="!secretConfigured" type="warning">
			{{
				t(
					'dossiq',
					'No callback secret is configured. Every dwangsom payment-confirmation callback is currently being rejected with HTTP 401.',
				)
			}}
		</NcNoteCard>

		<div class="form-group">
			<NcPasswordField
				:modelValue="dwangsomCallbackSecret"
				:label="t('dossiq', 'Dwangsom callback secret')"
				:helperText="
					t(
						'dossiq',
						'Shared HMAC-SHA256 signing secret. Provide this value to the ERP/openconnector integrator so it can sign X-Procest-Signature headers.',
					)
				"
				@update:modelValue="onSecretInput" />
		</div>

		<NcButton :disabled="saving" @click="generateSecret">
			{{ t('dossiq', 'Generate random secret') }}
		</NcButton>
		<NcLoadingIcon v-if="saving" :size="20" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import { useSettingsStore } from '../../../store/modules/settings.js'

export default {
	name: 'FinancialIntegrationTab',
	components: { NcButton, NcPasswordField, NcLoadingIcon, NcNoteCard },
	data() {
		return {
			dwangsomCallbackSecret: '',
			saving: false,
		}
	},

	computed: {
		/** @spec openspec/changes/enforce-dwangsom-callback-signature/tasks.md#task-2 */
		secretConfigured() {
			return !!this.dwangsomCallbackSecret
		},
	},

	/** @spec openspec/changes/enforce-dwangsom-callback-signature/tasks.md#task-2 */
	async created() {
		const store = useSettingsStore()
		if (!store.isInitialized) {
			await store.fetchSettings()
		}
		const config = store.getConfig || {}
		// The generic settings endpoint masks secrets as '***' for non-admins;
		// only render a placeholder in that case rather than the mask itself.
		this.dwangsomCallbackSecret =
			config.dwangsom_callback_secret === '***'
				? ''
				: config.dwangsom_callback_secret || ''
	},

	methods: {
		t,
		/**
		 * @param {string|number|boolean|object} value The new value.
		 * @spec openspec/changes/enforce-dwangsom-callback-signature/tasks.md#task-2
		 */
		async onSecretInput(value) {
			this.dwangsomCallbackSecret = value
			await this.persist(value)
		},

		/** @spec openspec/changes/enforce-dwangsom-callback-signature/tasks.md#task-2 */
		async generateSecret() {
			const bytes = new Uint8Array(32)
			crypto.getRandomValues(bytes)
			const secret = Array.from(bytes, (b) =>
				b.toString(16).padStart(2, '0'),
			).join('')
			this.dwangsomCallbackSecret = secret
			await this.persist(secret)
		},

		/**
		 * @param {string|number|boolean|object} value The new value.
		 * @spec openspec/changes/enforce-dwangsom-callback-signature/tasks.md#task-2
		 */
		async persist(value) {
			this.saving = true
			try {
				const store = useSettingsStore()
				await store.saveSettings({ dwangsom_callback_secret: value })
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.financial-integration-tab {
	max-width: 600px;
}

.financial-integration-tab__description {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.form-group {
	margin-bottom: 12px;
}
</style>
