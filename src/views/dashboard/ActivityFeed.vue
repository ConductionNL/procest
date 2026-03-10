<template>
	<div class="activity-feed">
		<h3 class="activity-feed__title">
			{{ t('procest', 'Recent Activity') }}
		</h3>

		<template v-if="loading">
			<div v-for="i in 5" :key="i" class="activity-feed__skeleton">
				<div class="skeleton-row" />
			</div>
		</template>

		<template v-else-if="error">
			<div class="activity-feed__error">
				<p>{{ error }}</p>
				<NcButton type="tertiary" @click="$emit('retry')">
					{{ t('procest', 'Retry') }}
				</NcButton>
			</div>
		</template>

		<template v-else-if="entries.length === 0">
			<p class="activity-feed__empty">
				{{ t('procest', 'No recent activity') }}
			</p>
		</template>

		<template v-else>
			<div
				v-for="(entry, index) in entries"
				:key="index"
				class="activity-feed__entry">
				<span class="activity-feed__icon">{{ typeIcon(entry.type) }}</span>
				<div class="activity-feed__content">
					<span class="activity-feed__description">{{ entry.description }}</span>
					<span class="activity-feed__meta">
						{{ t('procest', 'by {user}', { user: entry.user }) }}
						<span class="activity-feed__case-ref">#{{ entry.caseIdentifier }}</span>
					</span>
				</div>
				<span class="activity-feed__time">{{ formatTime(entry.date) }}</span>
			</div>

			<div class="activity-feed__footer">
				<a href="#" @click.prevent="$emit('view-all')">
					{{ t('procest', 'View all activity') }} &rarr;
				</a>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { formatRelativeTime } from '../../utils/dashboardHelpers.js'

const TYPE_ICONS = {
	created: '+',
	status_change: '→',
	update: '✎',
	extension: '⏱',
	note: '💬',
}

export default {
	name: 'ActivityFeed',
	components: {
		NcButton,
	},
	props: {
		entries: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
		error: { type: String, default: null },
	},
	emits: ['view-all', 'retry'],
	methods: {
		typeIcon(type) {
			return TYPE_ICONS[type] || '•'
		},
		formatTime(date) {
			return formatRelativeTime(date)
		},
	},
}
</script>

<style scoped>
.activity-feed {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
}

.activity-feed__title {
	font-size: 15px;
	margin: 0 0 12px;
}

.activity-feed__entry {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.activity-feed__entry:last-of-type {
	border-bottom: none;
}

.activity-feed__icon {
	flex-shrink: 0;
	width: 24px;
	height: 24px;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	border-radius: 50%;
}

.activity-feed__content {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.activity-feed__description {
	font-size: 13px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.activity-feed__meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.activity-feed__case-ref {
	margin-left: 4px;
	color: var(--color-text-maxcontrast);
}

.activity-feed__time {
	flex-shrink: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.activity-feed__footer {
	margin-top: 12px;
	text-align: center;
}

.activity-feed__footer a {
	font-size: 13px;
	color: var(--color-primary);
	text-decoration: none;
}

.activity-feed__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 20px 0;
}

.activity-feed__error {
	text-align: center;
	padding: 12px;
	color: var(--color-error);
}

.activity-feed__skeleton {
	margin-bottom: 8px;
}

.skeleton-row {
	height: 36px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
	0% { opacity: 0.6; }
	50% { opacity: 1; }
	100% { opacity: 0.6; }
}
</style>
