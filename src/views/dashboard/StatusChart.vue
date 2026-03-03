<template>
	<div class="status-chart">
		<h3 class="status-chart__title">{{ t('procest', 'Cases by Status') }}</h3>

		<template v-if="loading">
			<div v-for="i in 4" :key="i" class="status-chart__skeleton">
				<div class="skeleton-bar" />
			</div>
		</template>

		<template v-else-if="error">
			<div class="status-chart__error">
				<p>{{ error }}</p>
				<NcButton type="tertiary" @click="$emit('retry')">
					{{ t('procest', 'Retry') }}
				</NcButton>
			</div>
		</template>

		<template v-else-if="statusData.length === 0">
			<p class="status-chart__empty">{{ t('procest', 'No open cases') }}</p>
		</template>

		<template v-else>
			<div
				v-for="(item, index) in statusData"
				:key="item.name"
				class="status-chart__row">
				<span class="status-chart__label">{{ item.name }}</span>
				<div class="status-chart__bar-container">
					<div
						class="status-chart__bar"
						:style="{ width: barWidth(item.count), backgroundColor: barColor(index) }" />
				</div>
				<span class="status-chart__count">{{ item.count }}</span>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

const BAR_COLORS = [
	'var(--color-primary)',
	'var(--color-primary-element-light)',
	'var(--color-warning)',
	'var(--color-success)',
	'var(--color-error)',
	'var(--color-text-maxcontrast)',
]

export default {
	name: 'StatusChart',
	components: {
		NcButton,
	},
	props: {
		statusData: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
		error: { type: String, default: null },
	},
	emits: ['retry'],
	computed: {
		maxCount() {
			return Math.max(1, ...this.statusData.map(s => s.count))
		},
	},
	methods: {
		barWidth(count) {
			const pct = (count / this.maxCount) * 100
			return `max(20px, ${pct}%)`
		},
		barColor(index) {
			return BAR_COLORS[index % BAR_COLORS.length]
		},
	},
}
</script>

<style scoped>
.status-chart {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
}

.status-chart__title {
	font-size: 15px;
	margin: 0 0 12px;
}

.status-chart__row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.status-chart__label {
	flex: 0 0 140px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	text-align: right;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.status-chart__bar-container {
	flex: 1;
	height: 24px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.status-chart__bar {
	height: 100%;
	border-radius: var(--border-radius);
	transition: width 0.3s ease;
}

.status-chart__count {
	flex: 0 0 32px;
	font-size: 13px;
	font-weight: 600;
	text-align: right;
}

.status-chart__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 20px 0;
}

.status-chart__error {
	text-align: center;
	padding: 12px;
	color: var(--color-error);
}

.status-chart__skeleton {
	margin-bottom: 8px;
}

.skeleton-bar {
	height: 24px;
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
