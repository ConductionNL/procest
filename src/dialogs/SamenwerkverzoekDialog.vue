<!--
 SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Initiate Samenwerkverzoek')"
		:canClose="true"
		@close="$emit('close')">
		<template #default>
			<div class="samenwerk-dialog">
				<p class="samenwerk-dialog__intro">
					{{
						t(
							'dossiq',
							'Request collaboration from another bevoegd gezag for this vergunningaanvraag.',
						)
					}}
				</p>

				<NcTextField
					v-model="requestedCompetentAuthority"
					:label="t('dossiq', 'Aangezocht bevoegd gezag (OIN or name)')"
					:required="true"
					:placeholder="
						t('dossiq', 'e.g. Waterschap Amstel, Gooi en Vecht')
					" />

				<div class="samenwerk-dialog__suggestions">
					<NcButton
						v-for="org in commonOrganizations"
						:key="org"
						type="tertiary"
						@click="requestedCompetentAuthority = org">
						{{ org }}
					</NcButton>
				</div>

				<NcTextArea
					v-model="rationale"
					:label="t('dossiq', 'Rationale')"
					:placeholder="
						t('dossiq', 'Explain why collaboration is needed...')
					"
					rows="4" />

				<div v-if="error" class="samenwerk-dialog__error">
					{{ error }}
				</div>
			</div>
		</template>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!requestedCompetentAuthority || submitting"
				@click="submit">
				{{ t('dossiq', 'Initiate') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'SamenwerkverzoekDialog',
	components: { NcButton, NcDialog, NcTextArea, NcTextField },
	props: {
		caseId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'initiated'],
	data() {
		return {
			requestedCompetentAuthority: '',
			rationale: '',
			submitting: false,
			error: null,
			commonOrganizations: [
				'Provincie Noord-Holland',
				'Waterschap Amstel, Gooi en Vecht',
				'Rijkswaterstaat',
			],
		}
	},

	methods: {
		t,
		/**
		 * Send a samenwerkverzoek to the requested competent authority.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-req-dso-011-multi-tenancy-and-bevoegd-gezag-isolation
		 */
		async submit() {
			if (!this.requestedCompetentAuthority) {
				return
			}

			this.submitting = true
			this.error = null
			try {
				const { data } = await axios.post(
					generateUrl(
						'/apps/dossiq/api/dso/cases/'
							+ encodeURIComponent(this.caseId)
							+ '/samenwerking',
					),
					{
						requestedCompetentAuthority:
							this.requestedCompetentAuthority,
						rationale: this.rationale,
					},
				)
				this.$emit('initiated', data)
			} catch (e) {
				this.error = t(
					'dossiq',
					'Could not initiate samenwerkverzoek. Please try again.',
				)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.samenwerk-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

.samenwerk-dialog__intro {
	color: var(--color-text-maxcontrast);
}

.samenwerk-dialog__suggestions {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.samenwerk-dialog__error {
	color: var(--color-error);
	font-size: 0.9em;
}
</style>
