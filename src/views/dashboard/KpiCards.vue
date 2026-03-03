<template>
	<div class="kpi-cards">
		<div
			v-for="card in cards"
			:key="card.id"
			class="kpi-card"
			:class="[card.colorClass, { 'kpi-card--clickable': true }]"
			@click="$emit('click-card', card.id)">
			<template v-if="loading">
				<NcLoadingIcon :size="32" />
			</template>
			<template v-else>
				<h3 class="kpi-card__title">
					{{ card.title }}
				</h3>
				<span class="kpi-card__count">{{ card.count }}</span>
				<span class="kpi-card__sub" :class="card.subClass">{{ card.sub }}</span>
			</template>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'KpiCards',
	components: {
		NcLoadingIcon,
	},
	props: {
		openCases: { type: Number, default: 0 },
		newToday: { type: Number, default: 0 },
		overdueCases: { type: Number, default: 0 },
		completedThisMonth: { type: Number, default: 0 },
		avgProcessingDays: { type: Number, default: null },
		myTasks: { type: Number, default: 0 },
		tasksDueToday: { type: Number, default: 0 },
		loading: { type: Boolean, default: false },
	},
	emits: ['click-card'],
	computed: {
		cards() {
			return [
				{
					id: 'open',
					title: t('procest', 'Open Cases'),
					count: this.openCases,
					sub: this.newToday > 0
						? t('procest', '+{n} today', { n: this.newToday })
						: t('procest', '0 today'),
					colorClass: 'kpi-card--primary',
					subClass: '',
				},
				{
					id: 'overdue',
					title: t('procest', 'Overdue'),
					count: this.overdueCases,
					sub: this.overdueCases > 0
						? t('procest', 'action needed')
						: t('procest', 'all on track'),
					colorClass: this.overdueCases > 0 ? 'kpi-card--warning' : 'kpi-card--primary',
					subClass: this.overdueCases > 0 ? 'kpi-card__sub--warning' : 'kpi-card__sub--success',
				},
				{
					id: 'completed',
					title: t('procest', 'Completed This Month'),
					count: this.completedThisMonth,
					sub: this.avgProcessingDays !== null
						? t('procest', 'avg {days} days', { days: this.avgProcessingDays })
						: t('procest', 'no data'),
					colorClass: 'kpi-card--success',
					subClass: '',
				},
				{
					id: 'tasks',
					title: t('procest', 'My Tasks'),
					count: this.myTasks,
					sub: this.tasksDueToday > 0
						? t('procest', '{n} due today', { n: this.tasksDueToday })
						: t('procest', 'none due today'),
					colorClass: 'kpi-card--primary',
					subClass: this.tasksDueToday > 0 ? 'kpi-card__sub--warning' : '',
				},
			]
		},
	},
}
</script>

<style scoped>
.kpi-cards {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 16px;
	margin-bottom: 20px;
}

@media (max-width: 768px) {
	.kpi-cards {
		grid-template-columns: repeat(2, 1fr);
	}
}

.kpi-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	text-align: center;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	min-height: 100px;
	justify-content: center;
}

.kpi-card--clickable {
	cursor: pointer;
	transition: box-shadow 0.15s ease;
}

.kpi-card--clickable:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.kpi-card--primary {
	border-top: 3px solid var(--color-primary);
}

.kpi-card--warning {
	border-top: 3px solid var(--color-warning);
}

.kpi-card--success {
	border-top: 3px solid var(--color-success);
}

.kpi-card__title {
	font-size: 13px;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.kpi-card__count {
	font-size: 32px;
	font-weight: bold;
	line-height: 1.2;
}

.kpi-card__sub {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.kpi-card__sub--warning {
	color: var(--color-warning);
	font-weight: 600;
}

.kpi-card__sub--success {
	color: var(--color-success);
}
</style>
