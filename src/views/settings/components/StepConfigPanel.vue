<template>
	<div class="step-config-panel">
		<div class="step-config-panel__header">
			<h4>{{ t('dossiq', 'Step Configuration') }}</h4>
			<div class="step-config-panel__header-actions">
				<NcButton
					v-if="!readOnly"
					type="tertiary"
					class="step-config-panel__delete-button"
					@click="$emit('delete', localStep.id)">
					{{ t('dossiq', 'Delete step') }}
				</NcButton>
				<NcButton
					type="tertiary"
					:aria-label="t('dossiq', 'Close step configuration')"
					@click="$emit('close')">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
				</NcButton>
			</div>
		</div>

		<div class="step-config-panel__body">
			<!-- Title -->
			<div class="step-config-panel__field">
				<label for="step-config-title">{{ t('dossiq', 'Title') }}</label>
				<input
					id="step-config-title"
					v-model="localStep.title"
					type="text"
					class="step-config-panel__input"
					@input="emitUpdate" />
			</div>

			<!-- Description -->
			<div class="step-config-panel__field">
				<label for="step-config-description">{{
					t('dossiq', 'Description')
				}}</label>
				<textarea
					id="step-config-description"
					v-model="localStep.description"
					class="step-config-panel__textarea"
					rows="3"
					@input="emitUpdate" />
			</div>

			<!-- Required -->
			<div class="step-config-panel__field step-config-panel__field--row">
				<input
					id="step-required"
					v-model="localStep.isRequired"
					type="checkbox"
					@change="emitUpdate" />
				<label for="step-required">{{
					t('dossiq', 'Required step (blocks status transition)')
				}}</label>
			</div>

			<!-- Assignee Role -->
			<div class="step-config-panel__field">
				<label>{{ t('dossiq', 'Assignee role') }}</label>
				<select
					v-model="localStep.assigneeRole"
					class="step-config-panel__select"
					@change="emitUpdate">
					<option :value="null">
						{{ t('dossiq', 'Any role') }}
					</option>
					<option
						v-for="role in roleTypes"
						:key="role.id"
						:value="role.id">
						{{ role.name }}
					</option>
				</select>
			</div>

			<!-- Checklist -->
			<div class="step-config-panel__section">
				<h5>{{ t('dossiq', 'Checklist') }}</h5>
				<div
					v-for="(item, index) in localChecklist"
					:key="item.id"
					class="step-config-panel__checklist-item"
					draggable="true"
					@dragstart="onCheckDragStart(index, $event)"
					@dragover.prevent
					@drop="onCheckDrop(index, $event)">
					<span class="step-config-panel__drag-handle">&#x2630;</span>
					<input
						v-model="item.label"
						type="text"
						class="step-config-panel__input"
						:placeholder="t('dossiq', 'Checklist item')"
						:aria-label="t('dossiq', 'Checklist item')"
						@input="emitUpdate" />
					<NcButton
						type="tertiary"
						:aria-label="t('dossiq', 'Remove checklist item')"
						@click="removeChecklistItem(index)">
						<template #icon>
							<CloseIcon :size="16" />
						</template>
					</NcButton>
				</div>
				<NcButton type="secondary" @click="addChecklistItem">
					{{ t('dossiq', 'Add checklist item') }}
				</NcButton>
			</div>

			<!-- Geavanceerd: SLA / required fields / escalation (process-step-configuration spec) -->
			<div class="step-config-panel__section">
				<button
					type="button"
					class="step-config-panel__advanced-toggle"
					@click="advancedOpen = !advancedOpen">
					<span>{{ t('dossiq', 'Advanced') }}</span>
					<span class="step-config-panel__advanced-caret">{{
						advancedOpen ? '▾' : '▸'
					}}</span>
				</button>

				<div v-if="readOnly" class="step-config-panel__advanced-banner">
					{{
						t(
							'dossiq',
							'Published versions are not editable — clone a new version first.',
						)
					}}
				</div>

				<div v-if="advancedOpen" class="step-config-panel__advanced-body">
					<!-- SLA -->
					<div class="step-config-panel__field">
						<label for="step-config-sla-value">{{
							t('dossiq', 'SLA')
						}}</label>
						<div class="step-config-panel__sla-row">
							<input
								id="step-config-sla-value"
								v-model.number="localConfig.sla.value"
								type="number"
								min="1"
								:max="slaValueMax"
								class="step-config-panel__input step-config-panel__sla-value"
								:disabled="readOnly"
								@input="emitUpdate" />
							<select
								v-model="localConfig.sla.unit"
								class="step-config-panel__select"
								:disabled="readOnly"
								@change="emitUpdate">
								<option value="">
									{{ t('dossiq', 'No SLA') }}
								</option>
								<option value="hours">
									{{ t('dossiq', 'hours') }}
								</option>
								<option value="businessDays">
									{{ t('dossiq', 'working days') }}
								</option>
								<option value="calendarDays">
									{{ t('dossiq', 'calendar days') }}
								</option>
							</select>
						</div>
					</div>

					<!-- Required fields -->
					<div class="step-config-panel__field">
						<label>{{
							t('dossiq', 'Required fields on completion')
						}}</label>
						<div
							v-for="(field, index) in localConfig.requiredFields"
							:key="`field-${index}`"
							class="step-config-panel__required-field">
							<input
								v-model="localConfig.requiredFields[index]"
								type="text"
								:placeholder="
									t('dossiq', 'Field name (property path)')
								"
								:aria-label="
									t('dossiq', 'Field name (property path)')
								"
								class="step-config-panel__input"
								:disabled="readOnly"
								@input="emitUpdate" />
							<NcButton
								type="tertiary"
								:disabled="readOnly"
								:aria-label="t('dossiq', 'Remove required field')"
								@click="removeRequiredField(index)">
								<template #icon>
									<CloseIcon :size="16" />
								</template>
							</NcButton>
						</div>
						<NcButton
							type="secondary"
							:disabled="readOnly"
							@click="addRequiredField">
							{{ t('dossiq', 'Add field') }}
						</NcButton>
					</div>

					<!-- Escalation -->
					<div class="step-config-panel__field">
						<label>
							<input
								v-model="escalationEnabled"
								type="checkbox"
								:disabled="readOnly"
								@change="onEscalationToggle" />
							{{ t('dossiq', 'Enable escalation') }}
						</label>
						<div
							v-if="escalationEnabled"
							class="step-config-panel__escalation">
							<select
								v-model="localConfig.escalationRule.trigger"
								class="step-config-panel__select"
								:disabled="readOnly"
								@change="emitUpdate">
								<option value="preBreach">
									{{ t('dossiq', 'Vóór deadline (pre-breach)') }}
								</option>
								<option value="slaBreached">
									{{ t('dossiq', 'Na deadline (sla-breached)') }}
								</option>
							</select>
							<div class="step-config-panel__sla-row">
								<input
									v-model.number="
										localConfig.escalationRule.offset
									"
									type="number"
									min="0"
									:aria-label="t('dossiq', 'Escalation offset')"
									class="step-config-panel__input step-config-panel__sla-value"
									:disabled="readOnly"
									@input="emitUpdate" />
								<select
									v-model="localConfig.escalationRule.offsetUnit"
									class="step-config-panel__select"
									:disabled="readOnly"
									@change="emitUpdate">
									<option value="hours">
										{{ t('dossiq', 'hours') }}
									</option>
									<option value="businessDays">
										{{ t('dossiq', 'working days') }}
									</option>
								</select>
							</div>
							<input
								v-model="localConfig.escalationRule.notifyRole"
								type="text"
								:placeholder="t('dossiq', 'Warn role (UUID)')"
								:aria-label="t('dossiq', 'Warn role (UUID)')"
								class="step-config-panel__input"
								:disabled="readOnly"
								@input="emitUpdate" />
							<input
								v-model="localConfig.escalationRule.escalateToRole"
								type="text"
								:placeholder="t('dossiq', 'Escalate to role (UUID)')"
								:aria-label="t('dossiq', 'Escalate to role (UUID)')"
								class="step-config-panel__input"
								:disabled="readOnly"
								@input="emitUpdate" />
							<label class="step-config-panel__inline-check">
								<input
									v-model="localConfig.escalationRule.openIncident"
									type="checkbox"
									:disabled="readOnly"
									@change="emitUpdate" />
								{{ t('dossiq', 'Also create an incident') }}
							</label>
						</div>
					</div>
				</div>
			</div>

			<!-- Automatic Actions -->
			<div class="step-config-panel__section">
				<h5>{{ t('dossiq', 'Automatic actions on completion') }}</h5>
				<div
					v-for="(action, index) in localActions"
					:key="index"
					class="step-config-panel__action">
					<select
						v-model="action.type"
						class="step-config-panel__select"
						@change="emitUpdate">
						<option value="createTask">
							{{ t('dossiq', 'Create task') }}
						</option>
						<option value="notify">
							{{ t('dossiq', 'Send notification') }}
						</option>
						<option value="webhook">
							{{ t('dossiq', 'Call webhook') }}
						</option>
					</select>
					<input
						v-if="action.type === 'createTask'"
						v-model="action.title"
						type="text"
						:placeholder="t('dossiq', 'Task title')"
						:aria-label="t('dossiq', 'Task title')"
						class="step-config-panel__input"
						@input="emitUpdate" />
					<input
						v-if="action.type === 'notify'"
						v-model="action.message"
						type="text"
						:placeholder="t('dossiq', 'Notification message')"
						:aria-label="t('dossiq', 'Notification message')"
						class="step-config-panel__input"
						@input="emitUpdate" />
					<input
						v-if="action.type === 'webhook'"
						v-model="action.url"
						type="url"
						:placeholder="t('dossiq', 'Webhook URL')"
						:aria-label="t('dossiq', 'Webhook URL')"
						class="step-config-panel__input"
						@input="emitUpdate" />
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
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'

