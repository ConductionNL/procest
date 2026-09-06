/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the email-template editor preview helpers
 * (case-email-integration T11): unresolved-variable detection + the
 * red/green HTML highlight that the live preview renders.
 *
 * @spec openspec/changes/case-email-integration/specs/case-email-integration/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	collectUnresolved,
	renderPreview,
} from '../../src/utils/emailTemplatePreview.js'

const KNOWN = ['zaakNummer', 'contactNaam', 'handler', 'startDate']

describe('collectUnresolved', () => {
	it('returns no unresolved names when all placeholders are known', () => {
		const text = 'Zaak {{zaakNummer}} voor {{contactNaam}}'
		expect(collectUnresolved(text, KNOWN)).toEqual([])
	})

	it('returns unknown placeholder names', () => {
		const text =
			'Hallo {{contactNaam}}, veld {{nonExistentField}} en {{onbekend}}'
		const result = collectUnresolved(text, KNOWN)
		expect(result).toContain('onbekend')
		expect(result).toContain('nonExistentField')
		expect(result).not.toContain('contactNaam')
	})

	it('de-duplicates repeated unresolved names', () => {
		const text = '{{foo}} en nog eens {{foo}}'
		expect(collectUnresolved(text, KNOWN)).toEqual(['foo'])
	})

	it('handles empty / nullish input safely', () => {
		expect(collectUnresolved('', KNOWN)).toEqual([])
		expect(collectUnresolved(null, KNOWN)).toEqual([])
	})
})

describe('renderPreview', () => {
	it('wraps known variables in an ok span', () => {
		const html = renderPreview('Beste {{contactNaam}}', KNOWN)
		expect(html).toContain('<span class="etpl-var-ok">{{contactNaam}}</span>')
	})

	it('wraps unresolved variables in a bad span', () => {
		const html = renderPreview('Veld {{onbekend}}', KNOWN)
		expect(html).toContain('<span class="etpl-var-bad">{{onbekend}}</span>')
	})

	it('escapes HTML in the body so raw markup cannot break out', () => {
		const html = renderPreview('<script>alert(1)</script>', KNOWN)
		expect(html).not.toContain('<script>')
		expect(html).toContain('&lt;script&gt;')
	})

	it('converts newlines to <br>', () => {
		const html = renderPreview('regel 1\nregel 2', KNOWN)
		expect(html).toContain('regel 1<br>regel 2')
	})
})
