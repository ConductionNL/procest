/**
 * Case helper utilities for deadline calculations, countdown display,
 * identifier generation, and overdue logic.
 */

import { formatDuration, parseDuration } from './durationHelpers.js'

/**
 * Parse a case field that the schema stores as a JSON-encoded array
 * (statusHistory, activity). Tolerates raw arrays (legacy writers),
 * JSON strings, and null/invalid values.
 *
 * @param {string|Array|null} value The raw field value from the case object
 * @return {Array} The parsed array, or [] when absent/invalid
 */
export function parseJsonArray(value) {
	if (Array.isArray(value)) return value
	if (typeof value === 'string' && value.length > 0) {
		try {
			const parsed = JSON.parse(value)
			return Array.isArray(parsed) ? parsed : []
		} catch {
			return []
		}
	}
	return []
}

/**
 * Calculate a deadline date by adding an ISO 8601 duration to a start date.
 *
 * @param {string|Date} startDate Start date (ISO string or Date)
 * @param {string} durationString ISO 8601 duration (e.g., "P56D")
 * @return {Date|null} The calculated deadline, or null if inputs are invalid
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function calculateDeadline(startDate, durationString) {
	if (!startDate || !durationString) return null
	const parsed = parseDuration(durationString)
	if (!parsed) return null

	const date = new Date(startDate)
	if (isNaN(date.getTime())) return null

	if (parsed.years) date.setFullYear(date.getFullYear() + parsed.years)
	if (parsed.months) date.setMonth(date.getMonth() + parsed.months)
	if (parsed.weeks) date.setDate(date.getDate() + parsed.weeks * 7)
	if (parsed.days) date.setDate(date.getDate() + parsed.days)

	return date
}

/**
 * Generate a case identifier in the format YYYY-NNNN.
 *
 * @return {string} Generated identifier (e.g., "2026-4281")
 */
/** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
export function generateIdentifier() {
	const year = new Date().getFullYear()
	const suffix = Date.now() % 10000
	return `${year}-${String(suffix).padStart(4, '0')}`
}

/**
 * Check if a case is overdue. A case is overdue when its deadline is in the
 * past and it is not at a final status.
 *
 * @param {object} caseObj Case object with deadline property
 * @param {boolean} isFinal Whether the case is at a final status
 * @return {boolean}
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function isCaseOverdue(caseObj, isFinal = false) {
	if (!caseObj.deadline) return false
	if (isFinal) return false
	const deadline = new Date(caseObj.deadline)
	const now = new Date()
	deadline.setHours(0, 0, 0, 0)
	now.setHours(0, 0, 0, 0)
	return deadline < now
}

/**
 * Check if a case is due today.
 *
 * @param {object} caseObj Case object with deadline property
 * @param {boolean} isFinal Whether the case is at a final status
 * @return {boolean}
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function isCaseDueToday(caseObj, isFinal = false) {
	if (!caseObj.deadline) return false
	if (isFinal) return false
	const deadline = new Date(caseObj.deadline)
	const now = new Date()
	return (
		deadline.getFullYear() === now.getFullYear()
		&& deadline.getMonth() === now.getMonth()
		&& deadline.getDate() === now.getDate()
	)
}

/**
 * Check if a case is due tomorrow.
 *
 * @param {object} caseObj Case object with deadline property
 * @param {boolean} isFinal Whether the case is at a final status
 * @return {boolean}
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function isCaseDueTomorrow(caseObj, isFinal = false) {
	if (!caseObj.deadline) return false
	if (isFinal) return false
	const deadline = new Date(caseObj.deadline)
	const tomorrow = new Date()
	tomorrow.setDate(tomorrow.getDate() + 1)
	return (
		deadline.getFullYear() === tomorrow.getFullYear()
		&& deadline.getMonth() === tomorrow.getMonth()
		&& deadline.getDate() === tomorrow.getDate()
	)
}

/**
 * Get a human-readable overdue text for a case (e.g., "5 days overdue").
 *
 * @param {object} caseObj Case object with deadline property
 * @param {boolean} isFinal Whether the case is at a final status
 * @return {string|null} Overdue text or null if not overdue
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function getCaseOverdueText(caseObj, isFinal = false) {
	if (!isCaseOverdue(caseObj, isFinal)) return null
	const deadline = new Date(caseObj.deadline)
	const now = new Date()
	deadline.setHours(0, 0, 0, 0)
	now.setHours(0, 0, 0, 0)
	const diffMs = now - deadline
	const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))
	if (diffDays === 1) {
		return t('dossiq', '1 day overdue')
	}
	return t('dossiq', '{days} days overdue', { days: diffDays })
}

/**
 * Format deadline countdown text with style classification.
 *
 * @param {object} caseObj Case object with deadline property
 * @param {boolean} isFinal Whether the case is at a final status
 * @return {{ text: string, style: string }} Countdown text and style class
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function formatDeadlineCountdown(caseObj, isFinal = false) {
	if (!caseObj.deadline) return { text: '—', style: '' }
	if (isFinal) {
		return { text: formatDate(caseObj.deadline), style: 'deadline--final' }
	}
	if (isCaseOverdue(caseObj, isFinal)) {
		return {
			text: getCaseOverdueText(caseObj, isFinal),
			style: 'deadline--overdue',
		}
	}
	if (isCaseDueToday(caseObj, isFinal)) {
		return { text: t('dossiq', 'Due today'), style: 'deadline--today' }
	}
	if (isCaseDueTomorrow(caseObj, isFinal)) {
		return { text: t('dossiq', 'Due tomorrow'), style: 'deadline--tomorrow' }
	}
	const days = getDaysRemaining(caseObj.deadline)
	return {
		text: t('dossiq', '{days} days remaining', { days }),
		style: 'deadline--ok',
	}
}

/**
 * Get the number of days elapsed since a start date.
 *
 * @param {string} startDate ISO date string
 * @return {number} Days elapsed (0 if today or invalid)
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function getDaysElapsed(startDate) {
	if (!startDate) return 0
	const start = new Date(startDate)
	const now = new Date()
	start.setHours(0, 0, 0, 0)
	now.setHours(0, 0, 0, 0)
	const diffMs = now - start
	return Math.max(0, Math.floor(diffMs / (1000 * 60 * 60 * 24)))
}

/**
 * Get the number of days remaining until a deadline.
 *
 * @param {string} deadline ISO date string
 * @return {number} Days remaining (negative if overdue)
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function getDaysRemaining(deadline) {
	if (!deadline) return 0
	const dl = new Date(deadline)
	const now = new Date()
	dl.setHours(0, 0, 0, 0)
	now.setHours(0, 0, 0, 0)
	const diffMs = dl - now
	return Math.floor(diffMs / (1000 * 60 * 60 * 24))
}

/**
 * Format a date string for display (e.g., "Feb 26, 2026").
 *
 * @param {string} dateString ISO date string
 * @return {string} Formatted date
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function formatDate(dateString) {
	if (!dateString) return '—'
	const date = new Date(dateString)
	return date.toLocaleDateString(undefined, {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	})
}

/**
 * Format a date string for short display (e.g., "Feb 26").
 *
 * @param {string} dateString ISO date string
 * @return {string} Formatted date
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function formatDateShort(dateString) {
	if (!dateString) return '—'
	const date = new Date(dateString)
	return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

/**
 * Re-export formatDuration for convenience.
 */
export { formatDuration }
