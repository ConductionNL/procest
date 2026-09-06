<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnAppRoot
		:aiCompanion="true"
		:manifest="manifest"
		:customComponents="customComponents"
		:registry="registry"
		:pageTypes="pageTypes"
		:formatters="formatters"
		appId="dossiq"
		:translate="translateForApp"
		:permissions="permissions">
		<!--
			Host-mounted object sidebar (decidesk pattern). CnAppRoot
			suppresses its own auto-mounted CnObjectSidebar because this
			App provides `objectSidebarState`, so the detail-page tab
			strip (manifest `config.sidebarTabs` on CaseDetail /
			BezwaarDetail) only renders when we mount the sidebar here.
			CnDetailPage publishes its tabs into `objectSidebarState`
			(shared with this slot via CnAppRoot's ancestor-aware
			provide); we bind those tabs straight through.
			`:use-registry="false"` keeps the manifest `component:`-based
			tabs (CaseTasksTab / CaseEmailTab / …) instead of the
			integration-registry tabs.
		-->
		<template #sidebar="{ pageSidebarComponent }">
			<CnObjectSidebar
				v-if="objectSidebarState.active"
				:useRegistry="false"
				:title="objectSidebarState.title"
				:subtitle="objectSidebarState.subtitle"
				:objectType="objectSidebarState.objectType"
				:objectId="objectSidebarState.objectId"
				:register="objectSidebarState.register"
				:schema="objectSidebarState.schema"
				:tabs="objectSidebarState.tabs"
				:hiddenTabs="objectSidebarState.hiddenTabs"
				:open="objectSidebarState.open"
				@update:open="objectSidebarState.open = $event" />
			<!-- The manifest page's own sidebar (pages[].sidebarComponent). Passed in
			     as a slot prop because filling this slot suppresses CnAppRoot's
			     fallback, which is what hid the flow sidebar. -->
			<component :is="pageSidebarComponent" v-if="pageSidebarComponent" />
		</template>
	</CnAppRoot>
</template>

<script>
import { CnAppRoot, CnObjectSidebar } from '@conduction/nextcloud-vue'
import { translate as ncT } from '@nextcloud/l10n'
import { reactive } from 'vue'
import { initializeStores } from './store/store.js'

export default {
	name: 'App',
	components: {
		CnAppRoot,
		CnObjectSidebar,
	},

	/** @spec openspec/changes/retrofit-2026-05-25-procest-app-scaffold/tasks.md */
	provide() {
		return {
			// Provide/inject channel for index pages that auto-mount sidebar
			// content; matches the decidesk pattern (App.vue hosts a single
			// CnObjectSidebar via CnAppRoot's #sidebar slot).
			objectSidebarState: this.objectSidebarState,
			// Legacy alias kept for any existing custom components that
			// inject `sidebarState` (CaseList / TaskList / AdminRoot
			// referenced this name in the pre-manifest shell).
			sidebarState: this.objectSidebarState,
		}
	},

	props: {
		manifest: {
			type: Object,
			required: true,
		},

		customComponents: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * V2 component registry — map of registry-key → `{ kind, component }`.
		 * Forwarded verbatim to CnAppRoot, which validates kinds at mount time.
		 * Replaces the string-keyed customComponents prop for v2 manifests.
		 * Both props may coexist during transition (CnAppRoot warns once).
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},

		pageTypes: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * Cell-formatter registry — forwarded to CnAppRoot as `cnFormatters`.
		 * Resolves `pages[].config.columns[].formatter` ids on index/logs
		 * pages (see src/services/formatters.js).
		 */
		formatters: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			objectSidebarState: reactive({
				active: false,
				open: true,
				// --- Detail-page object-sidebar fields (written by
				// CnDetailPage.syncSidebarState via inject). Predefined
				// here so Vue 2 tracks the writes reactively. ---
				objectType: '',
				objectId: '',
				title: '',
				subtitle: '',
				register: '',
				// `schema` doubles as the legacy index-sidebar schema and
				// the detail-sidebar schema slug; '' is the inert default.
				schema: '',
				hiddenTabs: [],
				tabs: undefined,
				// --- Legacy index-sidebar fields (kept for the custom
				// list components that inject `sidebarState`). ---
				visibleColumns: null,
				searchValue: '',
				activeFilters: {},
				facetData: {},
				onSearch: null,
				onColumnsChange: null,
				onFilterChange: null,
			}),
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-25-procest-app-scaffold/tasks.md */
		permissions() {
			const base = window.OC?.currentUser?.permissions ?? []
			// CnAppNav's permission filter is an array-includes check; Nextcloud
			// does not put the boolean admin flag into the permissions array, so
			// we inject it here for manifest entries gated on permission: "admin"
			// (the platform-admin tenant management pages). isUserAdmin() returns
			// true for users in the Nextcloud admin group, matching the backend
			// TenantService::isPlatformAdmin() check.
			const isAdmin =
				typeof window.OC?.isUserAdmin === 'function'
					? window.OC.isUserAdmin()
					: false
			return isAdmin ? [...base, 'admin'] : base
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-25-procest-app-scaffold/tasks.md */
	async created() {
		// Pinia stores still need to come up so legacy custom components
		// keep working through the manifest transition. CnAppRoot itself
		// doesn't depend on them.
		await initializeStores()
	},

	methods: {
		/**
		 * Translate function passed down to CnAppRoot / CnAppNav /
		 * CnPageRenderer. Closes over the Nextcloud `translate` import so
		 * the lib never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 * @spec openspec/changes/retrofit-2026-05-25-procest-app-scaffold/tasks.md
		 */
		translateForApp(key) {
			return ncT('dossiq', key)
		},
	},
}
</script>
