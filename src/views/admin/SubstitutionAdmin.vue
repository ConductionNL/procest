<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Coordinator substitution admin — view all substitutions, register on behalf
  of an absent handler, revoke, inspect the capacity-stamped action list of a
  substitution, and launch a bulk reassignment. All authorisation is enforced
  server-side (coordinator role); non-coordinators receive 403 from the API and
  see an empty list.

  @spec openspec/specs/handler-vervanging-waarneming/spec.md
-->
<template>
	<div class="substitution-admin">
		<div class="substitution-admin__header">
			<h2>{{ t('dossiq', 'Substitutions & reassignment') }}</h2>
			<div class="substitution-admin__actions">
				<NcButton type="secondary" @click="showReassign = true">
					<template #icon>
						<AccountArrowRight :size="20" />
					</template>
					{{ t('dossiq', 'Bulk reassign') }}
				</NcButton>
				<NcButton type="primary" @click="showForm = true">
					<template #icon>
						<AccountSwitch :size="20" />
					</template>
					{{ t('dossiq', 'Register for handler') }}
				</NcButton>
			</div>
		</div>

		<div class="substitution-admin__filters">
			<input
				v-model="filterText"
				type="search"
				class="substitution-admin__search"
				:aria-label="t('dossiq', 'Filter by handler…')"
				:placeholder="t('dossiq', 'Filter by handler…')" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="filtered.length === 0"
			:name="t('dossiq', 'No substitutions')" />

		<table v-else class="substitution-admin__table">
			<thead>
				<tr>
					<th scope="col">{{ t('dossiq', 'Absentee') }}</th>
					<th scope="col">{{ t('dossiq', 'Substitute') }}</th>
					<th scope="col">{{ t('dossiq', 'Period') }}</th>
					<th scope="col">{{ t('dossiq', 'Scope') }}</th>
					<th scope="col">{{ t('dossiq', 'Status') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="sub in filtered" :key="sub.id">
					<td>{{ sub.absentee }}</td>
					<td>{{ sub.substitute }}</td>
					<td>{{ sub.startDate }} → {{ sub.endDate }}</td>
					<td>{{ sub.scope }}</td>
					<td>
						<span :class="`status status--${sub.status}`">{{
							sub.status
						}}</span>
					</td>
					<td class="substitution-admin__row-actions">
						<NcButton type="tertiary" @click="openActions(sub)">
							{{ t('dossiq', 'Actions') }}
						</NcButton>
						<NcButton
							v-if="sub.status === 'active'"
							type="tertiary"
							@click="revoke(sub.id)">
							{{ t('dossiq', 'Revoke') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Capacity-stamped action list -->
		<div
			v-if="selectedSub"
			class="substitution-admin__detail"
			data-testid="substitution-actions">
			<h3>{{ t('dossiq', 'Actions performed under this substitution') }}</h3>
			<NcEmptyContent
				v-if="actions.length === 0"
				:name="t('dossiq', 'No actions recorded yet')" />
			<ul v-else>
				<li v-for="(a, idx) in actions" :key="idx">
					{{ a.timestamp }} — {{ a.caseTitle || a.caseId }} —
					{{ a.action }} ({{
						t('dossiq', 'on behalf of {who}', {
							who: a.actedOnBehalfOf,
						})
					}})
				</li>
			</ul>
		</div>

		<SubstitutionFormModal
			v-if="showForm"
			:allowCoordinator="true"
			@created="onCreated"
			@close="showForm = false" />

		<BulkReassignModal
			v-if="showReassign"
			@reassigned="onReassigned"
			@close="showReassign = false" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
import AccountSwitch from 'vue-material-design-icons/AccountSwitch.vue'
import BulkReassignModal from '../../modals/BulkReassignModal.vue'
import SubstitutionFormModal from '../../modals/SubstitutionFormModal.vue'
import {
	fetchSubstitutionActions,
	listSubstitutions,
	revokeSubstitution,
} from '../../services/substitutionApi.js'

export default {
	name: 'SubstitutionAdmin',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		AccountSwitch,
		AccountArrowRight,
		SubstitutionFormModal,
		BulkReassignModal,
	},

	data() {
		return {
			loading: true,
			substitutions: [],
			filterText: '',
			showForm: false,
			showReassign: false,
			selectedSub: null,
			actions: [],
		}
	},

	computed: {
		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		filtered() {
			const q = this.filterText.trim().toLowerCase()
			if (!q) {
				return this.substitutions
			}
			return this.substitutions.filter(
				(s) =>
					(s.absentee || '').toLowerCase().includes(q)
					|| (s.substitute || '').toLowerCase().includes(q),
			)
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
				console.error('[SubstitutionAdmin] load failed', err)
				this.substitutions = []
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		async onCreated() {
			this.showForm = false
			await this.load()
		},

		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		async onReassigned() {
			this.showReassign = false
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
				console.error('[SubstitutionAdmin] revoke failed', err)
			}
		},

		/**
		 * @param {object} sub The sub.
		 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
		 */
		async openActions(sub) {
			this.selectedSub = sub
			this.actions = []
			try {
				this.actions = await fetchSubstitutionActions(sub.id)
			} catch (err) {
				console.error('[SubstitutionAdmin] actions failed', err)
				this.actions = []
			}
		},
	},
}
</script>

<style scoped>
.substitution-admin {
	padding: 20px;
}

.substitution-admin__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.substitution-admin__actions {
	display: flex;
	gap: 8px;
}

.substitution-admin__filters {
	margin-bottom: 12px;
}

.substitution-admin__search {
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	min-width: 260px;
}

.substitution-admin__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9rem;
}

.substitution-admin__table th,
.substitution-admin__table td {
	text-align: left;
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
}

.substitution-admin__row-actions {
	display: flex;
	gap: 4px;
}

.substitution-admin__detail {
	margin-top: 20px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
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
