<template>
	<NcAppSettingsDialog
		:open.sync="open"
		:show-navigation="false"
		:name="t('procest', 'Procest settings')">
		<NcAppSettingsSection
			id="general"
			:name="t('procest', 'General')">
			<template #icon>
				<Cog :size="20" />
			</template>
			<NcEmptyContent
				:name="t('procest', 'No settings available yet')"
				:description="t('procest', 'User settings will appear here in a future update.')">
				<template #icon>
					<Cog :size="64" />
				</template>
			</NcEmptyContent>
		</NcAppSettingsSection>
	</NcAppSettingsDialog>
</template>

<script>
import { NcAppSettingsDialog, NcAppSettingsSection, NcEmptyContent } from '@nextcloud/vue'
import Cog from 'vue-material-design-icons/Cog.vue'

export default {
	name: 'UserSettings',
	components: {
		NcAppSettingsDialog,
		NcAppSettingsSection,
		NcEmptyContent,
		Cog,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},
}
</script>
