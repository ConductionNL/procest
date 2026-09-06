/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the KCC-werkplek sentiment trigger-word (de)serialisation
 * helpers in src/utils/kccTriggerWords.js. Covers the JSON-string <-> textarea
 * round-trip, defensive handling of malformed input, trimming, blank-line
 * dropping, and de-duplication.
 */

import { describe, expect, it } from 'vitest'
import {
	textToTriggerWords,
	triggerWordsToText,
} from '../../src/utils/kccTriggerWords.js'

describe('triggerWordsToText', () => {
	it('renders a JSON array as newline-separated words', () => {
		expect(triggerWordsToText('["klacht","advocaat","media"]')).toBe(
			'klacht\nadvocaat\nmedia',
		)
	})

	it('returns empty string for non-array JSON', () => {
		expect(triggerWordsToText('{"a":1}')).toBe('')
	})

	it('returns empty string for malformed JSON', () => {
		expect(triggerWordsToText('not json')).toBe('')
		expect(triggerWordsToText('')).toBe('')
	})

	it('trims and drops blank entries', () => {
		expect(triggerWordsToText('["  klacht  ","",  "advocaat"]')).toBe(
			'klacht\nadvocaat',
		)
	})
})

describe('textToTriggerWords', () => {
	it('serialises newline-separated text into a JSON array', () => {
		expect(textToTriggerWords('klacht\nadvocaat\nmedia')).toBe(
			'["klacht","advocaat","media"]',
		)
	})

	it('trims lines and drops blank lines', () => {
		expect(textToTriggerWords('  klacht  \n\n advocaat \n')).toBe(
			'["klacht","advocaat"]',
		)
	})

	it('de-duplicates while preserving first-seen order', () => {
		expect(textToTriggerWords('klacht\nadvocaat\nklacht')).toBe(
			'["klacht","advocaat"]',
		)
	})

	it('handles empty and null input', () => {
		expect(textToTriggerWords('')).toBe('[]')
		expect(textToTriggerWords(null)).toBe('[]')
	})
})

describe('round-trip', () => {
	it('is lossless for a clean word list', () => {
		const json = '["klacht","advocaat","wethouder"]'
		expect(textToTriggerWords(triggerWordsToText(json))).toBe(json)
	})
})
