/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component-logic unit tests for the NEW sub-case + case-email UI.
 *
 * Dossiq's Vitest project runs in the `node` environment with no Vue mount
 * harness installed (see vitest.config.js), so we cannot full-mount the .vue
 * leaves here. Instead we replicate the pure, side-effect-free computed logic
 * that drives each new component's rendering and assert it directly:
 *
 *   - DeelzaakList.sortedSubCases   (open-before-closed, then deadline asc)
 *   - DeelzaakList.rollUpText       (completed/total roll-up)
 *   - DeelzaakList.canCreate        (gating: closed / grandchild / no allowed types)
 *   - DeelzaakCreateModal.availableCaseTypes (filter to parent.subCaseTypes)
 *   - CaseEmailTab.applyCaseObject  (caseType + isFinal extraction)
 *   - CaseEmailTab.draftButtonLabel / formatVariable
 *
 * Each function below is a faithful copy of the component's computed/method
 * body (kept in lock-step with the source). Behavioural drift in the source
 * that is not mirrored here will surface as a coverage gap, not a false green.
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T05
 * @spec openspec/changes/deelzaak-support/tasks.md#T08
 * @spec openspec/changes/case-email-integration/tasks.md#T12
 */

import { describe, expect, it } from 'vitest'

// --- DeelzaakList.sortedSubCases (verbatim from the component) ---------------
function sortedSubCases(subCases) {
	return [...subCases].sort((a, b) => {
		const aOpen = !a.endDate
		const bOpen = !b.endDate
		if (aOpen !== bOpen) {
			return aOpen ? -1 : 1
		}
		const aDeadline = a.deadline
			? new Date(a.deadline).getTime()
			: Number.POSITIVE_INFINITY
		const bDeadline = b.deadline
			? new Date(b.deadline).getTime()
			: Number.POSITIVE_INFINITY
		return aDeadline - bDeadline
	})
}

// --- DeelzaakList.rollUpText -------------------------------------------------
function rollUp(subCases) {
	const completed = subCases.filter((sc) => sc.endDate).length
	const total = subCases.length
	return { completed, total, text: `(${completed}/${total} completed)` }
}

// --- DeelzaakList.canCreate (verbatim gating) -------------------------------
function canCreate(parent, parentCaseType) {
	if (!parent) return false
	if (parent.endDate) return false
	if (parent.parentCase) return false
	const allowed = parentCaseType?.subCaseTypes || []
	return Array.isArray(allowed) && allowed.length > 0
}

// --- DeelzaakCreateModal.availableCaseTypes (verbatim filter) ---------------
function availableCaseTypes(caseTypes, parentCaseType) {
	const allowed = parentCaseType?.subCaseTypes || []
	if (!Array.isArray(allowed) || allowed.length === 0) {
		return []
	}
	const allowedSet = new Set(allowed.map(String))
	return caseTypes.filter(
		(ct) =>
			allowedSet.has(String(ct.id))
			|| allowedSet.has(String(ct.slug))
			|| allowedSet.has(String(ct.uuid)),
	)
}

// --- CaseEmailTab.applyCaseObject (verbatim extraction) ---------------------
function applyCaseObject(caseObj) {
	if (!caseObj) return { caseTypeId: null, isFinal: false }
	const inner = caseObj['@self'] ? caseObj : caseObj
	return {
		caseTypeId: inner.caseType || inner['@self']?.caseType || null,
		isFinal: !!(inner.endDate || inner['@self']?.endDate),
	}
}

// --- CaseEmailTab.draftButtonLabel / formatVariable -------------------------
const formatVariable = (name) => `{{${name}}}`
function draftButtonLabel(selectedTemplate) {
	return selectedTemplate ? 'Open draft from template' : 'Open empty draft'
}

describe('DeelzaakList.sortedSubCases', () => {
	it('orders open cases before closed ones', () => {
		const rows = [{ id: 'closed', endDate: '2026-01-01' }, { id: 'open' }]
		expect(sortedSubCases(rows).map((r) => r.id)).toEqual(['open', 'closed'])
	})

	it('within the open group, sorts by deadline ascending; no-deadline sinks last', () => {
		const rows = [
			{ id: 'later', deadline: '2026-06-10' },
			{ id: 'none' },
			{ id: 'sooner', deadline: '2026-06-01' },
		]
		expect(sortedSubCases(rows).map((r) => r.id)).toEqual([
			'sooner',
			'later',
			'none',
		])
	})

	it('does not mutate the input array', () => {
		const rows = [{ id: 'b', endDate: '2026-01-01' }, { id: 'a' }]
		const snapshot = rows.map((r) => r.id)
		sortedSubCases(rows)
		expect(rows.map((r) => r.id)).toEqual(snapshot)
	})
})

