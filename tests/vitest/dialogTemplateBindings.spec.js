// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * These three dialogs RENDER, with a realistic object, and the values reach
 * the screen.
 *
 * 🔴 WHY A FULL MOUNT AND NOT A SHALLOW ONE. A shallow mount that never
 * evaluates the template cannot see the defect this file exists for: a rename
 * that moved a name in the `<script>` and left the `<template>` reading the
 * old one. Vue resolves an unknown root identifier to `undefined` and says
 * nothing, so the page renders empty, or a button stays disabled forever, and
 * every unit test still passes. `mount()` compiles and evaluates the template,
 * which is the only place the mismatch exists.
 *
 * All three were found by a `vue/no-undef-properties` sweep of `src/**` and
 * all three are the same shape, a Dutch name replaced by an English one:
 *
 *  - `DsoCaseDetail` renamed its prop to `case` and its id computed to
 *    `zaakId`, while the template still read `zaak.*` and `caseId`. Every
 *    field in the dialog rendered blank and the three sub-dialogs were handed
 *    an empty id. `case` is a JS RESERVED WORD, so the template cannot name
 *    the prop at all: the `zaak` computed is the fix, not a nicety.
 *  - `SamenwerkverzoekDialog` renamed its field to
 *    `requestedCompetentAuthority` and left `aangezochtBevoegdGezag` in the
 *    submit button's `:disabled`, which pinned the button disabled: the
 *    dialog could not be submitted at all.
 *  - `BeschikkingComposerDialog` renamed its field to `rationale` and left the
 *    textarea writing to `motivering`, so typing a motivering did nothing and
 *    the composed decision went out without one.
 *
 * The `@nextcloud/vue` components are stubbed, for the reason
 * `workflowEditorSmoke.spec.js` gives at length: several chunks deep they pull
 * in the rich-text/reference-picker stack, which assumes a live Nextcloud
 * runtime and has nothing to do with a component's own template wiring. The
 * stubs still render their slots and still emit, so the bindings under test
 * are exercised, not skipped.
 *
 * @spec exclude regression guard for template/script name drift
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import BeschikkingComposerDialog from '../../src/dialogs/BeschikkingComposerDialog.vue'
import DsoCaseDetail from '../../src/dialogs/DsoCaseDetail.vue'
import SamenwerkverzoekDialog from '../../src/dialogs/SamenwerkverzoekDialog.vue'

// `BeschikkingComposerDialog` imports from the `@nextcloud/vue` BARREL, not
// from the per-component paths the other two use. Loading that barrel pulls
// the whole library through Vite's transform on a cold cache and blew the
// 5s test timeout, which reads as a failing assertion and is nothing of the
// kind. The barrel is replaced with the same stubs `stubs` below installs by
// name, so the component's own template is still compiled and evaluated.
vi.mock('@nextcloud/vue', async () => {
	const { h: create } = await import('vue')
	const box = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return create('div', { class: name }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	})
	const field = (name) => ({
		name,
		props: {
			modelValue: { type: [String, Number, Object], default: '' },
			disabled: { type: Boolean, default: false },
			label: { type: String, default: '' },
		},
		emits: ['update:modelValue'],
		render() {
			return create('button', {
				class: name,
				disabled: this.disabled,
				'data-value': String(this.modelValue ?? ''),
			})
		},
	})

	return {
		NcButton: {
			name: 'NcButton',
			props: { disabled: { type: Boolean, default: false } },
			emits: ['click'],
			render() {
				return create(
					'button',
					{
						class: 'NcButton',
						disabled: this.disabled,
						onClick: (e) => this.$emit('click', e),
					},
					this.$slots.default?.(),
				)
			},
		},
		NcDialog: box('NcDialog'),
		NcNoteCard: box('NcNoteCard'),
		NcSelect: field('NcSelect'),
		NcTextArea: field('NcTextArea'),
		NcTextField: field('NcTextField'),
	}
})

/**
 * A stub that renders its default slot, so slotted template content is still
 * compiled and evaluated.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function passthrough(name) {
	return {
		name,
		inheritAttrs: false,
		render() {
			return h('div', { class: name }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	}
}

/**
 * A stub for the form controls, which must keep their modelValue visible and
 * their disabled state assertable.
 *
 * @param {string} name The component name.
 * @return {object} The stub component.
 */
function control(name) {
	return {
		name,
		props: {
			modelValue: { type: [String, Number, Object], default: '' },
			disabled: { type: Boolean, default: false },
			label: { type: String, default: '' },
		},
		emits: ['update:modelValue'],
		render() {
			return h('button', {
				class: name,
				disabled: this.disabled,
				'data-value': String(this.modelValue ?? ''),
				'data-label': this.label,
				onClick: () => this.$emit('update:modelValue', 'typed'),
			})
		},
	}
}

const stubs = {
	NcDialog: passthrough('NcDialog'),
	NcButton: {
		name: 'NcButton',
		props: { disabled: { type: Boolean, default: false } },
		emits: ['click'],
		render() {
			return h(
				'button',
				{
					class: 'NcButton',
					disabled: this.disabled,
					onClick: (e) => this.$emit('click', e),
				},
				this.$slots.default?.(),
			)
		},
	},
	NcNoteCard: passthrough('NcNoteCard'),
	NcSelect: control('NcSelect'),
	NcTextField: control('NcTextField'),
	NcTextArea: control('NcTextArea'),
	BeschikkingDialog: {
		name: 'BeschikkingDialog',
		props: { zaakId: { type: String, default: '' } },
		render() {
			return h('div', { 'data-zaak-id': this.zaakId })
		},
	},
	DoorstuurDialog: {
		name: 'DoorstuurDialog',
		props: { zaakId: { type: String, default: '' } },
		render() {
			return h('div', { 'data-zaak-id': this.zaakId })
		},
	},
	SamenwerkverzoekDialog: {
		name: 'SamenwerkverzoekDialog',
		props: { zaakId: { type: String, default: '' } },
		render() {
			return h('div', { 'data-zaak-id': this.zaakId })
		},
	},
}

