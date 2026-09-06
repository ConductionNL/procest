<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Mandaat Matrix admin tab — wraps the 4 sub-tabs (Besluiten, Rollen,
  Toewijzingen, Import) for the mandaatmatrix-admin-ui spec.
-->
<template>
	<div class="mandaat-matrix-tab">
		<div class="mandaat-matrix-tab__header">
			<h3>{{ t('dossiq', 'Mandate Matrix') }}</h3>
			<p class="mandaat-matrix-tab__description">
				{{
					t(
						'dossiq',
						'Configure mandate decisions, organisational roles, role assignments, and import legacy mandate exports. All changes are version-tracked.',
					)
				}}
			</p>
		</div>

		<NcAppNavigationCaption :name="t('dossiq', 'Sections')" />
		<div class="mandaat-matrix-tab__chips">
			<NcButton
				v-for="opt in tabOptions"
				:key="opt.id"
				:type="active === opt.id ? 'primary' : 'secondary'"
				size="small"
				@click="active = opt.id">
				{{ opt.label }}
			</NcButton>
		</div>

		<div class="mandaat-matrix-tab__body">
			<MandaatMatrixTable
				v-if="active === 'besluiten'"
				:matrices="matrices"
				:loading="loading"
				@edit="openEditor"
				@import="openImport" />
			<OrganisatieRolManager
				v-else-if="active === 'rollen'"
				:roles="roles"
				:loading="loading"
				@reload="loadRoles" />
			<MandaatToewijzingenTable
				v-else-if="active === 'toewijzingen'"
				:assignments="assignments"
				:loading="loading"
				:roleOptions="roleOptions"
				@reload="loadAssignments" />
			<MandaatImportPanel
				v-else-if="active === 'import'"
				@imported="onImported" />
		</div>

		<MandaatEditor
			v-if="editorOpen"
			:mandaat="editingMandaat"
			:roleOptions="roleOptions"
			@save="onMandaatSave"
			@close="closeEditor" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAppNavigationCaption, NcButton } from '@nextcloud/vue'
import MandaatEditor from '../../../modals/MandaatEditor.vue'
import MandaatImportPanel from '../components/MandaatImportPanel.vue'
import MandaatMatrixTable from '../components/MandaatMatrixTable.vue'
import MandaatToewijzingenTable from '../components/MandaatToewijzingenTable.vue'
import OrganisatieRolManager from '../components/OrganisatieRolManager.vue'

export default {
	name: 'MandaatMatrixTab',
	components: {
		NcButton,
		NcAppNavigationCaption,
		MandaatMatrixTable,
		OrganisatieRolManager,
		MandaatToewijzingenTable,
		MandaatImportPanel,
		MandaatEditor,
	},

	data() {
		return {
			active: 'besluiten',
			loading: false,
			matrices: [],
			roles: [],
			assignments: [],
			editorOpen: false,
			editingMandaat: null,
		}
	},

	computed: {
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		tabOptions() {
			return [
				{ id: 'besluiten', label: t('dossiq', 'Decisions') },
				{ id: 'rollen', label: t('dossiq', 'Roles') },
				{ id: 'toewijzingen', label: t('dossiq', 'Assignments') },
				{ id: 'import', label: t('dossiq', 'Import') },
			]
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		roleOptions() {
			return (this.roles || []).map((r) => ({
				id: r.id,
				label: r.name || r.label || r.id,
			}))
		},
	},

	watch: {
		active: {
			immediate: true,
			/**
			 * @param {string|number|boolean|object} v The new value.
			 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
			 */
			handler(v) {
				if (v === 'besluiten') this.loadBesluiten()
				else if (v === 'rollen') this.loadRoles()
				else if (v === 'toewijzingen') this.loadAssignments()
			},
		},
	},

	methods: {
		t,
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		async loadBesluiten() {
			this.loading = true
			try {
				const res = await axios.get(
					generateUrl('/apps/dossiq/api/mandate/besluiten'),
				)
				this.matrices = Array.isArray(res.data)
					? res.data
					: res.data?.results || []
			} catch (e) {
				this.matrices = []
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		async loadRoles() {
			this.loading = true
			try {
				const res = await axios.get(
					generateUrl('/apps/dossiq/api/mandate/rollen'),
				)
				this.roles = Array.isArray(res.data)
					? res.data
					: res.data?.results || []
			} catch (e) {
				this.roles = []
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		async loadAssignments() {
			this.loading = true
			try {
				const res = await axios.get(
					generateUrl('/apps/dossiq/api/mandate/toewijzingen'),
				)
				this.assignments = Array.isArray(res.data)
					? res.data
					: res.data?.results || []
			} catch (e) {
				this.assignments = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} mandaat The mandaat.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		openEditor(mandaat) {
			this.editingMandaat = mandaat
			this.editorOpen = true
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		closeEditor() {
			this.editorOpen = false
			this.editingMandaat = null
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		openImport() {
			this.active = 'import'
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		onImported() {
			this.active = 'besluiten'
			this.loadBesluiten()
		},

		/**
		 * @param {object} payload The payload.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		async onMandaatSave(payload) {
			try {
				if (this.editingMandaat && this.editingMandaat.id) {
					await axios.patch(
						generateUrl(
							'/apps/dossiq/api/mandate/mandaten/'
								+ encodeURIComponent(this.editingMandaat.id),
						),
						payload,
					)
				} else {
					await axios.post(
						generateUrl('/apps/dossiq/api/mandate/mandaten'),
						payload,
					)
				}
				this.closeEditor()
				this.loadBesluiten()
			} catch (e) {
				// Editor surfaces the error; keep modal open by not closing.
			}
		},
	},
}
</script>

<style scoped>
.mandaat-matrix-tab {
	padding: 8px 0;
}

.mandaat-matrix-tab__description {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 12px 0;
}

.mandaat-matrix-tab__chips {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.mandaat-matrix-tab__body {
	padding: 4px 0;
}
</style>
