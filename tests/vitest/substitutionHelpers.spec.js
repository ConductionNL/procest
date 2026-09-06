/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the substitution My Work helpers — building the substituted
 * lookup map, merging substituted cases without duplicates, resolving the
 * absentee for an item, and applying the show/hide-substituted filter.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	applySubstitutedFilter,
	buildSubstitutedMap,
	mergeSubstitutedCases,
	substitutedFor,
} from '../../src/utils/substitutionHelpers.js'

describe('buildSubstitutedMap', () => {
	it('keys cases and tasks by type:id with the absentee', () => {
		const map = buildSubstitutedMap(
			[{ id: 'c1', _substituted: { absentee: 'jan' } }],
			[{ id: 't1', _substituted: { absentee: 'jan' } }],
		)
		expect(map['case:c1']).toBe('jan')
		expect(map['task:t1']).toBe('jan')
	})

	it('tolerates missing _substituted metadata', () => {
		const map = buildSubstitutedMap([{ id: 'c1' }], [])
		expect(map['case:c1']).toBe('')
	})
})

describe('mergeSubstitutedCases', () => {
	it('appends only cases not already in the own list', () => {
		const own = [{ id: 'a' }, { id: 'b' }]
		const sub = [{ id: 'b' }, { id: 'c' }]
		const merged = mergeSubstitutedCases(own, sub)
		expect(merged.map((c) => c.id)).toEqual(['a', 'b', 'c'])
	})

	it('returns own cases unchanged when no substituted cases', () => {
		expect(mergeSubstitutedCases([{ id: 'a' }], []).map((c) => c.id)).toEqual([
			'a',
		])
	})
})

describe('substitutedFor', () => {
	const map = { 'case:c1': 'jan' }
	it('returns the absentee for a substituted item', () => {
		expect(substitutedFor(map, { type: 'case', id: 'c1' })).toBe('jan')
	})
	it('returns empty string for own work', () => {
		expect(substitutedFor(map, { type: 'case', id: 'other' })).toBe('')
	})
})

describe('applySubstitutedFilter', () => {
	const items = [
		{ type: 'case', id: 'own' },
		{ type: 'case', id: 'sub' },
	]
	const map = { 'case:sub': 'jan' }

	it('keeps everything when showSubstituted is true', () => {
		expect(applySubstitutedFilter(items, map, true)).toHaveLength(2)
	})

	it('hides substituted items when showSubstituted is false', () => {
		const filtered = applySubstitutedFilter(items, map, false)
		expect(filtered).toHaveLength(1)
		expect(filtered[0].id).toBe('own')
	})
})
