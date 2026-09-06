/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure presentation helpers in src/services/caseRelationApi.js
 * (relation-type labels, guard-reason → message mapping, type list). The
 * network functions are not exercised here (they need axios + a live route);
 * these tests pin the user-facing string mapping that the section/modal rely on.
 *
 * The global `t()` (NC translation) is stubbed to return the English source
 * string so output is deterministically assertable.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */

import { beforeAll, describe, expect, it } from 'vitest'

beforeAll(() => {
	globalThis.t = (app, text) => text
})

const importApi = async () => await import('../../src/utils/caseRelationHelpers.js')

describe('AARD_RELATIE_TYPES', () => {
	it('lists the three RGBZ/ZRC relation types', async () => {
		const { AARD_RELATIE_TYPES } = await importApi()
		expect(AARD_RELATIE_TYPES).toEqual(['vervolg', 'subject', 'bijdrage'])
	})
})

describe('relationTypeLabel', () => {
	it('maps each known type to a label', async () => {
		const { relationTypeLabel } = await importApi()
		expect(relationTypeLabel('vervolg')).toBe('Follow-up')
		expect(relationTypeLabel('subject')).toBe('Subject')
		expect(relationTypeLabel('bijdrage')).toBe('Contribution')
	})

	it('falls back to the raw value for an unknown type', async () => {
		const { relationTypeLabel } = await importApi()
		expect(relationTypeLabel('mystery')).toBe('mystery')
	})
})

describe('relationErrorMessage', () => {
	it('maps each guard reason to a distinct message', async () => {
		const { relationErrorMessage } = await importApi()
		expect(relationErrorMessage('self_relation')).toMatch(/itself/i)
		expect(relationErrorMessage('duplicate')).toMatch(/already exists/i)
		expect(relationErrorMessage('hierarchy_overlap')).toMatch(/hierarchy/i)
		expect(relationErrorMessage('access_denied')).toMatch(/access/i)
		expect(relationErrorMessage('invalid_aard_relatie')).toMatch(
			/valid relation type/i,
		)
	})

	it('falls back to a generic message for an unknown reason', async () => {
		const { relationErrorMessage } = await importApi()
		expect(relationErrorMessage('whatever')).toMatch(/could not save/i)
	})
})