describe('DsoCaseDetail', () => {
	/**
	 * A realistic omgevingsvergunning case, of the shape the DSO list hands the
	 * dialog.
	 *
	 * @return {object} The case.
	 */
	const dsoCase = () => ({
		id: 42,
		uuid: 'c0ffee00-1111-2222-3333-444455556666',
		title: 'Dakkapel Molenweg 5',
		dsoStatus: 'in_handling',
		procedureType: 'regulier',
		deadlineDate: '2026-10-01T00:00:00+00:00',
		competentAuthority: 'Gemeente Amsterdam',
		permitApplicationRef: 'OLO-2026-0042',
		besluitdatum: '2026-09-20T00:00:00+00:00',
		dsoNotes: 'Vergunning verleend onder voorwaarden.',
		collaboration_requests: ['sw-1', 'sw-2'],
		activity: JSON.stringify([
			{
				timestamp: '2026-09-01T09:00:00+00:00',
				userId: 'alice',
				oldStatus: 'submitted',
				newStatus: 'in_handling',
			},
		]),
	})

	it('renders the case it was given, rather than a body of blanks', async () => {
		const wrapper = mount(DsoCaseDetail, {
			props: { case: dsoCase() },
			global: { stubs },
		})

		const text = wrapper.text()
		expect(text).toContain('Dakkapel Molenweg 5')
		expect(text).toContain('in_handling')
		expect(text).toContain('regulier')
		expect(text).toContain('Gemeente Amsterdam')
		expect(text).toContain('OLO-2026-0042')
	})

	it('shows the decision section, which only renders when the case has a besluitdatum', async () => {
		const wrapper = mount(DsoCaseDetail, {
			props: { case: dsoCase() },
			global: { stubs },
		})

		// `v-if="zaak.besluitdatum"` on an undefined `zaak` is falsy, so this
		// whole section used to be absent rather than empty.
		expect(wrapper.text()).toContain('Vergunning verleend onder voorwaarden.')
	})

	it('lists the collaboration requests instead of claiming there are none', async () => {
		const wrapper = mount(DsoCaseDetail, {
			props: { case: dsoCase() },
			global: { stubs },
		})

		expect(wrapper.text()).toContain('sw-1')
		expect(wrapper.text()).toContain('sw-2')
	})

	it('hands the sub-dialogs the case id, not an empty string', async () => {
		const wrapper = mount(DsoCaseDetail, {
			props: { case: dsoCase() },
			global: { stubs },
		})

		await wrapper.setData({ showBeschikkingDialog: true })

		const child = wrapper.findComponent({ name: 'BeschikkingDialog' })
		expect(child.exists()).toBe(true)
		expect(child.props('zaakId')).toBe('c0ffee00-1111-2222-3333-444455556666')
	})
})

describe('SamenwerkverzoekDialog', () => {
	it('enables Initiate once a competent authority is named', async () => {
		const wrapper = mount(SamenwerkverzoekDialog, {
			props: { caseId: 'case-1' },
			global: { stubs },
		})

		const initiate = () =>
			wrapper
				.findAll('button.NcButton')
				.find((b) => b.text().includes('Initiate'))

		// Nothing named yet: the guard is real, so the button starts disabled.
		expect(initiate().attributes('disabled')).toBeDefined()

		await wrapper.setData({ requestedCompetentAuthority: 'Rijkswaterstaat' })

		// The button used to read `aangezochtBevoegdGezag`, which no longer
		// existed, so it stayed disabled here and the dialog could never submit.
		expect(initiate().attributes('disabled')).toBeUndefined()
	})

	it('lets a suggested organisation fill the field it guards on', async () => {
		const wrapper = mount(SamenwerkverzoekDialog, {
			props: { caseId: 'case-1' },
			global: { stubs },
		})

		const suggestion = wrapper
			.findAll('button.NcButton')
			.find((b) => b.text() === 'Rijkswaterstaat')
		expect(suggestion).toBeTruthy()

		await suggestion.trigger('click')

		expect(wrapper.vm.requestedCompetentAuthority).toBe('Rijkswaterstaat')
	})
})

describe('BeschikkingComposerDialog', () => {
	it('writes the motivering into the field the composer actually submits', async () => {
		const wrapper = mount(BeschikkingComposerDialog, {
			props: { open: true, caseId: 'case-1', templateOptions: [] },
			global: { stubs },
		})

		const textarea = wrapper.findComponent({ name: 'NcTextArea' })
		expect(textarea.exists()).toBe(true)

		await textarea.vm.$emit(
			'update:modelValue',
			'Strijd met het bestemmingsplan.',
		)

		// The handler used to assign to `motivering`, a name nothing declares.
		// `rationale` stayed empty and onCompose() sent no rationale at all.
		expect(wrapper.vm.rationale).toBe('Strijd met het bestemmingsplan.')
	})
})
