<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('procest', 'Dashboard')"
				:to="{ name: 'Dashboard' }">
				<template #icon>
					<ViewDashboard :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('procest', 'My Work')"
				:to="{ name: 'MyWork' }">
				<template #icon>
					<AccountCheck :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('procest', 'Cases')"
				:to="{ name: 'Cases' }">
				<template #icon>
					<FolderOpen :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('procest', 'Tasks')"
				:to="{ name: 'Tasks' }">
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
			<NcAppNavigationItem
				:name="t('procest', 'Settings')"
				:to="{ name: 'Settings' }">
				<template #icon>
					<Cog :size="20" />
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
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		ViewDashboard,
		AccountCheck,
		FolderOpen,
		ClipboardCheckOutline,
		BookOpenVariantOutline,
		Cog,
	},
	methods: {
		openLink(url, target) {
			window.open(url, target)
		},
	},
}
</script>
