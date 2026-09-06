<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="rol-manager">
		<div class="rol-manager__toolbar">
			<NcButton type="primary" @click="openEditor(null)">
				<template #icon>
					<Plus :size="18" />
				</template>
				{{ t('dossiq', 'New role') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-if="!loading && roles.length === 0"
			:name="t('dossiq', 'No organisational roles')"
			:description="
				t(
					'dossiq',
					'Define roles to build a mandate hierarchy. Roles can have parents (afdeling/team) and a mandaat level.',
				)
			">
			<template #icon>
				<AccountGroup :size="48" />
			</template>
		</NcEmptyContent>

		<!-- Hierarchical role list (depth-first render). -->
		<div v-if="!loading && roots.length > 0" class="rol-manager__tree">
			<RolNode
				v-for="r in roots"
				:key="r.id"
				:role="r"
				:childrenByParent="childrenByParent"
				:onEdit="openEditor"
				:onDelete="confirmDelete" />
		</div>

		<RolEditorDialog
			v-if="editorOpen"
			:role="editingRole"
			:parentOptions="parentOptions"
			@save="onSave"
			@close="closeEditor" />

		<DeleteRolDialog
			v-if="deleting"
			:role="deleting"
			:blockedReason="deleteBlockedReason"
			@close="deleting = null"
			@confirm="doDelete" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import DeleteRolDialog from '../../../dialogs/DeleteRolDialog.vue'
import RolEditorDialog from '../../../dialogs/RolEditorDialog.vue'
import RolNode from './RolNode.vue'

export default {
	name: 'OrganisatieRolManager',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Plus,
		AccountGroup,
		RolNode,
		RolEditorDialog,
		DeleteRolDialog,
	},

	props: {
		roles: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
	},

	emits: ['reload'],
	data() {
		return {
			editorOpen: false,
			editingRole: null,
			deleting: null,
		}
	},

	computed: {
		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		childrenByParent() {
			const map = {}
			for (const r of this.roles) {
				const p = r.parentRole || ''
				if (!map[p]) map[p] = []
				map[p].push(r)
			}
			return map
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		roots() {
			return (this.roles || []).filter((r) => !r.parentRole)
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		parentOptions() {
			return [
				{ id: '', label: t('dossiq', '(top level)') },
				...this.roles.map((r) => ({ id: r.id, label: r.name || r.id })),
			]
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		deleteBlockedReason() {
			if (!this.deleting) return ''
			const refsAsParent = this.roles.some(
				(r) => r.parentRole === this.deleting.id,
			)
			if (refsAsParent)
				return t(
					'dossiq',
					'Cannot delete: this role is the parent of other roles. Re-parent them first.',
				)
			return ''
		},
	},

	methods: {
		t,
		/**
		 * @param {object} role The role.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		openEditor(role) {
			this.editingRole = role
			this.editorOpen = true
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		closeEditor() {
			this.editorOpen = false
			this.editingRole = null
		},

		/**
		 * @param {object} role The role.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		confirmDelete(role) {
			this.deleting = role
		},

		/**
		 * @param {object} payload The payload.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		async onSave(payload) {
			try {
				if (this.editingRole && this.editingRole.id) {
					await axios.patch(
						generateUrl(
							'/apps/dossiq/api/mandate/rollen/'
								+ encodeURIComponent(this.editingRole.id),
						),
						payload,
					)
				} else {
					await axios.post(
						generateUrl('/apps/dossiq/api/mandate/rollen'),
						payload,
					)
				}
				this.closeEditor()
				this.$emit('reload')
			} catch (e) {
				// Surface in dialog; leave open.
			}
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		async doDelete() {
			if (!this.deleting) return
			try {
				await axios.delete(
					generateUrl(
						'/apps/dossiq/api/mandate/rollen/'
							+ encodeURIComponent(this.deleting.id),
					),
				)
			} catch (e) {
				/* silent — user can retry */
			}
			this.deleting = null
			this.$emit('reload')
		},
	},
}
</script>

<style scoped>
.role-manager__toolbar {
	margin-bottom: 12px;
}

.role-manager__tree {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	background: var(--color-main-background);
}
</style>
