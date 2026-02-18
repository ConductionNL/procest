<template>
	<NcContent app-name="procest">
		<MainMenu :current-route="currentRoute" @navigate="navigateTo" />
		<NcAppContent>
			<component :is="currentView" v-bind="currentProps" @navigate="navigateTo" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { NcContent, NcAppContent } from '@nextcloud/vue'
import MainMenu from './navigation/MainMenu.vue'
import Dashboard from './views/Dashboard.vue'
import CaseList from './views/cases/CaseList.vue'
import CaseDetail from './views/cases/CaseDetail.vue'
import { initializeStores } from './store/store.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		MainMenu,
		Dashboard,
		CaseList,
		CaseDetail,
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
			case 'cases':
				return this.currentId ? 'CaseDetail' : 'CaseList'
			case 'case-detail':
				return 'CaseDetail'
			default:
				return 'Dashboard'
			}
		},
		currentProps() {
			if (this.currentRoute === 'case-detail' && this.currentId) {
				return { caseId: this.currentId }
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
			}
		},
	},
}
</script>
