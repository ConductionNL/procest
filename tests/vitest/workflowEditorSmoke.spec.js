// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component smoke tests for the visual workflow editor
 * (`WorkflowEditor.vue`, the single canonical editor after
 * `workflow-editor-integration` deleted the dead `@vue-flow`-based
 * duplicate): renders a loaded definition's status nodes, and blocks the
 * save/publish path when the graph fails real-engine validation.
 *
 * Every child SFC `WorkflowEditor.vue` imports (`WorkflowNode`,
 * `WorkflowTransitionArrow`, `WorkflowPalette`, `StepConfigPanel`,
 * `TransitionConfigPanel`) is replaced with a trivial local stub via
 * `vi.mock` BEFORE the real module graph loads. This is deliberate, not a
 * shortcut around real functionality: those components transitively import
 * `@nextcloud/vue`'s `NcActions`, which — several chunks deep — pulls in
 * the rich-text/reference-widget picker stack (`NcRichContenteditable`,
 * `@nextcloud/router` `imagePath`, capabilities, markdown parsing) that
 * assumes a live Nextcloud runtime and has nothing to do with what this
 * test verifies (this component's own render-tree wiring and its public
 * `validate()` API). `WorkflowValidationBanner.vue` has no such
 * dependency, so its own suite below mounts the REAL component.
 *
 * The `workflowStore`/`objectStore` computed properties are overridden
 * with plain mocks instead of a real Pinia instance, so no network/HTTP
 * seam needs stubbing either.
 *
 * Opts into the jsdom environment via the `@vitest-environment` pragma
 * above (see `vitest.config.js` — the suite default is `node` for the
 * pure-logic tests).
 *
 * @spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-drag-and-drop-workflow-canvas
 * @spec openspec/changes/workflow-editor-integration/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

// VUE 3 NOTE — these stubs used to be written as `render(h) { ... }` /
// `render: (h) => h(...)`. Vue 2 passed the `createElement` factory as the
// render function's first argument; Vue 3 passes NO such argument and
// exposes `h` as a module-level import from `vue`, so every one of these
// stubs threw `h is not a function` at mount time. The second argument is
// also flat now: Vue 2's nested `{ attrs: { ... } }` data object became a
// flat props/attrs object in Vue 3.
vi.mock('../../src/views/settings/components/WorkflowNode.vue', () => ({
	default: {
		name: 'WorkflowNode',
		props: [
			'status',
			'steps',
			'position',
			'selected',
			'otherStatuses',
			'outgoingTransitions',
		],
		render() {
			return h(
				'div',
				{
					class: 'workflow-node-stub',
					'data-status-id': this.status.id,
				},
				this.status.name,
			)
		},
	},
}))
vi.mock('../../src/views/settings/components/WorkflowTransitionArrow.vue', () => ({
	default: {
		name: 'WorkflowTransitionArrow',
		props: ['transition', 'fromPos', 'toPos', 'selected'],
		render: () => h('g', { class: 'workflow-transition-arrow-stub' }),
	},
}))
vi.mock('../../src/views/settings/components/WorkflowPalette.vue', () => ({
	default: {
		name: 'WorkflowPalette',
		render: () => h('div', { class: 'workflow-palette-stub' }),
	},
}))
vi.mock('../../src/views/settings/components/StepConfigPanel.vue', () => ({
	default: {
		name: 'StepConfigPanel',
		props: ['step', 'roleTypes', 'readOnly'],
		render: () => h('div', { class: 'step-config-panel-stub' }),
	},
}))
vi.mock('../../src/views/settings/components/TransitionConfigPanel.vue', () => ({
	default: {
		name: 'TransitionConfigPanel',
		props: ['transition', 'roleTypes', 'documentTypes'],
		render: () => h('div', { class: 'transition-config-panel-stub' }),
	},
}))

// Imported AFTER the mocks above so WorkflowEditor.vue's own `import
// WorkflowNode from './components/WorkflowNode.vue'` etc. resolve to the
// stubs (vi.mock factories are hoisted by Vitest, but importing the real
// component-under-test afterwards keeps the intent explicit).
const { default: WorkflowEditor } =
	await import('../../src/views/settings/WorkflowEditor.vue')
const { default: WorkflowValidationBanner } =
	await import('../../src/views/settings/components/WorkflowValidationBanner.vue')
const { validateWorkflowGraph } =
	await import('../../src/utils/workflowGraphValidation.js')

