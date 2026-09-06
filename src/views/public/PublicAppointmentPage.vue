<template>
	<div class="public-appointment-page">
		<div v-if="loading" class="public-appointment-page__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="error" class="public-appointment-page__error">
			<h2>{{ t('dossiq', 'Appointment not found') }}</h2>
			<p>{{ error }}</p>
		</div>

		<div v-else-if="appointment" class="public-appointment-page__content">
			<h2>{{ t('dossiq', 'Your Appointment') }}</h2>

			<div class="public-appointment-page__details">
				<p>
					<strong>{{ t('dossiq', 'Date and time') }}:</strong>
					{{ formatDateTime(appointment.dateTime) }}
				</p>
				<p>
					<strong>{{ t('dossiq', 'Status') }}:</strong>
					{{ statusLabel(appointment.status) }}
				</p>
			</div>

			<div
				v-if="appointment.status === 'scheduled'"
				class="public-appointment-page__actions">
				<NcButton type="error" @click="cancelAppointment">
					{{ t('dossiq', 'Cancel appointment') }}
				</NcButton>
			</div>

			<div v-if="cancelled" class="public-appointment-page__cancelled">
				<NcNoteCard type="success">
					{{ t('dossiq', 'Your appointment has been cancelled.') }}
				</NcNoteCard>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'PublicAppointmentPage',
	components: { NcButton, NcLoadingIcon, NcNoteCard },
	props: {
		token: { type: String, required: true },
	},

	data() {
		return { loading: true, error: null, appointment: null, cancelled: false }
	},

	/** @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md */
	async mounted() {
		try {
			const url = generateUrl(
				`/apps/dossiq/api/public/appointment/${this.token}`,
			)
			const response = await axios.get(url)
			this.appointment = response.data.appointment
		} catch (e) {
			this.error = t(
				'dossiq',
				'This appointment link is invalid or has expired.',
			)
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		/**
		 * @param {string} dt The date-time to format.
		 * @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md
		 */
		formatDateTime(dt) {
			if (!dt) return '-'
			return new Date(dt).toLocaleString('nl-NL', {
				dateStyle: 'long',
				timeStyle: 'short',
			})
		},

		/**
		 * @param {string} status The status.
		 * @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md
		 */
		statusLabel(status) {
			const labels = {
				scheduled: t('dossiq', 'Scheduled'),
				cancelled: t('dossiq', 'Cancelled'),
				completed: t('dossiq', 'Completed'),
				no_show: t('dossiq', 'Not appeared'),
			}
			return labels[status] || status
		},

		/** @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md */
		async cancelAppointment() {
			try {
				const url = generateUrl(
					`/apps/dossiq/api/public/appointment/${this.token}/cancel`,
				)
				await axios.post(url)
				this.appointment.status = 'cancelled'
				this.cancelled = true
			} catch (e) {
				// Handle error
			}
		},
	},
}
</script>

<style scoped>
.public-appointment-page {
	max-width: 600px;
	margin: 40px auto;
	padding: 24px;
}

.public-appointment-page__details {
	background: var(--color-background-dark);
	padding: 16px;
	border-radius: var(--border-radius);
	margin: 16px 0;
}

.public-appointment-page__actions {
	margin-top: 16px;
}
</style>
