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
		async fetchMappings() {
			this.loading = true
			this.error = null

			try {
				const response = await fetch('/apps/procest/api/zgw-mappings', {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})

				if (!response.ok) {
					throw new Error(`Failed to fetch ZGW mappings: ${response.statusText}`)
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

		async saveMapping(resourceKey, config) {
			this.loading = true
			this.error = null

			try {
				const response = await fetch(`/apps/procest/api/zgw-mappings/${resourceKey}`, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(config),
				})

				if (!response.ok) {
					throw new Error(`Failed to save ZGW mapping: ${response.statusText}`)
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

		async resetMapping(resourceKey) {
			this.loading = true
			this.error = null

			try {
				const response = await fetch(`/apps/procest/api/zgw-mappings/${resourceKey}/reset`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})

				if (!response.ok) {
					throw new Error(`Failed to reset ZGW mapping: ${response.statusText}`)
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
