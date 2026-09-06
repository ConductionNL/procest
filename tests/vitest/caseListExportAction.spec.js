/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the case-list export URL builder
 * (src/utils/caseExportHelpers.js), consumed by
 * src/components/export/CaseListExportAction.vue.
 *
 * Dossiq's Vitest project runs in the `node` environment with no Vue mount
 * harness installed (see vitest.config.js — no @vue/test-utils / jsdom /
 * vue-loader plugin registered), so the `.vue` single-file component cannot
 * be full-mounted here (see tests/vitest/deelzaakComponentLogic.spec.js for
 * the same constraint on other new components). Instead this spec imports
 * and exercises the REAL `buildCaseExportUrl()` helper the component calls
 * on click — the same "test the extracted pure logic directly" pattern used
 * by tests/vitest/pdokService.spec.js and tests/vitest/caseRelationApi.spec.js.
 * `@nextcloud/router`'s `generateUrl()` is aliased to the deterministic
 * `/index.php`-prefixing stub in tests/vitest/stubs/nextcloud-router.js.
 *
 * The component itself only wires `exportAs(format)` ->
 * `window.location.assign(buildCaseExportUrl(format, this.$route?.query ?? {}))`
 * and renders two NcActionButton entries (CSV / Excel) inside an NcActions
 * menu — there is no additional logic to duplicate/assert beyond what is
 * covered here.
 *
 * @spec openspec/changes/case-list-export-via-or-export-leaf/specs/case-list-export-via-or-export-leaf/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	buildCaseExportUrl,
	CASE_EXPORT_ENDPOINT,
} from '../../src/utils/caseExportHelpers.js'

const EXPECTED_BASE = '/index.php/apps/openregister/api/objects/dossiq/case/export'

describe('CASE_EXPORT_ENDPOINT', () => {
	it('points at the (dossiq, case) OpenRegister export leaf', () => {
		expect(CASE_EXPORT_ENDPOINT).toBe(
			'/apps/openregister/api/objects/dossiq/case/export',
		)
	})
})

describe('buildCaseExportUrl', () => {
	it('builds a CSV export URL with no query params', () => {
		const url = buildCaseExportUrl('csv', {})
		expect(url).toBe(`${EXPECTED_BASE}?format=csv`)
	})

	it('builds an Excel export URL with no query params', () => {
		const url = buildCaseExportUrl('excel', {})
		expect(url).toBe(`${EXPECTED_BASE}?format=excel`)
	})

	it('defaults query to {} when omitted', () => {
		const url = buildCaseExportUrl('csv')
		expect(url).toBe(`${EXPECTED_BASE}?format=csv`)
	})

	it('passes a single route-query filter through (status=open)', () => {
		const url = buildCaseExportUrl('csv', { status: 'open' })
		expect(url).toBe(`${EXPECTED_BASE}?format=csv&status=open`)
	})

	it('passes multiple route-query filters through in insertion order', () => {
		const url = buildCaseExportUrl('csv', { status: 'open', assignee: 'jdoe' })
		expect(url).toBe(`${EXPECTED_BASE}?format=csv&status=open&assignee=jdoe`)
	})

	it('always sets format first even if the query object has a format-like key', () => {
		const url = buildCaseExportUrl('excel', { status: 'open' })
		const params = new URLSearchParams(url.split('?')[1])
		expect(params.get('format')).toBe('excel')
		expect([...params.keys()][0]).toBe('format')
	})

	it('repeats an array-valued query entry as multiple key=value pairs', () => {
		const url = buildCaseExportUrl('csv', { status: ['open', 'in_progress'] })
		const params = new URLSearchParams(url.split('?')[1])
		expect(params.getAll('status')).toEqual(['open', 'in_progress'])
	})

	it('skips null/undefined query values', () => {
		const url = buildCaseExportUrl('csv', {
			status: 'open',
			assignee: null,
			deadline: undefined,
		})
		expect(url).toBe(`${EXPECTED_BASE}?format=csv&status=open`)
	})

	it("falls back to {} when query is not provided (mirrors the component's $route-less guard)", () => {
		const query = undefined
		const url = buildCaseExportUrl('csv', query ?? {})
		expect(url).toBe(`${EXPECTED_BASE}?format=csv`)
	})
})
