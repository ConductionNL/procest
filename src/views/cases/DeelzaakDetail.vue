<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  DeelzaakDetail — full-page detail view of a single sub-case, with an
  always-visible breadcrumb back to the parent case. Mounted under
  /cases/:parentId/deelzaken/:id and shares the underlying `case`
  schema with CaseDetail — the breadcrumb is the differentiator.

  Components are kept lean here: the page renders a parent breadcrumb,
  basic identifying metadata, status, deadline and end-date, and links
  to the canonical CaseDetail view for the full edit experience.

  @spec openspec/changes/deelzaak-support/tasks.md#T06
-->
<template>
	<div class="deelzaak-detail">
		<!-- Parent breadcrumb (mandatory when viewing a sub-case) -->
		<nav
			v-if="parent"
			class="deelzaak-detail__breadcrumb"
			aria-label="breadcrumb">
			<router-link :to="parentRoute" class="deelzaak-detail__breadcrumb-link">
				<ArrowLeft :size="16" />
				{{ parent.title || parent.identifier || t('dossiq', 'Parent case') }}
			</router-link>
			<span class="deelzaak-detail__breadcrumb-sep" aria-hidden="true">
				›
			</span>
			<span class="deelzaak-detail__breadcrumb-current">
				{{
					subCase
						? subCase.title || subCase.identifier
						: t('dossiq', 'Sub-case')
				}}
			</span>
		</nav>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="!subCase"
			:name="t('dossiq', 'Sub-case not found')"
			:description="
				t(
					'dossiq',
					'The sub-case could not be loaded. It may have been deleted or unlinked from its parent.',
				)
			">
			<template #icon>
				<AlertCircleOutline :size="48" />
			</template>
			<template #action>
				<NcButton type="primary" @click="goToParent">
					{{ t('dossiq', 'Back to parent case') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<header class="deelzaak-detail__header">
				<div>
					<h2>{{ subCase.title || subCase.identifier }}</h2>
					<p class="deelzaak-detail__subtitle">
						{{ subCase.identifier || '—' }}
					</p>
				</div>
				<div class="deelzaak-detail__actions">
					<NcButton type="secondary" @click="goToFullCase">
						<template #icon>
							<OpenInNew :size="20" />
						</template>
						{{ t('dossiq', 'Open in case view') }}
					</NcButton>
					<NcButton type="tertiary" @click="goToParent">
						<template #icon>
							<ArrowLeft :size="20" />
						</template>
						{{ t('dossiq', 'Back to parent') }}
					</NcButton>
				</div>
			</header>

			<dl class="deelzaak-detail__grid">
				<div class="deelzaak-detail__row">
					<dt>{{ t('dossiq', 'Status') }}</dt>
					<dd>
						<span class="status-badge" :class="statusClass">
							{{ statusName }}
						</span>
					</dd>
				</div>
				<div class="deelzaak-detail__row">
					<dt>{{ t('dossiq', 'Assignee') }}</dt>
					<dd>{{ subCase.assignee || '—' }}</dd>
				</div>
				<div class="deelzaak-detail__row">
					<dt>{{ t('dossiq', 'Case type') }}</dt>
					<dd>{{ caseType ? caseType.title || caseType.name : '—' }}</dd>
				</div>
				<div class="deelzaak-detail__row">
					<dt>{{ t('dossiq', 'Start date') }}</dt>
					<dd>{{ formatDate(subCase.startDate) }}</dd>
				</div>
				<div class="deelzaak-detail__row">
					<dt>{{ t('dossiq', 'Deadline') }}</dt>
					<dd>{{ formatDate(subCase.deadline) }}</dd>
				</div>
				<div class="deelzaak-detail__row">
					<dt>{{ t('dossiq', 'Completed') }}</dt>
					<dd>
						{{
							subCase.endDate
								? formatDate(subCase.endDate)
								: t('dossiq', 'Open')
						}}
					</dd>
				</div>
			</dl>

			<section v-if="subCase.description" class="deelzaak-detail__section">
				<h3>{{ t('dossiq', 'Description') }}</h3>
				<p>{{ subCase.description }}</p>
			</section>
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import { useDeelzaakStore } from '../../store/modules/deelzaak.js'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { formatDate } from '../../utils/caseHelpers.js'

export default {
	name: 'DeelzaakDetail',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		AlertCircleOutline,
		ArrowLeft,
		OpenInNew,
	},

	data() {
		return {
			subCase: null,
			parent: null,
			caseType: null,
			statusType: null,
			loading: true,
			/**
			 * Live-updates handle for the or-object-{uuid} subscription of
			 * the viewed sub-case (nc-vue liveUpdatesPlugin, default-on
			 * since beta.212). Managed by syncLiveSubscription(); liveKey
			 * is the subscribed uuid so a re-render for the same object is
			 * a no-op. livePendingKey marks an in-flight subscribe;
			 * liveEpoch invalidates in-flight resolutions after a release
			 * (object switch / destroy). Events are refetch HINTS only —
			 * reload() re-runs the existing fetch path (debounced).
			 */
			liveHandle: null,
			liveKey: '',
			livePendingKey: '',
			liveEpoch: 0,
			liveRefetchTimer: null,
			liveRefreshing: false,
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		deelzaakStore() {
			return useDeelzaakStore()
		},

		parentIdFromRoute() {
			return this.$route?.params?.parentId || this.subCase?.parentCase || null
		},

		subCaseIdFromRoute() {
			return this.$route?.params?.id || null
		},

		parentRoute() {
			return this.parent
				? { name: 'CaseDetail', params: { id: this.parent.id } }
				: { name: 'Cases' }
		},

		/**
		 * Display name of the sub-case's current status.
		 *
		 * @return {string} The status name.
		 *
		 * @spec openspec/specs/deelzaak-support/spec.md#requirement-parent-case-breadcrumb-navigation
		 */
		statusName() {
			return (
				this.statusType?.name
				|| (this.subCase?.status ? '—' : t('dossiq', 'No status'))
			)
		},

		statusClass() {
			if (!this.statusType) {
				return ''
			}
			if (
				this.statusType.isFinal === true
				|| this.statusType.isFinal === 'true'
			) {
				return 'status-badge--final'
			}
			return 'status-badge--active'
		},
	},

	watch: {
		subCaseIdFromRoute: {
			immediate: false,
			handler() {
				this.reload()
				this.syncLiveSubscription()
			},
		},

		/**
		 * Live event hint received on the store (or-object event →
		 * liveUpdatesPlugin) — reload through the existing fetch path,
		 * debounced, never patched from a payload.
		 *
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 */
		'objectStore.liveLastEventAt': function () {
			this.onLiveEvent()
		},
	},

	async mounted() {
		// Object types are registered by App.vue's async created(), which
		// does not block child mounting — on a deep link this view can
		// mount first, so wait for the registry before fetching.
		await initializeStores()
		await this.reload()
		this.syncLiveSubscription()
	},

	/**
	 * Release the live object subscription on unmount.
	 *
	 * @spec openspec/specs/realtime-updates-ui/spec.md
	 */
	beforeUnmount() {
		clearTimeout(this.liveRefetchTimer)
		this.releaseLiveSubscription()
	},

	methods: {
		formatDate,
		/**
		 * Subscribe to live updates for the viewed sub-case
		 * (or-object-{uuid} via notify_push, polling fallback).
		 * Idempotent per uuid; releases the previous subscription when
		 * another sub-case is opened. Guarded with a pending-key marker
		 * plus an epoch counter so a release during an in-flight
		 * subscribe drops the stale handle instead of leaking it.
		 *
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 * @return {Promise<void>}
		 */
		async syncLiveSubscription() {
			const store = this.objectStore
			if (typeof store.subscribe !== 'function') {
				return
			}
			const uuid = this.subCaseIdFromRoute
			if (!uuid || !store.objectTypeRegistry?.case) {
				this.releaseLiveSubscription()
				return
			}
			if (
				(this.liveHandle && this.liveKey === uuid)
				|| this.livePendingKey === uuid
			) {
				return
			}
			this.releaseLiveSubscription()
			const epoch = this.liveEpoch
			this.livePendingKey = uuid
			this.liveKey = uuid
			try {
				const handle = await store.subscribe('case', uuid)
				if (this.liveEpoch !== epoch) {
					// Released while awaiting (another sub-case opened, or
					// the view was destroyed) — drop the stale subscription.
					store.unsubscribe(handle)
					return
				}
				this.liveHandle = handle
			} catch (err) {
				this.liveHandle = null
				this.liveKey = ''
				console.warn(
					'[DeelzaakDetail] live subscription failed:',
					err?.message ?? err,
				)
			} finally {
				if (this.livePendingKey === uuid) {
					this.livePendingKey = ''
				}
			}
		},

		/**
		 * Release the current live object subscription and invalidate any
		 * in-flight subscribe (its resolution unsubscribes itself via the
		 * epoch check).
		 *
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 */
		releaseLiveSubscription() {
			this.liveEpoch += 1
			this.livePendingKey = ''
			if (
				this.liveHandle
				&& typeof this.objectStore.unsubscribe === 'function'
			) {
				this.objectStore.unsubscribe(this.liveHandle)
			}
			this.liveHandle = null
			this.liveKey = ''
		},

		/**
		 * Debounced reload on a live event hint — through the existing
		 * reload() path (non-blanking), never patched from a payload.
		 *
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 */
		onLiveEvent() {
			if (!this.liveHandle) {
				return
			}
			clearTimeout(this.liveRefetchTimer)
			this.liveRefetchTimer = setTimeout(async () => {
				if (this.loading || this.liveRefreshing) {
					return
				}
				// Non-blanking: the template swaps the content for a spinner
				// on `loading`, so a background reload must not toggle it.
				this.liveRefreshing = true
				try {
					await this.reload({ background: true })
				} finally {
					this.liveRefreshing = false
				}
			}, 500)
		},

		/**
		 * Load the sub-case with its parent, case type and status type.
		 *
		 * @param {object} [options] Fetch options
		 * @param {boolean} [options.background] When true (live-update
		 *   refresh), don't toggle `loading` — the template blanks the
		 *   whole view for a spinner on it.
		 *
		 * @return {Promise<void>}
		 */
		async reload({ background = false } = {}) {
			if (!this.subCaseIdFromRoute) {
				this.loading = false
				return
			}
			if (!background) {
				this.loading = true
			}
			try {
				this.subCase = await this.objectStore
					.fetchObject('case', this.subCaseIdFromRoute)
					.catch(() => null)

				if (!this.subCase) {
					return
				}

				const parentUuid = this.subCase.parentCase || this.parentIdFromRoute
				if (parentUuid) {
					// Prefer the store action so the parentCase getter stays warm.
					this.parent = await this.deelzaakStore
						.fetchParentCase(parentUuid)
						.catch(() => null)
					if (!this.parent) {
						this.parent = await this.objectStore
							.fetchObject('case', parentUuid)
							.catch(() => null)
					}
				} else {
					this.parent = null
				}

				if (this.subCase.caseType) {
					this.caseType = await this.objectStore
						.fetchObject('caseType', this.subCase.caseType)
						.catch(() => null)
				}
				if (this.subCase.status) {
					this.statusType = await this.objectStore
						.fetchObject('statusType', this.subCase.status)
						.catch(() => null)
				}
			} catch (err) {
				console.error('[DeelzaakDetail] reload failed', err)
			} finally {
				if (!background) {
					this.loading = false
				}
			}
		},

		goToParent() {
			this.$router.push(this.parentRoute)
		},

		goToFullCase() {
			if (this.subCase) {
				this.$router.push({
					name: 'CaseDetail',
					params: { id: this.subCase.id },
				})
			}
		},
	},
}
</script>

<style scoped>
.deelzaak-detail {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
}

.deelzaak-detail__breadcrumb {
	display: flex;
	gap: 4px;
	align-items: center;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.deelzaak-detail__breadcrumb-link {
	display: inline-flex;
	gap: 4px;
	align-items: center;
	color: var(--color-primary-element);
	text-decoration: none;
}

.deelzaak-detail__breadcrumb-link:hover {
	text-decoration: underline;
}

.deelzaak-detail__breadcrumb-sep {
	color: var(--color-text-lighter);
}

.deelzaak-detail__breadcrumb-current {
	color: var(--color-main-text);
	font-weight: 500;
}

.deelzaak-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
	flex-wrap: wrap;
}

.deelzaak-detail__header h2 {
	margin: 0;
}

.deelzaak-detail__subtitle {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.deelzaak-detail__actions {
	display: flex;
	gap: 8px;
}

.deelzaak-detail__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px;
	margin: 0;
}

.deelzaak-detail__row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.deelzaak-detail__row dt {
	font-size: 0.8rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.deelzaak-detail__row dd {
	margin: 0;
	color: var(--color-main-text);
}

.deelzaak-detail__section {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
}

.deelzaak-detail__section h3 {
	margin: 0 0 8px;
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 500;
}

.status-badge--active {
	background: var(--color-primary-light);
	color: var(--color-primary-text);
}

.status-badge--final {
	background: var(--color-success);
	color: white;
}
</style>
