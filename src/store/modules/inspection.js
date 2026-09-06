/**
 * Inspection store module for Dossiq VTH.
 *
 * Manages inspection checklists, inspection reports, photo uploads,
 * and follow-up task creation for VTH supervision cases.
 */
import { defineStore } from 'pinia'
import { useObjectStore } from './object.js'

export const useInspectionStore = defineStore('inspection', {
	state: () => ({
		/** @type {Array} Checklists for the current case type */
		checklists: [],
		/** @type {object|null} Currently selected checklist */
		currentChecklist: null,
		/** @type {Array} Inspection reports for the current case */
		reports: [],
		/** @type {boolean} Loading state */
		loading: false,
		/** @type {string|null} Error message */
		error: null,
	}),

	getters: {
		/**
		 * Get active checklists (not archived).
		 *
		 * @param {object} state Store state
		 * @return {Array} Active checklists
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		activeChecklists(state) {
			return state.checklists.filter((c) => c.status === 'active')
		},

		/**
		 * Get completed reports count.
		 *
		 * @param {object} state Store state
		 * @return {number} Number of completed reports
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		completedReportsCount(state) {
			return state.reports.length
		},

		/**
		 * Get reports with non-conformities.
		 *
		 * @param {object} state Store state
		 * @return {Array} Reports with failed items
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		nonConformReports(state) {
			return state.reports.filter(
				(r) => r.result === 'non_conform' || r.result === 'partly_conform',
			)
		},
	},

	actions: {
		/**
		 * Fetch all checklists for a case type.
		 *
		 * @param {string} caseTypeId UUID of the case type
		 * @return {Promise<Array>} Checklists
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async fetchChecklists(caseTypeId) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const response = await objectStore.fetchCollection(
					'inspectieChecklist',
					{
						caseType: caseTypeId,
						limit: 100,
					},
				)
				this.checklists = response?.results || response || []
				return this.checklists
			} catch (error) {
				this.error = error.message
				console.error('Error fetching checklists:', error)
				return []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save a checklist (create or update).
		 *
		 * @param {object} checklistData The checklist data
		 * @return {Promise<object|null>} Saved checklist
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async saveChecklist(checklistData) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const saved = await objectStore.saveObject(
					'inspectieChecklist',
					checklistData,
				)
				// Update local list
				const index = this.checklists.findIndex((c) => c.id === saved.id)
				if (index >= 0) {
					this.checklists.splice(index, 1, saved)
				} else {
					this.checklists.push(saved)
				}
				return saved
			} catch (error) {
				this.error = error.message
				console.error('Error saving checklist:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new version of a checklist.
		 *
		 * @param {object} checklist The checklist to version
		 * @return {Promise<object|null>} New version
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async createNewVersion(checklist) {
			const newVersion = {
				...checklist,
				id: undefined,
				version: (checklist.version || 1) + 1,
				status: 'draft',
			}
			// Archive old version
			if (checklist.id) {
				await this.saveChecklist({ ...checklist, status: 'archived' })
			}
			return this.saveChecklist(newVersion)
		},

		/**
		 * Delete a checklist.
		 *
		 * @param {string} checklistId UUID of the checklist
		 * @return {Promise<boolean>} Success
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async deleteChecklist(checklistId) {
			this.loading = true
			try {
				const objectStore = useObjectStore()
				await objectStore.deleteObject('inspectieChecklist', checklistId)
				this.checklists = this.checklists.filter((c) => c.id !== checklistId)
				return true
			} catch (error) {
				this.error = error.message
				return false
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch inspection reports for a case.
		 *
		 * @param {string} caseId UUID of the case
		 * @return {Promise<Array>} Reports
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async fetchReports(caseId) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const response = await objectStore.fetchCollection(
					'inspectieRapport',
					{
						case: caseId,
						limit: 100,
					},
				)
				this.reports = response?.results || response || []
				return this.reports
			} catch (error) {
				this.error = error.message
				console.error('Error fetching reports:', error)
				return []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create an inspection report with auto-calculated result.
		 *
		 * @param {object} reportData Report data with items array
		 * @return {Promise<object|null>} Created report
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async createReport(reportData) {
			this.loading = true
			this.error = null
			try {
				const items = reportData.items || []
				const failedItems = items.filter(
					(item) => item.result === 'fail',
				).length
				const nvtItems = items.filter((item) => item.result === 'nvt').length
				const totalItems = items.length

				// Auto-calculate overall result
				let result = 'conform'
				if (failedItems > 0 && failedItems < totalItems - nvtItems) {
					result = 'partly_conform'
				} else if (failedItems > 0) {
					result = 'non_conform'
				}

				const report = {
					...reportData,
					result,
					failedItems,
					followUpRequired: failedItems > 0,
					inspectionDate:
						reportData.inspectionDate || new Date().toISOString(),
				}

				const objectStore = useObjectStore()
				const saved = await objectStore.saveObject(
					'inspectieRapport',
					report,
				)
				this.reports.push(saved)

				// Create follow-up task if non-conformities found
				if (failedItems > 0) {
					await this.createFollowUpTask(
						reportData.case,
						failedItems,
						saved.id,
					)
				}

				return saved
			} catch (error) {
				this.error = error.message
				console.error('Error creating report:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Upload a photo for an inspection item.
		 *
		 * Uses the @conduction/nextcloud-vue filesPlugin (`uploadFiles`),
		 * which expects FormData and the parent object's registered type.
		 * Returns the first uploaded file's ID, mirroring the legacy contract.
		 *
		 * @param {string} caseId  UUID of the parent case
		 * @param {File}   file    The photo file
		 * @return {Promise<string|null>} Nextcloud file ID
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async uploadPhoto(caseId, file) {
			try {
				const objectStore = useObjectStore()
				const formData = new FormData()
				formData.append('file', file)
				const result = await objectStore.uploadFiles(
					'case',
					caseId,
					formData,
				)
				const uploaded = result?.results?.[0] || result?.[0] || result
				return uploaded?.id || null
			} catch (error) {
				console.error('Error uploading inspection photo:', error)
				return null
			}
		},

		/**
		 * Create a follow-up task when non-conformities are found.
		 *
		 * @param {string} caseId      UUID of the case
		 * @param {number} failedCount Number of failed items
		 * @param {string} reportId    UUID of the inspection report
		 * @return {Promise<object|null>} Created task
		 * @spec openspec/changes/retrofit-2026-05-24-inspection-checklists/tasks.md
		 */
		async createFollowUpTask(caseId, failedCount, reportId) {
			try {
				const objectStore = useObjectStore()
				return await objectStore.saveObject('caseTask', {
					case: caseId,
					title: `Opvolging vereist: ${failedCount} afwijkingen geconstateerd`,
					description: `Inspectierapport bevat ${failedCount} niet-conforme punten. Beoordeel de afwijkingen en plan opvolging.`,
					status: 'open',
					relatedObject: reportId,
				})
			} catch (error) {
				console.error('Error creating follow-up task:', error)
				return null
			}
		},
	},
})
