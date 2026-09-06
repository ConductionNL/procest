/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the ISO 8601 duration helpers in src/utils/durationHelpers.js
 * (validation, component parsing, human-readable formatting, error text).
 *
 * @nextcloud/l10n is aliased to a deterministic stub that returns the English
 * source string with {placeholder} substitution, so formatted output is
 * exactly assertable.
 */

import { describe, expect, it } from 'vitest'
import {
	formatDuration,
	getDurationError,
	isValidDuration,
	parseDuration,
} from '../../src/utils/durationHelpers.js'

describe('isValidDuration', () => {
	it('accepts well-formed ISO 8601 durations', () => {
		expect(isValidDuration('P56D')).toBe(true)
		expect(isValidDuration('P8W')).toBe(true)
		expect(isValidDuration('P2M')).toBe(true)
		expect(isValidDuration('P1Y6M')).toBe(true)
	})

	it('rejects empty, bare "P", non-strings, and malformed input', () => {
		expect(isValidDuration('')).toBe(false)
		expect(isValidDuration('P')).toBe(false)
		expect(isValidDuration(null)).toBe(false)
		expect(isValidDuration(56)).toBe(false)
		expect(isValidDuration('56D')).toBe(false)
		expect(isValidDuration('P1H')).toBe(false) // hours not supported
	})
})

describe('parseDuration', () => {
	it('splits into year/month/week/day components', () => {
		expect(parseDuration('P1Y6M')).toEqual({
			years: 1,
			months: 6,
			weeks: 0,
			days: 0,
		})
		expect(parseDuration('P56D')).toEqual({
			years: 0,
			months: 0,
			weeks: 0,
			days: 56,
		})
		expect(parseDuration('P8W')).toEqual({
			years: 0,
			months: 0,
			weeks: 8,
			days: 0,
		})
	})

	it('returns null for invalid input', () => {
		expect(parseDuration('nope')).toBeNull()
		expect(parseDuration('P')).toBeNull()
	})
})

describe('formatDuration', () => {
	it('formats singular and plural components (via l10n stub)', () => {
		expect(formatDuration('P1Y')).toBe('1 year')
		expect(formatDuration('P2Y')).toBe('2 years')
		expect(formatDuration('P1M')).toBe('1 month')
		expect(formatDuration('P3M')).toBe('3 months')
		expect(formatDuration('P1W')).toBe('1 week')
		expect(formatDuration('P1D')).toBe('1 day')
		expect(formatDuration('P56D')).toBe('56 days')
	})

	it('joins multiple components with commas in Y/M/W/D order', () => {
		expect(formatDuration('P1Y6M')).toBe('1 year, 6 months')
		expect(formatDuration('P2M15D')).toBe('2 months, 15 days')
	})

	it('returns the raw input for unparseable strings', () => {
		expect(formatDuration('garbage')).toBe('garbage')
		expect(formatDuration('')).toBe('')
	})
})

describe('getDurationError', () => {
	it('returns empty string for valid or empty values', () => {
		expect(getDurationError('')).toBe('')
		expect(getDurationError('P56D')).toBe('')
	})

	it('returns a guidance message for an invalid value', () => {
		expect(getDurationError('56 days')).toMatch(/valid ISO 8601 duration/)
	})
})
