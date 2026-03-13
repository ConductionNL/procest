<template>
	<div class="deadline-panel">
		<h3 class="deadline-panel__title">
			{{ t('procest', 'Deadline & Timing') }}
		</h3>

		<div class="deadline-panel__grid">
			<div class="deadline-panel__item">
				<span class="deadline-panel__label">{{ t('procest', 'Started') }}</span>
				<span class="deadline-panel__value">{{ formattedStartDate }}</span>
			</div>

			<div class="deadline-panel__item">
				<span class="deadline-panel__label">{{ t('procest', 'Deadline') }}</span>
				<span class="deadline-panel__value">{{ formattedDeadline }}</span>
			</div>

			<div class="deadline-panel__item">
				<span class="deadline-panel__label">{{ t('procest', 'Processing time') }}</span>
				<span class="deadline-panel__value">{{ formattedProcessingDeadline }}</span>
			</div>

			<div class="deadline-panel__item">
				<span class="deadline-panel__label">{{ t('procest', 'Days elapsed') }}</span>
				<span class="deadline-panel__value">{{ daysElapsed }}</span>
			</div>
		</div>

		<!-- Countdown -->
		<div class="deadline-panel__countdown" :class="countdown.style">
			{{ countdown.text }}
		</div>

		<!-- Extension info -->
		<div class="deadline-panel__extension">
			<span v-if="extensionAllowed && extensionCount === 0" class="deadline-panel__extension-info">
				{{ t('procest', 'Extension: allowed (+{period})', { period: formattedExtensionPeriod }) }}
			</span>
			<span v-else-if="extensionAllowed && extensionCount > 0" class="deadline-panel__extension-info">
				{{ t('procest', 'Extension: already extended') }}
			</span>
			<span v-else class="deadline-panel__extension-info">
				{{ t('procest', 'Extension: not allowed') }}
			</span>

			<NcButton
				v-if="canExtend"
				type="secondary"
				@click="$emit('extend')">
				{{ t('procest', 'Request Extension') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { formatDate, formatDeadlineCountdown, getDaysElapsed, formatDuration } from '../../../utils/caseHelpers.js'

export default {
	name: 'DeadlinePanel',
	components: {
		NcButton,
	},
	props: {
		startDate: {
			type: String,
			default: null,
		},
		deadline: {
			type: String,
			default: null,
		},
		processingDeadline: {
			type: String,
			default: null,
		},
		extensionAllowed: {
			type: Boolean,
			default: false,
		},
		extensionPeriod: {
			type: String,
			default: null,
		},
		extensionCount: {
			type: Number,
			default: 0,
		},
		isFinal: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['extend'],
	computed: {
		formattedStartDate() {
			return formatDate(this.startDate)
		},
		formattedDeadline() {
			return formatDate(this.deadline)
		},
		formattedProcessingDeadline() {
			return this.processingDeadline ? formatDuration(this.processingDeadline) : '—'
		},
		formattedExtensionPeriod() {
			return this.extensionPeriod ? formatDuration(this.extensionPeriod) : '—'
		},
		daysElapsed() {
			return getDaysElapsed(this.startDate)
		},
		countdown() {
			return formatDeadlineCountdown({ deadline: this.deadline }, this.isFinal)
		},
		canExtend() {
			return this.extensionAllowed && this.extensionCount === 0 && !this.isFinal
		},
	},
}
</script>

<style scoped>
.deadline-panel {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 16px;
	margin-bottom: 16px;
}

.deadline-panel__title {
	margin: 0 0 12px;
	font-size: 14px;
	font-weight: bold;
}

.deadline-panel__grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px 16px;
	margin-bottom: 12px;
}

.deadline-panel__item {
	display: flex;
	flex-direction: column;
}

.deadline-panel__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.deadline-panel__value {
	font-size: 14px;
	font-weight: 500;
}

.deadline-panel__countdown {
	font-size: 16px;
	font-weight: bold;
	padding: 8px 12px;
	border-radius: var(--border-radius);
	text-align: center;
	margin-bottom: 12px;
}

.deadline--ok {
	color: var(--color-success);
	background: rgba(0, 128, 0, 0.08);
}

.deadline--today,
.deadline--tomorrow {
	color: var(--color-warning);
	background: rgba(255, 165, 0, 0.08);
}

.deadline--overdue {
	color: var(--color-error);
	background: rgba(255, 0, 0, 0.08);
}

.deadline--final {
	color: var(--color-text-maxcontrast);
}

.deadline-panel__extension {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.deadline-panel__extension-info {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
