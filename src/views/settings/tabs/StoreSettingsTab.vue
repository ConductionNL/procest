<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  StoreSettingsTab — the registry connection behind the Store page.

  The token is WRITE-ONLY. This form can never show the stored value; it
  reports only whether one is set, and an empty field on save leaves the
  stored token alone. A settings form that round-trips a credential through
  the browser has handed it to every extension the administrator runs.

  @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md
-->
<template>
	<div class="store-settings">
		<NcNoteCard type="info">
			{{
				t(
					'dossiq',
					'A store registry is another OpenRegister instance that publishes case configuration. Leave the address empty to keep dossiq offline: nothing is requested from the network until one is set.',
				)
			}}
		</NcNoteCard>

		<NcTextField
			v-model="registryUrl"
			:label="t('dossiq', 'Registry address')"
			placeholder="https://registry.example.org"
			data-testid="store-registry-url" />

		<NcTextField
			v-model="registryRegister"
			:label="t('dossiq', 'Register on that instance')"
			data-testid="store-registry-register" />

		<NcPasswordField
			v-model="registryToken"
			:label="tokenLabel"
			data-testid="store-registry-token" />

		<p class="store-settings__hint">
			{{
				t(
					'dossiq',
					'The token is never shown again after saving. Leave it empty to keep the one already stored.',
				)
			}}
		</p>

		<NcButton variant="primary" :disabled="saving" @click="save">
			{{
				saving ? t('dossiq', 'Saving…') : t('dossiq', 'Save store settings')
			}}
		</NcButton>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcPasswordField, NcTextField } from '@nextcloud/vue'

export default {
	name: 'StoreSettingsTab',

	components: {
		NcButton,
		NcNoteCard,
		NcPasswordField,
		NcTextField,
	},

	data() {
		return {
			registryUrl: '',
			registryRegister: 'dossiq',
			registryToken: '',
			registryTokenSet: false,
			saving: false,
		}
	},

	computed: {
		/**
		 * Name the field for what it will DO, so an administrator who leaves it
		 * empty knows the stored token survives.
		 *
		 * @return {string} The token field label.
		 *
		 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md
		 */
		tokenLabel() {
			return this.registryTokenSet
				? t('dossiq', 'Replace the registry token')
				: t('dossiq', 'Registry token')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the current connection. Never receives the token itself.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md
		 */
		async load() {
			try {
				const response = await fetch(
					generateUrl('/apps/dossiq/api/store/settings'),
					{
						headers: { requesttoken: window.OC?.requestToken },
					},
				)
				if (response.ok !== true) {
					return
				}

				const body = await response.json()
				this.registryUrl = body.registryUrl ?? ''
				this.registryRegister = body.registryRegister ?? 'dossiq'
				this.registryTokenSet = body.registryTokenSet === true
			} catch {
				showError(t('dossiq', 'The store settings could not be loaded.'))
			}
		},

		/**
		 * Save the connection.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md
		 */
		async save() {
			this.saving = true
			try {
				const response = await fetch(
					generateUrl('/apps/dossiq/api/store/settings'),
					{
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: window.OC?.requestToken,
						},
						body: JSON.stringify({
							registryUrl: this.registryUrl,
							registryRegister: this.registryRegister,
							registryToken: this.registryToken,
						}),
					},
				)

				if (response.ok !== true) {
					showError(t('dossiq', 'The store settings could not be saved.'))
					return
				}

				const body = await response.json()
				this.registryTokenSet = body.registryTokenSet === true
				// Drop the typed token from memory: it is stored now, and the
				// field must not keep offering it back.
				this.registryToken = ''
				showSuccess(t('dossiq', 'Saved.'))
			} catch {
				showError(t('dossiq', 'The store settings could not be saved.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.store-settings {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 520px;
}

.store-settings__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
