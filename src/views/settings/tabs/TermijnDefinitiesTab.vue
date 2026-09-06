<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="termijn-definities-tab">
		<div class="termijn-definities-tab__header">
			<h3>{{ t('dossiq', 'AWB Term definitions') }}</h3>
			<p class="termijn-definities-tab__description">
				{{
					t(
						'dossiq',
						'Configure statutory term definitions per zaaktype (legal basis, duration, validity). Saving a new version automatically sets validFrom=tomorrow on the new version and validUntil=today on the prior version. New cases use the latest version; running cases keep the version they were bound to.',
					)
				}}
			</p>
			<NcButton type="primary" @click="openNew">
				<template #icon>
					<Plus :size="18" />
				</template>
				{{ t('dossiq', 'New term definition') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div
			v-if="!loading && definitions.length === 0"
			class="termijn-definities-tab__empty">
			<NcEmptyContent
				:name="t('dossiq', 'No term definitions')"
				:description="
					t(
						'dossiq',
						'No AWB term definitions configured yet. Create one to enable termijnbewaking for a zaaktype.',
					)
				">
				<template #icon>
					<TimerSand :size="48" />
				</template>
			</NcEmptyContent>
		</div>

		<div
			v-if="!loading && definitions.length > 0"
			class="termijn-definities-tab__list">
			<div
				v-for="def in definitions"
				:key="def.id"
				class="termijn-definities-tab__row"
				:class="{ 'termijn-definities-tab__row--inactive': !isActive(def) }">
				<div class="termijn-definities-tab__row-info">
					<strong class="termijn-definities-tab__row-name">
						{{ def.case_type }}
					</strong>
					<span class="termijn-definities-tab__pill">
						{{ def.basis || t('dossiq', '(no grondslag)') }}
					</span>
					<span
						class="termijn-definities-tab__pill termijn-definities-tab__pill--alt">
						{{ formatDuur(def) }}
					</span>
					<span
						class="termijn-definities-tab__pill termijn-definities-tab__pill--alt">
						v{{ def.version || 1 }}
					</span>
					<span class="termijn-definities-tab__validity">
						{{ def.validFrom || '?' }} → {{ def.validUntil || '∞' }}
					</span>
					<span
						class="termijn-definities-tab__badge"
						:class="
							isActive(def)
								? 'termijn-definities-tab__badge--active'
								: 'termijn-definities-tab__badge--inactive'
						">
						{{
							isActive(def)
								? t('dossiq', 'Active')
								: t('dossiq', 'Inactive')
						}}
					</span>
				</div>
				<div class="termijn-definities-tab__row-actions">
					<NcButton size="small" @click="openEdit(def)">
						{{ t('dossiq', 'New version') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Editor modal (own file under src/modals/ per ADR-004) -->
		<TermijnDefinitieEditor
			v-if="editorOpen"
			:definition="editingDefinition"
			:zaaktypeOptions="zaaktypeOptions"
			@save="onSave"
			@close="closeEditor" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TimerSand from 'vue-material-design-icons/TimerSand.vue'
import TermijnDefinitieEditor from '../../../modals/TermijnDefinitieEditor.vue'

export default {
	name: 'TermijnDefinitiesTab',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		Plus,
		TimerSand,
		TermijnDefinitieEditor,
	},

	data() {
		return {
			loading: false,
			error: null,
			definitions: [],
			editorOpen: false,
			editingDefinition: null,
		}
	},

	computed: {
		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		zaaktypeOptions() {
			const seen = new Set()
			const opts = []
			for (const d of this.definitions) {
				const z = d.case_type || ''
				if (z && !seen.has(z)) {
					seen.add(z)
					opts.push({ id: z, label: z })
				}
			}
			return opts
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		async load() {
			this.loading = true
			this.error = null
			try {
				const res = await axios.get(
					generateUrl('/apps/dossiq/api/termijn/definities'),
				)
				this.definitions = Array.isArray(res.data)
					? res.data
					: res.data?.results || []
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e.message
					|| t('dossiq', 'Failed to load term definitions')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} def The definition being edited.
		 * @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md
		 */
		isActive(def) {
			const today = new Date().toISOString().slice(0, 10)
			const from = def.validFrom || '0000-00-00'
			const until = def.validUntil || '9999-12-31'
			return from <= today && today <= until
		},

		/**
		 * @param {object} def The definition being edited.
		 * @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md
		 */
		formatDuur(def) {
			const v = def.duurDagen || def.duur || 0
			return v ? t('dossiq', '{n} days', { n: v }) : '—'
		},

		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		openNew() {
			this.editingDefinition = null
			this.editorOpen = true
		},

		/**
		 * @param {object} def The definition being edited.
		 * @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md
		 */
		openEdit(def) {
			this.editingDefinition = def
			this.editorOpen = true
		},

		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		closeEditor() {
			this.editorOpen = false
			this.editingDefinition = null
		},

		/**
		 * @param {object} payload The payload.
		 * @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md
		 */
		async onSave(payload) {
			// Versioning: new version's validFrom=tomorrow, prior validUntil=today.
			const today = new Date()
			const tomorrow = new Date(today.getTime() + 24 * 60 * 60 * 1000)
			const isoTomorrow = tomorrow.toISOString().slice(0, 10)
			const isoToday = today.toISOString().slice(0, 10)

			const next = {
				...payload,
				validFrom: isoTomorrow,
				validUntil: null,
				version: this.editingDefinition
					? Number(this.editingDefinition.version || 1) + 1
					: 1,
			}

			try {
				// Close the prior version if editing
				if (this.editingDefinition) {
					await axios.patch(
						generateUrl(
							'/apps/dossiq/api/termijn/definities/'
								+ encodeURIComponent(this.editingDefinition.id),
						),
						{ validUntil: isoToday },
					)
				}
				await axios.post(
					generateUrl('/apps/dossiq/api/termijn/definities'),
					next,
				)
				this.closeEditor()
				await this.load()
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e.message
					|| t('dossiq', 'Failed to save')
			}
		},
	},
}
</script>

<style scoped>
.termijn-definities-tab {
	padding: 8px 0;
}

.termijn-definities-tab__header {
	margin-bottom: 16px;
}

.termijn-definities-tab__description {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 12px 0;
}

.termijn-definities-tab__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.termijn-definities-tab__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.termijn-definities-tab__row--inactive {
	opacity: 0.65;
}

.termijn-definities-tab__row-info {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.termijn-definities-tab__pill {
	font-size: 12px;
	padding: 2px 8px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.termijn-definities-tab__pill--alt {
	background: var(--color-background-darker);
}

.termijn-definities-tab__validity {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.termijn-definities-tab__badge {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 8px;
	font-weight: 500;
}

.termijn-definities-tab__badge--active {
	background: var(--color-success);
	color: var(--color-main-background);
}

.termijn-definities-tab__badge--inactive {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.termijn-definities-tab__row-actions {
	display: flex;
	gap: 6px;
}

.termijn-definities-tab__empty {
	margin-top: 32px;
}
</style>
