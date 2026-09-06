<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Woo statutory-deadline panel. Thin wrapper — WooDeadlinePanel.vue unchanged.

	@spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
-->
<template>
	<WooDeadlinePanel
		:cases="dtStore.wooCases"
		:loading="dtLoading"
		@clickCase="openCase"
		@viewAll="viewAllWoo" />
</template>

<script>
import WooDeadlinePanel from '../../dashboard/WooDeadlinePanel.vue'
import { dtWidgetMixin } from './dtWidgetMixin.js'

export default {
	name: 'DtWooWidget',
	components: { WooDeadlinePanel },
	mixins: [dtWidgetMixin],
	methods: {
		/**
		 * Show every Woo case on the case index.
		 *
		 * @spec openspec/changes/page-topology-cleanup/specs/analytics-dashboard-surface/spec.md
		 */
		viewAllWoo() {
			this.$router.push({ name: 'Cases', query: { caseTypeContains: 'woo' } })
		},
	},
}
</script>
