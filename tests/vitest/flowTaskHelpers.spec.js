/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The task half of the flow waiting relationship (case-flow-human-steps 6.1).
 *
 * TaskWaitingCaseSection.vue renders "a case is waiting on this task" from
 * `waitingCaseIdFrom()`, and renders NOTHING when it answers null. That null
 * is the load-bearing half of the requirement: a non-flow task must look
 * exactly as it did before the feature existed. Dossiq's vitest project runs
 * in the node environment with no Vue mount harness (see
 * caseListExportAction.spec.js), so the extracted logic is what is tested.
 *
 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import { caseRouteFor, waitingCaseIdFrom } from '../../src/utils/flowTaskHelpers.js'

describe('waitingCaseIdFrom', () => {
	it('names the case for a flow task with a string case reference', () => {
		expect(
			waitingCaseIdFrom({
				flowRun: 'run-1',
				flowNode: 'ask-indiener',
				case: 'case-9',
			}),
		).toBe('case-9')
	})

	it('reads an expanded case reference by id', () => {
		expect(
			waitingCaseIdFrom({
				flowRun: 'run-1',
				case: { id: 'case-9', title: 'Schuur' },
			}),
		).toBe('case-9')
	})

	it('reads an expanded case reference by uuid when id is absent', () => {
		expect(
			waitingCaseIdFrom({ flowRun: 'run-1', case: { uuid: 'uuid-9' } }),
		).toBe('uuid-9')
	})

	it('reads an entity-shaped reference via @self', () => {
		expect(
			waitingCaseIdFrom({
				flowRun: 'run-1',
				case: { '@self': { id: 'case-9' } },
			}),
		).toBe('case-9')
	})

	it('answers null for a task no run is waiting on', () => {
		// The pre-existing task shape: a case, no flowRun. It renders unchanged.
		expect(waitingCaseIdFrom({ case: 'case-9', status: 'active' })).toBeNull()
	})

	it('answers null for a blank flowRun', () => {
		expect(waitingCaseIdFrom({ flowRun: '   ', case: 'case-9' })).toBeNull()
	})

	it('answers null for a flow task whose case reference is missing', () => {
		expect(waitingCaseIdFrom({ flowRun: 'run-1' })).toBeNull()
	})

	it('answers null for a blank case reference', () => {
		expect(waitingCaseIdFrom({ flowRun: 'run-1', case: '' })).toBeNull()
	})

	it('answers null for an unreadable case reference shape', () => {
		expect(waitingCaseIdFrom({ flowRun: 'run-1', case: 42 })).toBeNull()
	})

	it('answers null for a missing task', () => {
		expect(waitingCaseIdFrom(null)).toBeNull()
		expect(waitingCaseIdFrom(undefined)).toBeNull()
	})
})

describe('caseRouteFor', () => {
	it('routes to the manifest CaseDetail page', () => {
		expect(caseRouteFor('case-9')).toBe('/cases/case-9')
	})

	it('escapes an id that would otherwise break the path', () => {
		expect(caseRouteFor('a/b')).toBe('/cases/a%2Fb')
	})
})
