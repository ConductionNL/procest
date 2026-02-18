<template>
	<div class="procest-dashboard">
		<h2>{{ t('procest', 'Welcome to Procest') }}</h2>
		<p>{{ t('procest', 'Manage your cases and tasks') }}</p>

		<div class="dashboard-actions">
			<NcButton type="primary" @click="$emit('navigate', 'cases')">
				{{ t('procest', 'Cases') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'Dashboard',
	components: {
		NcButton,
	},
}
</script>

<style scoped>
.procest-dashboard {
	padding: 20px;
	max-width: 800px;
}

.dashboard-actions {
	margin-top: 20px;
	display: flex;
	gap: 12px;
}
</style>
