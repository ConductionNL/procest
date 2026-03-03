/**
 * Task API wrapper for fetching CalDAV tasks via OpenRegister's convenience API.
 *
 * Uses the per-object endpoint: GET /apps/openregister/api/objects/{register}/{schema}/{id}/tasks
 * Since there is no register-level endpoint, we fetch user's cases first,
 * then batch-fetch tasks per case (DD-01 fallback strategy).
 */

import { useObjectStore } from '../store/modules/object.js'

/**
 * Build standard headers for OpenRegister API calls.
 *
 * @return {object} Headers object with requesttoken
 */
function getHeaders() {
	return {
		'Content-Type': 'application/json',
		requesttoken: OC.requestToken,
		'OCS-APIREQUEST': 'true',
	}
}

/**
 * Map iCalendar priority (0-9) to app priority string.
 * 1-3 → 'urgent', 4 → 'high', 5-6 → 'normal', 7-9 → 'low', 0 → 'normal'
 *
 * @param {number} icalPriority iCalendar priority value (0-9)
 * @return {string} App priority: 'urgent' | 'high' | 'normal' | 'low'
 */
function mapCalDavPriority(icalPriority) {
	const p = Number(icalPriority) || 0
	if (p >= 1 && p <= 3) return 'urgent'
	if (p === 4) return 'high'
	if (p >= 5 && p <= 6) return 'normal'
	if (p >= 7 && p <= 9) return 'low'
	return 'normal'
}

/**
 * Normalize a CalDAV task JSON object (from OpenRegister tasks API) to the
 * standard work item shape used by dashboardHelpers.
 *
 * @param {object} task CalDAV task object from API
 * @return {object} Normalized work item
 */
export function normalizeCalDavTask(task) {
	const due = task.due || null
	const isCompleted = task.status === 'completed'
	const isCancelled = task.status === 'cancelled'
	const isTerminal = isCompleted || isCancelled

	let isOverdue = false
	let daysText = '—'

	if (due && !isTerminal) {
		const dueDate = new Date(due)
		const today = new Date()
		today.setHours(0, 0, 0, 0)
		dueDate.setHours(0, 0, 0, 0)
		const diffDays = Math.floor((dueDate - today) / (1000 * 60 * 60 * 24))

		if (diffDays < 0) {
			isOverdue = true
			const absDays = Math.abs(diffDays)
			daysText = absDays === 1
				? t('procest', '1 day overdue')
				: t('procest', '{days} days overdue', { days: absDays })
		} else if (diffDays === 0) {
			daysText = t('procest', 'Due today')
		} else {
			daysText = t('procest', '{days} days', { days: diffDays })
		}
	}

	return {
		type: 'task',
		id: task.uid || task.id,
		calendarId: task.calendarId || null,
		taskUri: task.id || null,
		title: task.summary || '—',
		reference: task.objectUuid ? `Object: ${task.objectUuid}` : '',
		objectUuid: task.objectUuid || null,
		deadline: due,
		daysText,
		isOverdue,
		isCompleted,
		priority: mapCalDavPriority(task.priority),
		status: task.status || 'needs-action',
	}
}

/**
 * Fetch CalDAV tasks for a specific OpenRegister object.
 *
 * @param {string|number} registerId The register ID
 * @param {string|number} schemaId The schema (schema ID)
 * @param {string} objectId The object UUID
 * @return {Promise<object[]>} Array of normalized task work items
 */
export async function fetchTasksForObject(registerId, schemaId, objectId) {
	const url = `/apps/openregister/api/objects/${registerId}/${schemaId}/${objectId}/tasks`

	try {
		const response = await fetch(url, { headers: getHeaders() })
		if (!response.ok) {
			if (response.status === 404) return []
			console.warn(`Failed to fetch tasks for object ${objectId}: ${response.status}`)
			return []
		}

		const data = await response.json()
		const tasks = data.results || data || []
		return tasks.map(normalizeCalDavTask)
	} catch (error) {
		console.warn('Error fetching tasks for object:', objectId, error)
		return []
	}
}

/**
 * Fetch all CalDAV tasks linked to the Procest register by fetching tasks
 * for each of the user's assigned cases.
 *
 * Strategy (DD-01 fallback): Fetch user's cases first, then batch-fetch
 * tasks per case. Limits to 20 most recent cases for performance.
 *
 * @param {object[]} cases Array of case objects (must have id property)
 * @return {Promise<object[]>} Array of normalized task work items
 */
export async function fetchTasksForCases(cases) {
	const objectStore = useObjectStore()
	const caseConfig = objectStore.objectTypeRegistry.case
	if (!caseConfig) {
		console.warn('Case object type not registered')
		return []
	}

	const casesToFetch = cases.slice(0, 20)

	const taskPromises = casesToFetch.map(c =>
		fetchTasksForObject(caseConfig.register, caseConfig.schema, c.id),
	)

	const results = await Promise.allSettled(taskPromises)

	const allTasks = []
	for (const result of results) {
		if (result.status === 'fulfilled' && Array.isArray(result.value)) {
			allTasks.push(...result.value)
		}
	}

	return allTasks
}
