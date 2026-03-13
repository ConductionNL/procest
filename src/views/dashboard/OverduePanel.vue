<template>
	<div class="overdue-panel">
		<div class="overdue-panel__header">
			<h3 class="overdue-panel__title">
				{{ t('procest', 'Overdue Cases') }}
				<span v-if="cases.length > 0" class="overdue-panel__badge">{{ cases.length }}</span>
			</h3>
		</div>

		<template v-if="loading">
			<div v-for="i in 3" :key="i" class="overdue-panel__skeleton">
				<div class="skeleton-row" />
			</div>
		</template>

		<template v-else-if="error">
			<div class="overdue-panel__error">
				<p>{{ error }}</p>
				<NcButton type="tertiary" @click="$emit('retry')">
					{{ t('procest', 'Retry') }}
				</NcButton>
			</div>
		</template>

		<template v-else-if="cases.length === 0">
			<div class="overdue-panel__empty">
				<span class="overdue-panel__check">&#10003;</span>
				<p>{{ t('procest', 'No overdue cases') }}</p>
			</div>
		</template>

		<template v-else>
			<div class="overdue-panel__list">
				<div
					v-for="c in cases"
					:key="c.id"
					class="overdue-panel__row"
					:class="severityClass(c.daysOverdue)"
					@click="$emit('click-case', c.id)">
					<div class="overdue-panel__info">
						<span class="overdue-panel__identifier">{{ c.identifier }}</span>
						<span class="overdue-panel__case-title">{{ c.title }}</span>
						<span class="overdue-panel__type">{{ c.caseTypeName }}</span>
					</div>
					<div class="overdue-panel__meta">
						<span class="overdue-panel__days">
							{{ t('procest', '{days} days overdue', { days: c.daysOverdue }) }}
						</span>
						<span class="overdue-panel__handler">{{ c.handler }}</span>
					</div>
				</div>
			</div>

			<div class="overdue-panel__footer">
				<a href="#" @click.prevent="$emit('view-all')">
					{{ t('procest', 'View all overdue') }} &rarr;
				</a>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'OverduePanel',
	components: {
		NcButton,
	},
	props: {
		cases: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
		error: { type: String, default: null },
	},
	emits: ['click-case', 'view-all', 'retry'],
	methods: {
		severityClass(daysOverdue) {
			if (daysOverdue > 7) return 'overdue-panel__row--severe'
			if (daysOverdue > 2) return 'overdue-panel__row--moderate'
			return 'overdue-panel__row--mild'
		},
	},
}
</script>

<style scoped>
.overdue-panel {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
}

.overdue-panel__header {
	margin-bottom: 12px;
}

.overdue-panel__title {
	font-size: 15px;
	margin: 0;
	display: flex;
	align-items: center;
	gap: 8px;
}

.overdue-panel__badge {
	background: var(--color-error);
	color: white;
	font-size: 12px;
	font-weight: bold;
	padding: 2px 8px;
	border-radius: 10px;
}

.overdue-panel__list {
	max-height: 300px;
	overflow-y: auto;
}

.overdue-panel__row {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding: 10px 12px;
	margin-bottom: 4px;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: background 0.15s ease;
}

.overdue-panel__row:hover {
	background: var(--color-background-hover);
}

.overdue-panel__row--severe {
	border-left: 3px solid var(--color-error);
	background: rgba(var(--color-error-rgb, 229, 57, 53), 0.05);
}

.overdue-panel__row--moderate {
	border-left: 3px solid var(--color-warning);
	background: rgba(var(--color-warning-rgb, 255, 152, 0), 0.05);
}

.overdue-panel__row--mild {
	border-left: 3px solid var(--color-warning-text);
}

.overdue-panel__info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1;
}

.overdue-panel__identifier {
	font-weight: bold;
	font-size: 13px;
}

.overdue-panel__case-title {
	font-size: 13px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.overdue-panel__type {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.overdue-panel__meta {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 2px;
	flex-shrink: 0;
	margin-left: 12px;
}

.overdue-panel__days {
	color: var(--color-error);
	font-size: 13px;
	font-weight: 600;
	white-space: nowrap;
}

.overdue-panel__handler {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.overdue-panel__footer {
	margin-top: 12px;
	text-align: center;
}

.overdue-panel__footer a {
	font-size: 13px;
	color: var(--color-primary);
	text-decoration: none;
}

.overdue-panel__empty {
	text-align: center;
	padding: 20px;
	color: var(--color-success);
}

.overdue-panel__check {
	font-size: 24px;
	display: block;
	margin-bottom: 4px;
}

.overdue-panel__error {
	text-align: center;
	padding: 12px;
	color: var(--color-error);
}

.overdue-panel__skeleton {
	margin-bottom: 8px;
}

.skeleton-row {
	height: 48px;
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
