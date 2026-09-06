<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Bulk reassignment modal (handler-vervanging-waarneming) — isolated NcDialog
  per ADR-004. Coordinator transfers all (or a case-type-filtered subset of) a
  departing handler's open cases and tasks to another handler. A mandatory
  non-mutating preview is shown before execute; per-item results are displayed
  after. The backend gate-checks the coordinator role.

  @spec openspec/specs/handler-vervanging-waarneming/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Bulk reassign workload')"
		:open="true"
		size="large"
		@update:open="onDialogClose"
		@closing="$emit('close')">
		<div class="bulk-reassign">
			<div class="form-row">
				<div class="form-group">
					<NcTextField
						v-model="fromUser"
						:label="t('dossiq', 'From handler (user id)')"
						:placeholder="t('dossiq', 'Departing handler…')" />
				</div>
				<div class="form-group">
					<NcTextField
						v-model="toUser"
						:label="t('dossiq', 'To handler (user id)')"
						:placeholder="t('dossiq', 'Receiving handler…')" />
				</div>
			</div>

			<div class="form-group">
				<NcSelect
					v-model="selectedCaseType"
					:options="caseTypes"
					:inputLabel="t('dossiq', 'Limit to case type (optional)')"
					:aria-label-combobox="t('dossiq', 'Limit to case type')"
					label="title"
					trackBy="id" />
			</div>

			<NcButton
				type="secondary"
				:disabled="!fromUser || loadingPreview"
				@click="loadPreview">
				<template v-if="loadingPreview" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('dossiq', 'Preview affected work') }}
			</NcButton>

			<!-- Mandatory preview table -->
			<div
				v-if="preview"
				class="bulk-reassign__preview"
				data-testid="reassign-preview">
				<h4>{{ t('dossiq', 'Affected open work') }} ({{ previewCount }})</h4>
				<NcEmptyContent
					v-if="previewCount === 0"
					:name="t('dossiq', 'No open work to reassign')" />
				<table v-else class="bulk-reassign__table">
					<thead>
						<tr>
							<th scope="col">{{ t('dossiq', 'Type') }}</th>
							<th scope="col">{{ t('dossiq', 'Title') }}</th>
							<th scope="col">{{ t('dossiq', 'Case type') }}</th>
							<th scope="col">{{ t('dossiq', 'Status') }}</th>
							<th scope="col">{{ t('dossiq', 'Next deadline') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="row in previewRows"
							:key="`${row.type}-${row.id}`">
							<td>
								{{
									row.type === 'case'
										? t('dossiq', 'Case')
										: t('dossiq', 'Task')
								}}
							</td>
							<td>{{ row.title }}</td>
							<td>{{ row.caseTypeName }}</td>
							<td>{{ row.status }}</td>
							<td>{{ row.deadline || '—' }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Per-item execution results -->
			<div
				v-if="results"
				class="bulk-reassign__results"
				data-testid="reassign-results">
				<h4>{{ t('dossiq', 'Reassignment result') }}</h4>
				<p>
					{{
						t(
							'dossiq',
							'{ok} succeeded, {fail} failed (batch {batch})',
							{
								ok: results.succeeded,
								fail: results.failed,
								batch: results.batchId,
							},
						)
					}}
				</p>
				<ul>
					<li
						v-for="r in results.results"
						:key="`${r.type}-${r.id}`"
						:class="r.success ? 'ok' : 'fail'">
						{{ r.title || r.id }} —
						{{
							r.success
								? t('dossiq', 'reassigned')
								: t('dossiq', 'failed')
						}}
					</li>
				</ul>
			</div>

			<p v-if="serverError" class="form-error form-error--server" role="alert">
				{{ serverError }}
			</p>
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
			<NcButton type="primary" :disabled="!canExecute" @click="execute">
				<template v-if="executing" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('dossiq', 'Reassign all') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import {
	executeReassignment,
	previewReassignment,
} from '../services/substitutionApi.js'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'BulkReassignModal',
	components: {
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	emits: ['reassigned', 'close'],
	data() {
		return {
			fromUser: '',
			toUser: '',
			selectedCaseType: null,
			caseTypes: [],
			caseTypeMap: {},
			preview: null,
			results: null,
			loadingPreview: false,
			executing: false,
			serverError: '',
		}
	},

	computed: {
		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		previewCount() {
			if (!this.preview) {
				return 0
			}
			return this.preview.cases.length + this.preview.tasks.length
		},

		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		previewRows() {
			if (!this.preview) {
				return []
			}
			const caseRows = this.preview.cases.map((c) => ({
				type: 'case',
				id: c.id,
				title: c.title || '—',
				caseTypeName: this.caseTypeMap[c.caseType] || '',
				status: c.status || '',
				deadline: (c.deadline || '').slice(0, 10),
			}))
			const taskRows = this.preview.tasks.map((tk) => ({
				type: 'task',
				id: tk.id,
				title: tk.title || '—',
				caseTypeName: '',
				status: tk.status || '',
				deadline: (tk.dueDate || '').slice(0, 10),
			}))
			return [...caseRows, ...taskRows]
		},

		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		canExecute() {
			return (
				!!this.fromUser
				&& !!this.toUser
				&& !!this.preview
				&& this.previewCount > 0
				&& !this.executing
			)
		},
	},

	async mounted() {
		await this.loadCaseTypes()
	},

	methods: {
		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		async loadCaseTypes() {
			try {
				const results = await this.objectStore.fetchCollection('caseType', {
					_limit: 200,
				})
				this.caseTypes = Array.isArray(results) ? results : []
				const map = {}
				for (const ct of this.caseTypes) {
					map[ct.id] = ct.title
				}
				this.caseTypeMap = map
			} catch (err) {
				this.caseTypes = []
			}
		},

		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		async loadPreview() {
			this.serverError = ''
			this.results = null
			this.loadingPreview = true
			try {
				this.preview = await previewReassignment(
					this.fromUser.trim(),
					this.selectedCaseType ? this.selectedCaseType.id : undefined,
				)
			} catch (err) {
				this.serverError =
					err?.response?.data?.error
					|| err?.message
					|| t('dossiq', 'Preview failed.')
			} finally {
				this.loadingPreview = false
			}
		},

		/** @spec openspec/specs/handler-vervanging-waarneming/spec.md */
		async execute() {
			this.serverError = ''
			this.executing = true
			try {
				this.results = await executeReassignment(
					this.fromUser.trim(),
					this.toUser.trim(),
					this.selectedCaseType ? this.selectedCaseType.id : undefined,
				)
				this.preview = null
				this.$emit('reassigned', this.results)
			} catch (err) {
				this.serverError =
					err?.response?.data?.error
					|| err?.message
					|| t('dossiq', 'Reassignment failed.')
			} finally {
				this.executing = false
			}
		},

		/**
		 * @param {boolean} open Whether the open is set.
		 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
		 */
		onDialogClose(open) {
			if (!open) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.bulk-reassign {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
}

.form-row {
	display: flex;
	gap: 12px;
}

.form-row .form-group {
	flex: 1;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.bulk-reassign__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.875rem;
}

.bulk-reassign__table th,
.bulk-reassign__table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.bulk-reassign__results .ok {
	color: var(--color-success);
}

.bulk-reassign__results .fail {
	color: var(--color-error);
}

.form-error {
	color: var(--color-error);
	font-size: 0.85rem;
	margin: 0;
}

.form-error--server {
	padding: 8px;
	background: var(--color-error-hover);
	border-radius: var(--border-radius);
}
</style>
