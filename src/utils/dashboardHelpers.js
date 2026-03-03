/**
 * Dashboard helper utilities for KPI calculations, status aggregation,
 * overdue extraction, activity aggregation, and my work item merging.
 */

import { isCaseOverdue, getDaysRemaining, formatDeadlineCountdown } from './caseHelpers.js'
import { prioritySortWeight } from './taskHelpers.js'
import { isTerminalStatus } from './taskLifecycle.js'

/**
 * Get today's date as ISO string (YYYY-MM-DD).
 *
 * @return {string}
 */
function todayString() {
	return new Date().toISOString().slice(0, 10)
}

/**
 * Compute dashboard KPI values from raw data arrays.
 *
 * @param {object[]} openCases Cases with non-final status
 * @param {object[]} completedCases Cases completed this month
 * @param {object[]} myTasks Tasks assigned to current user (available/active)
 * @return {object} KPI values
 */
export function computeKpis(openCases, completedCases, myTasks) {
	const today = todayString()

	const openCount = openCases.length
	const newToday = openCases.filter(c => c.startDate && c.startDate.slice(0, 10) === today).length

	const overdueCount = openCases.filter(c => isCaseOverdue(c, false)).length

	const completedCount = completedCases.length
	let avgDays = null
	if (completedCount > 0) {
		const totalDays = completedCases.reduce((sum, c) => {
			if (c.startDate && c.endDate) {
				const start = new Date(c.startDate)
				const end = new Date(c.endDate)
				start.setHours(0, 0, 0, 0)
				end.setHours(0, 0, 0, 0)
				return sum + Math.max(0, Math.floor((end - start) / (1000 * 60 * 60 * 24)))
			}
			return sum
		}, 0)
		avgDays = Math.round(totalDays / completedCount)
	}

	const taskCount = myTasks.length
	const tasksDueToday = myTasks.filter(t => t.dueDate && t.dueDate.slice(0, 10) === today).length

	return { openCount, newToday, overdueCount, completedCount, avgDays, taskCount, tasksDueToday }
}

/**
 * Aggregate open cases by status name for the status chart.
 * Same-named statuses across case types are merged.
 *
 * @param {object[]} openCases Cases with non-final status
 * @param {object[]} statusTypes All status types
 * @return {Array<{ name: string, count: number }>} Sorted by status type order
 */
export function aggregateByStatus(openCases, statusTypes) {
	const statusMap = new Map()
	const orderMap = new Map()

	for (const st of statusTypes) {
		if (!orderMap.has(st.name)) {
			orderMap.set(st.name, st.order ?? 999)
		}
	}

	const statusIdToName = new Map()
	for (const st of statusTypes) {
		statusIdToName.set(st.id, st.name)
	}

	for (const c of openCases) {
		const name = statusIdToName.get(c.status) || c.status || 'Unknown'
		statusMap.set(name, (statusMap.get(name) || 0) + 1)
	}

	return Array.from(statusMap.entries())
		.map(([name, count]) => ({ name, count }))
		.sort((a, b) => (orderMap.get(a.name) ?? 999) - (orderMap.get(b.name) ?? 999))
}

/**
 * Extract overdue cases with display-ready data.
 *
 * @param {object[]} openCases Cases with non-final status
 * @param {object[]} caseTypes All case types (for name resolution)
 * @return {Array<{ id, identifier, title, caseTypeName, daysOverdue, handler }>}
 */
export function getOverdueCases(openCases, caseTypes) {
	const typeMap = new Map()
	for (const ct of caseTypes) {
		typeMap.set(ct.id, ct.title || ct.name || 'Unknown')
	}

	return openCases
		.filter(c => isCaseOverdue(c, false))
		.map(c => ({
			id: c.id,
			identifier: c.identifier || '—',
			title: c.title || '—',
			caseTypeName: typeMap.get(c.caseType) || 'Unknown',
			daysOverdue: Math.abs(getDaysRemaining(c.deadline)),
			handler: c.assignee || '—',
		}))
		.sort((a, b) => b.daysOverdue - a.daysOverdue)
}

/**
 * Aggregate recent activity entries from case objects.
 *
 * @param {object[]} cases All visible cases (with activity arrays)
 * @param {number} limit Max entries to return
 * @return {Array<{ date, type, description, user, caseIdentifier }>}
 */
export function getRecentActivity(cases, limit = 10) {
	const entries = []

	for (const c of cases) {
		if (!Array.isArray(c.activity)) continue
		for (const entry of c.activity) {
			entries.push({
				date: entry.date,
				type: entry.type,
				description: entry.description,
				user: entry.user || '—',
				caseIdentifier: c.identifier || '—',
			})
		}
	}

	entries.sort((a, b) => new Date(b.date) - new Date(a.date))
	return entries.slice(0, limit)
}

/**
 * Merge cases and tasks into unified My Work items, sorted by priority then deadline.
 *
 * @param {object[]} cases Cases assigned to current user (non-final)
 * @param {object[]} tasks Tasks assigned to current user (available/active)
 * @param {number} limit Max items to return
 * @return {Array<{ type, id, title, reference, deadline, daysText, isOverdue, priority }>}
 */
