import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

export const useZgwMappingStore = defineStore('zgwMapping', {
	state: () => ({
		mappings: {},
		loading: false,
		error: null,
	}),
	getters: {
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		getMappings: (state) => state.mappings,
	},
	actions: {
		/** @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md */
		async fetchMappings() {
			this.loading = true
			this.error = null

			try {
				const response = await fetch(
					generateUrl('/apps/dossiq/api/zgw-mappings'),
					{
						method: 'GET',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					},
				)

				if (!response.ok) {
					throw new Error(
						`Failed to fetch ZGW mappings: ${response.statusText}`,
					)
				}

				const data = await response.json()
				this.mappings = data.mappings || {}
				return this.mappings
			} catch (error) {
				this.error = error.message
				console.error('Error fetching ZGW mappings:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} resourceKey The resource key.
		 * @param {object} config The config.
		 * @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md
		 */
		async saveMapping(resourceKey, config) {
			this.loading = true
			this.error = null

			try {
				const response = await fetch(
					generateUrl(`/apps/dossiq/api/zgw-mappings/${resourceKey}`),
					{
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify(config),
					},
				)

				if (!response.ok) {
					throw new Error(
						`Failed to save ZGW mapping: ${response.statusText}`,
					)
				}

				const data = await response.json()
				this.mappings[resourceKey] = data.mapping
				return data.mapping
			} catch (error) {
				this.error = error.message
				console.error('Error saving ZGW mapping:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} resourceKey The resource key.
		 * @spec openspec/changes/retrofit-2026-05-24-zgw-api-mapping/tasks.md
		 */
		async resetMapping(resourceKey) {
			this.loading = true
			this.error = null

			try {
				const response = await fetch(
					generateUrl(
						`/apps/dossiq/api/zgw-mappings/${resourceKey}/reset`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					},
				)

				if (!response.ok) {
					throw new Error(
						`Failed to reset ZGW mapping: ${response.statusText}`,
					)
				}

				const data = await response.json()
				this.mappings[resourceKey] = data.mapping
				return data.mapping
			} catch (error) {
				this.error = error.message
				console.error('Error resetting ZGW mapping:', error)
				return null
			} finally {
				this.loading = false
			}
		},
	},
})
