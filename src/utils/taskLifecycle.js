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

/**
 *
 */
function getStatusLabels() {
	return {
		available: t('dossiq', 'Available'),
		active: t('dossiq', 'Active'),
		completed: t('dossiq', 'Completed'),
		terminated: t('dossiq', 'Terminated'),
		disabled: t('dossiq', 'Disabled'),
	}
}

const TRANSITION_MAP = {
	available: ['active', 'terminated', 'disabled'],
	active: ['completed', 'terminated'],
	completed: [],
	terminated: [],
	disabled: [],
}

/**
 *
 */
function getTransitionLabels() {
	return {
		active: t('dossiq', 'Start'),
		completed: t('dossiq', 'Complete'),
		terminated: t('dossiq', 'Terminate'),
		disabled: t('dossiq', 'Disable'),
	}
}

const TERMINAL_STATUSES = new Set(['completed', 'terminated', 'disabled'])

/**
 * Get the allowed target statuses for a given current status.
 *
 * @param {string} currentStatus One of the TASK_STATUSES values
 * @return {string[]} Array of valid target statuses
 * @spec openspec/specs/task-management/spec.md
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
 * @spec openspec/specs/task-management/spec.md
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
 * @spec openspec/specs/task-management/spec.md
 */
export function getStatusLabel(status) {
	return getStatusLabels()[status] || status
}

/**
 * Get the button label for a transition target.
 *
 * @param {string} targetStatus The status being transitioned to
 * @return {string}
 * @spec openspec/specs/task-management/spec.md
 */
export function getTransitionLabel(targetStatus) {
	return getTransitionLabels()[targetStatus] || targetStatus
}

/**
 * Check whether a status is terminal (no further transitions possible).
 *
 * @param {string} status One of the TASK_STATUSES values
 * @return {boolean}
 * @spec openspec/specs/task-management/spec.md
 */
export function isTerminalStatus(status) {
	return TERMINAL_STATUSES.has(status)
}
