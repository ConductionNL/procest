/**
 * Dashboard helper utilities for KPI calculations, status aggregation,
 * overdue extraction, activity aggregation, and my work item merging.
 */

import { translate as t } from '@nextcloud/l10n'
import {
	formatDeadlineCountdown,
	getDaysRemaining,
	isCaseOverdue,
} from './caseHelpers.js'
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
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */
export function computeKpis(openCases, completedCases, myTasks) {
	const today = todayString()

	const openCount = openCases.length
	const newToday = openCases.filter(
		(c) => c.startDate && c.startDate.slice(0, 10) === today,
	).length

	const overdueCount = openCases.filter((c) => isCaseOverdue(c, false)).length

	const completedCount = completedCases.length
	let avgDays = null
	if (completedCount > 0) {
		const totalDays = completedCases.reduce((sum, c) => {
			if (c.startDate && c.endDate) {
				const start = new Date(c.startDate)
				const end = new Date(c.endDate)
				start.setHours(0, 0, 0, 0)
				end.setHours(0, 0, 0, 0)
				return (
					sum
					+ Math.max(0, Math.floor((end - start) / (1000 * 60 * 60 * 24)))
				)
			}
			return sum
		}, 0)
		avgDays = Math.round(totalDays / completedCount)
	}

	const taskCount = myTasks.length
	const tasksDueToday = myTasks.filter(
		(t) => t.dueDate && t.dueDate.slice(0, 10) === today,
	).length

	return {
		openCount,
		newToday,
		overdueCount,
		completedCount,
		avgDays,
		taskCount,
		tasksDueToday,
	}
}

/**
 * Aggregate open cases by status name for the status chart.
 * Same-named statuses across case types are merged into one bar; every
 * underlying statusType id that contributes to a bar is collected in
 * `statusIds` so a bar click can deep-link to `/cases?status[]=<id>&…` (an IN
 * match across all case types that share the status name).
 *
 * @param {object[]} openCases Cases with non-final status
 * @param {object[]} statusTypes All status types
 * @return {Array<{ name: string, count: number, statusIds: string[] }>} Sorted by status type order
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */
export function aggregateByStatus(openCases, statusTypes) {
	const statusMap = new Map()
	const idsMap = new Map()
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
		// A case's `status` is a statusType id. Only ids that resolve to a known
		// statusType get their real name; anything else (null, or a stale/junk
		// value that no longer references a statusType) is bucketed under
		// "Unknown" rather than leaking the raw value into the chart — this
		// mirrors aggregateByType's resolution. Only resolved ids feed statusIds,
		// so an "Unknown" bar click never deep-links on a junk/absent id.
		const resolvedName = statusIdToName.get(c.status)
		const name = resolvedName || t('dossiq', 'Unknown')
		statusMap.set(name, (statusMap.get(name) || 0) + 1)
		if (resolvedName) {
			if (!idsMap.has(name)) idsMap.set(name, new Set())
			idsMap.get(name).add(c.status)
		}
	}

	return Array.from(statusMap.entries())
		.map(([name, count]) => ({
			name,
			count,
			statusIds: Array.from(idsMap.get(name) || []),
		}))
		.sort(
			(a, b) => (orderMap.get(a.name) ?? 999) - (orderMap.get(b.name) ?? 999),
		)
}

