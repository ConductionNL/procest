/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the visual workflow editor's real-engine validation rules
 * (`src/utils/workflowGraphValidation.js`). Every rule is exercised in
 * isolation (one triggering fixture) plus a fully-valid pass case and a
 * serialization round-trip (the graph as stored on `workflowTemplate` —
 * JSON-stringified steps/transitions — must validate identically to the
 * already-parsed object form).
 *
 * @spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation
 */

import { describe, expect, it } from 'vitest'
import {
	RULES,
	validateWorkflowGraph,
} from '../../src/utils/workflowGraphValidation.js'

const codesOf = (issues) => issues.map((i) => i.code)

describe('validateWorkflowGraph', () => {
	it('returns no issues for an empty graph (nothing to validate yet)', () => {
		expect(validateWorkflowGraph({ statusNodes: [], transitions: [] })).toEqual(
			[],
		)
		expect(validateWorkflowGraph({})).toEqual([])
	})

	it('passes a well-formed graph with no issues', () => {
		const statusNodes = [
			{ id: 's1', name: 'Received', isFinal: false },
			{ id: 's2', name: 'In handling', isFinal: false },
			{ id: 's3', name: 'Handled', isFinal: true },
		]
		const transitions = [
			{ id: 't1', fromStatus: 's1', toStatus: 's2' },
			{ id: 't2', fromStatus: 's2', toStatus: 's3' },
		]
		expect(validateWorkflowGraph({ statusNodes, transitions })).toEqual([])
	})

	it('NO_FINAL_STATUS: flags a graph with no isFinal:true status', () => {
		const statusNodes = [
			{ id: 's1', name: 'Received', isFinal: false },
			{ id: 's2', name: 'In handling', isFinal: false },
		]
		const transitions = [{ id: 't1', fromStatus: 's1', toStatus: 's2' }]
		const issues = validateWorkflowGraph({ statusNodes, transitions })
		expect(codesOf(issues)).toContain(RULES.NO_FINAL_STATUS)
		expect(issues.find((i) => i.code === RULES.NO_FINAL_STATUS).type).toBe(
			'error',
		)
	})

	it('UNREACHABLE_FINAL: flags a final status with no path from any clear starting status', () => {
		const statusNodes = [
			{ id: 's1', name: 'A', isFinal: false },
			{ id: 's2', name: 'B', isFinal: false },
			{ id: 's3', name: 'Handled', isFinal: true },
		]
		// s1 <-> s2 is a cycle with no node lacking an incoming edge, so
		// there is no clear entry point into the workflow at all — s3 has
		// an incoming edge (not an orphan) but can never actually be
		// reached because nothing can enter the s1/s2 cycle to begin with.
		const transitions = [
			{ id: 't1', fromStatus: 's1', toStatus: 's2' },
			{ id: 't2', fromStatus: 's2', toStatus: 's1' },
			{ id: 't3', fromStatus: 's2', toStatus: 's3' },
		]
		const issues = validateWorkflowGraph({ statusNodes, transitions })
		expect(codesOf(issues)).toContain(RULES.UNREACHABLE_FINAL)
		const issue = issues.find((i) => i.code === RULES.UNREACHABLE_FINAL)
		expect(issue.type).toBe('error')
		expect(issue.message).toContain('Handled')
		// The cycle DOES have an exit to a final status (s2 -> s3), so it
		// must not also be flagged as CYCLE_NO_EXIT — the two rules answer
		// different questions (can the cycle escape vs. can anything ever
		// enter it in the first place).
		expect(codesOf(issues)).not.toContain(RULES.CYCLE_NO_EXIT)
	})

	it('DANGLING_EDGE: flags a transition referencing a status not in the graph', () => {
		const statusNodes = [
			{ id: 's1', name: 'Received', isFinal: false },
			{ id: 's2', name: 'Handled', isFinal: true },
		]
		const transitions = [
			{
				id: 't1',
				fromStatus: 's1',
				toStatus: 'ghost-status',
				label: 'Afronden',
			},
		]
		const issues = validateWorkflowGraph({ statusNodes, transitions })
		expect(codesOf(issues)).toContain(RULES.DANGLING_EDGE)
		expect(issues.find((i) => i.code === RULES.DANGLING_EDGE).type).toBe('error')
	})

	it('DUPLICATE_TRANSITION: flags two transitions with the same from/to pair', () => {
		const statusNodes = [
			{ id: 's1', name: 'Received', isFinal: false },
			{ id: 's2', name: 'Handled', isFinal: true },
		]
		const transitions = [
			{ id: 't1', fromStatus: 's1', toStatus: 's2' },
			{ id: 't2', fromStatus: 's1', toStatus: 's2' },
		]
		const issues = validateWorkflowGraph({ statusNodes, transitions })
		expect(codesOf(issues)).toContain(RULES.DUPLICATE_TRANSITION)
		expect(issues.find((i) => i.code === RULES.DUPLICATE_TRANSITION).type).toBe(
			'warning',
		)
	})

	it('ORPHAN_NODE: flags a status with no incoming or outgoing transitions', () => {
		const statusNodes = [
			{ id: 's1', name: 'Received', isFinal: false },
			{ id: 's2', name: 'Handled', isFinal: true },
			{ id: 's3', name: 'Losstaand', isFinal: false },
		]
		const transitions = [{ id: 't1', fromStatus: 's1', toStatus: 's2' }]
		const issues = validateWorkflowGraph({ statusNodes, transitions })
		expect(codesOf(issues)).toContain(RULES.ORPHAN_NODE)
		const issue = issues.find((i) => i.code === RULES.ORPHAN_NODE)
		expect(issue.type).toBe('warning')
		expect(issue.message).toContain('Losstaand')
	})

	it('does not flag ORPHAN_NODE for a single-node graph', () => {
		const statusNodes = [{ id: 's1', name: 'Solo', isFinal: true }]
		const issues = validateWorkflowGraph({ statusNodes, transitions: [] })
		expect(codesOf(issues)).not.toContain(RULES.ORPHAN_NODE)
	})

	it('CYCLE_NO_EXIT: flags a cycle that can never reach a final status', () => {
		const statusNodes = [
			{ id: 's1', name: 'A', isFinal: false },
			{ id: 's2', name: 'B', isFinal: false },
			{ id: 's3', name: 'Final', isFinal: true },
		]
		// s1 <-> s2 cycle with no edge ever leaving to s3.
		const transitions = [
			{ id: 't1', fromStatus: 's1', toStatus: 's2' },
			{ id: 't2', fromStatus: 's2', toStatus: 's1' },
		]
		const issues = validateWorkflowGraph({ statusNodes, transitions })
		expect(codesOf(issues)).toContain(RULES.CYCLE_NO_EXIT)
		expect(issues.find((i) => i.code === RULES.CYCLE_NO_EXIT).type).toBe(
			'warning',
		)
	})

	it('does not flag CYCLE_NO_EXIT when the cycle has an exit to a final status', () => {
		const statusNodes = [
			{ id: 's1', name: 'A', isFinal: false },
			{ id: 's2', name: 'B', isFinal: false },
			{ id: 's3', name: 'Final', isFinal: true },
		]
		const transitions = [
			{ id: 't1', fromStatus: 's1', toStatus: 's2' },
			{ id: 't2', fromStatus: 's2', toStatus: 's1' },
			// Exit from the cycle to the final status.
			{ id: 't3', fromStatus: 's2', toStatus: 's3' },
		]
		const issues = validateWorkflowGraph({ statusNodes, transitions })
		expect(codesOf(issues)).not.toContain(RULES.CYCLE_NO_EXIT)
	})

	it('serialization round-trip: validating JSON-string steps/transitions (as stored on workflowTemplate) matches validating the parsed object form', () => {
		const statusNodes = [
			{ id: 's1', name: 'Received', isFinal: false },
			{ id: 's2', name: 'In handling', isFinal: false },
			{ id: 's3', name: 'Handled', isFinal: true },
		]
		const transitions = [
			{ id: 't1', fromStatus: 's1', toStatus: 's2' },
			{ id: 't2', fromStatus: 's2', toStatus: 's3' },
		]

		const objectFormIssues = validateWorkflowGraph({ statusNodes, transitions })

		// Round-trip through JSON exactly as `workflow.js`'s saveTemplate()
		// stores it on the object (steps/transitions/nodePositions are
		// persisted as JSON strings, not arrays).
		const serialized = JSON.stringify(transitions)
		const roundTrippedTransitions = JSON.parse(serialized)
		const stringFormIssues = validateWorkflowGraph({
			statusNodes,
			transitions: roundTrippedTransitions,
		})

		expect(stringFormIssues).toEqual(objectFormIssues)
		expect(objectFormIssues).toEqual([])

		// The util also accepts the raw JSON string directly (as read back
		// off `currentTemplate.transitions` before the store's
		// `parsedTransitions` getter runs).
		const directStringFormIssues = validateWorkflowGraph({
			statusNodes,
			transitions: serialized,
		})
		expect(directStringFormIssues).toEqual(objectFormIssues)
	})

	it('gracefully returns [] for malformed JSON transitions instead of throwing', () => {
		const statusNodes = [{ id: 's1', name: 'A', isFinal: true }]
		expect(() =>
			validateWorkflowGraph({ statusNodes, transitions: '{not valid json' }),
		).not.toThrow()
	})
})
