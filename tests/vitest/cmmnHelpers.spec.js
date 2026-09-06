/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure CMMN case-plan panel helpers in
 * src/utils/cmmnHelpers.js (cmmn-adaptive-case): tree building, state badge
 * mapping, and the enable/complete/terminate action-availability gates the
 * panel relies on.
 *
 * The global `t()` (NC translation) is stubbed to return the English source
 * string so output is deterministically assertable.
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md
 */

import { beforeAll, describe, expect, it } from 'vitest'

beforeAll(() => {
	globalThis.t = (app, text) => text
})

const importHelpers = async () => await import('../../src/utils/cmmnHelpers.js')

describe('buildPlanTree', () => {
	it('nests items under their parentId', async () => {
		const { buildPlanTree } = await importHelpers()
		const items = [
			{ id: 'stage-1', parentId: null, name: 'Stage' },
			{ id: 'task-1', parentId: 'stage-1', name: 'Task' },
		]
		const tree = buildPlanTree(items)
		expect(tree).toHaveLength(1)
		expect(tree[0].id).toBe('stage-1')
		expect(tree[0].children).toHaveLength(1)
		expect(tree[0].children[0].id).toBe('task-1')
	})

	it('treats null/absent parentId as top-level', async () => {
		const { buildPlanTree } = await importHelpers()
		const items = [{ id: 'a', parentId: null }, { id: 'b' }]
		const tree = buildPlanTree(items)
		expect(tree.map((n) => n.id).sort()).toEqual(['a', 'b'])
	})

	it('falls back to top-level when parentId does not resolve (defensive, never throws)', async () => {
		const { buildPlanTree } = await importHelpers()
		const items = [{ id: 'orphan', parentId: 'missing-parent' }]
		const tree = buildPlanTree(items)
		expect(tree).toHaveLength(1)
		expect(tree[0].id).toBe('orphan')
	})

	it('handles a non-array input gracefully', async () => {
		const { buildPlanTree } = await importHelpers()
		expect(buildPlanTree(undefined)).toEqual([])
		expect(buildPlanTree(null)).toEqual([])
	})

	it('nests multiple levels (stage within stage)', async () => {
		const { buildPlanTree } = await importHelpers()
		const items = [
			{ id: 'root', parentId: null },
			{ id: 'mid', parentId: 'root' },
			{ id: 'leaf', parentId: 'mid' },
		]
		const tree = buildPlanTree(items)
		expect(tree[0].children[0].id).toBe('mid')
		expect(tree[0].children[0].children[0].id).toBe('leaf')
	})
})

describe('stateBadge', () => {
	it('maps every known state to a label and CSS class', async () => {
		const { stateBadge } = await importHelpers()
		for (const state of [
			'available',
			'enabled',
			'active',
			'completed',
			'terminated',
			'disabled',
		]) {
			const badge = stateBadge(state)
			expect(badge.label).not.toBe('')
			expect(badge.cssClass).toBe(`cmmn-plan-panel__badge--${state}`)
		}
	})

	it('falls back to the raw state string for an unrecognised state', async () => {
		const { stateBadge } = await importHelpers()
		expect(stateBadge('something-new').label).toBe('something-new')
	})
})

describe('isEnableable', () => {
	it('is true for a discretionary item in the enableable list', async () => {
		const { isEnableable } = await importHelpers()
		const item = { id: 'disc-1', discretionary: true }
		expect(isEnableable(item, ['disc-1'])).toBe(true)
	})

	it('is false for a mandatory item even if listed', async () => {
		const { isEnableable } = await importHelpers()
		const item = { id: 'mand-1', discretionary: false }
		expect(isEnableable(item, ['mand-1'])).toBe(false)
	})

	it('is false when the item id is not in the enableable list', async () => {
		const { isEnableable } = await importHelpers()
		const item = { id: 'disc-1', discretionary: true }
		expect(isEnableable(item, [])).toBe(false)
	})
})

describe('canComplete / canTerminate', () => {
	it('allows completing only an active humanTask', async () => {
		const { canComplete } = await importHelpers()
		expect(canComplete({ type: 'humanTask', state: 'active' })).toBe(true)
		expect(canComplete({ type: 'humanTask', state: 'enabled' })).toBe(false)
		expect(canComplete({ type: 'stage', state: 'active' })).toBe(false)
	})

	it('allows terminating an enabled or active humanTask only', async () => {
		const { canTerminate } = await importHelpers()
		expect(canTerminate({ type: 'humanTask', state: 'active' })).toBe(true)
		expect(canTerminate({ type: 'humanTask', state: 'enabled' })).toBe(true)
		expect(canTerminate({ type: 'humanTask', state: 'available' })).toBe(false)
		expect(canTerminate({ type: 'milestone', state: 'active' })).toBe(false)
	})
})

describe('isMilestoneAchieved', () => {
	it('reads the achieved flag for a given milestone id', async () => {
		const { isMilestoneAchieved } = await importHelpers()
		const milestones = { ms1: { achieved: true }, ms2: { achieved: false } }
		expect(isMilestoneAchieved(milestones, 'ms1')).toBe(true)
		expect(isMilestoneAchieved(milestones, 'ms2')).toBe(false)
		expect(isMilestoneAchieved(milestones, 'unknown')).toBe(false)
	})

	it('handles a missing/null milestones map', async () => {
		const { isMilestoneAchieved } = await importHelpers()
		expect(isMilestoneAchieved(null, 'ms1')).toBe(false)
		expect(isMilestoneAchieved(undefined, 'ms1')).toBe(false)
	})
})
