<template>
	<div class="transition-config-panel">
		<div class="transition-config-panel__header">
			<h4>{{ t('dossiq', 'Transition Configuration') }}</h4>
			<NcButton
				type="tertiary"
				:aria-label="t('dossiq', 'Close transition configuration')"
				@click="$emit('close')">
				<template #icon>
					<CloseIcon :size="20" />
				</template>
			</NcButton>
		</div>

		<div class="transition-config-panel__body">
			<!-- Label -->
			<div class="transition-config-panel__field">
				<label for="transition-config-panel-label">{{
					t('dossiq', 'Label')
				}}</label>
				<input
					id="transition-config-panel-label"
					v-model="localTransition.label"
					type="text"
					class="transition-config-panel__input"
					:placeholder="t('dossiq', 'e.g. Goedkeuren, Afwijzen')"
					@input="emitUpdate" />
			</div>

			<!-- Allowed Roles -->
			<div class="transition-config-panel__field">
				<label>{{ t('dossiq', 'Allowed roles (empty = all roles)') }}</label>
				<div
					v-for="role in roleTypes"
					:key="role.id"
					class="transition-config-panel__role-check">
					<input
						:id="'role-' + role.id"
						type="checkbox"
						:checked="localAllowedRoles.includes(role.id)"
						@change="toggleRole(role.id)" />
					<label :for="'role-' + role.id">{{ role.name }}</label>
				</div>
			</div>

			<!-- Guards -->
			<div class="transition-config-panel__section">
				<h5>{{ t('dossiq', 'Pre-conditions (guards)') }}</h5>
				<div
					v-for="(guard, index) in localGuards"
					:key="index"
					class="transition-config-panel__guard">
					<select
						v-model="guard.type"
						class="transition-config-panel__select"
						@change="emitUpdate">
						<option value="checklist">
							{{ t('dossiq', 'Checklist complete') }}
						</option>
						<option value="requiredField">
							{{ t('dossiq', 'Required field') }}
						</option>
						<option value="requiredDocument">
							{{ t('dossiq', 'Required document') }}
						</option>
						<option value="roleGuard">
							{{ t('dossiq', 'Role check') }}
						</option>
					</select>

					<!-- Field name for requiredField guard -->
					<input
						v-if="guard.type === 'requiredField'"
						v-model="guard.fieldName"
						type="text"
						:aria-label="t('dossiq', 'Field name (e.g. result)')"
						:placeholder="t('dossiq', 'Field name (e.g. result)')"
						class="transition-config-panel__input"
						@input="emitUpdate" />

					<!-- Document type for requiredDocument guard -->
					<select
						v-if="guard.type === 'requiredDocument'"
						v-model="guard.documentTypeName"
						class="transition-config-panel__select"
						@change="emitUpdate">
						<option value="">
							{{ t('dossiq', 'Select document type') }}
						</option>
						<option
							v-for="docType in documentTypes"
							:key="docType.id"
							:value="docType.name">
							{{ docType.name }}
						</option>
					</select>

					<!-- Role for roleGuard -->
					<select
						v-if="guard.type === 'roleGuard'"
						v-model="guard.roleTypeId"
						class="transition-config-panel__select"
						@change="emitUpdate">
						<option value="">
							{{ t('dossiq', 'Select role') }}
						</option>
						<option
							v-for="role in roleTypes"
							:key="role.id"
							:value="role.id">
							{{ role.name }}
						</option>
					</select>

					<NcButton
						type="tertiary"
						:aria-label="t('dossiq', 'Remove guard')"
						@click="removeGuard(index)">
						<template #icon>
							<CloseIcon :size="16" />
						</template>
					</NcButton>
				</div>
				<NcButton type="secondary" @click="addGuard">
					{{ t('dossiq', 'Add guard') }}
				</NcButton>
			</div>

			<!-- Automatic Actions -->
			<div class="transition-config-panel__section">
				<h5>{{ t('dossiq', 'Automatic actions') }}</h5>
				<div
					v-for="(action, index) in localActions"
					:key="index"
					class="transition-config-panel__action">
					<select
						v-model="action.type"
						class="transition-config-panel__select"
						@change="emitUpdate">
						<option value="sendEmail">
							{{ t('dossiq', 'Send email') }}
						</option>
						<option value="createTask">
							{{ t('dossiq', 'Create task') }}
						</option>
						<option value="createSubCase">
							{{ t('dossiq', 'Create sub-case') }}
						</option>
						<option value="webhook">
							{{ t('dossiq', 'Call webhook') }}
						</option>
						<option value="setField">
							{{ t('dossiq', 'Set field value') }}
						</option>
						<option value="notify">
							{{ t('dossiq', 'Send notification') }}
						</option>
					</select>

					<!-- sendEmail config -->
					<template v-if="action.type === 'sendEmail'">
						<input
							v-model="action.recipient"
							type="text"
							:aria-label="
								t('dossiq', 'Recipient (role name or email)')
							"
							:placeholder="
								t('dossiq', 'Recipient (role name or email)')
							"
							class="transition-config-panel__input"
							@input="emitUpdate" />
						<input
							v-model="action.subject"
							type="text"
							:aria-label="t('dossiq', 'Subject template')"
							:placeholder="t('dossiq', 'Subject template')"
							class="transition-config-panel__input"
							@input="emitUpdate" />
						<textarea
							v-model="action.template"
							:aria-label="
								t(
									'dossiq',
									'Email template (use {{case.title}}, {{transition.label}})',
								)
							"
							:placeholder="
								t(
									'dossiq',
									'Email template (use {{case.title}}, {{transition.label}})',
								)
							"
							class="transition-config-panel__textarea"
							rows="3"
							@input="emitUpdate" />
					</template>

					<!-- createTask config -->
					<template v-if="action.type === 'createTask'">
						<input
							v-model="action.title"
							type="text"
							:aria-label="t('dossiq', 'Task title')"
							:placeholder="t('dossiq', 'Task title')"
							class="transition-config-panel__input"
							@input="emitUpdate" />
						<input
							v-model="action.description"
							type="text"
							:aria-label="t('dossiq', 'Task description')"
							:placeholder="t('dossiq', 'Task description')"
							class="transition-config-panel__input"
							@input="emitUpdate" />
					</template>

					<!-- webhook config -->
					<template v-if="action.type === 'webhook'">
						<input
							v-model="action.url"
							type="url"
							:aria-label="t('dossiq', 'Webhook URL')"
							:placeholder="t('dossiq', 'Webhook URL')"
							class="transition-config-panel__input"
							@input="emitUpdate" />
					</template>

					<!-- setField config -->
					<template v-if="action.type === 'setField'">
						<input
							v-model="action.fieldName"
							type="text"
							:aria-label="t('dossiq', 'Field name')"
							:placeholder="t('dossiq', 'Field name')"
							class="transition-config-panel__input"
							@input="emitUpdate" />
						<input
							v-model="action.value"
							type="text"
							:aria-label="t('dossiq', 'Value')"
							:placeholder="t('dossiq', 'Value')"
							class="transition-config-panel__input"
							@input="emitUpdate" />
					</template>

					<!-- notify config -->
					<template v-if="action.type === 'notify'">
						<input
							v-model="action.message"
							type="text"
							:aria-label="t('dossiq', 'Notification message')"
							:placeholder="t('dossiq', 'Notification message')"
							class="transition-config-panel__input"
							@input="emitUpdate" />
					</template>

					<NcButton
						type="tertiary"
						:aria-label="t('dossiq', 'Remove action')"
						@click="removeAction(index)">
						<template #icon>
							<CloseIcon :size="16" />
						</template>
					</NcButton>
				</div>
				<NcButton type="secondary" @click="addAction">
					{{ t('dossiq', 'Add action') }}
				</NcButton>
			</div>

			<!-- Delete transition -->
			<div class="transition-config-panel__danger">
				<NcButton type="error" @click="onDelete">
					{{ t('dossiq', 'Delete transition') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

export default {
	name: 'TransitionConfigPanel',
	components: {
		NcButton,
		CloseIcon,
	},

	props: {
		transition: {
			type: Object,
			required: true,
		},

		roleTypes: {
			type: Array,
			default: () => [],
		},

		documentTypes: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update', 'delete', 'close'],
	data() {
		return {
			localTransition: { ...this.transition },
			localAllowedRoles: [...(this.transition.allowedRoles || [])],
			localGuards: this.parseGuards(this.transition.guards),
			localActions: this.parseActions(this.transition.automaticActions),
		}
	},

	watch: {
		transition: {
			/**
			 * @param {string|number|boolean|object} newVal The new value.
			 * @spec openspec/specs/status-transition-engine/spec.md
			 */
			handler(newVal) {
				this.localTransition = { ...newVal }
				this.localAllowedRoles = [...(newVal.allowedRoles || [])]
				this.localGuards = this.parseGuards(newVal.guards)
				this.localActions = this.parseActions(newVal.automaticActions)
			},

			deep: true,
		},
	},

	methods: {
		/**
		 * @param {Array} guards The guards.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		parseGuards(guards) {
			if (!guards) return []
			if (typeof guards === 'string') {
				try {
					return JSON.parse(guards)
				} catch {
					return []
				}
			}
			return [...guards]
		},

		/**
		 * @param {Array} actions The actions.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		parseActions(actions) {
			if (!actions) return []
			if (typeof actions === 'string') {
				try {
					return JSON.parse(actions)
				} catch {
					return []
				}
			}
			return [...actions]
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		emitUpdate() {
			this.$emit('update', {
				...this.localTransition,
				allowedRoles: this.localAllowedRoles,
				guards: this.localGuards,
				automaticActions: this.localActions,
			})
		},

		/**
		 * @param {string} roleId Identifier of the role id.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		toggleRole(roleId) {
			const index = this.localAllowedRoles.indexOf(roleId)
			if (index >= 0) {
				this.localAllowedRoles.splice(index, 1)
			} else {
				this.localAllowedRoles.push(roleId)
			}
			this.emitUpdate()
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		addGuard() {
			this.localGuards.push({ type: 'checklist' })
			this.emitUpdate()
		},

		/**
		 * @param {number} index Index of the row in the list.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		removeGuard(index) {
			this.localGuards.splice(index, 1)
			this.emitUpdate()
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		addAction() {
			this.localActions.push({ type: 'notify', message: '' })
			this.emitUpdate()
		},

		/**
		 * @param {number} index Index of the row in the list.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		removeAction(index) {
			this.localActions.splice(index, 1)
			this.emitUpdate()
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		onDelete() {
			if (
				confirm(
					t('dossiq', 'Are you sure you want to delete this transition?'),
				)
			) {
				this.$emit('delete', this.transition.id)
			}
		},
	},
}
</script>

<style scoped>
.transition-config-panel {
	position: fixed;
	right: 0;
	top: 0;
	width: 400px;
	height: 100%;
	background: var(--color-main-background);
	border-left: 1px solid var(--color-border);
	box-shadow: -2px 0 8px rgba(0, 0, 0, 0.1);
	z-index: 100;
	display: flex;
	flex-direction: column;
}

.transition-config-panel__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.transition-config-panel__header h4 {
	margin: 0;
}

.transition-config-panel__body {
	padding: 16px;
	flex: 1;
	overflow-y: auto;
}

.transition-config-panel__field {
	margin-bottom: 12px;
}

.transition-config-panel__field label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	margin-bottom: 4px;
	color: var(--color-text-maxcontrast);
}

.transition-config-panel__input {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
	margin-bottom: 4px;
}

.transition-config-panel__textarea {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
	resize: vertical;
}

.transition-config-panel__select {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
	margin-bottom: 4px;
}

.transition-config-panel__role-check {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.transition-config-panel__section {
	margin-top: 20px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.transition-config-panel__section h5 {
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px 0;
}

.transition-config-panel__guard,
.transition-config-panel__action {
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
}

.transition-config-panel__danger {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
