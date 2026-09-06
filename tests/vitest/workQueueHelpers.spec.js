/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure helpers in src/utils/workQueueHelpers.js: the
 * sort-mode → CnIndexPage sort-key mapping, the urgency tier → chip CSS
 * class mapping, and the work-queue response → urgency-map builder.
 *
 * @spec openspec/changes/werkvoorraad-intelligent-queue/specs/werkvoorraad-intelligent-queue/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	buildUrgencyMap,
	resolveSortConfig,
	SORT_MODES,
	urgencyChipClass,
} from '../../src/utils/workQueueHelpers.js'

describe('SORT_MODES', () => {
	it('lists urgency and newest', () => {
		expect(SORT_MODES).toEqual(['urgency', 'newest'])
	})
})

describe('resolveSortConfig', () => {
	it('maps "urgency" to deadline ascending', () => {
		expect(resolveSortConfig('urgency')).toEqual({
			key: 'deadline',
			order: 'asc',
		})
	})

	it('maps "newest" to startDate descending', () => {
		expect(resolveSortConfig('newest')).toEqual({
			key: 'startDate',
			order: 'desc',
		})
	})

	it('falls back to the urgency default for an unknown mode', () => {
		expect(resolveSortConfig('bogus')).toEqual({ key: 'deadline', order: 'asc' })
	})

	it('falls back to the urgency default for undefined', () => {
		expect(resolveSortConfig(undefined)).toEqual({
			key: 'deadline',
			order: 'asc',
		})
	})
})

describe('urgencyChipClass', () => {
	it('maps overdue', () => {
		expect(urgencyChipClass('overdue')).toBe(
			'mywork-card__urgency-chip--overdue',
		)
	})

	it('maps critical', () => {
		expect(urgencyChipClass('critical')).toBe(
			'mywork-card__urgency-chip--critical',
		)
	})

	it('maps warning', () => {
		expect(urgencyChipClass('warning')).toBe(
			'mywork-card__urgency-chip--warning',
		)
	})

	it('returns empty string for normal (no chip)', () => {
		expect(urgencyChipClass('normal')).toBe('')
	})

	it('returns empty string for an unknown tier', () => {
		expect(urgencyChipClass('mystery')).toBe('')
	})

	it('returns empty string for a falsy value', () => {
		expect(urgencyChipClass(null)).toBe('')
		expect(urgencyChipClass(undefined)).toBe('')
		expect(urgencyChipClass('')).toBe('')
	})
})

describe('buildUrgencyMap', () => {
	it('keys case items by id', () => {
		const items = [
			{
				itemType: 'case',
				id: 'case-1',
				tier: 'overdue',
				score: 1005,
				daysUntilDeadline: -2,
			},
			{
				itemType: 'case',
				id: 'case-2',
				tier: 'normal',
				score: 260,
				daysUntilDeadline: 30,
			},
		]
		expect(buildUrgencyMap(items)).toEqual({
			'case-1': { tier: 'overdue', score: 1005, daysUntilDeadline: -2 },
			'case-2': { tier: 'normal', score: 260, daysUntilDeadline: 30 },
		})
	})

	it('skips task items', () => {
		const items = [
			{
				itemType: 'task',
				id: 'task-1',
				tier: 'overdue',
				score: 1005,
				daysUntilDeadline: -2,
			},
			{
				itemType: 'case',
				id: 'case-1',
				tier: 'warning',
				score: 493,
				daysUntilDeadline: 7,
			},
		]
		const map = buildUrgencyMap(items)
		expect(Object.keys(map)).toEqual(['case-1'])
	})

	it('skips items missing an id', () => {
		const items = [
			{ itemType: 'case', id: '', tier: 'overdue', score: 1005 },
			{ itemType: 'case', tier: 'overdue', score: 1005 },
		]
		expect(buildUrgencyMap(items)).toEqual({})
	})

	it('skips null/undefined entries and returns {} for empty/missing input', () => {
		expect(buildUrgencyMap([null, undefined])).toEqual({})
		expect(buildUrgencyMap([])).toEqual({})
		expect(buildUrgencyMap(undefined)).toEqual({})
	})
})
