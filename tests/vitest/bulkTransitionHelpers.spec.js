/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the bulk status-transition helper utilities
 * (case-bulk-status-transition): column-scoped selection toggling
 * (incl. cross-column reset), payload builders, and result summarisation.
 */

import { describe, expect, it } from 'vitest'
import {
	buildExecutePayload,
	buildPreviewPayload,
	clearSelection,
	emptySelection,
	isSelected,
	summarizeResults,
	toggleSelection,
} from '../../src/utils/bulkTransitionHelpers.js'

describe('emptySelection', () => {
	it('returns a selection with no column and no cases', () => {
		expect(emptySelection()).toEqual({ columnId: null, caseIds: [] })
	})
})

describe('toggleSelection', () => {
	it('selects a case into an empty selection', () => {
		const result = toggleSelection(emptySelection(), 'case-1', 'Received')
		expect(result).toEqual({ columnId: 'Received', caseIds: ['case-1'] })
	})

	it('adds a second case within the same column', () => {
		const first = toggleSelection(emptySelection(), 'case-1', 'Received')
		const second = toggleSelection(first, 'case-2', 'Received')
		expect(second).toEqual({
			columnId: 'Received',
			caseIds: ['case-1', 'case-2'],
		})
	})

	it('removes a case already selected within the same column', () => {
		const selection = { columnId: 'Received', caseIds: ['case-1', 'case-2'] }
		const result = toggleSelection(selection, 'case-1', 'Received')
		expect(result).toEqual({ columnId: 'Received', caseIds: ['case-2'] })
	})

	it('clears the column scope when the last case is deselected', () => {
		const selection = { columnId: 'Received', caseIds: ['case-1'] }
		const result = toggleSelection(selection, 'case-1', 'Received')
		expect(result).toEqual({ columnId: null, caseIds: [] })
	})

	it('resets the selection when a case is selected in a different column (cross-column reset)', () => {
		const selection = { columnId: 'Received', caseIds: ['case-1', 'case-2'] }
		const result = toggleSelection(selection, 'case-9', 'In handling')
		expect(result).toEqual({ columnId: 'In handling', caseIds: ['case-9'] })
	})

	it('compares case ids as strings so numeric/string mismatches still toggle correctly', () => {
		const selection = { columnId: 'Received', caseIds: [1, 2] }
		const result = toggleSelection(selection, '1', 'Received')
		expect(result).toEqual({ columnId: 'Received', caseIds: [2] })
	})

	it('tolerates a null/undefined selection as the starting state', () => {
		expect(toggleSelection(null, 'case-1', 'Received')).toEqual({
			columnId: 'Received',
			caseIds: ['case-1'],
		})
		expect(toggleSelection(undefined, 'case-1', 'Received')).toEqual({
			columnId: 'Received',
			caseIds: ['case-1'],
		})
	})
})

describe('isSelected', () => {
	it('returns true when the case id is in the selection', () => {
		const selection = { columnId: 'Received', caseIds: ['case-1', 'case-2'] }
		expect(isSelected(selection, 'case-1')).toBe(true)
	})

	it('returns false when the case id is not in the selection', () => {
		const selection = { columnId: 'Received', caseIds: ['case-1'] }
		expect(isSelected(selection, 'case-9')).toBe(false)
	})

	it('returns false for an empty or malformed selection', () => {
		expect(isSelected(emptySelection(), 'case-1')).toBe(false)
		expect(isSelected(null, 'case-1')).toBe(false)
		expect(isSelected({}, 'case-1')).toBe(false)
	})
})

describe('clearSelection', () => {
	it('returns an empty selection', () => {
		expect(clearSelection()).toEqual({ columnId: null, caseIds: [] })
	})
})

describe('buildPreviewPayload', () => {
	it('builds a payload from the selection and transitionId', () => {
		const selection = { columnId: 'Received', caseIds: ['case-1', 'case-2'] }
		expect(buildPreviewPayload(selection, 'submit')).toEqual({
			caseIds: ['case-1', 'case-2'],
			transitionId: 'submit',
		})
	})

	it('defaults to an empty caseIds array and empty transitionId for a malformed selection', () => {
		expect(buildPreviewPayload(null, undefined)).toEqual({
			caseIds: [],
			transitionId: '',
		})
	})
})

describe('buildExecutePayload', () => {
	it('builds a payload including the comment', () => {
		const selection = { columnId: 'Received', caseIds: ['case-1'] }
		expect(buildExecutePayload(selection, 'submit', 'go ahead')).toEqual({
			caseIds: ['case-1'],
			transitionId: 'submit',
			comment: 'go ahead',
		})
	})

	it('nulls out an empty/undefined comment', () => {
		const selection = { columnId: 'Received', caseIds: ['case-1'] }
		expect(buildExecutePayload(selection, 'submit', '')).toEqual({
			caseIds: ['case-1'],
			transitionId: 'submit',
			comment: null,
		})
		expect(buildExecutePayload(selection, 'submit')).toEqual({
			caseIds: ['case-1'],
			transitionId: 'submit',
			comment: null,
		})
	})
})

describe('summarizeResults', () => {
	it('counts statuses and collects non-ready/non-succeeded entries as failed', () => {
		const results = {
			'case-1': { status: 'ready', reasons: [] },
			'case-2': {
				status: 'blocked',
				reasons: [{ message: 'missing document' }],
			},
			'case-3': { status: 'succeeded' },
			'case-4': { status: 'failed', reasons: [{ message: 'guard failed' }] },
			'case-5': { status: 'error', reasons: [{ message: 'preview_failed' }] },
		}

		const summary = summarizeResults(results)

		expect(summary.total).toBe(5)
		expect(summary.counts).toEqual({
			ready: 1,
			blocked: 1,
			succeeded: 1,
			failed: 1,
			error: 1,
		})
		expect(summary.failed).toEqual([
			{
				caseId: 'case-2',
				status: 'blocked',
				reasons: [{ message: 'missing document' }],
			},
			{
				caseId: 'case-4',
				status: 'failed',
				reasons: [{ message: 'guard failed' }],
			},
			{
				caseId: 'case-5',
				status: 'error',
				reasons: [{ message: 'preview_failed' }],
			},
		])
	})

	it('returns an empty summary for an empty or malformed results map', () => {
		expect(summarizeResults({})).toEqual({ total: 0, counts: {}, failed: [] })
		expect(summarizeResults(null)).toEqual({ total: 0, counts: {}, failed: [] })
		expect(summarizeResults(undefined)).toEqual({
			total: 0,
			counts: {},
			failed: [],
		})
	})
})
