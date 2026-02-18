<template>
	<div class="case-detail">
		<div class="case-detail__header">
			<NcButton @click="$emit('navigate', 'cases')">
				{{ t('procest', 'Back to list') }}
			</NcButton>
			<h2 v-if="!isNew">
				{{ caseData.title || t('procest', 'Case') }}
			</h2>
			<h2 v-else>
				{{ t('procest', 'New case') }}
			</h2>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else class="case-detail__form">
			<div class="form-group">
				<label>{{ t('procest', 'Title') }}</label>
				<NcTextField :value="form.title" @update:value="v => form.title = v" />
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Description') }}</label>
				<textarea v-model="form.description" rows="4" />
			</div>
			<div class="form-row">
				<div class="form-group">
					<label>{{ t('procest', 'Status') }}</label>
					<NcSelect
						v-model="form.status"
						:options="statusOptions"
						:placeholder="t('procest', 'Status')" />
				</div>
				<div class="form-group">
					<label>{{ t('procest', 'Priority') }}</label>
					<NcSelect
						v-model="form.priority"
						:options="priorityOptions"
						:placeholder="t('procest', 'Priority')" />
				</div>
			</div>
			<div class="form-group">
				<label>{{ t('procest', 'Assignee') }}</label>
				<NcTextField :value="form.assignee" @update:value="v => form.assignee = v" />
			</div>

			<div class="case-detail__actions">
				<NcButton type="primary" @click="save">
					{{ t('procest', 'Save') }}
				</NcButton>
				<NcButton v-if="!isNew" type="error" @click="confirmDelete">
					{{ t('procest', 'Delete') }}
				</NcButton>
			</div>
		</div>

		<!-- Tasks section -->
		<div v-if="!isNew && !loading" class="case-detail__tasks">
			<div class="tasks-header">
				<h3>{{ t('procest', 'Tasks') }}</h3>
				<NcButton @click="showNewTask = !showNewTask">
					{{ t('procest', 'New task') }}
				</NcButton>
			</div>

			<div v-if="showNewTask" class="task-form">
				<NcTextField
					:value="newTask.title"
					:label="t('procest', 'Title')"
					@update:value="v => newTask.title = v" />
				<div class="form-row">
					<NcTextField
						:value="newTask.assignee"
						:label="t('procest', 'Assignee')"
						@update:value="v => newTask.assignee = v" />
					<NcTextField
						:value="newTask.dueDate"
						:label="t('procest', 'Due date')"
						type="date"
						@update:value="v => newTask.dueDate = v" />
				</div>
				<NcButton type="primary" @click="createTask">
					{{ t('procest', 'Save') }}
				</NcButton>
			</div>

			<div v-if="tasks.length === 0" class="tasks-empty">
				<p>{{ t('procest', 'No tasks found') }}</p>
			</div>
			<table v-else class="tasks-table">
				<thead>
					<tr>
						<th>{{ t('procest', 'Title') }}</th>
						<th>{{ t('procest', 'Status') }}</th>
						<th>{{ t('procest', 'Assignee') }}</th>
						<th>{{ t('procest', 'Due date') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="task in tasks" :key="task.id">
						<td>{{ task.title || '-' }}</td>
						<td>{{ task.status || '-' }}</td>
						<td>{{ task.assignee || '-' }}</td>
						<td>{{ task.dueDate || '-' }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField, NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'CaseDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
	},
	props: {
		caseId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			form: {
				title: '',
				description: '',
				status: 'open',
				priority: 'normal',
				assignee: '',
			},
			showNewTask: false,
			newTask: {
				title: '',
				assignee: '',
				dueDate: '',
			},
			tasks: [],
			statusOptions: ['open', 'in_progress', 'closed'],
			priorityOptions: ['low', 'normal', 'high', 'urgent'],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return !this.caseId || this.caseId === 'new'
		},
		loading() {
			return this.objectStore.isLoading('case')
		},
		caseData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('case', this.caseId) || {}
		},
	},
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('case', this.caseId)
			this.populateForm()
			await this.fetchTasks()
		}
	},
	methods: {
		populateForm() {
			const data = this.caseData
			this.form = {
				title: data.title || '',
				description: data.description || '',
				status: data.status || 'open',
				priority: data.priority || 'normal',
				assignee: data.assignee || '',
			}
		},
		async save() {
			const objectData = { ...this.form }
			if (!this.isNew) {
				objectData.id = this.caseId
			}

			const result = await this.objectStore.saveObject('case', objectData)
			if (result) {
				if (this.isNew) {
					this.$emit('navigate', 'case-detail', result.id)
				}
			}
		},
		async confirmDelete() {
			if (confirm(t('procest', 'Are you sure you want to delete this?'))) {
				const success = await this.objectStore.deleteObject('case', this.caseId)
				if (success) {
					this.$emit('navigate', 'cases')
				}
			}
		},
		async fetchTasks() {
			// Fetch tasks filtered by this case
			const allTasks = await this.objectStore.fetchCollection('task', {
				_limit: 50,
				'case': this.caseId,
			})
			this.tasks = allTasks || []
		},
		async createTask() {
			const taskData = {
				...this.newTask,
				case: this.caseId,
				status: 'todo',
			}

			const result = await this.objectStore.saveObject('task', taskData)
			if (result) {
				this.tasks.push(result)
				this.showNewTask = false
				this.newTask = { title: '', assignee: '', dueDate: '' }
			}
		},
	},
}
</script>

<style scoped>
.case-detail {
	padding: 20px;
	max-width: 800px;
}

.case-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
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

.case-detail__actions {
	display: flex;
	gap: 12px;
	margin-top: 20px;
}

.case-detail__tasks {
	margin-top: 40px;
	border-top: 1px solid var(--color-border);
	padding-top: 20px;
}

.tasks-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.task-form {
	background: var(--color-background-dark);
	padding: 16px;
	border-radius: var(--border-radius);
	margin-bottom: 16px;
}

.tasks-table {
	width: 100%;
	border-collapse: collapse;
}

.tasks-table th,
.tasks-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.tasks-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 20px;
}
</style>
