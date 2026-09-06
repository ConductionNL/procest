<!--
 SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Omgevingsvergunning — Detail')"
		size="large"
		:canClose="true"
		@close="$emit('close')">
		<template #default>
			<div class="dso-case-detail">
				<!-- Lifecycle action bar -->
				<div class="dso-case-detail__actions">
					<NcButton type="primary" @click="showTransitionDialog = true">
						{{ t('dossiq', 'Status transition') }}
					</NcButton>
					<NcButton type="secondary" @click="showBeschikkingDialog = true">
						{{ t('dossiq', 'Generate beschikking') }}
					</NcButton>
					<NcButton type="secondary" @click="showSamenwerkDialog = true">
						{{ t('dossiq', 'Collaboration') }}
					</NcButton>
					<NcButton type="secondary" @click="showDoorstuurDialog = true">
						{{ t('dossiq', 'Forward') }}
					</NcButton>
				</div>

				<!-- Aanvraag section -->
				<section class="dso-section">
					<h3>{{ t('dossiq', 'Application') }}</h3>
					<dl class="dso-dl">
						<dt>{{ t('dossiq', 'Title') }}</dt>
						<dd>{{ zaak.title || '—' }}</dd>
						<dt>{{ t('dossiq', 'DSO Status') }}</dt>
						<dd>{{ zaak.dsoStatus || '—' }}</dd>
						<dt>{{ t('dossiq', 'Procedure type') }}</dt>
						<dd>{{ zaak.procedureType || '—' }}</dd>
						<dt>{{ t('dossiq', 'Deadline') }}</dt>
						<dd>{{ formatDate(zaak.deadlineDate) }}</dd>
						<dt>{{ t('dossiq', 'Competent Authority') }}</dt>
						<dd>{{ zaak.competentAuthority || '—' }}</dd>
						<dt>{{ t('dossiq', 'Permit application ref') }}</dt>
						<dd>{{ zaak.permitApplicationRef || '—' }}</dd>
					</dl>
				</section>

				<!-- Decision section (when verleend/geweigerd) -->
				<section v-if="zaak.besluitdatum" class="dso-section">
					<h3>{{ t('dossiq', 'Decision') }}</h3>
					<dl class="dso-dl">
						<dt>{{ t('dossiq', 'Decision date') }}</dt>
						<dd>{{ formatDate(zaak.besluitdatum) }}</dd>
						<dt>{{ t('dossiq', 'Explanation') }}</dt>
						<dd>{{ zaak.dsoNotes || '—' }}</dd>
					</dl>
				</section>

				<!-- Samenwerkverzoeken section -->
				<section class="dso-section">
					<h3>{{ t('dossiq', 'Collaboration requests') }}</h3>
					<p
						v-if="
							!zaak.collaboration_requests
							|| zaak.collaboration_requests.length === 0
						">
						{{ t('dossiq', 'No samenwerkverzoeken linked') }}
					</p>
					<ul v-else>
						<li v-for="swId in zaak.collaboration_requests" :key="swId">
							{{ swId }}
						</li>
					</ul>
				</section>

				<!-- Activity timeline -->
				<section class="dso-section">
					<h3>{{ t('dossiq', 'Activity timeline') }}</h3>
					<ul v-if="activityEntries.length > 0" class="dso-activity">
						<li v-for="(entry, idx) in activityEntries" :key="idx">
							<span class="dso-activity__timestamp">{{
								formatDate(entry.timestamp)
							}}</span>
							<span class="dso-activity__user">{{
								entry.userId
							}}</span>
							<span
								>{{ entry.oldStatus }} → {{ entry.newStatus }}</span
							>
						</li>
					</ul>
					<p v-else>
						{{ t('dossiq', 'No activity recorded') }}
					</p>
				</section>
			</div>

			<!-- Sub-dialogs -->
			<BeschikkingDialog
				v-if="showBeschikkingDialog"
				:zaakId="zaakId"
				@close="showBeschikkingDialog = false"
				@generated="onBeschikkingGenerated" />
			<SamenwerkverzoekDialog
				v-if="showSamenwerkDialog"
				:zaakId="zaakId"
				@close="showSamenwerkDialog = false"
				@initiated="onSamenwerkInitiated" />
			<DoorstuurDialog
				v-if="showDoorstuurDialog"
				:zaakId="zaakId"
				@close="showDoorstuurDialog = false" />

			<!-- Inline transition form -->
			<div v-if="showTransitionDialog" class="dso-transition-form">
				<h3>{{ t('dossiq', 'Transition status') }}</h3>
				<NcSelect
					v-model="transitionStatus"
					:options="transitionOptions"
					:inputLabel="t('dossiq', 'New status')"
					inputId="transition-status" />
				<NcTextField
					v-model="transitionToelichting"
					:label="t('dossiq', 'Explanation')" />
				<NcTextField
					v-if="requiresBesluitdatum"
					v-model="transitionBesluitdatum"
					:label="t('dossiq', 'Decision date')"
					type="date" />
				<div class="dso-transition-form__actions">
					<NcButton
						type="primary"
						:disabled="!transitionStatus"
						@click="executeTransition">
						{{ t('dossiq', 'Confirm') }}
					</NcButton>
					<NcButton type="tertiary" @click="showTransitionDialog = false">
						{{ t('dossiq', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import BeschikkingDialog from './BeschikkingDialog.vue'
import DoorstuurDialog from './DoorstuurDialog.vue'
import SamenwerkverzoekDialog from './SamenwerkverzoekDialog.vue'

export default {
	name: 'DsoCaseDetail',
	components: {
		NcButton,
		NcDialog,
		NcSelect,
		NcTextField,
		BeschikkingDialog,
		DoorstuurDialog,
		SamenwerkverzoekDialog,
	},

	props: {
		case: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'transition'],
	data() {
		return {
			showBeschikkingDialog: false,
			showSamenwerkDialog: false,
			showDoorstuurDialog: false,
			showTransitionDialog: false,
			transitionStatus: null,
			transitionToelichting: '',
			transitionBesluitdatum: '',
			transitionOptions: [
				{ label: t('dossiq', 'Submitted'), value: 'submitted' },
				{ label: t('dossiq', 'In handling'), value: 'in_handling' },
				{ label: t('dossiq', 'Granted'), value: 'granted' },
				{ label: t('dossiq', 'Refused'), value: 'refused' },
				{ label: t('dossiq', 'Withdrawn'), value: 'withdrawn' },
			],
		}
	},

	computed: {
		/**
		 * The case, under a name a template expression may actually use.
		 *
		 * 🔴 THE PROP IS CALLED `case`, AND A TEMPLATE CANNOT READ IT. Vue
		 * parses every template expression as JavaScript, and `case` is a
		 * reserved word: `{{ case.title }}` is a compile error, not a lookup
		 * that returns undefined. So this alias is not a nicety, it is the
		 * only way the template reaches the prop at all.
		 *
		 * The template was written against `zaak` and the prop was later
		 * renamed to `case` without it, which is why every field in this
		 * dialog rendered as nothing.
		 *
		 * @return {object} The case this dialog shows.
		 *
		 * @spec exclude presentational alias for a reserved-word prop name
		 */
		zaak() {
			return this.case
		},

		zaakId() {
			return this.case.uuid || this.case.id || ''
		},

		activityEntries() {
			try {
				const raw = this.case.activity
				if (!raw) {
					return []
				}

				const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw
				return Array.isArray(parsed) ? parsed : []
			} catch {
				return []
			}
		},

		requiresBesluitdatum() {
			const val = this.transitionStatus?.value
			return val === 'granted' || val === 'refused'
		},
	},

	methods: {
		t,
		formatDate(dateStr) {
			if (!dateStr) {
				return '—'
			}

			return new Date(dateStr).toLocaleDateString('nl-NL')
		},

		/**
		 * Execute the selected transition on the DSO case.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-req-dso-008-dso-status-lifecycle-for-vergunningaanvragen
		 */
		async executeTransition() {
			if (!this.transitionStatus) {
				return
			}

			try {
				const payload = {
					newStatus: this.transitionStatus.value,
					notes: this.transitionToelichting || undefined,
					besluitdatum: this.transitionBesluitdatum || undefined,
				}
				const { data } = await axios.post(
					generateUrl(
						'/apps/dossiq/api/dso/cases/'
							+ encodeURIComponent(this.zaakId)
							+ '/transition',
					),
					payload,
				)
				this.showTransitionDialog = false
				this.$emit('transition', data)
			} catch {
				// Error is shown via Nextcloud toast in a real impl; silent for now.
			}
		},

		onBeschikkingGenerated() {
			this.showBeschikkingDialog = false
		},

		onSamenwerkInitiated(samenwerk) {
			this.showSamenwerkDialog = false
			if (samenwerk?.uuid || samenwerk?.id) {
				this.$emit('transition', {
					...this.case,
					collaboration_requests: [
						...(this.case.collaboration_requests || []),
						samenwerk.uuid || samenwerk.id,
					],
				})
			}
		},
	},
}
</script>

<style scoped>
.dso-case-detail {
	padding: 8px 0;
}

.dso-case-detail__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.dso-section {
	margin-bottom: 20px;
}

.dso-section h3 {
	font-weight: bold;
	margin-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 4px;
}

.dso-dl {
	display: grid;
	grid-template-columns: 180px 1fr;
	gap: 4px 12px;
}

.dso-dl dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.dso-activity {
	list-style: none;
	padding: 0;
}

.dso-activity li {
	display: flex;
	gap: 8px;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.dso-activity__timestamp {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.dso-transition-form {
	margin-top: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
}

.dso-transition-form__actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}
</style>
