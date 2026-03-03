<template>
	<div class="task-create-overlay" @click.self="$emit('close')">
		<div class="task-create-dialog">
			<div class="task-create-dialog__header">
				<h3>{{ t('procest', 'New Task') }}</h3>
				<NcButton type="tertiary" @click="$emit('close')">
					✕
				</NcButton>
			</div>

			<div class="task-create-dialog__body">
				<!-- Title -->
				<div class="form-group">
					<label>{{ t('procest', 'Title') }} *</label>
					<NcTextField
						:value="form.title"
						:placeholder="t('procest', 'Enter task title...')"
						:error="!!errors.title"
						@update:value="v => { form.title = v; errors.title = '' }" />
					<p v-if="errors.title" class="form-error">
						{{ errors.title }}
					</p>
				</div>

				<!-- Description -->
				<div class="form-group">
					<label>{{ t('procest', 'Description') }}</label>
					<textarea
						v-model="form.description"
						:placeholder="t('procest', 'Optional description...')"
						rows="3" />
				</div>

				<!-- Priority + Due Date row -->
				<div class="form-row">
					<div class="form-group">
						<label>{{ t('procest', 'Priority') }}</label>
						<NcSelect
							v-model="form.priority"
							:options="priorityOptions"
							:clearable="false"
							:placeholder="t('procest', 'Select priority')" />
					</div>
					<div class="form-group">
						<label>{{ t('procest', 'Due date') }}</label>
						<NcTextField
							:value="form.dueDate || ''"
							type="date"
							:placeholder="t('procest', 'Select due date')"
							@update:value="v => form.dueDate = v || null" />
					</div>
				</div>

				<!-- Assignee -->
				<div class="form-group">
					<label>{{ t('procest', 'Assignee') }}</label>
					<NcTextField
						:value="form.assignee"
						:placeholder="t('procest', 'Username (optional)')"
						@update:value="v => form.assignee = v" />
				</div>

				<!-- Case -->
				<div class="form-group">
					<label>{{ t('procest', 'Case') }}</label>
					<NcSelect
						v-model="form.case"
						:options="caseOptions"
						:clearable="true"
						label="label"
						:reduce="o => o.value"
						:placeholder="t('procest', 'Link to a case (optional)')" />
				</div>
			</div>

			<div class="task-create-dialog__footer">
				<NcButton @click="$emit('close')">
					{{ t('procest', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving"
					@click="submit">
					<template v-if="saving">
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('procest', 'Create task') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'TaskCreateDialog',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
	},
	emits: ['created', 'close'],
	data() {
		return {
			form: {
				title: '',
				description: '',
				priority: 'normal',
				dueDate: null,
				assignee: '',
				case: null,
			},
			priorityOptions: ['urgent', 'high', 'normal', 'low'],
			cases: [],
			errors: {},
			saving: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		caseOptions() {
			return this.cases.map(c => ({
				value: c.id,
				label: c.title || c.identifier || c.id,
			}))
		},
	},
	async mounted() {
		const results = await this.objectStore.fetchCollection('case', { _limit: 200 })
		this.cases = results || []
	},
	methods: {
		async submit() {
			this.errors = {}

			if (!this.form.title || !this.form.title.trim()) {
				this.errors.title = t('procest', 'Title is required')
				return
			}

			this.saving = true

			const taskData = {
				title: this.form.title.trim(),
				description: this.form.description.trim(),
				priority: this.form.priority || 'normal',
				status: 'available',
				assignee: this.form.assignee.trim() || null,
				dueDate: this.form.dueDate ? this.form.dueDate + 'T17:00:00Z' : null,
				case: this.form.case || null,
				completedDate: null,
			}

			const result = await this.objectStore.saveObject('task', taskData)
			this.saving = false

			if (result) {
				this.$emit('created', result.id)
			}
		},
	},
}
</script>

<style scoped>
.task-create-overlay {
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

.task-create-dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.2);
	width: 560px;
	max-width: 90vw;
	max-height: 85vh;
	overflow-y: auto;
}

.task-create-dialog__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
}

.task-create-dialog__header h3 {
	margin: 0;
}

.task-create-dialog__body {
	padding: 20px;
}

.task-create-dialog__footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	padding: 16px 20px;
	border-top: 1px solid var(--color-border);
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.form-group textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.form-row {
	display: flex;
	gap: 16px;
}

.form-row .form-group {
	flex: 1;
}

.form-error {
	color: var(--color-error);
	font-size: 13px;
	margin-top: 4px;
}
</style>
