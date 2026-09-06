/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure presentation helpers in
 * src/utils/redactionAssistHelpers.js (woo-llm-anonymisation): span preview
 * text, initial checkbox selection state, selected-span filtering, and the
 * rule-span-is-never-toggleable UI mirror of the backend rules-floor
 * invariant.
 *
 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-4-1
 */

import { describe, expect, it } from 'vitest'
import {
	buildInitialSelections,
	buildSpanPreview,
	filterSelectedSpans,
	isSpanToggleable,
} from '../../src/utils/redactionAssistHelpers.js'

describe('buildSpanPreview', () => {
	it('builds an ellipsis-bounded snippet around the span offsets', () => {
		const text = 'Klant BSN: 123456782 heeft een aanvraag ingediend.'
		const preview = buildSpanPreview(text, { start: 11, end: 20 })
		expect(preview.startsWith('…')).toBe(true)
		expect(preview.endsWith('…')).toBe(true)
		expect(preview).toContain('123456782')
	})

	it('clamps offsets into range rather than throwing on out-of-bounds spans', () => {
		expect(() =>
			buildSpanPreview('short', { start: -50, end: 999 }),
		).not.toThrow()
		const preview = buildSpanPreview('short', { start: -50, end: 999 })
		expect(preview).toBe('…short…')
	})

	it('handles a missing/non-string text gracefully', () => {
		expect(buildSpanPreview(undefined, { start: 0, end: 5 })).toBe('……')
		expect(buildSpanPreview(null, { start: 0, end: 5 })).toBe('……')
	})
})

describe('buildInitialSelections', () => {
	it('selects every span by default, rule and llm alike', () => {
		const spans = [
			{ source: 'rule', category: 'bsn' },
			{ source: 'llm', category: 'person' },
		]
		expect(buildInitialSelections(spans)).toEqual({ 0: true, 1: true })
	})

	it('returns an empty map for an empty/missing spans array', () => {
		expect(buildInitialSelections([])).toEqual({})
		expect(buildInitialSelections(undefined)).toEqual({})
	})
})

describe('filterSelectedSpans', () => {
	it('keeps only spans marked selected', () => {
		const spans = [
			{ category: 'bsn' },
			{ category: 'person' },
			{ category: 'phone' },
		]
		const selections = { 0: true, 1: false, 2: true }
		const result = filterSelectedSpans(spans, selections)
		expect(result).toEqual([{ category: 'bsn' }, { category: 'phone' }])
	})

	it('returns an empty array when nothing is selected', () => {
		const spans = [{ category: 'bsn' }]
		expect(filterSelectedSpans(spans, { 0: false })).toEqual([])
	})

	it('treats an unset selection as unselected', () => {
		const spans = [{ category: 'bsn' }, { category: 'person' }]
		expect(filterSelectedSpans(spans, { 0: true })).toEqual([
			{ category: 'bsn' },
		])
	})
})

describe('isSpanToggleable', () => {
	it('rule spans are NEVER toggleable — mirrors the backend rules-floor invariant', () => {
		expect(isSpanToggleable({ source: 'rule' })).toBe(false)
	})

	it('llm spans are toggleable', () => {
		expect(isSpanToggleable({ source: 'llm' })).toBe(true)
	})

	it('an unknown/missing source defaults to toggleable (fail-open on display only)', () => {
		expect(isSpanToggleable({})).toBe(true)
	})
})
