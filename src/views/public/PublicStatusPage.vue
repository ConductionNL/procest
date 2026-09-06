<template>
	<div class="public-status-page">
		<div v-if="loading" class="public-status-page__loading">
			<NcLoadingIcon :size="32" />
			<p>{{ t('dossiq', 'Loading status...') }}</p>
		</div>

		<div v-else-if="error" class="public-status-page__error">
			<h2>{{ t('dossiq', 'Status unavailable') }}</h2>
			<p>{{ error }}</p>
		</div>

		<div v-else-if="statusData" class="public-status-page__content">
			<header class="public-status-page__header">
				<h1>{{ statusData.title }}</h1>
				<p v-if="statusData.identifier" class="public-status-page__ref">
					{{
						t('dossiq', 'Reference: {ref}', {
							ref: statusData.identifier,
						})
					}}
				</p>
			</header>

			<!-- Visual status indicator -->
			<section
				class="public-status-page__progress"
				role="progressbar"
				:aria-label="t('dossiq', 'Case progress')">
				<div class="public-status-page__status-label">
					{{ t('dossiq', 'Current status') }}
				</div>
				<div class="public-status-page__status-value">
					{{ statusData.currentStatus || t('dossiq', 'In progress') }}
				</div>
			</section>

			<!-- Dates -->
			<section class="public-status-page__dates">
				<div
					v-if="statusData.startDate"
					class="public-status-page__date-item">
					<span class="public-status-page__date-label">{{
						t('dossiq', 'Submitted')
					}}</span>
					<span class="public-status-page__date-value">{{
						formatDate(statusData.startDate)
					}}</span>
				</div>
				<div
					v-if="statusData.plannedEndDate"
					class="public-status-page__date-item">
					<span class="public-status-page__date-label">{{
						t('dossiq', 'Expected completion')
					}}</span>
					<span class="public-status-page__date-value">{{
						formatDate(statusData.plannedEndDate)
					}}</span>
				</div>
			</section>

			<footer class="public-status-page__footer">
				<p>
					{{
						t(
							'dossiq',
							'For questions about your case, please contact the municipality.',
						)
					}}
				</p>
			</footer>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'PublicStatusPage',
	components: {
		NcLoadingIcon,
	},

	props: {
		token: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			statusData: null,
		}
	},

	mounted() {
		this.loadStatus()
	},

	methods: {
		/**
		 * Resolve the public "track your case" token through OpenRegister's
		 * shares integration leaf (ADR-022). The OR `#[PublicPage]` endpoint
		 * `GET /apps/openregister/api/public/case-tokens/{token}` returns an
		 * RBAC-respecting, public-safe view of the case (only the fields the
		 * public group may read) — dossiq no longer runs its own public
		 * token-resolution controller. An unknown / revoked / expired token,
		 * or an RBAC-denied object, resolves to a uniform 404.
		 *
		 * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P2.1
		 */
		async loadStatus() {
			this.loading = true
			try {
				const response = await fetch(
					`/apps/openregister/api/public/case-tokens/${encodeURIComponent(this.token)}`,
				)
				if (!response.ok) {
					this.error = t('dossiq', 'Status unavailable')
					return
				}

				const data = await response.json()
				const obj = data.object || {}
				// Map the public-safe OR object view onto the citizen status fields.
				this.statusData = {
					title: obj.title || data.label || '',
					identifier: obj.identifier || '',
					currentStatus: obj.status || '',
					plannedEndDate: obj.plannedEndDate || null,
					startDate: obj.startDate || null,
				}
			} catch (err) {
				this.error = t('dossiq', 'Could not load status')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} dateString The date, as an ISO 8601 string.
		 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
		 */
		formatDate(dateString) {
			if (!dateString) return ''
			return new Date(dateString).toLocaleDateString('nl-NL', {
				year: 'numeric',
				month: 'long',
				day: 'numeric',
			})
		},
	},
}
</script>

<style scoped>
.public-status-page {
	max-width: 600px;
	margin: 0 auto;
	padding: 32px 24px;
	font-family: var(--font-face), sans-serif;
}

.public-status-page__loading {
	text-align: center;
	padding: 48px;
}

.public-status-page__error {
	text-align: center;
	padding: 48px;
	color: var(--color-error);
}

.public-status-page__header {
	margin-bottom: 32px;
	text-align: center;
}

.public-status-page__header h1 {
	margin: 0 0 8px;
	font-size: 24px;
}

.public-status-page__ref {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.public-status-page__progress {
	text-align: center;
	padding: 24px;
	margin-bottom: 24px;
	border: 2px solid var(--color-primary-element);
	border-radius: var(--border-radius-large);
	background: var(--color-primary-element-light);
}

.public-status-page__status-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.public-status-page__status-value {
	font-size: 20px;
	font-weight: bold;
	color: var(--color-primary-element);
}

.public-status-page__dates {
	display: flex;
	gap: 24px;
	justify-content: center;
	margin-bottom: 32px;
}

.public-status-page__date-item {
	text-align: center;
}

.public-status-page__date-label {
	display: block;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.public-status-page__date-value {
	font-weight: bold;
}

.public-status-page__footer {
	text-align: center;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