let nextCheckId = 1

export default {
	name: 'StepConfigPanel',
	components: {
		NcButton,
		CloseIcon,
	},

	props: {
		step: {
			type: Object,
			required: true,
		},

		roleTypes: {
			type: Array,
			default: () => [],
		},

		readOnly: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update', 'close', 'delete'],
	data() {
		return {
			localStep: { ...this.step },
			localChecklist: this.parseChecklist(this.step.checklist),
			localActions: this.parseActions(this.step.automaticActions),
			localConfig: this.parseConfig(this.step.config),
			advancedOpen: false,
			escalationEnabled: !!(
				this.step
				&& this.step.config
				&& this.step.config.escalationRule
			),

			slaValueMax: 10000,
			dragCheckIndex: null,
		}
	},

	watch: {
		step: {
			/**
			 * @param {object} newStep The new step.
			 * @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md
			 */
			handler(newStep) {
				this.localStep = { ...newStep }
				this.localChecklist = this.parseChecklist(newStep.checklist)
				this.localActions = this.parseActions(newStep.automaticActions)
				this.localConfig = this.parseConfig(newStep.config)
				this.escalationEnabled = !!(
					newStep
					&& newStep.config
					&& newStep.config.escalationRule
				)
			},

			deep: true,
		},
	},

	methods: {
		/**
		 * @param {object} config The config.
		 * @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md
		 */
		parseConfig(config) {
			let raw = config
			if (typeof raw === 'string') {
				try {
					raw = JSON.parse(raw)
				} catch {
					raw = null
				}
			}
			const safe = raw && typeof raw === 'object' ? raw : {}
			return {
				sla: {
					value:
						safe.sla && Number.isFinite(safe.sla.value)
							? safe.sla.value
							: null,

					unit:
						safe.sla && typeof safe.sla.unit === 'string'
							? safe.sla.unit
							: '',
				},

				requiredFields: Array.isArray(safe.requiredFields)
					? [...safe.requiredFields]
					: [],

				autoActions: Array.isArray(safe.autoActions)
					? [...safe.autoActions]
					: [],

				escalationRule:
					safe.escalationRule && typeof safe.escalationRule === 'object'
						? {
								trigger:
									typeof safe.escalationRule.trigger === 'string'
										? safe.escalationRule.trigger
										: 'preBreach',

								offset: Number.isFinite(safe.escalationRule.offset)
									? safe.escalationRule.offset
									: 0,

								offsetUnit:
									typeof safe.escalationRule.offsetUnit
									=== 'string'
										? safe.escalationRule.offsetUnit
										: 'businessDays',

								notifyRole:
									typeof safe.escalationRule.notifyRole
									=== 'string'
										? safe.escalationRule.notifyRole
										: '',

								escalateToRole:
									typeof safe.escalationRule.escalateToRole
									=== 'string'
										? safe.escalationRule.escalateToRole
										: '',

								openIncident: !!safe.escalationRule.openIncident,
							}
						: {
								trigger: 'preBreach',
								offset: 0,
								offsetUnit: 'businessDays',
								notifyRole: '',
								escalateToRole: '',
								openIncident: false,
							},
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md */
		buildConfigPayload() {
			const out = {}
			if (
				this.localConfig.sla.value != null
				&& this.localConfig.sla.unit !== ''
			) {
				out.sla = {
					value: this.localConfig.sla.value,
					unit: this.localConfig.sla.unit,
				}
			}
			const fields = this.localConfig.requiredFields.filter(
				(f) => typeof f === 'string' && f.trim() !== '',
			)
			if (fields.length > 0) {
				out.requiredFields = fields
			}
			if (
				Array.isArray(this.localConfig.autoActions)
				&& this.localConfig.autoActions.length > 0
			) {
				out.autoActions = this.localConfig.autoActions
			}
			if (this.escalationEnabled) {
				out.escalationRule = { ...this.localConfig.escalationRule }
			}
			return Object.keys(out).length > 0 ? out : undefined
		},

		/** @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md */
		addRequiredField() {
			this.localConfig.requiredFields.push('')
			this.emitUpdate()
		},

		/**
		 * @param {number} index Index of the row in the list.
		 * @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md
		 */
		removeRequiredField(index) {
			this.localConfig.requiredFields.splice(index, 1)
			this.emitUpdate()
		},

		/** @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md */
		onEscalationToggle() {
			this.emitUpdate()
		},

		/**
		 * @param {object} checklist The checklist.
		 * @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md
		 */
		parseChecklist(checklist) {
			if (!checklist) return []
			if (typeof checklist === 'string') {
				try {
					return JSON.parse(checklist)
				} catch {
					return []
				}
			}
			return [...checklist]
		},

		/**
		 * @param {Array} actions The actions.
		 * @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md
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

		/** @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md */
		emitUpdate() {
			const payload = {
				...this.localStep,
				checklist: this.localChecklist,
				automaticActions: this.localActions,
			}
			const config = this.buildConfigPayload()
			if (config !== undefined) {
				payload.config = config
			} else {
				delete payload.config
			}
			this.$emit('update', payload)
		},

		/** @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md */
		addChecklistItem() {
			this.localChecklist.push({
				id: `check-${nextCheckId++}`,
				label: '',
				description: '',
			})
			this.emitUpdate()
		},

		/**
		 * @param {number} index Index of the row in the list.
		 * @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md
		 */
		removeChecklistItem(index) {
			this.localChecklist.splice(index, 1)
			this.emitUpdate()
		},

		/**
		 * @param {number} index Index of the row in the list.
		 * @param {Event} event The originating DOM event.
		 * @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md
		 */
		onCheckDragStart(index, event) {
			this.dragCheckIndex = index
			event.dataTransfer.effectAllowed = 'move'
		},

		/**
		 * @param {number} targetIndex The target index.
		 * @param {Event} event The originating DOM event.
		 * @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md
		 */
		onCheckDrop(targetIndex, event) {
			if (
				this.dragCheckIndex !== null
				&& this.dragCheckIndex !== targetIndex
			) {
				const item = this.localChecklist.splice(this.dragCheckIndex, 1)[0]
				this.localChecklist.splice(targetIndex, 0, item)
				this.emitUpdate()
			}
			this.dragCheckIndex = null
		},

		/** @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md */
		addAction() {
			this.localActions.push({ type: 'createTask', title: '' })
			this.emitUpdate()
		},

		/**
		 * @param {number} index Index of the row in the list.
		 * @spec openspec/changes/retrofit-2026-05-25-process-step-configuration/tasks.md
		 */
		removeAction(index) {
			this.localActions.splice(index, 1)
			this.emitUpdate()
		},
	},
}
</script>

<style scoped>
.step-config-panel {
	position: fixed;
	right: 0;
	top: 0;
	width: 360px;
	height: 100%;
	background: var(--color-main-background);
	border-left: 1px solid var(--color-border);
	box-shadow: -2px 0 8px rgba(0, 0, 0, 0.1);
	z-index: 100;
	display: flex;
	flex-direction: column;
	overflow-y: auto;
}

.step-config-panel__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.step-config-panel__header-actions {
	display: flex;
	align-items: center;
	gap: 4px;
}

.step-config-panel__header h4 {
	margin: 0;
}

.step-config-panel__body {
	padding: 16px;
	flex: 1;
	overflow-y: auto;
}

.step-config-panel__field {
	margin-bottom: 12px;
}

.step-config-panel__field label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	margin-bottom: 4px;
	color: var(--color-text-maxcontrast);
}

.step-config-panel__field--row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.step-config-panel__field--row label {
	display: inline;
	margin-bottom: 0;
}

.step-config-panel__input {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
}

.step-config-panel__textarea {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
	resize: vertical;
}

.step-config-panel__select {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 13px;
}

.step-config-panel__section {
	margin-top: 20px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.step-config-panel__section h5 {
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px 0;
}

.step-config-panel__checklist-item {
	display: flex;
	align-items: center;
	gap: 4px;
	margin-bottom: 4px;
}

.step-config-panel__drag-handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.step-config-panel__action {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 8px;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.step-config-panel__advanced-toggle {
	display: flex;
	align-items: center;
	justify-content: space-between;
	width: 100%;
	padding: 6px 0;
	background: none;
	border: none;
	cursor: pointer;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.step-config-panel__advanced-caret {
	font-size: 14px;
}

.step-config-panel__advanced-banner {
	padding: 8px;
	background: var(--color-warning, #fff4d6);
	border-radius: var(--border-radius);
	font-size: 12px;
	margin-bottom: 8px;
}

.step-config-panel__advanced-body {
	margin-top: 8px;
}

.step-config-panel__sla-row {
	display: flex;
	gap: 8px;
}

.step-config-panel__sla-value {
	width: 80px;
	flex: 0 0 80px;
}

.step-config-panel__required-field {
	display: flex;
	align-items: center;
	gap: 4px;
	margin-bottom: 4px;
}

.step-config-panel__escalation {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 6px;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.step-config-panel__inline-check {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
}
</style>
