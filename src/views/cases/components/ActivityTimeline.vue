<template>
	<div class="activity-timeline">
		<h3 class="activity-timeline__title">
			{{ t('procest', 'Activity') }}
		</h3>

		<!-- Add note input -->
		<div v-if="!isReadOnly" class="activity-timeline__add-note">
			<textarea
				v-model="noteText"
				:placeholder="t('procest', 'Add a note...')"
				rows="2"
				class="activity-timeline__note-input" />
			<NcButton
				type="primary"
				:disabled="!noteText.trim()"
				@click="addNote">
				{{ t('procest', 'Add note') }}
			</NcButton>
		</div>

		<!-- Timeline entries -->
		<div v-if="sortedActivity.length === 0" class="activity-timeline__empty">
			{{ t('procest', 'No activity yet') }}
		</div>

		<div
			v-for="(entry, index) in sortedActivity"
			:key="index"
			class="activity-timeline__entry"
			:class="'activity-timeline__entry--' + entry.type">
			<div class="activity-timeline__icon">
				{{ getIcon(entry.type) }}
			</div>
			<div class="activity-timeline__content">
				<div class="activity-timeline__description">
					{{ entry.description }}
				</div>
				<div class="activity-timeline__meta">
					<span class="activity-timeline__user">{{ entry.user }}</span>
					<span class="activity-timeline__date">{{ formatEntryDate(entry.date) }}</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { formatDate } from '../../../utils/caseHelpers.js'

export default {
	name: 'ActivityTimeline',
	components: {
		NcButton,
	},
	props: {
		activity: {
			type: Array,
			default: () => [],
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['add-note'],
	data() {
		return {
			noteText: '',
		}
	},
	computed: {
		sortedActivity() {
			return [...this.activity].sort((a, b) => new Date(b.date) - new Date(a.date))
		},
	},
	methods: {
		getIcon(type) {
			const icons = {
				created: '+',
				status_change: '→',
				update: '✎',
				extension: '⏰',
				note: '💬',
			}
			return icons[type] || '•'
		},
		formatEntryDate(dateString) {
			return formatDate(dateString)
		},
		addNote() {
			if (!this.noteText.trim()) return
			this.$emit('add-note', this.noteText.trim())
			this.noteText = ''
		},
	},
}
</script>

<style scoped>
.activity-timeline {
	margin-top: 24px;
}

.activity-timeline__title {
	margin: 0 0 12px;
	font-size: 14px;
	font-weight: bold;
}

.activity-timeline__add-note {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	margin-bottom: 16px;
}

.activity-timeline__note-input {
	flex: 1;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
	font-size: 14px;
}

.activity-timeline__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 12px 0;
}

.activity-timeline__entry {
	display: flex;
	gap: 12px;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
}

.activity-timeline__entry:last-child {
	border-bottom: none;
}

.activity-timeline__icon {
	width: 28px;
	height: 28px;
	border-radius: 50%;
	background: var(--color-background-dark);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 14px;
	flex-shrink: 0;
}

.activity-timeline__entry--status_change .activity-timeline__icon {
	background: var(--color-primary-light);
}

.activity-timeline__entry--extension .activity-timeline__icon {
	background: var(--color-warning-light, rgba(255, 165, 0, 0.15));
}

.activity-timeline__content {
	flex: 1;
	min-width: 0;
}

.activity-timeline__description {
	font-size: 14px;
	line-height: 1.4;
}

.activity-timeline__meta {
	display: flex;
	gap: 12px;
	margin-top: 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
