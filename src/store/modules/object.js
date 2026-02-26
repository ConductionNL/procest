import { defineStore } from 'pinia'

export const useObjectStore = defineStore('object', {
	state: () => ({
		objectTypeRegistry: {},
		collections: {},
		objects: {},
		loading: {},
		errors: {},
		pagination: {},
		searchTerms: {},
	}),
	getters: {
		objectTypes: (state) => Object.keys(state.objectTypeRegistry),
		getCollection: (state) => (type) => state.collections[type] || [],
		getObject: (state) => (type, id) => state.objects[type]?.[id] || null,
		isLoading: (state) => (type) => state.loading[type] || false,
		getError: (state) => (type) => state.errors[type] || null,
		getPagination: (state) => (type) => state.pagination[type] || { total: 0, page: 1, pages: 1, limit: 20 },
		getSearchTerm: (state) => (type) => state.searchTerms[type] || '',
	},
	actions: {
		registerObjectType(slug, schemaId, registerId) {
			this.objectTypeRegistry[slug] = { schema: schemaId, register: registerId }
			this.collections[slug] = []
			this.objects[slug] = {}
			this.loading[slug] = false
			this.errors[slug] = null
			this.pagination[slug] = { total: 0, page: 1, pages: 1, limit: 20 }
			this.searchTerms[slug] = ''
		},

		unregisterObjectType(slug) {
			delete this.objectTypeRegistry[slug]
			delete this.collections[slug]
			delete this.objects[slug]
			delete this.loading[slug]
			delete this.errors[slug]
			delete this.pagination[slug]
			delete this.searchTerms[slug]
		},

		_getTypeConfig(type) {
			const config = this.objectTypeRegistry[type]
			if (!config) {
				throw new Error(`Object type "${type}" is not registered`)
			}
			return config
		},

		_getHeaders() {
			return {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
				'OCS-APIREQUEST': 'true',
			}
		},

		_buildUrl(type, id = null) {
			const config = this._getTypeConfig(type)
			let url = `/apps/openregister/api/objects/${config.register}/${config.schema}`
			if (id) {
				url += `/${id}`
			}
			return url
		},

		async _parseError(response, type) {
			const status = response.status
			let details = null
			let message = ''

			try {
				const body = await response.json()
				details = body.errors || body.error || body.message || null
			} catch {
				// Response body is not JSON
			}

			switch (true) {
			case status === 400 || status === 422:
				message = details ? 'Validation failed' : `Invalid ${type} data`
				return { status, message, details, isValidation: true, toString() { return this.message } }
			case status === 401:
				message = 'Session expired, please log in again'
				break
			case status === 403:
				message = 'You do not have permission to perform this action'
				break
			case status === 404:
				message = `The requested ${type} could not be found`
				break
			case status === 409:
				message = `This ${type} was modified by another user. Please reload.`
				break
			default:
				message = 'An unexpected error occurred. Please try again later.'
			}

			console.error(`API error [${status}] for ${type}:`, details || response.statusText)
			return { status, message, details, isValidation: false, toString() { return this.message } }
		},

		async fetchCollection(type, params = {}) {
			this.loading[type] = true
			this.errors[type] = null

			try {
				const config = this._getTypeConfig(type)
				const queryParams = new URLSearchParams()

				for (const [key, value] of Object.entries(params)) {
					if (value !== undefined && value !== null && value !== '') {
						if (key === '_order' && typeof value === 'object') {
							queryParams.set(key, JSON.stringify(value))
						} else {
							queryParams.set(key, String(value))
						}
					}
				}

				const url = this._buildUrl(type) + (queryParams.toString() ? '?' + queryParams.toString() : '')

				const response = await fetch(url, {
					method: 'GET',
					headers: this._getHeaders(),
				})

				if (!response.ok) {
					this.errors[type] = await this._parseError(response, type)
					return []
				}

				const data = await response.json()

				this.collections[type] = data.results || data
				this.pagination[type] = {
					total: data.total || (data.results || data).length,
					page: data.page || 1,
					pages: data.pages || 1,
					limit: params._limit || 20,
				}

				return this.collections[type]
			} catch (error) {
				this.errors[type] = { status: 0, message: error.message, details: null, isValidation: false, toString() { return this.message } }
				console.error(`Error fetching ${type} collection:`, error)
				return []
			} finally {
				this.loading[type] = false
			}
		},

		async fetchObject(type, id) {
			this.loading[type] = true
			this.errors[type] = null

			try {
				const url = this._buildUrl(type, id)

				const response = await fetch(url, {
					method: 'GET',
					headers: this._getHeaders(),
				})

				if (!response.ok) {
					this.errors[type] = await this._parseError(response, type)
					return null
				}

				const data = await response.json()

				if (!this.objects[type]) {
					this.objects[type] = {}
				}
				this.objects[type][id] = data

				return data
			} catch (error) {
				this.errors[type] = { status: 0, message: error.message, details: null, isValidation: false, toString() { return this.message } }
				console.error(`Error fetching ${type}/${id}:`, error)
				return null
			} finally {
				this.loading[type] = false
			}
		},

		async saveObject(type, objectData) {
			this.loading[type] = true
			this.errors[type] = null

			try {
				const isUpdate = !!objectData.id
				const url = isUpdate ? this._buildUrl(type, objectData.id) : this._buildUrl(type)
				const method = isUpdate ? 'PUT' : 'POST'

				const response = await fetch(url, {
					method,
					headers: this._getHeaders(),
					body: JSON.stringify(objectData),
				})

				if (!response.ok) {
					this.errors[type] = await this._parseError(response, type)
					return null
				}

				const data = await response.json()

				if (!this.objects[type]) {
					this.objects[type] = {}
				}
				const savedId = data.id || objectData.id
				this.objects[type][savedId] = data

				return data
			} catch (error) {
				this.errors[type] = { status: 0, message: error.message, details: null, isValidation: false, toString() { return this.message } }
				console.error(`Error saving ${type}:`, error)
				return null
			} finally {
				this.loading[type] = false
			}
		},

		async deleteObject(type, id) {
			this.loading[type] = true
			this.errors[type] = null

			try {
				const url = this._buildUrl(type, id)

				const response = await fetch(url, {
					method: 'DELETE',
					headers: this._getHeaders(),
				})

				if (!response.ok) {
					this.errors[type] = await this._parseError(response, type)
					return false
				}

				if (this.objects[type]) {
					delete this.objects[type][id]
				}
				if (this.collections[type]) {
					this.collections[type] = this.collections[type].filter(obj => obj.id !== id)
				}

				return true
			} catch (error) {
				this.errors[type] = { status: 0, message: error.message, details: null, isValidation: false, toString() { return this.message } }
				console.error(`Error deleting ${type}/${id}:`, error)
				return false
			} finally {
				this.loading[type] = false
			}
		},

		setSearchTerm(type, term) {
			this.searchTerms[type] = term
		},

		clearSearchTerm(type) {
			this.searchTerms[type] = ''
		},
	},
})
