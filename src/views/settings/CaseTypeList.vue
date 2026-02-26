<template>
	<div class="case-type-list">
		<div class="case-type-list__header">
			<NcButton type="primary" @click="$emit('create')">
				{{ t('procest', 'Add Case Type') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcEmptyContent
			v-else-if="caseTypes.length === 0"
			:name="t('procest', 'No case types configured')"
			:description="t('procest', 'Create a case type to define case behavior, statuses, and deadlines')">
			<template #icon>
				<FolderCogOutline :size="64" />
			</template>
		</NcEmptyContent>

		<table v-else class="case-type-list__table">
			<thead>
				<tr>
					<th>{{ t('procest', 'Title') }}</th>
					<th>{{ t('procest', 'Status') }}</th>
					<th>{{ t('procest', 'Deadline') }}</th>
					<th>{{ t('procest', 'Statuses') }}</th>
					<th>{{ t('procest', 'Validity') }}</th>
					<th>{{ t('procest', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="ct in caseTypes"
					:key="ct.id"
					class="case-type-list__row"
					@click="$emit('select', ct.id)">
					<td class="case-type-list__title">
						<StarIcon v-if="isDefault(ct.id)" :size="16" class="default-star" />
						{{ ct.title || '—' }}
					</td>
					<td>
						<span
							class="ct-badge"
							:class="ct.isDraft ? 'ct-badge--draft' : 'ct-badge--published'">
							{{ ct.isDraft ? t('procest', 'Draft') : t('procest', 'Published') }}
						</span>
					</td>
					<td>{{ formatDeadline(ct.processingDeadline) }}</td>
					<td>{{ getStatusTypeCount(ct.id) }}</td>
					<td>
						<span :class="validityClass(ct)">
							{{ formatValidity(ct) }}
						</span>
					</td>
					<td class="case-type-list__actions" @click.stop>
						<NcButton
							v-if="!ct.isDraft"
							type="tertiary"
							:title="t('procest', 'Set as default')"
							@click="setDefault(ct)">
							<template #icon>
								<StarIcon :size="20" />
							</template>
						</NcButton>
						<NcButton
							type="tertiary"
							:title="t('procest', 'Delete')"
							@click="confirmDelete(ct)">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<p v-if="error" class="case-type-list__error">{{ error }}</p>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import StarIcon from 'vue-material-design-icons/Star.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FolderCogOutline from 'vue-material-design-icons/FolderCogOutline.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { useSettingsStore } from '../../store/modules/settings.js'
import { formatDuration } from '../../utils/durationHelpers.js'

export default {
	name: 'CaseTypeList',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		StarIcon,
		DeleteIcon,
		FolderCogOutline,
	},
	data() {
		return {
			statusTypeCounts: {},
			error: '',
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		settingsStore() {
			return useSettingsStore()
		},
		loading() {
			return this.objectStore.isLoading('caseType')
		},
		caseTypes() {
			return this.objectStore.getCollection('caseType')
		},
		defaultCaseTypeId() {
			return this.settingsStore.config?.default_case_type || ''
		},
	},
	async mounted() {
		await this.fetchCaseTypes()
	},
	methods: {
		async fetchCaseTypes() {
			await this.objectStore.fetchCollection('caseType', { _limit: 100 })
			for (const ct of this.caseTypes) {
				this.loadStatusTypeCount(ct.id)
			}
		},

		async loadStatusTypeCount(caseTypeId) {
			const statusTypes = await this.objectStore.fetchCollection('statusType', {
				'_filters[caseType]': caseTypeId,
				_limit: 100,
			})
			this.$set(this.statusTypeCounts, caseTypeId, (statusTypes || []).length)
			// Re-fetch case types collection since fetchCollection overwrites the statusType collection
			await this.objectStore.fetchCollection('caseType', { _limit: 100 })
		},

		getStatusTypeCount(caseTypeId) {
			const count = this.statusTypeCounts[caseTypeId]
			return count !== undefined ? count : '...'
		},

		isDefault(id) {
			return this.defaultCaseTypeId === id
		},

		formatDeadline(duration) {
			return formatDuration(duration)
		},

		formatValidity(ct) {
			if (!ct.validFrom) return '—'
			const from = new Date(ct.validFrom).toLocaleDateString('nl-NL', { month: 'short', year: 'numeric' })
			if (ct.validUntil) {
				const until = new Date(ct.validUntil).toLocaleDateString('nl-NL', { month: 'short', year: 'numeric' })
				return `${from} — ${until}`
			}
			return t('procest', '{from} — (no end)', { from })
		},

		validityClass(ct) {
			if (!ct.validUntil) return ''
			const now = new Date()
			const until = new Date(ct.validUntil)
			if (until < now) return 'validity--expired'
			return ''
		},

		async setDefault(ct) {
			this.error = ''
			if (ct.isDraft) {
				this.error = t('procest', 'Only published case types can be set as default')
				return
			}
			const config = { ...this.settingsStore.config, default_case_type: ct.id }
			await this.settingsStore.saveSettings(config)
		},

		async confirmDelete(ct) {
			this.error = ''

			// Check for active cases using this type
			try {
				const cases = await this.objectStore.fetchCollection('case', {
					'_filters[caseType]': ct.id,
					_limit: 1,
				})
				if (cases && cases.length > 0) {
					this.error = t('procest', "Cannot delete: active cases are using this type")
					// Re-fetch case types since fetchCollection overwrote the collection
					await this.fetchCaseTypes()
					return
				}
			} catch (e) {
				// If we can't check, proceed with caution
			}

			const statusCount = this.statusTypeCounts[ct.id] || 0
			const message = statusCount > 0
				? t('procest', 'This will delete the case type and all {count} status types. Continue?', { count: statusCount })
				: t('procest', 'Delete case type "{title}"?', { title: ct.title })

			if (!confirm(message)) {
				await this.fetchCaseTypes()
				return
			}

			// Cascade delete: remove status types first
			if (statusCount > 0) {
				const statusTypes = await this.objectStore.fetchCollection('statusType', {
					'_filters[caseType]': ct.id,
					_limit: 100,
				})
				for (const st of (statusTypes || [])) {
					const ok = await this.objectStore.deleteObject('statusType', st.id)
					if (!ok) {
						this.error = t('procest', 'Failed to delete status type "{name}"', { name: st.name })
						await this.fetchCaseTypes()
						return
					}
				}
			}

			// Delete the case type
			const ok = await this.objectStore.deleteObject('caseType', ct.id)
			if (!ok) {
				this.error = t('procest', 'Failed to delete case type')
			}

			// Clear default if it was the deleted type
			if (this.defaultCaseTypeId === ct.id) {
				const config = { ...this.settingsStore.config, default_case_type: '' }
				await this.settingsStore.saveSettings(config)
			}

			await this.fetchCaseTypes()
		},
	},
}
</script>

<style scoped>
.case-type-list__header {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 16px;
}

.case-type-list__table {
	width: 100%;
	border-collapse: collapse;
}

.case-type-list__table th {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 2px solid var(--color-border);
	font-weight: bold;
	white-space: nowrap;
}

.case-type-list__table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.case-type-list__row {
	cursor: pointer;
}

.case-type-list__row:hover {
	background: var(--color-background-hover);
}

.case-type-list__title {
	display: flex;
	align-items: center;
	gap: 6px;
	font-weight: 500;
}

.default-star {
	color: var(--color-warning);
}

.ct-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
}

.ct-badge--published {
	background: var(--color-success);
	color: white;
}

.ct-badge--draft {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.validity--expired {
	color: var(--color-error);
	font-weight: 500;
}

.case-type-list__actions {
	display: flex;
	gap: 4px;
}

.case-type-list__error {
	color: var(--color-error);
	margin-top: 12px;
	padding: 8px;
	background: var(--color-error-light, rgba(var(--color-error-rgb), 0.1));
	border-radius: var(--border-radius);
}
</style>
