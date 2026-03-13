<template>
	<div v-if="result || showEmpty" class="result-section">
		<h4>{{ t('procest', 'Result') }}</h4>
		<template v-if="result">
			<div class="result-section__card">
				<div class="result-section__name">
					{{ result.name || '—' }}
				</div>
				<div v-if="result.description" class="result-section__description">
					{{ result.description }}
				</div>
				<div v-if="resultTypeName" class="result-section__type">
					{{ t('procest', 'Type: {type}', { type: resultTypeName }) }}
				</div>
			</div>
		</template>
		<template v-else>
			<p class="result-section__empty">
				{{ t('procest', 'No result recorded yet') }}
			</p>
		</template>
	</div>
</template>

<script>
export default {
	name: 'ResultSection',
	props: {
		result: {
			type: Object,
			default: null,
		},
		resultTypes: {
			type: Array,
			default: () => [],
		},
		showEmpty: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		resultTypeName() {
			if (!this.result?.resultType) return ''
			const rt = this.resultTypes.find(t => t.id === this.result.resultType)
			return rt?.name || ''
		},
	},
}
</script>

<style scoped>
.result-section {
	margin-top: 16px;
}

.result-section h4 {
	margin: 0 0 8px;
	font-size: 14px;
}

.result-section__card {
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border-left: 3px solid var(--color-success);
}

.result-section__name {
	font-weight: 600;
	margin-bottom: 4px;
}

.result-section__description {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.result-section__type {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.result-section__empty {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
