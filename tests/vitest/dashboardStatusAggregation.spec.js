/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for aggregateByStatus() in src/utils/dashboardHelpers.js — the
 * "Cases by Status" bar-chart aggregation. A case's `status` is a statusType
 * id; these tests pin the resolution contract, in particular that an absent or
 * junk (non-resolving) status is bucketed under "Unknown" WITHOUT leaking the
 * raw value into the chart, and that only resolved ids feed `statusIds` (so an
 * "Unknown" bar click never deep-links on a junk/absent id).
 *
 * The global `t()` (NC translation) is stubbed via the vitest @nextcloud/l10n
 * alias to return the English source string so output is deterministically
 * assertable.
 *
 * @spec openspec/changes/retrofit-2026-05-24-dashboard/tasks.md
 */

import { describe, expect, it } from 'vitest'
import { aggregateByStatus } from '../../src/utils/dashboardHelpers.js'

const STATUS_TYPES = [
	{ id: 'uuid-ontvangen', name: 'Received', order: 1 },
	{ id: 'uuid-behandeling', name: 'In handling', order: 2 },
	{ id: 'uuid-afgehandeld', name: 'Handled', order: 3 },
]

describe('aggregateByStatus', () => {
	it('groups cases by resolved statusType name, sorted by statusType order', () => {
		const openCases = [
			{ status: 'uuid-behandeling' },
			{ status: 'uuid-ontvangen' },
			{ status: 'uuid-behandeling' },
		]

		const result = aggregateByStatus(openCases, STATUS_TYPES)

		expect(result).toEqual([
			{ name: 'Received', count: 1, statusIds: ['uuid-ontvangen'] },
			{ name: 'In handling', count: 2, statusIds: ['uuid-behandeling'] },
		])
	})

	it('buckets null / undefined status under "Unknown" with no statusIds', () => {
		const openCases = [{ status: null }, { status: undefined }, {}]

		const result = aggregateByStatus(openCases, STATUS_TYPES)

		expect(result).toEqual([{ name: 'Unknown', count: 3, statusIds: [] }])
	})

	it('buckets junk / non-resolving status under "Unknown" WITHOUT leaking the raw value', () => {
		const openCases = [
			{ status: 'Array' }, // stale QA junk that no longer references a statusType
			{ status: 'not-a-real-id' },
		]

		const result = aggregateByStatus(openCases, STATUS_TYPES)

		// A single "Unknown" bar — never an "Array" or "not-a-real-id" bar.
		expect(result).toEqual([{ name: 'Unknown', count: 2, statusIds: [] }])
		expect(result.some((r) => r.name === 'Array')).toBe(false)
	})

	it('merges resolved, null and junk statuses into the correct buckets', () => {
		const openCases = [
			{ status: 'uuid-ontvangen' },
			{ status: null },
			{ status: 'Array' },
			{ status: 'uuid-ontvangen' },
			{ status: 'uuid-behandeling' },
		]

		const result = aggregateByStatus(openCases, STATUS_TYPES)
		const byName = Object.fromEntries(result.map((r) => [r.name, r.count]))

		expect(byName).toEqual({ Received: 2, 'In handling': 1, Unknown: 2 })
		// The Unknown bucket carries no statusIds (null + junk contribute none).
		expect(result.find((r) => r.name === 'Unknown').statusIds).toEqual([])
	})

	it('returns an empty array when there are no open cases', () => {
		expect(aggregateByStatus([], STATUS_TYPES)).toEqual([])
	})
})