export function getMyWorkItems(cases, tasks, limit = 5) {
	const items = []

	for (const c of cases) {
		const countdown = formatDeadlineCountdown(c, false)
		items.push({
			type: 'case',
			id: c.id,
			title: c.title || '—',
			reference: c.identifier ? `#${c.identifier}` : '',
			deadline: c.deadline || null,
			daysText: countdown.text,
			isOverdue: isCaseOverdue(c, false),
			priority: c.priority || 'normal',
		})
	}

	for (const task of tasks) {
		const overdue = !isTerminalStatus(task.status) && task.dueDate && new Date(task.dueDate) < new Date(todayString())
		const daysLeft = task.dueDate ? getDaysRemaining(task.dueDate) : null
		let daysText = '—'
		if (daysLeft !== null) {
			if (daysLeft < 0) {
				daysText = Math.abs(daysLeft) === 1
					? t('procest', '1 day overdue')
					: t('procest', '{days} days overdue', { days: Math.abs(daysLeft) })
			} else if (daysLeft === 0) {
				daysText = t('procest', 'Due today')
			} else {
				daysText = t('procest', '{days} days', { days: daysLeft })
			}
		}

		items.push({
			type: 'task',
			id: task.id,
			title: task.title || '—',
			reference: task.case ? `Case: ${task.case}` : '',
			deadline: task.dueDate || null,
			daysText,
			isOverdue: overdue,
			priority: task.priority || 'normal',
		})
	}

	items.sort((a, b) => {
		const pDiff = prioritySortWeight(a.priority) - prioritySortWeight(b.priority)
		if (pDiff !== 0) return pDiff

		if (a.deadline && b.deadline) return new Date(a.deadline) - new Date(b.deadline)
		if (a.deadline) return -1
		if (b.deadline) return 1
		return 0
	})

	return items.slice(0, limit)
}

/**
 * Get the end-of-week date (Sunday 23:59:59) for grouping purposes.
 *
 * @return {Date}
 */
function endOfWeek() {
	const now = new Date()
	const day = now.getDay() // 0 = Sunday
	const daysUntilSunday = day === 0 ? 0 : (7 - day)
	const sunday = new Date(now)
	sunday.setDate(now.getDate() + daysUntilSunday)
	sunday.setHours(23, 59, 59, 999)
	return sunday
}

/**
 * Group cases and normalized CalDAV tasks into urgency-based sections.
 *
 * Accepts OpenRegister case objects and already-normalized CalDAV task items
 * (from normalizeCalDavTask). Returns grouped sections: overdue, dueThisWeek,
 * upcoming, noDeadline.
 *
 * @param {object[]} cases Cases assigned to current user (non-final)
 * @param {object[]} normalizedTasks Already-normalized CalDAV task work items
 * @return {{ overdue: object[], dueThisWeek: object[], upcoming: object[], noDeadline: object[], totalCount: number }}
 */
export function getGroupedMyWorkItems(cases, normalizedTasks) {
	const items = []
	const today = new Date()
	today.setHours(0, 0, 0, 0)
	const weekEnd = endOfWeek()

	// Build case work items.
	for (const c of cases) {
		const countdown = formatDeadlineCountdown(c, false)
		items.push({
			type: 'case',
			id: c.id,
			title: c.title || '—',
			reference: c.identifier ? `#${c.identifier}` : '',
			deadline: c.deadline || null,
			daysText: countdown.text,
			isOverdue: isCaseOverdue(c, false),
			isCompleted: false,
			priority: c.priority || 'normal',
			status: c.status || null,
		})
	}

	// Add already-normalized task items.
	for (const task of normalizedTasks) {
		items.push(task)
	}

	// Classify into groups.
	const overdue = []
	const dueThisWeek = []
	const upcoming = []
	const noDeadline = []

	for (const item of items) {
		if (!item.deadline) {
			noDeadline.push(item)
		} else {
			const deadline = new Date(item.deadline)
			deadline.setHours(0, 0, 0, 0)

			if (item.isOverdue) {
				overdue.push(item)
			} else if (deadline <= weekEnd) {
				dueThisWeek.push(item)
			} else {
				upcoming.push(item)
			}
		}
	}

	// Sort within each group: priority first, then deadline.
	const sortFn = (a, b) => {
		const pDiff = prioritySortWeight(a.priority) - prioritySortWeight(b.priority)
		if (pDiff !== 0) return pDiff
		if (a.deadline && b.deadline) return new Date(a.deadline) - new Date(b.deadline)
		if (a.deadline) return -1
		if (b.deadline) return 1
		return 0
	}

	overdue.sort(sortFn)
	dueThisWeek.sort(sortFn)
	upcoming.sort(sortFn)
	noDeadline.sort(sortFn)

	return {
		overdue,
		dueThisWeek,
		upcoming,
		noDeadline,
		totalCount: items.length,
	}
}

/**
 * Format a relative timestamp (e.g., "10 min ago", "2 hours ago", "yesterday").
 *
 * @param {string} dateString ISO date string
 * @return {string}
 */
export function formatRelativeTime(dateString) {
	if (!dateString) return '—'
	const date = new Date(dateString)
	const now = new Date()
	const diffMs = now - date
	const diffMin = Math.floor(diffMs / 60000)
	const diffHours = Math.floor(diffMs / 3600000)
	const diffDays = Math.floor(diffMs / 86400000)

	if (diffMin < 1) return t('procest', 'just now')
	if (diffMin < 60) return t('procest', '{min} min ago', { min: diffMin })
	if (diffHours < 24) return t('procest', '{hours} hours ago', { hours: diffHours })
	if (diffDays === 1) return t('procest', 'yesterday')
	if (diffDays < 7) return t('procest', '{days} days ago', { days: diffDays })
	return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
