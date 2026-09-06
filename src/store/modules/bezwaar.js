/**
 * Bezwaar (objection) and Beroep (appeal) store module for Dossiq.
 *
 * Manages CRUD operations for objection, hearing session, advisory report,
 * and appeal decision objects. Also handles deadline calculation and
 * bezwaar-to-beroep escalation.
 *
 * Uses the object store (createObjectStore) for CRUD operations on
 * OpenRegister objects.
 */
import { defineStore } from 'pinia'
import { useObjectStore } from './object.js'

/**
 * Add working days to a date (skipping weekends).
 *
 * @param {Date} startDate The starting date
 * @param {number} days Number of working days to add
 * @return {Date} The resulting date
 */
function addWorkingDays(startDate, days) {
	const result = new Date(startDate)
	let added = 0
	while (added < days) {
		result.setDate(result.getDate() + 1)
		const dayOfWeek = result.getDay()
		if (dayOfWeek !== 0 && dayOfWeek !== 6) {
			added++
		}
	}
	return result
}

/**
 * Add calendar weeks to a date.
 *
 * @param {Date} startDate The starting date
 * @param {number} weeks Number of weeks to add
 * @return {Date} The resulting date
 */
function addWeeks(startDate, weeks) {
	const result = new Date(startDate)
	result.setDate(result.getDate() + weeks * 7)
	return result
}

/**
 * Calculate the difference in calendar days between two dates.
 *
 * @param {Date} dateA First date
 * @param {Date} dateB Second date
 * @return {number} Days difference (positive if dateB is after dateA)
 */
function daysDifference(dateA, dateB) {
	const msPerDay = 86400000
	return Math.ceil((dateB.getTime() - dateA.getTime()) / msPerDay)
}

