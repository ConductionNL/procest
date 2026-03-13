/**
 * CMMN PlanItem lifecycle for tasks.
 *
 * Statuses and allowed transitions follow the CMMN 1.1 spec:
 *   available → active | terminated | disabled
 *   active    → completed | terminated
 *   completed, terminated, disabled → (terminal, no outgoing transitions)
 */

export const TASK_STATUSES = {
	available: 'available',
	active: 'active',
	completed: 'completed',
	terminated: 'terminated',
	disabled: 'disabled',
}

function getStatusLabels() {
	return {
		available: t('procest', 'Available'),
		active: t('procest', 'Active'),
		completed: t('procest', 'Completed'),
		terminated: t('procest', 'Terminated'),
		disabled: t('procest', 'Disabled'),
	}
}

const TRANSITION_MAP = {
	available: ['active', 'terminated', 'disabled'],
	active: ['completed', 'terminated'],
	completed: [],
	terminated: [],
	disabled: [],
}

function getTransitionLabels() {
	return {
		active: t('procest', 'Start'),
		completed: t('procest', 'Complete'),
		terminated: t('procest', 'Terminate'),
		disabled: t('procest', 'Disable'),
	}
}

const TERMINAL_STATUSES = new Set(['completed', 'terminated', 'disabled'])

/**
 * Get the allowed target statuses for a given current status.
 *
 * @param {string} currentStatus One of the TASK_STATUSES values
 * @return {string[]} Array of valid target statuses
 */
export function getAllowedTransitions(currentStatus) {
	return TRANSITION_MAP[currentStatus] || []
}

/**
 * Check whether a transition from one status to another is valid.
 *
 * @param {string} from Current status
 * @param {string} to   Target status
 * @return {boolean}
 */
export function validateTransition(from, to) {
	const allowed = TRANSITION_MAP[from]
	return Array.isArray(allowed) && allowed.includes(to)
}

/**
 * Get a human-readable label for a status.
 *
 * @param {string} status One of the TASK_STATUSES values
 * @return {string}
 */
export function getStatusLabel(status) {
	return getStatusLabels()[status] || status
}

/**
 * Get the button label for a transition target.
 *
 * @param {string} targetStatus The status being transitioned to
 * @return {string}
 */
export function getTransitionLabel(targetStatus) {
	return getTransitionLabels()[targetStatus] || targetStatus
}

/**
 * Check whether a status is terminal (no further transitions possible).
 *
 * @param {string} status One of the TASK_STATUSES values
 * @return {boolean}
 */
export function isTerminalStatus(status) {
	return TERMINAL_STATUSES.has(status)
}