/**
 * Mount `WorkflowEditor` with its `workflowStore`/`objectStore` computed
 * properties replaced by the supplied mocks.
 *
 * This used to be expressed as a top-level `computed:` MOUNTING OPTION
 * (`mount(C, { propsData, computed: { ... } })`), the VTU v1 spelling.
 *
 * MEASURED, so the next reader does not have to re-derive it: `computed:`
 * (and `stubs:`, `mocks:`, `propsData:`) as top-level mount options DO
 * still work under @vue/test-utils 2.4.11 — a probe mounting a component
 * with `computed: { label: () => 'OVERRIDDEN' }` renders "OVERRIDDEN".
 * They are legacy compatibility shims, not part of v2's documented
 * mounting API (which is `props` / `attrs` / `slots` / `global` /
 * `shallow` / `attachTo`). So this refactor did NOT fix a broken mount —
 * the three tests here were failing on `h is not a function`, not on
 * dropped mocks.
 *
 * It is kept because overriding on the component definition is explicit
 * about what is being replaced, does not depend on an undocumented shim,
 * and removes the same six lines from three call sites.
 *
 * @param {object} workflowStore Mock workflow store.
 * @param {object} objectStore Mock object store.
 * @param {object} props Props to pass to the component.
 * @return {object} The mounted wrapper.
 */
function mountEditor(workflowStore, objectStore, props) {
	return mount(
		{
			...WorkflowEditor,
			computed: {
				...WorkflowEditor.computed,
				workflowStore: () => workflowStore,
				objectStore: () => objectStore,
			},
		},
		{ props },
	)
}

/**
 * Build a minimal mock workflow store exposing exactly what
 * `WorkflowEditor.vue` reads: the three parsed* getters plus the
 * `validateWorkflow`/`updateNodePosition` actions it calls directly.
 *
 * @param {object} overrides Partial store shape to override the defaults
 * @return {object} Mock store
 */
function buildMockWorkflowStore(overrides = {}) {
	return {
		currentTemplate: { id: 'tpl-1', isDraft: true },
		parsedSteps: [],
		parsedTransitions: [],
		parsedNodePositions: {},
		validationErrors: [],
		validateWorkflow: vi.fn((statusNodes) =>
			validateWorkflowGraph({ statusNodes, transitions: [] }),
		),
		getTemplate: vi.fn(() => Promise.resolve(null)),
		updateNodePosition: vi.fn(),
		addTransition: vi.fn(),
		removeTransition: vi.fn(),
		removeStatusNode: vi.fn(),
		...overrides,
	}
}

/**
 * Build a minimal mock object store exposing exactly what
 * `WorkflowEditor.vue::loadData()` calls.
 *
 * @param {object} overrides Partial store shape to override the defaults
 * @return {object} Mock store
 */
function buildMockObjectStore(overrides = {}) {
	return {
		fetchCollection: vi.fn(() => Promise.resolve([])),
		fetchObject: vi.fn(() => Promise.resolve(null)),
		saveObject: vi.fn((schema, data) =>
			Promise.resolve({ id: 'new-id', ...data }),
		),
		deleteObject: vi.fn(() => Promise.resolve(true)),
		...overrides,
	}
}

describe('WorkflowEditor.vue — renders a loaded definition', () => {
	it('renders one status node per statusType loaded for the case type', async () => {
		const statusNodes = [
			{ id: 's1', name: 'Received', isFinal: false },
			{ id: 's2', name: 'Handled', isFinal: true },
		]
		const objectStore = buildMockObjectStore({
			fetchCollection: vi.fn((schema) =>
				schema === 'statusType'
					? Promise.resolve(statusNodes)
					: Promise.resolve([]),
			),
		})
		const workflowStore = buildMockWorkflowStore()

		const wrapper = mountEditor(workflowStore, objectStore, {
			caseTypeId: 'ct-1',
			templateId: 'tpl-1',
		})

		// mounted() -> loadData() is async; flush it.
		//
		// This used to be a fixed number of `await $nextTick()` calls. That is
		// a Vue-2-era idiom that happens to drain a specific number of
		// microtasks; under Vue 3 the awaited promise chain in `loadData()` is
		// a different length and the count no longer lines up — `statusNodes`
		// was still `[]` when the assertions ran. `flushPromises()` drains the
		// whole pending microtask queue AND the scheduler flush, so it does not
		// depend on the internal await count at all.
		await flushPromises()

		expect(wrapper.vm.statusNodes).toHaveLength(2)
		const nodeEls = wrapper.findAll('.workflow-node-stub')
		expect(nodeEls).toHaveLength(2)
		expect(nodeEls.at(0).attributes('data-status-id')).toBe('s1')
		expect(nodeEls.at(0).text()).toBe('Received')
		expect(nodeEls.at(1).attributes('data-status-id')).toBe('s2')
		expect(nodeEls.at(1).text()).toBe('Handled')
	})
})

