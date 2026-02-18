<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('procest', 'Dashboard')"
				:class="{ active: currentRoute === 'dashboard' }"
				@click="$emit('navigate', 'dashboard')">
				<template #icon>
					<ViewDashboard :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('procest', 'Cases')"
				:class="{ active: currentRoute === 'cases' }"
				@click="$emit('navigate', 'cases')">
				<template #icon>
					<FolderOpen :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem } from '@nextcloud/vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		ViewDashboard,
		FolderOpen,
	},
	props: {
		currentRoute: {
			type: String,
			default: 'dashboard',
		},
	},
}
</script>
