<template>
	<div class="procest-admin">
		<h2>{{ t('procest', 'Procest') }}</h2>

		<div class="procest-admin__sections">
			<section class="procest-admin__section">
				<h3 class="procest-admin__section-title" @click="showConfig = !showConfig">
					{{ t('procest', 'Configuration') }}
					<span class="toggle-indicator">{{ showConfig ? '▼' : '▶' }}</span>
				</h3>
				<Settings v-if="showConfig" />
			</section>

			<section class="procest-admin__section">
				<h3 class="procest-admin__section-title">
					{{ t('procest', 'Case Type Management') }}
				</h3>
				<CaseTypeAdmin v-if="storesReady" />
				<NcLoadingIcon v-else />
			</section>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import Settings from './Settings.vue'
import CaseTypeAdmin from './CaseTypeAdmin.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		NcLoadingIcon,
		Settings,
		CaseTypeAdmin,
	},
	data() {
		return {
			showConfig: false,
			storesReady: false,
		}
	},
	async created() {
		await initializeStores()
		this.storesReady = true
	},
}
</script>

<style scoped>
.procest-admin {
	padding: 20px;
	max-width: 900px;
}

.procest-admin__sections {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.procest-admin__section-title {
	cursor: pointer;
	user-select: none;
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.toggle-indicator {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
