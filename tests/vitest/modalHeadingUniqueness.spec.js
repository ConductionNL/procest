/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Structural guard: a modal must not repeat its own dialog title as a
 * heading inside its body.
 *
 * `NcModal`'s `name` prop already renders `<h2 class="modal-header__name">`
 * and wires it as the dialog's accessible name. A second `<h2>` in the body
 * carrying the same string is a real WCAG defect — a screen-reader user is
 * told the dialog's name, then immediately hears the identical heading again
 * — and it also makes every `getByRole('heading', { name })` query ambiguous.
 *
 * That second effect is how this surfaced. `InzageExportModal` rendered the
 * title twice, so the AVG e2e spec's
 * `getByRole('heading', { name: 'Data subject access export' })` resolved to
 * TWO elements and Playwright failed it under strict mode:
 *
 *   strict mode violation: ... resolved to 2 elements:
 *     1) <h2 class="modal-header__name">Data subject access export</h2>
 *     2) <h2 data-v-3de9aa6e="">Data subject access export</h2>
 *
 * It presented as an intermittent failure rather than a constant one because
 * the two headings mount at slightly different moments: an assertion that
 * resolves while only the header exists passes, and one that resolves after
 * the body slot renders throws. That is why the same commit could go green on
 * one PR and red on another.
 *
 * Dossiq's Vitest project runs in the `node` environment with no Vue mount
 * harness (see vitest.config.js — no @vue/test-utils / jsdom / vue-loader),
 * so `.vue` SFCs cannot be mounted here. This spec therefore asserts the
 * invariant against the component SOURCE, which is the level the defect
 * actually lives at: a literal duplicated between the `name` prop and a body
 * heading. It covers every modal at once, so a newly added one is guarded on
 * the day it lands rather than whenever someone writes an e2e test for it.
 */

import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const MODAL_DIR = join(__dirname, '..', '..', 'src', 'modals')

/**
 * Pull the translated literal out of `:name="t('dossiq', '...')"`.
 *
 * @param {string} source Component source.
 * @return {string|null} The dialog title, or null when the modal does not set one.
 */
function dialogTitle(source) {
	const m = source.match(/:name="t\('dossiq',\s*'((?:[^'\\]|\\.)+)'\)"/)
	return m ? m[1] : null
}

/**
 * Collect every heading literal rendered in the component body.
 *
 * @param {string} source Component source.
 * @return {string[]} Heading literals.
 */
function bodyHeadings(source) {
	return [
		...source.matchAll(
			/<h[1-6][^>]*>\s*\{\{\s*t\('dossiq',\s*'((?:[^'\\]|\\.)+)'\)\s*\}\}\s*<\/h[1-6]>/g,
		),
	].map((m) => m[1])
}

describe('modal dialog headings are not duplicated in the body', () => {
	const files = readdirSync(MODAL_DIR).filter((f) => f.endsWith('.vue'))

	it('finds modal components to check (guards against an empty scope passing vacuously)', () => {
		expect(files.length).toBeGreaterThan(0)
	})

	it.each(files)(
		'%s does not repeat its NcModal name as a body heading',
		(file) => {
			const source = readFileSync(join(MODAL_DIR, file), 'utf8')
			const title = dialogTitle(source)

			if (title === null) {
				// Modal sets no `name` — nothing to duplicate.
				return
			}

			expect(bodyHeadings(source)).not.toContain(title)
		},
	)
})
