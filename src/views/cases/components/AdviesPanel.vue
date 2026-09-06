<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="advies-panel">
		<div class="advies-panel__header">
			<h3 class="advies-panel__title">
				{{ t(appName, 'Adviezen') }}
			</h3>
			<NcButton type="primary" @click="openCreateDialog">
				{{ t(appName, 'Advies aanvragen') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="advies.length === 0"
			:title="t(appName, 'Geen adviezen aangevraagd')"
			:description="
				t(
					appName,
					'Vraag advies aan bij interne of externe partijen om hier te tonen.',
				)
			" />

		<ul v-else class="advies-panel__list">
			<li
				v-for="item in advies"
				:key="item.id || item.uuid"
				class="advies-panel__item"
				:class="{ 'advies-panel__item--overdue': isOverdue(item) }">
				<div class="advies-panel__row">
					<div class="advies-panel__meta">
						<strong>{{ item.advisor }}</strong>
						<CnStatusBadge
							:status="typeLabel(item.type)"
							:type="typeBadgeType(item.type)" />
						<CnStatusBadge
							:status="statusLabel(item.status)"
							:type="statusBadgeType(item.status)" />
					</div>
					<div class="advies-panel__deadline">
						<template v-if="isOverdue(item)">
							{{
								t(appName, '{days} dagen te laat', {
									days: daysOverdue(item),
								})
							}}
						</template>
						<template v-else-if="item.deadline">
							{{
								t(appName, 'Deadline: {date}', {
									date: formatDate(item.deadline),
								})
							}}
						</template>
					</div>
				</div>
				<p v-if="item.subject" class="advies-panel__subject">
					{{ item.subject }}
				</p>
				<div class="advies-panel__actions">
					<NcButton
						v-if="item.status === 'requested'"
						type="secondary"
						@click="onRemind(item)">
						{{ t(appName, 'Herinnering sturen') }}
					</NcButton>
					<NcButton
						v-if="item.status === 'requested' && item.adviceDocument"
						type="secondary"
						@click="onMarkReceived(item)">
						{{ t(appName, 'Markeer als ontvangen') }}
					</NcButton>
					<NcButton
						v-if="item.status === 'received' && item.adviceDocument"
						type="tertiary"
						@click="onViewDocument(item)">
						{{ t(appName, 'Bekijk advies') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<AdviesAanvraagDialog
			v-if="showDialog"
			:caseId="caseId"
			@close="showDialog = false"
			@created="onCreated" />
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AdviesAanvraagDialog from '../../../dialogs/AdviesAanvraagDialog.vue'
import {
	dispatchReminder,
	getAdviceForCase,
	transitionStatus,
} from '../../../services/adviceApi.js'

const APP_NAME = 'dossiq'

export default {
	name: 'AdviesPanel',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		CnStatusBadge,
		AdviesAanvraagDialog,
	},

	props: {
		caseId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			appName: APP_NAME,
			advies: [],
			loading: false,
			showDialog: false,
		}
	},

	watch: {
		caseId: {
			immediate: true,
			/**
			 * @param {string|number|boolean|object} value The new value.
			 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
			 */
			handler(value) {
				if (value) {
					this.fetchAdvies()
				}
			},
		},
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md */
		async fetchAdvies() {
			this.loading = true
			try {
				this.advies = await getAdviceForCase(this.caseId)
			} catch (error) {
				console.error('Dossiq: failed to load advice', error)
				this.advies = []
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md */
		openCreateDialog() {
			this.showDialog = true
		},

		/** @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md */
		onCreated() {
			this.showDialog = false
			this.fetchAdvies()
		},

		/**
		 * @param {object} item The item.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		async onRemind(item) {
			try {
				await dispatchReminder(item.id || item.uuid)
			} catch (error) {
				console.error('Dossiq: failed to send reminder', error)
			}
		},

		/**
		 * @param {object} item The item.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		async onMarkReceived(item) {
			try {
				await transitionStatus(item.id || item.uuid, {
					to: 'received',
					adviceDocument: item.adviceDocument || '',
				})
				await this.fetchAdvies()
			} catch (error) {
				console.error('Dossiq: failed to mark received', error)
			}
		},

		/**
		 * @param {object} item The item.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		onViewDocument(item) {
			if (item.adviceDocument) {
				window.open(`/index.php/f/${item.adviceDocument}`, '_blank')
			}
		},

		/**
		 * @param {string} type The type.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		typeLabel(type) {
			return type === 'intern'
				? this.t(this.appName, 'Intern')
				: this.t(this.appName, 'Extern')
		},

		/**
		 * @param {string} type The type.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		typeBadgeType(type) {
			return type === 'intern' ? 'neutral' : 'info'
		},

		/**
		 * @param {string} status The status.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		statusLabel(status) {
			const labels = {
				requested: this.t(this.appName, 'Aangevraagd'),
				received: this.t(this.appName, 'Received'),
				expired: this.t(this.appName, 'Verlopen'),
			}
			return labels[status] || status
		},

		/**
		 * @param {string} status The status.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		statusBadgeType(status) {
			const types = {
				requested: 'info',
				received: 'success',
				expired: 'error',
			}
			return types[status] || 'neutral'
		},

		/**
		 * @param {object} item The item.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		isOverdue(item) {
			if (item.status !== 'requested' || !item.deadline) {
				return false
			}
			return new Date(item.deadline) < new Date()
		},

		/**
		 * @param {object} item The item.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		daysOverdue(item) {
			if (!item.deadline) {
				return 0
			}
			const diff = Date.now() - new Date(item.deadline).getTime()
			return Math.max(0, Math.floor(diff / (1000 * 60 * 60 * 24)))
		},

		/**
		 * @param {string|number|boolean|object} value The new value.
		 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
		 */
		formatDate(value) {
			if (!value) {
				return ''
			}
			try {
				return new Date(value).toLocaleDateString('nl-NL')
			} catch (error) {
				return value
			}
		},
	},
}
</script>

<style scoped>
.advies-panel {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 1rem;
}

.advies-panel__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.advies-panel__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.advies-panel__item {
	border: 1px solid var(--color-border, #d0d0d0);
	border-radius: var(--border-radius-large, 8px);
	padding: 0.75rem 1rem;
	background: var(--color-main-background, #fff);
}

.advies-panel__item--overdue {
	border-color: var(--color-error, #d94343);
	background: var(--color-error-hover, #fdecec);
}

.advies-panel__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.5rem;
	flex-wrap: wrap;
}

.advies-panel__meta {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	flex-wrap: wrap;
}

.advies-panel__subject {
	margin: 0.25rem 0 0.5rem 0;
	color: var(--color-text-maxcontrast, #555);
}

.advies-panel__actions {
	display: flex;
	gap: 0.5rem;
	flex-wrap: wrap;
}
</style>