describe('WorkflowEditor.vue — blocks save/publish on an invalid graph', () => {
	it('validate() returns false and records real-engine validation errors for a graph with no final status', async () => {
		const statusNodes = [
			{ id: 's1', name: 'Received', isFinal: false },
			{ id: 's2', name: 'In handling', isFinal: false },
		]
		const objectStore = buildMockObjectStore({
			fetchCollection: vi.fn((schema) =>
				schema === 'statusType'
					? Promise.resolve(statusNodes)
					: Promise.resolve([]),
			),
		})
		const workflowStore = buildMockWorkflowStore()

		const wrapper = mountEditor(workflowStore, objectStore, {
			caseTypeId: 'ct-1',
			templateId: null,
		})

		await flushPromises()

		expect(wrapper.vm.statusNodes).toHaveLength(2)

		// This is exactly what WorkflowTab.vue::publish() calls before
		// saving/publishing — `if (this.$refs.editor && !this.$refs.editor.validate())`.
		const isValid = wrapper.vm.validate()

		expect(isValid).toBe(false)
		expect(workflowStore.validateWorkflow).toHaveBeenCalledWith(statusNodes)
		expect(wrapper.vm.validationErrors.length).toBeGreaterThan(0)
		expect(wrapper.vm.validationErrors[0].code).toBe('NO_FINAL_STATUS')

		// The banner (mounted for real, see the suite below) shows the exact
		// blocking message a user acts on.
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('Workflow has no final status defined')
	})

	it('validate() returns true for a well-formed graph', async () => {
		const statusNodes = [
			{ id: 's1', name: 'Received', isFinal: false },
			{ id: 's2', name: 'Handled', isFinal: true },
		]
		const objectStore = buildMockObjectStore({
			fetchCollection: vi.fn((schema) =>
				schema === 'statusType'
					? Promise.resolve(statusNodes)
					: Promise.resolve([]),
			),
		})
		const workflowStore = buildMockWorkflowStore({
			parsedTransitions: [{ id: 't1', fromStatus: 's1', toStatus: 's2' }],
			validateWorkflow: vi.fn((nodes) =>
				validateWorkflowGraph({
					statusNodes: nodes,
					transitions: [{ id: 't1', fromStatus: 's1', toStatus: 's2' }],
				}),
			),
		})

		const wrapper = mountEditor(workflowStore, objectStore, {
			caseTypeId: 'ct-1',
			templateId: null,
		})

		await flushPromises()

		// Guard against a VACUOUS pass: with the old fixed `$nextTick()` count
		// `loadData()` had not resolved, so `statusNodes` was still `[]` — and
		// `validate()` returned true for an EMPTY graph, not for the
		// well-formed two-node graph this test claims to exercise. Assert the
		// graph is actually loaded before asserting it validates.
		expect(wrapper.vm.statusNodes).toHaveLength(2)

		expect(wrapper.vm.validate()).toBe(true)
		expect(workflowStore.validateWorkflow).toHaveBeenCalledWith(statusNodes)
		expect(wrapper.vm.validationErrors).toEqual([])
	})
})

describe('WorkflowValidationBanner.vue — renders the blocking issues', () => {
	it('renders the per-issue message text for each validation error', () => {
		const wrapper = mount(WorkflowValidationBanner, {
			props: {
				errors: [
					{
						type: 'error',
						code: 'NO_FINAL_STATUS',
						message: 'Workflow has no final status defined',
					},
					{
						type: 'warning',
						code: 'ORPHAN_NODE',
						message: 'Status "Losstaand" has no transitions',
					},
				],
			},
		})

		expect(wrapper.text()).toContain('Workflow has no final status defined')
		expect(wrapper.text()).toContain('Status "Losstaand" has no transitions')
		expect(wrapper.findAll('.workflow-validation__item')).toHaveLength(2)
	})

	it('renders nothing when there are no errors', () => {
		const wrapper = mount(WorkflowValidationBanner, { props: { errors: [] } })
		expect(wrapper.find('.workflow-validation').exists()).toBe(false)
	})
})
