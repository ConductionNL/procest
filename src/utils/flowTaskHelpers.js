// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Pure logic behind TaskWaitingCaseSection.vue, extracted so the node-env
 * vitest suite can exercise it (dossiq's vitest project mounts no Vue
 * components — see tests/vitest/caseListExportAction.spec.js for the
 * pattern and the reason).
 *
 * A task is "holding up a case" only when a flow stamped it with the run it
 * blocks. An ordinary to-do also names a case, but nothing waits on it, so
 * saying "the case is waiting" there would be untrue. The distinction is the
 * `flowRun` field: DossiqAskPersonNode writes it, nothing else does.
 */

/**
 * The id of the case this task is holding up, or null.
 *
 * Null for every task no run is waiting on: a task without a flowRun, a flow
 * task whose case reference is missing, or a non-object input. Callers render
 * NOTHING on null, which is what keeps non-flow tasks looking exactly as they
 * did before this feature existed.
 *
 * The case reference is a relation and may arrive as a bare id string or as
 * an expanded object; both shapes are read.
 *
 * @param {object|null|undefined} task The task object as the store returns it.
 * @return {string|null} The case id, or null when no case is waiting.
 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
 */
export function waitingCaseIdFrom(task) {
	if (!task || typeof task !== 'object') {
		return null
	}

	const run = String(task.flowRun ?? '').trim()
	if (run === '') {
		return null
	}

	return caseIdFrom(task.case)
}

/**
 * Read a case reference in either of the shapes the store returns.
 *
 * @param {string|object|null|undefined} ref The task's case reference.
 * @return {string|null} The case id, or null when unreadable.
 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
 */
function caseIdFrom(ref) {
	if (typeof ref === 'string') {
		const id = ref.trim()
		return id === '' ? null : id
	}

	if (ref && typeof ref === 'object') {
		const id = String(ref.id ?? ref.uuid ?? ref['@self']?.id ?? '').trim()
		return id === '' ? null : id
	}

	return null
}

/**
 * The in-app route to the waiting case's detail page.
 *
 * @param {string} caseId The case id.
 * @return {string} The vue-router path for the manifest CaseDetail page.
 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
 */
export function caseRouteFor(caseId) {
	return `/cases/${encodeURIComponent(caseId)}`
}
