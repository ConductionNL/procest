/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the workflow-board keyboard "Move to…" target-column
 * resolution (kanban-board-keyboard-status-transition, REQ-KBD-01).
 */

import { describe, expect, it } from 'vitest'
import { columnsExcludingCurrent } from '../../src/utils/workflowBoardHelpers.js'

describe('columnsExcludingCurrent', () => {
	const columns = [
		{ id: 'status-1', name: 'Received' },
		{ id: 'status-2', name: 'In handling' },
		{ id: 'status-3', name: 'Besluitvorming' },
	]

	it('excludes only the current status column', () => {
		const result = columnsExcludingCurrent(columns, 'status-2')
		expect(result).toEqual([
			{ id: 'status-1', name: 'Received' },
			{ id: 'status-3', name: 'Besluitvorming' },
		])
	})

	it('returns all columns when the current status matches none of them', () => {
		const result = columnsExcludingCurrent(columns, 'status-unknown')
		expect(result).toHaveLength(3)
	})

	it('compares ids as strings so numeric/string mismatches still match', () => {
		const numericColumns = [
			{ id: 1, name: 'Received' },
			{ id: 2, name: 'In handling' },
		]
		const result = columnsExcludingCurrent(numericColumns, '1')
		expect(result).toEqual([{ id: 2, name: 'In handling' }])
	})

	it('returns an empty array for a non-array input', () => {
		expect(columnsExcludingCurrent(null, 'status-1')).toEqual([])
		expect(columnsExcludingCurrent(undefined, 'status-1')).toEqual([])
	})

	it('returns an empty array when there are no other columns', () => {
		const result = columnsExcludingCurrent(
			[{ id: 'status-1', name: 'Received' }],
			'status-1',
		)
		expect(result).toEqual([])
	})
})
