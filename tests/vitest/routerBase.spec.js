// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * routerBase() — the history base comes from the path the document was
 * ACTUALLY served under, so a hard load of EITHER Nextcloud URL form
 * (`/index.php/apps/dossiq/...` and `/apps/dossiq/...`) deep-links correctly.
 *
 * The defect this pins down: the router base used to be generateUrl()'s
 * preferred form only, so a hard load of the other form fell outside the
 * base, matched the `/:pathMatch(.*)*` catch-all, and was redirected to the
 * dashboard — a deep link to a case answered 200 from the server and landed
 * on `/apps/dossiq/` client-side.
 *
 * @spec openspec/changes/case-flow-human-steps/specs/case-flow-human-steps/spec.md
 */
import { describe, expect, it } from 'vitest'
import { routerBase } from '../../src/utils/routerBase.js'

const FALLBACK = '/index.php/apps/dossiq'

describe('routerBase', () => {
	it('keeps the front-controller prefix when the page was served with one', () => {
		expect(routerBase('/index.php/apps/dossiq/cases/abc-123', FALLBACK)).toBe(
			'/index.php/apps/dossiq',
		)
	})

	it('drops the front-controller prefix when the page was served pretty', () => {
		// THE live defect: this form used to fall outside a
		// '/index.php/apps/dossiq' base and redirect to the dashboard.
		expect(routerBase('/apps/dossiq/cases/abc-123', FALLBACK)).toBe(
			'/apps/dossiq',
		)
	})

	it('handles the app root itself, with and without a trailing slash', () => {
		expect(routerBase('/apps/dossiq', FALLBACK)).toBe('/apps/dossiq')
		expect(routerBase('/apps/dossiq/', FALLBACK)).toBe('/apps/dossiq')
		expect(routerBase('/index.php/apps/dossiq', FALLBACK)).toBe(
			'/index.php/apps/dossiq',
		)
	})

	it('does not match another app whose id merely starts with ours', () => {
		expect(routerBase('/apps/dossiqx/cases/abc-123', FALLBACK)).toBe(FALLBACK)
	})

	it('falls back to the generated URL when the path has no app mount at all', () => {
		expect(routerBase('/', FALLBACK)).toBe(FALLBACK)
		expect(routerBase('', FALLBACK)).toBe(FALLBACK)
		expect(routerBase(undefined, FALLBACK)).toBe(FALLBACK)
	})
})
