<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  User-facing Vervanging (substitution) settings — register/revoke your own
  waarnemer and review your active/past substitutions. Creation happens in the
  isolated SubstitutionFormModal (ADR-004). The backend enforces that a regular
  user may only manage substitutions where they are the absentee.

  @spec openspec/specs/handler-vervanging-waarneming/spec.md
-->
<template>
	<div class="substitution-settings">
		<div class="substitution-settings__header">
			<NcButton type="primary" @click="showModal = true">
				<template #icon>
					<AccountSwitch :size="20" />
				</template>
				{{ t('dossiq', 'Register substitution') }}
			</NcButton>
		</div>

		<p class="substitution-settings__intro">
			{{
				t(
					'procest',
					'Register a colleague to handle your cases and tasks while you are away. They will see your work in their My Work and receive your deadline signals for the period. Substitution does not grant any extra permissions — your colleague only sees what they are already allowed to access.',
				)
			}}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="ownSubstitutions.length === 0"
			:name="t('dossiq', 'No substitutions')"
			:description="t('dossiq', 'You have not registered any waarnemer yet.')">
			<template #icon>
				<AccountSwitch :size="48" />
			</template>
		</NcEmptyContent>

		<table v-else class="substitution-settings__table">
			<thead>
				<tr>
					<th scope="col">{{ t('dossiq', 'Substitute') }}</th>
					<th scope="col">{{ t('dossiq', 'Period') }}</th>
					<th scope="col">{{ t('dossiq', 'Scope') }}</th>
					<th scope="col">{{ t('dossiq', 'Reason') }}</th>
					<th scope="col">{{ t('dossiq', 'Status') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="sub in ownSubstitutions" :key="sub.id">
					<td>{{ sub.substitute }}</td>
					<td>{{ sub.startDate }} → {{ sub.endDate }}</td>
					<td>{{ sub.scope }}</td>
					<td>{{ sub.reason }}</td>
					<td>
						<span :class="`status status--${sub.status}`">{{
							sub.status
						}}</span>
					</td>
					<td>
						<NcButton
							v-if="sub.status === 'active'"
							type="tertiary"
							:aria-label="t('dossiq', 'Revoke substitution')"
							@click="revoke(sub.id)">
							{{ t('dossiq', 'Revoke') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<SubstitutionFormModal
			v-if="showModal"
			@created="onCreated"
			@close="showModal = false" />
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AccountSwitch from 'vue-material-design-icons/AccountSwitch.vue'
import SubstitutionFormModal from '../../modals/SubstitutionFormModal.vue'
import {
	listSubstitutions,
	revokeSubstitution,
} from '../../services/substitutionApi.js'

export default {
	name: 'SubstitutionSettings',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		AccountSwitch,
		SubstitutionFormModal,
	},

	data() {
		return {
			loading: true,
			substitutions: [],
			showModal: false,
		}
	},

	computed: {
		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		currentUser() {
			return (getCurrentUser() && getCurrentUser().uid) || ''
		},

		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		ownSubstitutions() {
			return this.substitutions.filter((s) => s.absentee === this.currentUser)
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		async load() {
			this.loading = true
			try {
				this.substitutions = await listSubstitutions()
			} catch (err) {
				console.error('[SubstitutionSettings] load failed', err)
				this.substitutions = []
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		async onCreated() {
			this.showModal = false
			await this.load()
		},

		/**
		 * @param {string} id Identifier of the id.
		 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
		 */
		async revoke(id) {
			try {
				await revokeSubstitution(id)
				await this.load()
			} catch (err) {
				console.error('[SubstitutionSettings] revoke failed', err)
			}
		},
	},
}
</script>

<style scoped>
.substitution-settings {
	padding: 20px;
	max-width: 900px;
}

.substitution-settings__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.substitution-settings__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.substitution-settings__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9rem;
}

.substitution-settings__table th,
.substitution-settings__table td {
	text-align: left;
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
}

.status {
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 0.8rem;
}

.status--active {
	background: var(--color-success);
	color: var(--color-primary-element-text);
}

.status--ended,
.status--revoked {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
</style>
