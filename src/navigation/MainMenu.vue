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
				:name="t('procest', 'My Work')"
				:class="{ active: currentRoute === 'my-work' }"
				@click="$emit('navigate', 'my-work')">
				<template #icon>
					<AccountCheck :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('procest', 'Cases')"
				:class="{ active: currentRoute === 'cases' || currentRoute === 'case-detail' }"
				@click="$emit('navigate', 'cases')">
				<template #icon>
					<FolderOpen :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('procest', 'Tasks')"
				:class="{ active: currentRoute === 'tasks' || currentRoute === 'task-detail' || currentRoute === 'task-new' }"
				@click="$emit('navigate', 'tasks')">
				<template #icon>
					<ClipboardCheckOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem } from '@nextcloud/vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import AccountCheck from 'vue-material-design-icons/AccountCheck.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		ViewDashboard,
		AccountCheck,
		FolderOpen,
		ClipboardCheckOutline,
	},
	props: {
		currentRoute: {
			type: String,
			default: 'dashboard',
		},
	},
}
</script>
