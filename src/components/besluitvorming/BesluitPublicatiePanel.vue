<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -->
<template>
	<div class="besluit-publicatie-panel">
		<div v-if="state === 'success'" class="besluit-publicatie-panel__success">
			<span
				class="besluit-publicatie-panel__badge besluit-publicatie-panel__badge--success">
				{{ t('dossiq', 'Gepubliceerd') }}
			</span>
			<a
				v-if="reference"
				:href="reference"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('dossiq', 'View publication in DROP/LVBB') }}
			</a>
		</div>

		<div v-else-if="state === 'failed'" class="besluit-publicatie-panel__failed">
			<span
				class="besluit-publicatie-panel__badge besluit-publicatie-panel__badge--failed">
				{{ t('dossiq', 'Publicatie mislukt') }}
			</span>
			<p>
				{{
					errorMessage || t('dossiq', 'The publication could not be sent.')
				}}
			</p>
			<NcButton type="primary" :disabled="busy" @click="retry">
				{{ t('dossiq', 'Opnieuw proberen') }}
			</NcButton>
		</div>

		<div v-else class="besluit-publicatie-panel__pending">
			<span class="besluit-publicatie-panel__badge">
				{{ t('dossiq', 'Publicatie in behandeling') }}
			</span>
			<NcButton type="secondary" :disabled="busy" @click="retry">
				{{ t('dossiq', 'Nu publiceren') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { publishBesluit } from '../../services/besluitvormingApi.js'

export default {
	name: 'BesluitPublicatiePanel',
	components: { NcButton },
	props: {
		caseId: {
			type: String,
			required: true,
		},

		initialState: {
			type: String,
			default: 'pending',
		},

		publicationReference: {
			type: String,
			default: '',
		},
	},

	emits: ['published'],

	data() {
		return {
			state: this.initialState,
			reference: this.publicationReference,
			errorMessage: '',
			busy: false,
		}
	},

	methods: {
		/**
		 * Trigger (retry) the DROP/LVBB publication.
		 *
		 * @spec openspec/specs/besluitvorming-workflow/spec.md
		 */
		async retry() {
			this.busy = true
			this.errorMessage = ''
			try {
				const result = await publishBesluit(this.caseId)
				if (result && result.ok) {
					this.state = 'success'
					this.reference = result.publicatieReferentie || this.reference
					this.$emit('published', result)
				} else {
					this.state = 'failed'
					this.errorMessage = this.mapError(result && result.error)
				}
			} catch (error) {
				this.state = 'failed'
				this.errorMessage = this.t(
					'dossiq',
					'The publication could not be sent.',
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Map a backend error code to a human message.
		 *
		 * @param {string} code The error code.
		 * @return {string} A localized message.
		 *
		 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-opencatalogi-absence-is-handled-gracefully
		 */
		mapError(code) {
			if (code === 'not_configured') {
				return this.t('dossiq', 'No DROP/LVBB endpoint has been configured.')
			}
			if (code === 'no_decision') {
				return this.t(
					'dossiq',
					'No decision has been recorded to publish yet.',
				)
			}
			return this.t('dossiq', 'The publication could not be sent.')
		},
	},
}
</script>

<style scoped>
.decision-publicatie-panel__badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	margin-bottom: 8px;
}

.decision-publicatie-panel__badge--success {
	background: var(--color-success);
	color: var(--color-primary-element-text);
}

.decision-publicatie-panel__badge--failed {
	background: var(--color-error);
	color: var(--color-primary-element-text);
}
</style>
