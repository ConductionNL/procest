// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression tests for procest#784 — the admin settings page's 41-50 MB DOM.
 *
 * MEASURED CAUSE, so the assertions below are not arbitrary. `ChecklistsTab`
 * fetched `/apps/dossiq/api/objects/inspectionChecklist`, a route dossiq does
 * not serve (its auto-exposed `/api/objects/<register>/<schema>` routes were
 * deleted; OpenRegister serves them). Nextcloud answers an unmatched app URL
 * with its own HTML page under **HTTP 200, `text/html`** — so axios did not
 * throw and `response.data` was a 45,031-character STRING. The old assignment
 *
 *     this.checklists = response.data?.results || response.data || []
 *
 * admits that string (a string has no `.results`, and a non-empty string is
 * truthy), and Vue's `v-for` over a string **iterates one item per character**.
 * Live measurement of the rendered page: 45,031 rows, 90,122 buttons, 632,482
 * elements, 50,372,821 bytes of HTML — 99.7 % of it inside this one section.
 * Playwright's accessible-name computation on that DOM ran 804,859 ms without
 * returning.
 *
 * These tests pin the two properties that jointly make that impossible:
 *   1. a non-array, non-`{results:[]}` body renders ZERO rows;
 *   2. the component addresses OpenRegister's route, not dossiq's dead one.
 *
 * TRUE-POSITIVE CONTROL, actually run rather than predicted: with the component
 * stashed back to its pre-fix state, **4 of these 6 tests fail** —
 *
 *   renders ZERO rows … HTML page   → expected 0, got 4104
 *   does not render one button …    → expected <=1, got 4104
 *   renders ZERO rows … not a collection envelope → expected 0, got 1
 *   addresses OpenRegister's route  → got '/index.php/apps/dossiq/api/objects/…'
 *
 * 4104 is the exact character length of `HTML_ERROR_PAGE` below: one row per
 * character, reproduced in a unit test. (The live page's 45,031 rows are the
 * same defect against Nextcloud's real 45,031-byte error page — this fixture is
 * deliberately smaller so the suite stays fast.)
 *
 * @spec openspec/changes/vth-module/tasks.md#task-5
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

// `@nextcloud/dialogs` reaches for a live Nextcloud runtime (toast container,
// CSS side effects) at import time and has nothing to do with what is asserted
// here — the component's response handling and the URL it addresses.
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

// Stub only the presentational children. `NcButton` is kept as a REAL <button>
// element rather than a bare div: the defect's signature is button count, and a
// stub that rendered no button would hide exactly the thing under test.
function stub(tag) {
	return {
		name: 'Stub' + tag,
		render() {
			return h(tag, this.$slots.default ? this.$slots.default() : [])
		},
	}
}
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		render() {
			return h('button', {}, this.$slots.default ? this.$slots.default() : [])
		},
	},
	NcLoadingIcon: stub('span'),
	NcEmptyContent: stub('div'),
	NcIconSvgWrapper: stub('span'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnConfirmDialog: stub('div'),
}))
vi.mock('../../src/components/InspectionChecklistEditor.vue', () => ({
	default: stub('div'),
}))

const ChecklistsTab = (
	await import('../../src/views/settings/tabs/ChecklistsTab.vue')
).default

/** A realistic stand-in for what Nextcloud actually returned: an HTML page. */
const HTML_ERROR_PAGE =
	'<!DOCTYPE html>\n<html class="ng-csp" lang="en">'
	+ '<head><title>Nextcloud</title></head><body>'
	+ 'x'.repeat(4000)
	+ '</body></html>'

/**
 * Mount the tab with a stubbed GET response body.
 *
 * @param {*} data The body axios should resolve with.
 * @return {Promise<object>} The mounted wrapper, after promises have flushed.
 */
async function mountWith(data) {
	axios.get.mockResolvedValue({ data })
	const wrapper = mount(ChecklistsTab)
	await flushPromises()
	return wrapper
}

describe('ChecklistsTab — response-shape handling (procest#784)', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('renders ZERO rows when the endpoint returns an HTML page instead of JSON', async () => {
		const wrapper = await mountWith(HTML_ERROR_PAGE)

		// The pre-fix component rendered one row per CHARACTER of this string.
		expect(wrapper.findAll('.checklists-tab__item')).toHaveLength(0)
	})

	it('does not render one button per character of a string body', async () => {
		const wrapper = await mountWith(HTML_ERROR_PAGE)

		// Only the header's "New checklist" control may remain. The defect
		// produced 2 buttons per character — 90,122 in the measured page.
		expect(wrapper.findAll('button').length).toBeLessThanOrEqual(1)
	})

	it('renders one row per checklist for a well-formed array body', async () => {
		const wrapper = await mountWith([
			{ id: 'a', name: 'Bouw', active: true, version: 2, items: [{}, {}] },
			{ id: 'b', name: 'Milieu', active: false, version: 1, items: [] },
		])

		const rows = wrapper.findAll('.checklists-tab__item')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('Bouw')
		expect(rows[1].text()).toContain('Milieu')
	})

	it('unwraps the paginated `{ results: [...] }` envelope', async () => {
		const wrapper = await mountWith({
			results: [{ id: 'a', name: 'Bouw', items: [] }],
			total: 1,
		})

		expect(wrapper.findAll('.checklists-tab__item')).toHaveLength(1)
	})

	it('renders ZERO rows for an object body that is not a collection envelope', async () => {
		// e.g. OpenRegister's `{"message":"Register not found: 'dossiq'"}`.
		const wrapper = await mountWith({ message: "Register not found: 'dossiq'" })

		expect(wrapper.findAll('.checklists-tab__item')).toHaveLength(0)
	})

	it('addresses the admin-guarded InspectionChecklistController route', async () => {
		await mountWith([])

		expect(axios.get).toHaveBeenCalledTimes(1)
		const url = axios.get.mock.calls[0][0]
		// `/api/vth/checklists` is served by InspectionChecklistController, whose
		// every verb carries #[AuthorizedAdminSetting]. Going straight to
		// OpenRegister's generic object route would work mechanically but would
		// bypass that guard and add a second write path — see the note on
		// COLLECTION_URL.
		expect(url).toContain('/apps/dossiq/api/vth/checklists')
		// The dead route is what made Nextcloud serve HTML under HTTP 200.
		expect(url).not.toContain('/api/objects/')
	})
})
