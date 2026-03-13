/**
 * Task helper utilities for overdue calculations, priority sorting,
 * and date formatting.
 */

import { isTerminalStatus } from './taskLifecycle.js'

const PRIORITY_WEIGHTS = {
	urgent: 1,
	high: 2,
	normal: 3,
	low: 4,
}

/**
 * Get priority level config with translated labels. Call this at render time,
 * not at module import time, to ensure the translation system is ready.
 *
 * @return {object} Priority levels keyed by priority name
 */
export function getPriorityLevels() {
	return {
		urgent: { label: t('procest', 'Urgent'), weight: 1, cssVar: '--color-error' },
		high: { label: t('procest', 'High'), weight: 2, cssVar: '--color-warning' },
		normal: { label: t('procest', 'Normal'), weight: 3, cssVar: null },
		low: { label: t('procest', 'Low'), weight: 4, cssVar: '--color-text-maxcontrast' },
	}
}

/**
 * Check if a task is overdue. Only non-terminal, non-completed tasks with a
 * past due date are considered overdue.
 *
 * @param {object} task Task object with dueDate and status
 * @return {boolean}
 */
export function isOverdue(task) {
	if (!task.dueDate) return false
	if (isTerminalStatus(task.status)) return false
	const due = new Date(task.dueDate)
	const now = new Date()
	now.setHours(0, 0, 0, 0)
	due.setHours(0, 0, 0, 0)
	return due < now
}

/**
 * Check if a task is due today.
 *
 * @param {object} task Task object with dueDate and status
 * @return {boolean}
 */
export function isDueToday(task) {
	if (!task.dueDate) return false
	if (isTerminalStatus(task.status)) return false
	const due = new Date(task.dueDate)
	const now = new Date()
	return due.getFullYear() === now.getFullYear()
		&& due.getMonth() === now.getMonth()
		&& due.getDate() === now.getDate()
}

/**
 * Get a human-readable overdue text (e.g. "5 days overdue").
 *
 * @param {object} task Task object with dueDate
 * @return {string|null} Overdue text or null if not overdue
 */
export function getOverdueText(task) {
	if (!isOverdue(task)) return null
	const due = new Date(task.dueDate)
	const now = new Date()
	due.setHours(0, 0, 0, 0)
	now.setHours(0, 0, 0, 0)
	const diffMs = now - due
	const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))
	if (diffDays === 1) {
		return t('procest', '1 day overdue')
	}
	return t('procest', '{days} days overdue', { days: diffDays })
}

/**
 * Format a date string for display (e.g. "Feb 26").
 *
 * @param {string} dateString ISO 8601 date string
 * @return {string} Formatted date
 */
export function formatDueDate(dateString) {
	if (!dateString) return '—'
	const date = new Date(dateString)
	return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

/**
 * Get the sort weight for a priority level (lower = higher priority).
 *
 * @param {string} priority One of urgent, high, normal, low
 * @return {number}
 */
export function prioritySortWeight(priority) {
	return PRIORITY_WEIGHTS[priority] ?? 3
}

/**
 * Get the status group weight for sorting (active tasks first).
 *
 * @param {string} status Task status
 * @return {number}
 */
function statusGroupWeight(status) {
	switch (status) {
	case 'active': return 0
	case 'available': return 1
	case 'completed': return 2
	case 'terminated': return 3
	case 'disabled': return 4
	default: return 5
	}
}

/**
 * Sort tasks by: status group (active/available first), then priority
 * (urgent first), then due date (earliest first, nulls last).
 *
 * @param {object[]} tasks Array of task objects
 * @return {object[]} Sorted copy of the array
 */
export function sortTasks(tasks) {
	return [...tasks].sort((a, b) => {
		const statusDiff = statusGroupWeight(a.status) - statusGroupWeight(b.status)
		if (statusDiff !== 0) return statusDiff

		const priorityDiff = prioritySortWeight(a.priority) - prioritySortWeight(b.priority)
		if (priorityDiff !== 0) return priorityDiff

		if (a.dueDate && b.dueDate) {
			return new Date(a.dueDate) - new Date(b.dueDate)
		}
		if (a.dueDate) return -1
		if (b.dueDate) return 1
		return 0
	})
}
