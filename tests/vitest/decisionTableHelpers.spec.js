/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure helpers in src/utils/decisionTableHelpers.js
 * (dmn-decision-tables): the structural validator (valid + each invalid
 * shape), the rule-count alignment check, the summary string, and the JSON
 * parse guard. No network, no Vue.
 *
 * @spec openspec/specs/dmn-decision-tables/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	FIELD_TYPES,
	HIT_POLICIES,
	parseDefinitionJson,
	summariseTable,
	validateTableStructure,
} from '../../src/utils/decisionTableHelpers.js'

const validDefinition = {
	inputs: [
		{ name: 'income', type: 'number' },
		{ name: 'householdSize', type: 'number' },
	],
	outputs: [
		{ name: 'eligible', type: 'boolean' },
		{ name: 'tier', type: 'string' },
	],
	rules: [
		{
			id: 'r1',
			inputEntries: ['[0..25000]', '-'],
			outputEntries: [true, 'gold'],
		},
		{ id: 'r2', inputEntries: ['> 25000', '-'], outputEntries: [false, 'none'] },
	],
}

describe('validateTableStructure', () => {
	it('accepts a well-formed definition', () => {
		const result = validateTableStructure(validDefinition)
		expect(result.valid).toBe(true)
		expect(result.errors).toEqual([])
	})

	it('rejects a non-object', () => {
		expect(validateTableStructure(null).valid).toBe(false)
		expect(validateTableStructure([]).valid).toBe(false)
	})

	it('rejects non-array inputs/outputs/rules', () => {
		const result = validateTableStructure({ inputs: {}, outputs: {}, rules: {} })
		expect(result.valid).toBe(false)
		expect(result.errors).toContain('inputs must be an array')
		expect(result.errors).toContain('outputs must be an array')
		expect(result.errors).toContain('rules must be an array')
	})

	it('rejects a field without a name', () => {
		const def = { ...validDefinition, inputs: [{ type: 'number' }] }
		const result = validateTableStructure(def)
		expect(result.valid).toBe(false)
		expect(
			result.errors.some((e) => e.includes('requires a non-empty name')),
		).toBe(true)
	})

	it('rejects a field with an invalid type', () => {
		const def = { ...validDefinition, inputs: [{ name: 'x', type: 'bogus' }] }
		const result = validateTableStructure(def)
		expect(result.valid).toBe(false)
		expect(result.errors.some((e) => e.includes('invalid type'))).toBe(true)
	})

	it('rejects a rule whose inputEntries count does not match inputs', () => {
		const def = {
			...validDefinition,
			rules: [
				{
					id: 'r1',
					inputEntries: ['[0..1]'],
					outputEntries: [true, 'gold'],
				},
			],
		}
		const result = validateTableStructure(def)
		expect(result.valid).toBe(false)
		expect(result.errors.some((e) => e.includes('inputEntries'))).toBe(true)
	})

	it('rejects a rule whose outputEntries count does not match outputs', () => {
		const def = {
			...validDefinition,
			rules: [
				{ id: 'r1', inputEntries: ['[0..1]', '-'], outputEntries: [true] },
			],
		}
		const result = validateTableStructure(def)
		expect(result.valid).toBe(false)
		expect(result.errors.some((e) => e.includes('outputEntries'))).toBe(true)
	})
})

describe('summariseTable', () => {
	it('summarises inputs/outputs/rules and hit policy', () => {
		const table = { hitPolicy: 'FIRST', ...validDefinition }
		expect(summariseTable(table)).toBe('FIRST · 2 inputs · 2 outputs · 2 rules')
	})

	it('defaults hit policy to UNIQUE and counts to zero for an empty table', () => {
		expect(summariseTable({})).toBe('UNIQUE · 0 inputs · 0 outputs · 0 rules')
	})
})

describe('parseDefinitionJson', () => {
	it('parses valid JSON', () => {
		const result = parseDefinitionJson('{"inputs":[],"outputs":[],"rules":[]}')
		expect(result.ok).toBe(true)
		expect(result.value).toEqual({ inputs: [], outputs: [], rules: [] })
	})

	it('reports an empty string', () => {
		expect(parseDefinitionJson('   ').ok).toBe(false)
	})

	it('reports invalid JSON', () => {
		const result = parseDefinitionJson('{not valid}')
		expect(result.ok).toBe(false)
		expect(result.error).toContain('Invalid JSON')
	})
})

describe('constants', () => {
	it('exposes the five DMN hit policies', () => {
		expect(HIT_POLICIES).toEqual([
			'UNIQUE',
			'FIRST',
			'PRIORITY',
			'ANY',
			'COLLECT',
		])
	})

	it('exposes the four field types', () => {
		expect(FIELD_TYPES).toEqual(['string', 'number', 'boolean', 'date'])
	})
})
