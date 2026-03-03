<template>
	<div class="add-participant-overlay" @click.self="$emit('close')">
		<div class="add-participant-dialog">
			<h3>{{ t('procest', 'Add Participant') }}</h3>

			<div class="form-group">
				<label>{{ t('procest', 'Role type') }} *</label>
				<NcSelect
					v-model="selectedRoleType"
					:options="roleTypes"
					label="name"
					track-by="id"
					:placeholder="t('procest', 'Select role type...')" />
			</div>

			<div class="form-group">
				<label>{{ t('procest', 'Participant') }} *</label>
				<NcSelect
					v-model="selectedUser"
					:options="userOptions"
					label="label"
					track-by="id"
					:placeholder="t('procest', 'Select user...')" />
			</div>

			<p v-if="error" class="form-error">{{ error }}</p>

			<div class="add-participant-dialog__actions">
				<NcButton @click="$emit('close')">
					{{ t('procest', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!canSubmit || saving"
					@click="submit">
					<template v-if="saving">
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('procest', 'Add') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../../../store/modules/object.js'

export default {
	name: 'AddParticipantDialog',
	components: {
		NcButton,
		NcSelect,
		NcLoadingIcon,
	},
	props: {
		caseId: {
			type: String,
			required: true,
		},
		roleTypes: {
			type: Array,
			default: () => [],
		},
		userOptions: {
			type: Array,
			default: () => [],
		},
		preSelectHandler: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['created', 'close'],
	data() {
		return {
			selectedRoleType: null,
			selectedUser: null,
			error: '',
			saving: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		canSubmit() {
			return !!this.selectedRoleType && !!this.selectedUser
		},
	},
	mounted() {
		if (this.preSelectHandler) {
			const handlerType = this.roleTypes.find(rt => rt.genericRole === 'handler')
			if (handlerType) {
				this.selectedRoleType = handlerType
			}
		}
	},
	methods: {
		async submit() {
			if (!this.canSubmit) return

			this.error = ''
			this.saving = true

			try {
				const roleData = {
					name: this.selectedRoleType.name,
					roleType: this.selectedRoleType.id,
					case: this.caseId,
					participant: this.selectedUser.id,
				}

				const result = await this.objectStore.saveObject('role', roleData)
				if (result) {
					this.$emit('created', result)
				} else {
					const storeError = this.objectStore.getError('role')
					this.error = storeError?.message || t('procest', 'Failed to add participant')
				}
			} catch (err) {
				this.error = err.message || t('procest', 'Failed to add participant')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.add-participant-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.add-participant-dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.2);
	padding: 24px;
	width: 440px;
	max-width: 90vw;
}

.add-participant-dialog h3 {
	margin: 0 0 16px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.form-error {
	color: var(--color-error);
	font-size: 13px;
	margin-bottom: 8px;
}

.add-participant-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}
</style>