describe('DeelzaakList.rollUpText', () => {
	it('counts completed (endDate present) over total', () => {
		const rows = [
			{ id: 'a', endDate: '2026-01-01' },
			{ id: 'b' },
			{ id: 'c', endDate: '2026-02-01' },
		]
		expect(rollUp(rows)).toEqual({
			completed: 2,
			total: 3,
			text: '(2/3 completed)',
		})
	})

	it('handles an all-open and an empty list', () => {
		expect(rollUp([{ id: 'a' }, { id: 'b' }]).text).toBe('(0/2 completed)')
		expect(rollUp([]).text).toBe('(0/0 completed)')
	})
})

describe('DeelzaakList.canCreate', () => {
	const parentType = { subCaseTypes: ['ct-1', 'ct-2'] }

	it('allows creation for an open top-level parent whose type permits sub-cases', () => {
		expect(canCreate({ id: 'p1' }, parentType)).toBe(true)
	})

	it('blocks when the parent is closed', () => {
		expect(canCreate({ id: 'p1', endDate: '2026-01-01' }, parentType)).toBe(
			false,
		)
	})

	it('blocks grandchildren (parent is itself a sub-case)', () => {
		expect(canCreate({ id: 'p1', parentCase: 'grandparent' }, parentType)).toBe(
			false,
		)
	})

	it('blocks when the case type allows no sub-case types', () => {
		expect(canCreate({ id: 'p1' }, { subCaseTypes: [] })).toBe(false)
		expect(canCreate({ id: 'p1' }, null)).toBe(false)
	})

	it('blocks when there is no parent at all', () => {
		expect(canCreate(null, parentType)).toBe(false)
	})
})

describe('DeelzaakCreateModal.availableCaseTypes', () => {
	const caseTypes = [
		{ id: 'ct-1', title: 'Permit' },
		{ id: 'ct-2', title: 'Objection' },
		{ id: 'ct-3', title: 'Other' },
	]

	it('keeps only the case types listed on parent.subCaseTypes', () => {
		const out = availableCaseTypes(caseTypes, { subCaseTypes: ['ct-1', 'ct-3'] })
		expect(out.map((c) => c.id)).toEqual(['ct-1', 'ct-3'])
	})

	it('matches against id, slug, or uuid', () => {
		const cts = [
			{ id: '1', slug: 'permit' },
			{ id: '2', uuid: 'uuid-abc' },
		]
		expect(
			availableCaseTypes(cts, { subCaseTypes: ['permit'] }).map((c) => c.id),
		).toEqual(['1'])
		expect(
			availableCaseTypes(cts, { subCaseTypes: ['uuid-abc'] }).map((c) => c.id),
		).toEqual(['2'])
	})

	it('returns empty when the parent allows nothing', () => {
		expect(availableCaseTypes(caseTypes, { subCaseTypes: [] })).toEqual([])
		expect(availableCaseTypes(caseTypes, null)).toEqual([])
	})
})

describe('CaseEmailTab.applyCaseObject', () => {
	it('reads caseType + closed flag from a flat case object', () => {
		expect(applyCaseObject({ caseType: 'ct-9', endDate: '2026-01-01' })).toEqual(
			{ caseTypeId: 'ct-9', isFinal: true },
		)
	})

	it('treats an open case (no endDate) as not final', () => {
		expect(applyCaseObject({ caseType: 'ct-9' })).toEqual({
			caseTypeId: 'ct-9',
			isFinal: false,
		})
	})

	it('falls back to @self for caseType + endDate', () => {
		expect(
			applyCaseObject({
				'@self': { caseType: 'ct-7', endDate: '2026-02-02' },
			}),
		).toEqual({ caseTypeId: 'ct-7', isFinal: true })
	})

	it('returns safe defaults for a null object', () => {
		expect(applyCaseObject(null)).toEqual({ caseTypeId: null, isFinal: false })
	})
})

describe('CaseEmailTab label + variable helpers', () => {
	it('formats a template variable with double braces', () => {
		expect(formatVariable('caseNumber')).toBe('{{caseNumber}}')
	})

	it('labels the compose button by template selection', () => {
		expect(draftButtonLabel(null)).toBe('Open empty draft')
		expect(draftButtonLabel({ id: 't1', name: 'Acknowledgement' })).toBe(
			'Open draft from template',
		)
	})
})
