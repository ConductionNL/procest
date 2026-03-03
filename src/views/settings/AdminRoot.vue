<template>
	<div class="procest-admin">
		<Settings />

		<CnSettingsSection
			:name="t('procest', 'Case Type Management')"
			:description="t('procest', 'Manage case types and their configurations')"
			:loading="!storesReady">
			<CaseTypeAdmin v-if="storesReady" />
		</CnSettingsSection>
	</div>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import Settings from './Settings.vue'
import CaseTypeAdmin from './CaseTypeAdmin.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnSettingsSection,
		Settings,
		CaseTypeAdmin,
	},
	data() {
		return {
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
	max-width: 900px;
}
</style>