export const useBezwaarStore = defineStore('objectionProceeding', {
	state: () => ({
		/** @type {object|null} Current objection object */
		currentObjection: null,
		/** @type {Array} Hearing sessions for the current case */
		hearingSessions: [],
		/** @type {object|null} Current advisory report */
		currentAdvisoryReport: null,
		/** @type {object|null} Current appeal decision */
		currentAppealDecision: null,
		/** @type {boolean} Loading state */
		loading: false,
		/** @type {string|null} Error message */
		error: null,
	}),

	getters: {
		/**
		 * Check if the current case has an objection.
		 *
		 * @param {object} state The store state
		 * @return {boolean} Whether an objection exists
		 */
		hasObjection: (state) => state.currentObjection !== null,

		/**
		 * Get the active hearing session (not cancelled or waived).
		 *
		 * @param {object} state The store state
		 * @return {object|null} The active hearing or null
		 */
		activeHearing: (state) => {
			return (
				state.hearingSessions.find(
					(h) => h.status !== 'cancelled' && h.status !== 'afgezien',
				) || null
			)
		},

		/**
		 * Check if the hearing was waived.
		 *
		 * @param {object} state The store state
		 * @return {boolean} Whether hearing was waived
		 */
		isHearingWaived: (state) => {
			return state.hearingSessions.some((h) => h.hearingWaived === true)
		},

		/**
		 * Check if there is an advisory report.
		 *
		 * @param {object} state The store state
		 * @return {boolean} Whether an advisory report exists
		 */
		hasAdvisoryReport: (state) => state.currentAdvisoryReport !== null,

		/**
		 * Check if there is an appeal decision.
		 *
		 * @param {object} state The store state
		 * @return {boolean} Whether an appeal decision exists
		 */
		hasAppealDecision: (state) => state.currentAppealDecision !== null,
	},

	actions: {
		/**
		 * Load all bezwaar-related data for a case.
		 *
		 * @param {string} caseId The case UUID
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		async loadBezwaarData(caseId) {
			this.loading = true
			this.error = null

			try {
				const objectStore = useObjectStore()

				// Load objection.
				const objections = await objectStore.fetchCollection('objection', {
					case: caseId,
					_limit: 1,
				})
				this.currentObjection = objections?.[0] || null

				// Load hearing sessions.
				const hearings = await objectStore.fetchCollection(
					'hearingSession',
					{
						case: caseId,
					},
				)
				this.hearingSessions = hearings || []

				// Load advisory report.
				const reports = await objectStore.fetchCollection('advisoryReport', {
					case: caseId,
					_limit: 1,
				})
				this.currentAdvisoryReport = reports?.[0] || null

				// Load appeal decision.
				const decisions = await objectStore.fetchCollection(
					'appealDecision',
					{
						case: caseId,
						_limit: 1,
					},
				)
				this.currentAppealDecision = decisions?.[0] || null
			} catch (error) {
				this.error = error.message
				console.error('Error loading bezwaar data:', error)
			} finally {
				this.loading = false
			}
		},

		// --- Objection CRUD ---

		/**
		 * Create a new objection for a bezwaar case.
		 *
		 * @param {object} data The objection data
		 * @return {Promise<object|null>} The created objection
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		async createObjection(data) {
			this.loading = true
			this.error = null

			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject('objection', data)
				this.currentObjection = result
				return result
			} catch (error) {
				this.error = error.message
				console.error('Error creating objection:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update the current objection.
		 *
		 * @param {object} data The updated objection data
		 * @return {Promise<object|null>} The updated objection
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		async updateObjection(data) {
			this.loading = true
			this.error = null

			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject('objection', data)
				this.currentObjection = result
				return result
			} catch (error) {
				this.error = error.message
				console.error('Error updating objection:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Delete the current objection.
		 *
		 * @param {string} objectionId The objection UUID
		 * @return {Promise<boolean>} Whether the deletion succeeded
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		async deleteObjection(objectionId) {
			this.loading = true
			this.error = null

			try {
				const objectStore = useObjectStore()
				await objectStore.deleteObject('objection', objectionId)
				this.currentObjection = null
				return true
			} catch (error) {
				this.error = error.message
				console.error('Error deleting objection:', error)
				return false
			} finally {
				this.loading = false
			}
		},

		// --- Hearing Session CRUD ---

		/**
		 * Create a new hearing session.
		 *
		 * @param {object} data The hearing session data
		 * @return {Promise<object|null>} The created hearing
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		async createHearingSession(data) {
			this.loading = true
			this.error = null

			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject('hearingSession', data)
				this.hearingSessions.push(result)
				return result
			} catch (error) {
				this.error = error.message
				console.error('Error creating hearing session:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update a hearing session.
		 *
		 * @param {object} data The updated hearing data
		 * @return {Promise<object|null>} The updated hearing
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		async updateHearingSession(data) {
			this.loading = true
			this.error = null

			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject('hearingSession', data)
				const index = this.hearingSessions.findIndex(
					(h) => h.id === result.id,
				)
				if (index >= 0) {
					this.hearingSessions[index] = result
				}
				return result
			} catch (error) {
				this.error = error.message
				console.error('Error updating hearing session:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		// --- Advisory Report CRUD ---

		/**
		 * Create an advisory report.
		 *
		 * @param {object} data The advisory report data
		 * @return {Promise<object|null>} The created report
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		async createAdvisoryReport(data) {
			this.loading = true
			this.error = null

			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject('advisoryReport', data)
				this.currentAdvisoryReport = result
				return result
			} catch (error) {
				this.error = error.message
				console.error('Error creating advisory report:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update the advisory report.
		 *
		 * @param {object} data The updated report data
		 * @return {Promise<object|null>} The updated report
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		async updateAdvisoryReport(data) {
			this.loading = true
			this.error = null

			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject('advisoryReport', data)
				this.currentAdvisoryReport = result
				return result
			} catch (error) {
				this.error = error.message
				console.error('Error updating advisory report:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		// --- Appeal Decision CRUD ---

		/**
		 * Create an appeal decision (beslissing op bezwaar).
		 *
		 * @param {object} data The appeal decision data
		 * @return {Promise<object|null>} The created decision
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		async createAppealDecision(data) {
			this.loading = true
			this.error = null

			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject('appealDecision', data)
				this.currentAppealDecision = result
				return result
			} catch (error) {
				this.error = error.message
				console.error('Error creating appeal decision:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update the appeal decision.
		 *
		 * @param {object} data The updated decision data
		 * @return {Promise<object|null>} The updated decision
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		async updateAppealDecision(data) {
			this.loading = true
			this.error = null

			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject('appealDecision', data)
				this.currentAppealDecision = result
				return result
			} catch (error) {
				this.error = error.message
				console.error('Error updating appeal decision:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		// --- Deadline Calculation ---

		/**
		 * Calculate bezwaar deadlines based on AWB rules.
		 *
		 * @param {string} ontvangstDatum The date the bezwaarschrift was received (ISO string)
		 * @return {object} Calculated deadlines
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		calculateDeadlines(ontvangstDatum) {
			const received = new Date(ontvangstDatum)

			// Ontvangstbevestiging: within 1 week (5 working days).
			const acknowledgmentDeadline = addWorkingDays(received, 5)

			// Afhandeling: 6 weeks from receipt (Awb art. 7:10 lid 1).
			const processingDeadline = addWeeks(received, 6)

			// Maximum deadline with extension: +6 weeks (Awb art. 7:10 lid 3).
			const maxDeadlineWithExtension = addWeeks(received, 12)

			return {
				acknowledgementOfReceiptDeadline: acknowledgmentDeadline
					.toISOString()
					.split('T')[0],
				afhandelDeadline: processingDeadline.toISOString().split('T')[0],
				maxDeadlineWithExtension: maxDeadlineWithExtension
					.toISOString()
					.split('T')[0],
				postponementPossible: true,
			}
		},

		/**
		 * Calculate an extended deadline after verdaging (extension).
		 *
		 * @param {string} currentDeadline The current deadline (ISO string)
		 * @return {string} The new extended deadline (ISO date string)
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		calculateExtendedDeadline(currentDeadline) {
			const current = new Date(currentDeadline)
			const extended = addWeeks(current, 6)
			return extended.toISOString().split('T')[0]
		},

		/**
		 * Calculate remaining days and status for a deadline.
		 *
		 * @param {string} deadline The deadline date (ISO string)
		 * @param {boolean} isSuspended Whether the deadline is currently suspended
		 * @return {object} Deadline status with daysRemaining, isAtRisk, isOverdue
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		getDeadlineStatus(deadline, isSuspended = false) {
			if (isSuspended) {
				return {
					daysRemaining: null,
					isAtRisk: false,
					isOverdue: false,
					isSuspended: true,
					label: 'Opgeschort',
				}
			}

			const today = new Date()
			today.setHours(0, 0, 0, 0)
			const deadlineDate = new Date(deadline)
			deadlineDate.setHours(0, 0, 0, 0)

			const daysRemaining = daysDifference(today, deadlineDate)

			// At risk: within 5 working days (approximately 7 calendar days).
			const isAtRisk = daysRemaining > 0 && daysRemaining <= 7
			const isOverdue = daysRemaining < 0

			let label = `${daysRemaining} dagen resterend`
			if (isOverdue) {
				label = `${Math.abs(daysRemaining)} dagen over termijn`
			} else if (daysRemaining === 0) {
				label = 'Vandaag is de deadline'
			}

			return {
				daysRemaining,
				isAtRisk,
				isOverdue,
				isSuspended: false,
				label,
			}
		},

		// --- Escalation ---

		/**
		 * Create a beroep case from a bezwaar case (escalation).
		 *
		 * @param {object} bezwaarCase The bezwaar case data
		 * @param {object} options Additional options (voorzieningRequested)
		 * @return {Promise<object|null>} The created beroep case
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		async escalateToBeroep(bezwaarCase, options = {}) {
			this.loading = true
			this.error = null

			try {
				const objectStore = useObjectStore()

				// Find the Beroep case type.
				const caseTypes = await objectStore.fetchCollection('caseType', {
					identifier: 'beroep',
					_limit: 1,
				})
				const beroepCaseType = caseTypes?.[0]

				if (!beroepCaseType) {
					throw new Error(
						'Beroep case type not found. Ensure seed data has been imported.',
					)
				}

				// Pre-fill beroep case data from bezwaar.
				const beroepData = {
					title: `Beroep: ${bezwaarCase.title || 'Bezwaar'}`,
					description: `Beroep tegen beslissing op bezwaar van zaak ${bezwaarCase.identifier || bezwaarCase.id}`,
					caseType: beroepCaseType.id,
					parentCase: bezwaarCase.id,
					startDate: new Date().toISOString().split('T')[0],
					priority: bezwaarCase.priority || 'normal',
					provisionRequested: options.provisionRequested || false,
				}

				const beroepCase = await objectStore.saveObject('case', beroepData)
				return beroepCase
			} catch (error) {
				this.error = error.message
				console.error('Error escalating to beroep:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Check timeliness of a bezwaar (whether filed within 6-week term).
		 *
		 * @param {string} besluitDate The date the original besluit was published
		 * @param {string} bezwaarReceivedDate The date the bezwaar was received
		 * @return {object} Timeliness assessment
		 * @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md
		 */
		checkTimeliness(besluitDate, bezwaarReceivedDate) {
			const besluit = new Date(besluitDate)
			const received = new Date(bezwaarReceivedDate)
			const termijnEnd = addWeeks(besluit, 6)

			const isTimely = received <= termijnEnd
			const daysOverTerm = isTimely ? 0 : daysDifference(termijnEnd, received)

			return {
				isTimely,
				termijnEnd: termijnEnd.toISOString().split('T')[0],
				daysOverTerm,
				message: isTimely
					? 'Bezwaar is tijdig ingediend'
					: `Bezwaartermijn mogelijk overschreden (${daysOverTerm} dagen te laat)`,
			}
		},

		/**
		 * Clear all bezwaar data from the store.
		 *
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-24-bezwaar-lifecycle/tasks.md */
		clearBezwaarData() {
			this.currentObjection = null
			this.hearingSessions = []
			this.currentAdvisoryReport = null
			this.currentAppealDecision = null
			this.error = null
		},
	},
})
