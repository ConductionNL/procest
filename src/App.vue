<template>
	<NcContent app-name="procest">
		<template v-if="storesReady">
			<MainMenu :current-route="currentRoute" @navigate="navigateTo" />
			<NcAppContent>
				<component :is="currentView" v-bind="currentProps" @navigate="navigateTo" />
			</NcAppContent>
		</template>
		<NcAppContent v-else>
			<div style="display: flex; justify-content: center; align-items: center; height: 100%;">
				<NcLoadingIcon :size="64" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { NcContent, NcAppContent, NcLoadingIcon } from '@nextcloud/vue'
import MainMenu from './navigation/MainMenu.vue'
import Dashboard from './views/Dashboard.vue'
import CaseList from './views/cases/CaseList.vue'
import CaseDetail from './views/cases/CaseDetail.vue'
import TaskList from './views/tasks/TaskList.vue'
import TaskDetail from './views/tasks/TaskDetail.vue'
import MyWork from './views/MyWork.vue'
import { initializeStores } from './store/store.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcLoadingIcon,
		MainMenu,
		Dashboard,
		CaseList,
		CaseDetail,
		TaskList,
		TaskDetail,
		MyWork,
	},
	data() {
		return {
			currentRoute: 'dashboard',
			currentId: null,
			storesReady: false,
		}
	},
	computed: {
		currentView() {
			switch (this.currentRoute) {
			case 'my-work':
				return 'MyWork'
			case 'cases':
				return this.currentId ? 'CaseDetail' : 'CaseList'
			case 'case-detail':
				return 'CaseDetail'
			case 'tasks':
				return 'TaskList'
			case 'task-detail':
				return 'TaskDetail'
			case 'task-new':
				return 'TaskDetail'
			default:
				return 'Dashboard'
			}
		},
		currentProps() {
			if (this.currentRoute === 'case-detail' && this.currentId) {
				return { caseId: this.currentId }
			}
			if (this.currentRoute === 'task-detail' && this.currentId) {
				return { taskId: this.currentId }
			}
			if (this.currentRoute === 'task-new') {
				return { taskId: 'new', caseIdProp: this.currentId || null }
			}
			return {}
		},
	},
	async created() {
		await initializeStores()
		this.storesReady = true
		this._handleHashRoute()
		window.addEventListener('hashchange', this._handleHashRoute)
	},
	beforeDestroy() {
		window.removeEventListener('hashchange', this._handleHashRoute)
	},
	methods: {
		navigateTo(route, id = null) {
			this.currentRoute = route
			this.currentId = id
			if (id) {
				window.location.hash = `#/${route}/${id}`
			} else {
				window.location.hash = `#/${route}`
			}
		},
		_handleHashRoute() {
			const hash = window.location.hash.replace('#/', '')
			const parts = hash.split('/')
			if (parts[0]) {
				this.currentRoute = parts[0]
				this.currentId = parts[1] || null
				if (parts[0] === 'cases' && parts[1]) {
					this.currentRoute = 'case-detail'
				}
				if (parts[0] === 'tasks' && parts[1]) {
					if (parts[1] === 'new') {
						this.currentRoute = 'task-new'
						this.currentId = parts[2] || null
					} else {
						this.currentRoute = 'task-detail'
					}
				}
			}
		},
	},
}
</script>
