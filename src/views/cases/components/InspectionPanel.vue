<template>
	<div class="inspection-panel">
		<div class="inspection-panel__header">
			<h3>{{ t('dossiq', 'Inspections') }}</h3>
			<NcButton
				v-if="canInspect"
				variant="primary"
				@click="showChecklistForm = true">
				{{ t('dossiq', 'New inspection') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="24" />

		<!-- Progress bar -->
		<div v-if="!loading && totalPhases > 0" class="inspection-panel__progress">
			<div class="inspection-panel__progress-bar">
				<div
					class="inspection-panel__progress-fill"
					:style="{ width: progressPercent + '%' }" />
			</div>
			<span class="inspection-panel__progress-label">
				{{
					t('dossiq', 'Inspection {completed}/{total} completed', {
						completed: completedPhases,
						total: totalPhases,
					})
				}}
			</span>
		</div>

		<!-- Reports list -->
		<div v-if="!loading" class="inspection-panel__reports">
			<div
				v-for="report in reports"
				:key="report.id"
				class="inspection-panel__report"
				role="button"
				tabindex="0"
				@click="toggleReport(report.id)"
				@keydown.enter="toggleReport(report.id)"
				@keydown.space.prevent="toggleReport(report.id)">
				<div class="inspection-panel__report-header">
					<span
						class="inspection-panel__result-badge"
						:class="'inspection-panel__result-badge--' + report.result">
						{{ resultLabel(report.result) }}
					</span>
					<span class="inspection-panel__report-date">
						{{ formatDate(report.inspectionDate) }}
					</span>
					<span class="inspection-panel__report-inspector">
						{{ report.inspector }}
					</span>
					<span
						v-if="report.failedItems > 0"
						class="inspection-panel__failed-count">
						{{
							t('dossiq', '{count} failed', {
								count: report.failedItems,
							})
						}}
					</span>
				</div>

				<!-- Expandable detail -->
				<div
					v-if="expandedReport === report.id"
					class="inspection-panel__report-detail">
					<div
						v-for="(item, index) in report.items || []"
						:key="index"
						class="inspection-panel__check-item"
						:class="'inspection-panel__check-item--' + item.result">
						<span class="inspection-panel__check-icon">
							{{
								item.result === 'pass'
									? '\u2713'
									: item.result === 'fail'
										? '\u2717'
										: '\u2014'
							}}
						</span>
						<span class="inspection-panel__check-label">{{
							item.label || item.itemId
						}}</span>
						<span
							v-if="item.comment"
							class="inspection-panel__check-comment">
							{{ item.comment }}
						</span>
						<span
							v-if="
								item.measurement !== undefined
								&& item.measurement !== null
							"
							class="inspection-panel__check-measurement">
							{{ item.measurement }}
						</span>
						<span
							v-if="item.photos && item.photos.length > 0"
							class="inspection-panel__check-photos">
							{{
								t('dossiq', '{count} photos', {
									count: item.photos.length,
								})
							}}
						</span>
					</div>
					<p v-if="report.remarks" class="inspection-panel__remarks">
						{{ report.remarks }}
					</p>
				</div>
			</div>

			<p v-if="reports.length === 0" class="inspection-panel__empty">
				{{ t('dossiq', 'No inspections completed yet.') }}
			</p>
		</div>

		<!-- Checklist completion form (modal/dialog) -->
		<div v-if="showChecklistForm" class="inspection-panel__form-overlay">
			<div class="inspection-panel__form">
				<h4>{{ t('dossiq', 'Complete inspection checklist') }}</h4>

				<!-- Checklist selector -->
				<div
					v-if="!selectedChecklist"
					class="inspection-panel__checklist-selector">
					<p>{{ t('dossiq', 'Select a checklist:') }}</p>
					<div
						v-for="cl in activeChecklists"
						:key="cl.id"
						class="inspection-panel__checklist-option"
						role="button"
						tabindex="0"
						@click="selectedChecklist = cl"
						@keydown.enter="selectedChecklist = cl"
						@keydown.space.prevent="selectedChecklist = cl">
						<strong>{{ cl.name }}</strong>
						<small>{{
							t('dossiq', '{count} items', {
								count: (cl.items || []).length,
							})
						}}</small>
					</div>
				</div>

				<!-- Checklist items form -->
				<div
					v-if="selectedChecklist"
					class="inspection-panel__checklist-form">
					<p>
						<strong>{{ selectedChecklist.name }}</strong>
					</p>

					<div
						v-for="(item, index) in selectedChecklist.items"
						:key="index"
						class="inspection-panel__form-item">
						<label
							class="inspection-panel__form-label"
							:for="`inspection-item-${index}-input`">
							{{ item.order }}. {{ item.label }}
							<span
								v-if="item.required"
								class="inspection-panel__required"
								>*</span
							>
						</label>
						<p v-if="item.helpText" class="inspection-panel__help">
							{{ item.helpText }}
						</p>

						<!-- Yes/No/N.A. -->
						<div
							v-if="item.type === 'yes_no_na'"
							class="inspection-panel__radio-group">
							<label>
								<input
									v-model="formResults[index].result"
									type="radio"
									value="pass" />
								{{ t('dossiq', 'Yes') }}
							</label>
							<label>
								<input
									v-model="formResults[index].result"
									type="radio"
									value="fail" />
								{{ t('dossiq', 'No') }}
							</label>
							<label>
								<input
									v-model="formResults[index].result"
									type="radio"
									value="nvt" />
								{{ t('dossiq', 'N/A') }}
							</label>
						</div>

						<!-- Number -->
						<input
							v-if="item.type === 'getal'"
							:id="`inspection-item-${index}-input`"
							v-model.number="formResults[index].measurement"
							type="number"
							class="inspection-panel__input"
							:placeholder="t('dossiq', 'Measurement value')" />

						<!-- Text -->
						<input
							v-if="item.type === 'text'"
							:id="`inspection-item-${index}-input`"
							v-model="formResults[index].comment"
							type="text"
							class="inspection-panel__input"
							:placeholder="t('dossiq', 'Enter text')" />

						<!-- Comment for all types -->
						<input
							v-if="item.type !== 'text'"
							v-model="formResults[index].comment"
							type="text"
							class="inspection-panel__input inspection-panel__input--comment"
							:aria-label="t('dossiq', 'Comment (optional)')"
							:placeholder="t('dossiq', 'Comment (optional)')" />

						<!-- Photo upload warning -->
						<p
							v-if="
								item.photoRequired
								&& formResults[index].result === 'fail'
							"
							class="inspection-panel__photo-warning">
							{{ t('dossiq', 'Photo required for failed items') }}
						</p>
					</div>

					<div class="inspection-panel__form-actions">
						<NcButton
							variant="primary"
							:disabled="submitting"
							@click="submitReport">
							{{
								submitting
									? t('dossiq', 'Submitting…')
									: t('dossiq', 'Submit report')
							}}
						</NcButton>
						<NcButton @click="closeForm">
							{{ t('dossiq', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useInspectionStore } from '../../../store/modules/inspection.js'

export default {
	name: 'InspectionPanel',

	components: {
		NcButton,
		NcLoadingIcon,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},

		caseTypeId: {
			type: String,
			required: true,
		},

		// Defaults to true deliberately: nothing passes this, and the panel is
		// permissive until a caller says otherwise. Flipping the default to
		// satisfy the rule would silently disable inspection everywhere.
		canInspect: {
			type: Boolean,
			// eslint-disable-next-line vue/no-boolean-default
			default: true,
		},
	},

	data() {
		return {
			showChecklistForm: false,
			selectedChecklist: null,
			formResults: [],
			expandedReport: null,
			submitting: false,
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		inspectionStore() {
			return useInspectionStore()
		},

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		reports() {
			return this.inspectionStore.reports
		},

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		activeChecklists() {
			return this.inspectionStore.activeChecklists
		},

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		loading() {
			return this.inspectionStore.loading
		},

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		totalPhases() {
			return this.activeChecklists.length
		},

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		completedPhases() {
			const completedChecklistIds = new Set(
				this.reports.map((r) => r.checklist),
			)
			return this.activeChecklists.filter((c) =>
				completedChecklistIds.has(c.id),
			).length
		},

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		progressPercent() {
			if (this.totalPhases === 0) {
				return 0
			}
			return Math.round((this.completedPhases / this.totalPhases) * 100)
		},
	},

	watch: {
		caseId: {
			immediate: true,
			/**
			 * @param {string} newId Identifier of the new id.
			 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
			 */
			handler(newId) {
				if (newId) {
					this.inspectionStore.fetchReports(newId)
				}
			},
		},

		caseTypeId: {
			immediate: true,
			/**
			 * @param {string} newId Identifier of the new id.
			 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
			 */
			handler(newId) {
				if (newId) {
					this.inspectionStore.fetchChecklists(newId)
				}
			},
		},

		/**
		 * @param {object} checklist The checklist.
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		selectedChecklist(checklist) {
			if (checklist) {
				this.formResults = (checklist.items || []).map((item) => ({
					itemId: item.label,
					result: null,
					comment: '',
					measurement: null,
					photos: [],
				}))
			}
		},
	},

	methods: {
		t,

		/**
		 * @param {object} result The result.
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		resultLabel(result) {
			const labels = {
				conform: t('dossiq', 'Compliant'),
				non_conform: t('dossiq', 'Non-conform'),
				partly_conform: t('dossiq', 'Partially conform'),
			}
			return labels[result] || result
		},

		/**
		 * @param {string} dateStr The date str, as a string.
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		formatDate(dateStr) {
			if (!dateStr) {
				return ''
			}
			return new Date(dateStr).toLocaleDateString()
		},

		/**
		 * @param {string} reportId Identifier of the report id.
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		toggleReport(reportId) {
			this.expandedReport = this.expandedReport === reportId ? null : reportId
		},

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		closeForm() {
			this.showChecklistForm = false
			this.selectedChecklist = null
			this.formResults = []
		},

		/** @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md */
		async submitReport() {
			this.submitting = true
			try {
				await this.inspectionStore.createReport({
					case: this.caseId,
					checklist: this.selectedChecklist.id,
					inspector: (getCurrentUser() && getCurrentUser().uid) || '',
					items: this.formResults,
				})
				this.closeForm()
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.inspection-panel {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin-bottom: 16px;
}

.inspection-panel__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.inspection-panel__progress {
	margin-bottom: 16px;
}

.inspection-panel__progress-bar {
	height: 8px;
	background: var(--color-background-dark);
	border-radius: 4px;
	overflow: hidden;
	margin-bottom: 4px;
}

.inspection-panel__progress-fill {
	height: 100%;
	background: var(--color-primary-element);
	border-radius: 4px;
	transition: width 0.3s;
}

.inspection-panel__progress-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.inspection-panel__report {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 10px 14px;
	margin-bottom: 8px;
	cursor: pointer;
}

.inspection-panel__report:hover {
	background: var(--color-background-hover);
}

.inspection-panel__report-header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.inspection-panel__result-badge {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 10px;
	font-weight: bold;
}

.inspection-panel__result-badge--conform {
	background: var(--color-success);
	color: white;
}

.inspection-panel__result-badge--niet_conform {
	background: var(--color-error);
	color: white;
}

.inspection-panel__result-badge--deels_conform {
	background: var(--color-warning);
	color: white;
}

.inspection-panel__report-date,
.inspection-panel__report-inspector {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.inspection-panel__failed-count {
	font-size: 12px;
	color: var(--color-error);
	font-weight: bold;
}

.inspection-panel__report-detail {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.inspection-panel__check-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.inspection-panel__check-item--pass .inspection-panel__check-icon {
	color: var(--color-success);
}

.inspection-panel__check-item--fail .inspection-panel__check-icon {
	color: var(--color-error);
}

.inspection-panel__check-item--nvt .inspection-panel__check-icon {
	color: var(--color-text-maxcontrast);
}

.inspection-panel__check-comment,
.inspection-panel__check-measurement,
.inspection-panel__check-photos {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.inspection-panel__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 16px;
}

.inspection-panel__form-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	justify-content: center;
	align-items: center;
	z-index: 1000;
}

.inspection-panel__form {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 24px;
	max-width: 600px;
	width: 90%;
	max-height: 80vh;
	overflow-y: auto;
}

.inspection-panel__checklist-option {
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
	cursor: pointer;
}

.inspection-panel__checklist-option:hover {
	background: var(--color-background-hover);
}

.inspection-panel__form-item {
	margin-bottom: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.inspection-panel__form-label {
	font-weight: bold;
	display: block;
	margin-bottom: 4px;
}

.inspection-panel__required {
	color: var(--color-error);
}

.inspection-panel__help {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.inspection-panel__radio-group {
	display: flex;
	gap: 16px;
}

.inspection-panel__input {
	width: 100%;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-top: 4px;
}

.inspection-panel__input--comment {
	margin-top: 8px;
}

.inspection-panel__photo-warning {
	color: var(--color-warning);
	font-size: 12px;
	margin-top: 4px;
}

.inspection-panel__form-actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}

@media (prefers-reduced-motion: reduce) {
	.inspection-panel__progress-fill {
		transition: none;
	}
}
</style>
