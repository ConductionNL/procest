<template>
	<div class="participants-section">
		<div class="section-header">
			<h3>{{ t('procest', 'Participants') }} ({{ roles.length }})</h3>
			<NcButton v-if="!isReadOnly" @click="showAddDialog = true">
				{{ t('procest', 'Add Participant') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<template v-else-if="roles.length === 0">
			<div class="participants-section__empty">
				<p>{{ t('procest', 'No participants assigned') }}</p>
				<NcButton v-if="!isReadOnly" type="primary" @click="onAssignHandler">
					{{ t('procest', 'Assign Handler') }}
				</NcButton>
			</div>
		</template>

		<template v-else>
			<div
				v-for="group in groupedRoles"
				:key="group.roleTypeName"
				class="participants-section__group">
				<div class="participants-section__group-label">
					{{ group.roleTypeName }}
				</div>
				<div
					v-for="role in group.roles"
					:key="role.id"
					class="participants-section__row">
					<div class="participants-section__avatar">
						{{ getInitials(role._displayName || role.participant) }}
					</div>
					<div class="participants-section__info">
						<span class="participants-section__name">
							{{ role._displayName || role.participant }}
						</span>
					</div>
					<div v-if="!isReadOnly" class="participants-section__actions">
						<template v-if="isHandlerRole(role)">
							<NcButton
								v-if="!reassigningHandler"
								type="tertiary"
								@click="startReassign(role)">
								{{ t('procest', 'Reassign') }}
							</NcButton>
						</template>
						<NcButton
							v-else
							type="tertiary"
							@click="removeRole(role)">
							<template #icon>
								<Delete :size="20" />
							</template>
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Inline handler reassign -->
			<div v-if="reassigningHandler" class="participants-section__reassign">
				<label>{{ t('procest', 'Reassign handler to:') }}</label>
				<NcSelect
					v-model="reassignUser"
					:options="userOptions"
					label="label"
					track-by="id"
					:placeholder="t('procest', 'Select user...')"
					@input="onReassignSelected" />
				<NcButton type="tertiary" @click="cancelReassign">
					{{ t('procest', 'Cancel') }}
				</NcButton>
			</div>
		</template>

		<AddParticipantDialog
			v-if="showAddDialog"
			:case-id="caseId"
			:role-types="roleTypes"
			:user-options="userOptions"
			:pre-select-handler="preSelectHandler"
			@created="onRoleCreated"
			@close="closeAddDialog" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { useObjectStore } from '../../../store/modules/object.js'
import AddParticipantDialog from './AddParticipantDialog.vue'

export default {
	name: 'ParticipantsSection',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		Delete,
		AddParticipantDialog,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['handler-changed'],
	data() {
		return {
			loading: true,
			roles: [],
			roleTypes: [],
			userOptions: [],
			showAddDialog: false,
			preSelectHandler: false,
			reassigningHandler: false,
			reassignRole: null,
			reassignUser: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		roleTypeMap() {
			const map = {}
			for (const rt of this.roleTypes) {
				map[rt.id] = rt
			}
			return map
		},
		groupedRoles() {
			const groups = {}
			for (const role of this.roles) {
				const rt = this.roleTypeMap[role.roleType]
				const name = rt?.name || role.name || t('procest', 'Unknown')
				if (!groups[name]) {
					groups[name] = { roleTypeName: name, roles: [] }
				}
				groups[name].roles.push(role)
			}
			return Object.values(groups)
		},
	},
	async mounted() {
		await this.fetchData()
	},
	methods: {
		async fetchData() {
			this.loading = true
			try {
				const [roles, roleTypes] = await Promise.all([
					this.objectStore.fetchCollection('role', {
						'_filters[case]': this.caseId,
						_limit: 100,
					}),
					this.objectStore.fetchCollection('roleType', { _limit: 100 }),
				])
				this.roles = roles || []
				this.roleTypes = roleTypes || []

				await this.resolveDisplayNames()
				await this.fetchUsers()
			} catch (err) {
				console.error('Failed to fetch participants data:', err)
			} finally {
				this.loading = false
			}
		},

		async fetchUsers() {
			try {
				const response = await fetch('/ocs/v2.php/cloud/users/details?format=json&limit=100', {
					headers: {
						'OCS-APIREQUEST': 'true',
						requesttoken: OC.requestToken,
					},
				})
				if (response.ok) {
					const data = await response.json()
					const users = data?.ocs?.data?.users || {}
					this.userOptions = Object.entries(users).map(([uid, info]) => ({
						id: uid,
						label: info.displayname || uid,
					}))
				}
			} catch (err) {
				console.warn('Failed to fetch user list:', err)
			}
		},

		async resolveDisplayNames() {
			for (const role of this.roles) {
				const uid = role.participant
				if (!uid) continue
				try {
					const response = await fetch(`/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}?format=json`, {
						headers: {
							'OCS-APIREQUEST': 'true',
							requesttoken: OC.requestToken,
						},
					})
					if (response.ok) {
						const data = await response.json()
						this.$set(role, '_displayName', data?.ocs?.data?.displayname || uid)
					} else {
						this.$set(role, '_displayName', uid)
					}
				} catch {
					this.$set(role, '_displayName', uid)
				}
			}
		},

		getInitials(name) {
			if (!name) return '?'
			const parts = name.split(/[\s.]+/)
			if (parts.length >= 2) {
				return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
			}
			return name.slice(0, 2).toUpperCase()
		},

		isHandlerRole(role) {
			const rt = this.roleTypeMap[role.roleType]
			return rt?.genericRole === 'handler'
		},

		onAssignHandler() {
			this.preSelectHandler = true
			this.showAddDialog = true
		},

		closeAddDialog() {
			this.showAddDialog = false
			this.preSelectHandler = false
		},

		async onRoleCreated(newRole) {
			this.closeAddDialog()
			await this.fetchData()
			if (this.isHandlerRole(newRole)) {
				this.$emit('handler-changed', newRole.participant)
			}
		},

		async removeRole(role) {
			if (!confirm(t('procest', 'Remove this participant?'))) return
			await this.objectStore.deleteObject('role', role.id)
			await this.fetchData()
		},

		startReassign(role) {
			this.reassigningHandler = true
			this.reassignRole = role
			this.reassignUser = null
		},

		cancelReassign() {
			this.reassigningHandler = false
			this.reassignRole = null
			this.reassignUser = null
		},

		async onReassignSelected(user) {
			if (!user || !this.reassignRole) return

			// Update the role participant.
			const updatedRole = { ...this.reassignRole, participant: user.id }
			await this.objectStore.saveObject('role', updatedRole)

			// Update the case assignee.
			const caseData = this.objectStore.getObject('case', this.caseId)
			if (caseData) {
				await this.objectStore.saveObject('case', { ...caseData, assignee: user.id })
			}

			this.cancelReassign()
			await this.fetchData()
			this.$emit('handler-changed', user.id)
		},
	},
}
</script>

<style scoped>
.participants-section {
	margin-top: 24px;
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}

.participants-section__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 16px;
}

.participants-section__group {
	margin-bottom: 12px;
}

.participants-section__group-label {
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.participants-section__row {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px;
	border-radius: var(--border-radius);
}

.participants-section__row:hover {
	background: var(--color-background-hover);
}

.participants-section__avatar {
	width: 32px;
	height: 32px;
	border-radius: 50%;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 12px;
	font-weight: bold;
	flex-shrink: 0;
}

.participants-section__info {
	flex: 1;
	min-width: 0;
}

.participants-section__name {
	font-size: 14px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.participants-section__actions {
	flex-shrink: 0;
}

.participants-section__reassign {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	margin-top: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.participants-section__reassign label {
	font-size: 13px;
	white-space: nowrap;
}
</style>
