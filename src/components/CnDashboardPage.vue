<template>
	<div class="cn-dashboard-page">
		<div v-if="loading" class="cn-dashboard-loading">
			<NcLoadingIcon :size="44" />
		</div>
		<div v-else-if="isEmpty" class="cn-dashboard-empty">
			<slot name="empty">
				<p>{{ emptyLabel }}</p>
			</slot>
		</div>
		<div v-else class="cn-dashboard-content">
			<div class="cn-dashboard-header">
				<h2 class="cn-dashboard-title">{{ title }}</h2>
				<div class="cn-dashboard-header-actions">
					<slot name="header-actions" />
				</div>
			</div>
			<div class="cn-dashboard-widgets">
				<div v-for="widget in widgets" :key="widget.id" class="cn-dashboard-widget">
					<h3 v-if="widget.title && showWidgetTitle(widget)" class="cn-dashboard-widget-title">
						{{ widget.title }}
					</h3>
					<slot :name="'widget-' + widget.id" />
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'CnDashboardPage',
	components: { NcLoadingIcon },
	props: {
		title: { type: String, default: '' },
		widgets: { type: Array, default: () => [] },
		layout: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
		emptyLabel: { type: String, default: 'No data' },
		unavailableLabel: { type: String, default: '' },
	},
	emits: ['layout-change'],
	computed: {
		isEmpty() {
			return this.widgets.length === 0
		},
	},
	methods: {
		showWidgetTitle(widget) {
			const layoutItem = this.layout.find(l => l.widgetId === widget.id)
			return layoutItem ? layoutItem.showTitle !== false : true
		},
	},
}
</script>

<style scoped>
.cn-dashboard-page {
	padding: 16px;
}

.cn-dashboard-loading {
	display: flex;
	justify-content: center;
	padding: 40px;
}

.cn-dashboard-empty {
	text-align: center;
	padding: 40px;
	color: var(--color-text-maxcontrast);
}

.cn-dashboard-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.cn-dashboard-title {
	font-size: 20px;
	font-weight: 700;
}

.cn-dashboard-header-actions {
	display: flex;
	gap: 8px;
}

.cn-dashboard-widgets {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.cn-dashboard-widget-title {
	font-size: 16px;
	font-weight: 600;
	margin-bottom: 8px;
}
</style>
