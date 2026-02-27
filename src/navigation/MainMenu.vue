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
			<NcAppNavigationItem
				:name="t('procest', 'Documentation')"
				@click="openLink('https://procest.app', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationSettings>
				<NcAppNavigationItem
					:name="t('procest', 'Case Types')"
					:class="{ active: currentRoute === 'case-types' }"
					@click="$emit('navigate', 'case-types')">
					<template #icon>
						<ShapeOutline :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('procest', 'Configuration')"
					:class="{ active: currentRoute === 'settings' }"
					@click="$emit('navigate', 'settings')">
					<template #icon>
						<Cog :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationSettings>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem, NcAppNavigationSettings } from '@nextcloud/vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import AccountCheck from 'vue-material-design-icons/AccountCheck.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		ViewDashboard,
		AccountCheck,
		FolderOpen,
		ClipboardCheckOutline,
		BookOpenVariantOutline,
		Cog,
		ShapeOutline,
	},
	props: {
		currentRoute: {
			type: String,
			default: 'dashboard',
		},
	},
	methods: {
		openLink(url, target) {
			window.open(url, target)
		},
	},
}
</script>
