<template>
	<div class="status-timeline">
		<div
			v-for="(statusType, index) in statusTypes"
			:key="statusType.id"
			class="status-timeline__step"
			:class="stepClass(statusType)">
			<!-- Connecting line (before dot, except for first) -->
			<div v-if="index > 0" class="status-timeline__line" :class="lineClass(statusType)" />

			<!-- Dot -->
			<div class="status-timeline__dot" :class="dotClass(statusType)" />

			<!-- Label and date -->
			<div class="status-timeline__label">
				<span class="status-timeline__name">{{ statusType.name }}</span>
				<span v-if="getStatusDate(statusType)" class="status-timeline__date">
					{{ getStatusDate(statusType) }}
				</span>
			</div>
		</div>
	</div>
</template>

<script>
import { formatDateShort } from '../../../utils/caseHelpers.js'

export default {
	name: 'StatusTimeline',
	props: {
		statusTypes: {
			type: Array,
			required: true,
		},
		currentStatusId: {
			type: String,
			default: null,
		},
		statusHistory: {
			type: Array,
			default: () => [],
		},
	},
	computed: {
		currentIndex() {
			return this.statusTypes.findIndex(st => st.id === this.currentStatusId)
		},
		historyMap() {
			const map = {}
			for (const entry of this.statusHistory) {
				map[entry.status] = entry.date
			}
			return map
		},
	},
	methods: {
		isPassed(statusType) {
			const idx = this.statusTypes.findIndex(st => st.id === statusType.id)
			return idx < this.currentIndex
		},
		isCurrent(statusType) {
			return statusType.id === this.currentStatusId
		},
		isFuture(statusType) {
			const idx = this.statusTypes.findIndex(st => st.id === statusType.id)
			return idx > this.currentIndex
		},
		stepClass(statusType) {
			return {
				'status-timeline__step--passed': this.isPassed(statusType),
				'status-timeline__step--current': this.isCurrent(statusType),
				'status-timeline__step--future': this.isFuture(statusType),
			}
		},
		dotClass(statusType) {
			return {
				'status-timeline__dot--passed': this.isPassed(statusType),
				'status-timeline__dot--current': this.isCurrent(statusType),
				'status-timeline__dot--future': this.isFuture(statusType),
			}
		},
		lineClass(statusType) {
			return {
				'status-timeline__line--passed': this.isPassed(statusType) || this.isCurrent(statusType),
			}
		},
		getStatusDate(statusType) {
			const date = this.historyMap[statusType.id]
			if (!date) return null
			return formatDateShort(date)
		},
	},
}
</script>

<style scoped>
.status-timeline {
	display: flex;
	align-items: flex-start;
	padding: 16px 0;
	overflow-x: auto;
}

.status-timeline__step {
	display: flex;
	flex-direction: column;
	align-items: center;
	position: relative;
	flex: 1;
	min-width: 80px;
}

.status-timeline__line {
	position: absolute;
	top: 10px;
	right: 50%;
	width: 100%;
	height: 2px;
	background: var(--color-border-dark);
	z-index: 0;
}

.status-timeline__line--passed {
	background: var(--color-success);
}

.status-timeline__dot {
	width: 20px;
	height: 20px;
	border-radius: 50%;
	border: 2px solid var(--color-border-dark);
	background: var(--color-main-background);
	z-index: 1;
	position: relative;
}

.status-timeline__dot--passed {
	background: var(--color-success);
	border-color: var(--color-success);
}

.status-timeline__dot--current {
	width: 24px;
	height: 24px;
	background: var(--color-primary);
	border-color: var(--color-primary);
	box-shadow: 0 0 0 3px var(--color-primary-light);
}

.status-timeline__dot--future {
	background: var(--color-main-background);
	border-color: var(--color-text-maxcontrast);
}

.status-timeline__label {
	display: flex;
	flex-direction: column;
	align-items: center;
	margin-top: 8px;
	text-align: center;
}

.status-timeline__name {
	font-size: 12px;
	font-weight: 500;
	color: var(--color-main-text);
}

.status-timeline__step--future .status-timeline__name {
	color: var(--color-text-maxcontrast);
}

.status-timeline__date {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}
</style>