/**
 * Extract overdue cases with display-ready data.
 *
 * @param {object[]} openCases Cases with non-final status
 * @param {object[]} caseTypes All case types (for name resolution)
 * @return {Array<{ id, identifier, title, caseTypeName, daysOverdue, handler }>}
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */
export function getOverdueCases(openCases, caseTypes) {
	const typeMap = new Map()
	for (const ct of caseTypes) {
		typeMap.set(ct.id, ct.title || ct.name || t('dossiq', 'Unknown'))
	}

	return openCases
		.filter((c) => isCaseOverdue(c, false))
		.map((c) => ({
			id: c.id,
			identifier: c.identifier || '—',
			title: c.title || '—',
			caseTypeName: typeMap.get(c.caseType) || t('dossiq', 'Unknown'),
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
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
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
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
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
		const overdue =
			!isTerminalStatus(task.status)
			&& task.dueDate
			&& new Date(task.dueDate) < new Date(todayString())
		const daysLeft = task.dueDate ? getDaysRemaining(task.dueDate) : null
		let daysText = '—'
		if (daysLeft !== null) {
			if (daysLeft < 0) {
				daysText =
					Math.abs(daysLeft) === 1
						? t('dossiq', '1 day overdue')
						: t('dossiq', '{days} days overdue', {
								days: Math.abs(daysLeft),
							})
			} else if (daysLeft === 0) {
				daysText = t('dossiq', 'Due today')
			} else {
				daysText = t('dossiq', '{days} days', { days: daysLeft })
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

		if (a.deadline && b.deadline)
			return new Date(a.deadline) - new Date(b.deadline)
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
	const daysUntilSunday = day === 0 ? 0 : 7 - day
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
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
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
			caseType: c.caseType || null,
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
		if (a.deadline && b.deadline)
			return new Date(a.deadline) - new Date(b.deadline)
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
 * Default warning threshold in days for deadline alerts.
 *
 * @type {number}
 */
export const DEADLINE_WARNING_DAYS = 3

/**
 * Default threshold in days for stalled case detection.
 *
 * @type {number}
 */
export const STALLED_THRESHOLD_DAYS = 7

/**
 * Get deadline alerts: cases that are overdue or approaching their deadline
 * within the warning threshold.
 *
 * @param {object[]} openCases Cases with non-final status
 * @param {object[]} caseTypes All case types (for name resolution)
 * @param {number} warningDays Number of days before deadline to flag as at-risk
 * @return {{ overdue: object[], atRisk: object[] }}
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */
export function getDeadlineAlerts(
	openCases,
	caseTypes,
	warningDays = DEADLINE_WARNING_DAYS,
) {
	const typeMap = new Map()
	for (const ct of caseTypes) {
		typeMap.set(ct.id, ct.title || ct.name || t('dossiq', 'Unknown'))
	}

	const today = new Date()
	today.setHours(0, 0, 0, 0)

	const overdue = []
	const atRisk = []

	for (const c of openCases) {
		if (!c.deadline) continue

		const deadline = new Date(c.deadline)
		deadline.setHours(0, 0, 0, 0)

		const daysRemaining = getDaysRemaining(c.deadline)
		const item = {
			id: c.id,
			title: c.title || '\u2014',
			identifier: c.identifier || '\u2014',
			caseTypeName: typeMap.get(c.caseType) || t('dossiq', 'Unknown'),
			handler: c.assignee || '\u2014',
		}

		if (isCaseOverdue(c, false)) {
			overdue.push({ ...item, daysOverdue: Math.abs(daysRemaining) })
		} else if (daysRemaining >= 0 && daysRemaining <= warningDays) {
			atRisk.push({ ...item, daysRemaining })
		}
	}

	overdue.sort((a, b) => b.daysOverdue - a.daysOverdue)
	atRisk.sort((a, b) => a.daysRemaining - b.daysRemaining)

	return { overdue, atRisk }
}

/**
 * Get task due reminders: tasks that are overdue or approaching their due date
 * within the warning threshold.
 *
 * @param {object[]} tasks Tasks assigned to the current user
 * @param {number} warningDays Number of days before due date to flag as due-soon
 * @return {{ overdue: object[], dueSoon: object[] }}
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */
export function getTaskDueReminders(tasks, warningDays = DEADLINE_WARNING_DAYS) {
	const today = new Date()
	today.setHours(0, 0, 0, 0)

	const overdue = []
	const dueSoon = []

	for (const task of tasks) {
		if (!task.dueDate) continue
		if (isTerminalStatus(task.status)) continue

		const dueDate = new Date(task.dueDate)
		dueDate.setHours(0, 0, 0, 0)

		const diffMs = dueDate - today
		const daysRemaining = Math.ceil(diffMs / (1000 * 60 * 60 * 24))

		const item = {
			id: task.id,
			title: task.title || '\u2014',
			caseReference: task.case ? `Case: ${task.case}` : '\u2014',
			priority: task.priority || 'normal',
		}

		if (daysRemaining < 0) {
			overdue.push({ ...item, daysOverdue: Math.abs(daysRemaining) })
		} else if (daysRemaining <= warningDays) {
			dueSoon.push({ ...item, daysRemaining })
		}
	}

	overdue.sort((a, b) => b.daysOverdue - a.daysOverdue)
	dueSoon.sort((a, b) => a.daysRemaining - b.daysRemaining)

	return { overdue, dueSoon }
}

/**
 * Get stalled cases: open cases that have had no activity (no dateModified update)
 * for longer than the stalled threshold.
 *
 * @param {object[]} openCases Cases with non-final status
 * @param {object[]} caseTypes All case types (for name resolution)
 * @param {number} stalledDays Number of days without activity to consider stalled
 * @return {Array<{ id, title, identifier, caseTypeName, daysSinceActivity, handler }>}
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */
export function getStalledCases(
	openCases,
	caseTypes,
	stalledDays = STALLED_THRESHOLD_DAYS,
) {
	const typeMap = new Map()
	for (const ct of caseTypes) {
		typeMap.set(ct.id, ct.title || ct.name || t('dossiq', 'Unknown'))
	}

	const today = new Date()
	today.setHours(0, 0, 0, 0)

	const stalled = []

	for (const c of openCases) {
		const lastActivity = c.dateModified || c.dateCreated || null
		if (!lastActivity) continue

		const activityDate = new Date(lastActivity)
		activityDate.setHours(0, 0, 0, 0)

		const diffMs = today - activityDate
		const daysSinceActivity = Math.floor(diffMs / (1000 * 60 * 60 * 24))

		if (daysSinceActivity >= stalledDays) {
			stalled.push({
				id: c.id,
				title: c.title || '\u2014',
				identifier: c.identifier || '\u2014',
				caseTypeName: typeMap.get(c.caseType) || t('dossiq', 'Unknown'),
				daysSinceActivity,
				handler: c.assignee || '\u2014',
			})
		}
	}

	stalled.sort((a, b) => b.daysSinceActivity - a.daysSinceActivity)
	return stalled
}

/**
 * Format a relative timestamp (e.g., "10 min ago", "2 hours ago", "yesterday").
 *
 * @param {string} dateString ISO date string
 * @return {string}
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */
export function formatRelativeTime(dateString) {
	if (!dateString) return '—'
	const date = new Date(dateString)
	const now = new Date()
	const diffMs = now - date
	const diffMin = Math.floor(diffMs / 60000)
	const diffHours = Math.floor(diffMs / 3600000)
	const diffDays = Math.floor(diffMs / 86400000)

	if (diffMin < 1) return t('dossiq', 'just now')
	if (diffMin < 60) return t('dossiq', '{min} min ago', { min: diffMin })
	if (diffHours < 24) return t('dossiq', '{hours} hours ago', { hours: diffHours })
	if (diffDays === 1) return t('dossiq', 'yesterday')
	if (diffDays < 7) return t('dossiq', '{days} days ago', { days: diffDays })
	return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

/**
 * Aggregate open cases by case type for the "Cases by Type" bar chart.
 * Grouped by the caseType id (the value a case object stores) so a bar click
 * can deep-link to `/cases?caseType=<id>` and have the index pre-filter match.
 * The human-readable title is carried alongside as `label` for display. Cases
 * whose caseType cannot be resolved are grouped under an empty id / "Unknown".
 *
 * @param {object[]} openCases Cases with non-final status
 * @param {object[]} caseTypes All case types (for label resolution)
 * @return {Array<{ type: string, label: string, count: number }>} `type` = caseType id
 *   (the case's stored value), `label` = title. Sorted by count descending.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
export function aggregateByType(openCases, caseTypes) {
	const labelMap = new Map()
	for (const ct of caseTypes) {
		labelMap.set(ct.id, ct.title || ct.name || t('dossiq', 'Unknown'))
	}

	const counts = new Map()
	for (const c of openCases) {
		const id = c.caseType || ''
		counts.set(id, (counts.get(id) || 0) + 1)
	}

	return Array.from(counts.entries())
		.map(([type, count]) => ({
			type,
			label: labelMap.get(type) || t('dossiq', 'Unknown'),
			count,
		}))
		.sort((a, b) => b.count - a.count)
}

/**
 * Default Woo (Wet open overheid) severity thresholds in days. A Woo response
 * is statutorily due within 4 weeks (28 days) with a single 2-week extension;
 * the panel surfaces urgency relative to the remaining days on the deadline.
 *
 * @type {number}
 */
export const WOO_CRITICAL_DAYS = 7

/**
 * @type {number}
 */
export const WOO_WARNING_DAYS = 14

/**
 * Get open Woo cases with statutory-deadline countdown and traffic-light
 * severity. Woo cases are identified by a case-insensitive substring match on
 * the resolved case-type title containing "woo" (DD-03). Cases without a
 * deadline are excluded — there is nothing to count down against.
 *
 * Severity mapping:
 *   - overdue   : daysRemaining < 0 (red / --color-error)
 *   - critical  : 0 <= daysRemaining <= 7 (orange)
 *   - warning   : 7 < daysRemaining <= 14 (yellow)
 *   - ok        : daysRemaining > 14 (green / --color-success)
 *
 * @param {object[]} openCases Cases with non-final status
 * @param {object[]} caseTypes All case types (for Woo detection + name)
 * @return {Array<{ id, identifier, title, initiator, deadline, daysRemaining, isOverdue, severity }>}
 *   Sorted overdue first (most overdue), then by ascending daysRemaining.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
export function getWooCases(openCases, caseTypes) {
	const typeTitleMap = new Map()
	for (const ct of caseTypes) {
		typeTitleMap.set(ct.id, ct.title || ct.name || '')
	}

	const result = []

	for (const c of openCases) {
		if (!c.deadline) continue

		const typeTitle = (typeTitleMap.get(c.caseType) || '').toLowerCase()
		if (!typeTitle.includes('woo')) continue

		const daysRemaining = getDaysRemaining(c.deadline)
		const isOverdue = daysRemaining < 0

		let severity
		if (isOverdue) {
			severity = 'overdue'
		} else if (daysRemaining <= WOO_CRITICAL_DAYS) {
			severity = 'critical'
		} else if (daysRemaining <= WOO_WARNING_DAYS) {
			severity = 'warning'
		} else {
			severity = 'ok'
		}

		result.push({
			id: c.id,
			identifier: c.identifier || '—',
			title: c.title || '—',
			initiator: c.initiator || c.assignee || '—',
			deadline: c.deadline,
			daysRemaining,
			isOverdue,
			severity,
		})
	}

	// Overdue first (most overdue at top), then ascending daysRemaining.
	result.sort((a, b) => {
		if (a.isOverdue && b.isOverdue) return a.daysRemaining - b.daysRemaining
		if (a.isOverdue) return -1
		if (b.isOverdue) return 1
		return a.daysRemaining - b.daysRemaining
	})

	return result
}

/**
 * Group completed cases into weekly throughput buckets (cases closed per ISO
 * week) for the trailing `weeks` window ending at the most recent completion.
 * Used by the Process Analytics throughput line chart (REQ-DASH-V1-005c).
 *
 * @param {object[]} completedCases Cases with an `endDate`
 * @param {number} weeks Number of trailing weeks to include
 * @return {Array<{ weekLabel: string, count: number }>} Oldest week first
 *
 * @spec openspec/specs/dashboard/spec.md
 */
export function computeWeeklyThroughput(completedCases, weeks = 12) {
	// Build a Monday-anchored week key for an ISO-week approximation.
	const weekStart = (d) => {
		const date = new Date(d)
		date.setHours(0, 0, 0, 0)
		const day = date.getDay() // 0 = Sunday
		const diff = day === 0 ? 6 : day - 1 // days since Monday
		date.setDate(date.getDate() - diff)
		return date
	}

	const isoWeekLabel = (monday) => {
		// ISO-8601 week number: the Thursday of the current week decides the year,
		// and week 1 is the week containing the first Thursday of that year.
		const target = new Date(monday)
		target.setHours(0, 0, 0, 0)
		target.setDate(target.getDate() + 3) // shift to this week's Thursday
		const isoYear = target.getFullYear()
		const firstThursday = new Date(isoYear, 0, 4)
		const weekNo =
			1 + Math.round((target - weekStart(firstThursday)) / (7 * 86400000))
		return `W${weekNo} ${isoYear}`
	}

	const completed = completedCases.filter((c) => c.endDate)
	if (completed.length === 0) return []

	// Determine the anchor (most recent completion) and build the trailing window.
	const latest = completed.reduce((max, c) => {
		const d = new Date(c.endDate)
		return d > max ? d : max
	}, new Date(0))

	const anchorMonday = weekStart(latest)
	const buckets = []
	const keyToIndex = new Map()
	for (let i = weeks - 1; i >= 0; i--) {
		const monday = new Date(anchorMonday)
		monday.setDate(monday.getDate() - i * 7)
		const key = monday.toISOString().slice(0, 10)
		keyToIndex.set(key, buckets.length)
		buckets.push({ weekLabel: isoWeekLabel(monday), count: 0 })
	}

	for (const c of completed) {
		const key = weekStart(c.endDate).toISOString().slice(0, 10)
		const idx = keyToIndex.get(key)
		if (idx !== undefined) buckets[idx].count += 1
	}

	return buckets
}
