/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the doorlooptijd (processing-time) analytics helpers in
 * src/utils/doorlooptijdHelpers.js.
 *
 * These are pure calc functions (SLA compliance, processing-day math,
 * histogram distribution, monthly trend, at-risk detection, performance
 * status) whose exact numeric output is only ever shown through rendered
 * dashboards — never asserted at the value level. This suite locks the
 * arithmetic: rounding, ISO-8601 day approximation, bucket boundaries,
 * inclusive bin edges, status thresholds, and sort order.
 *
 * Runs in Vitest's `node` environment; @nextcloud/l10n is aliased to a
 * deterministic stub (see vitest.config.js).
 */

import { describe, expect, it } from 'vitest'
import {
	buildCaseTypeMap,
	computeMonthlyTrend,
	computePerformanceTable,
	computeProcessingTimeDistribution,
	computeSlaCompliance,
	getAtRiskCases,
	getProcessingDays,
	getSlaTargetDays,
	parseDurationToDays,
} from '../../src/utils/doorlooptijdHelpers.js'

describe('parseDurationToDays', () => {
	it('parses plain day/week/month/year forms', () => {
		expect(parseDurationToDays('P30D')).toBe(30)
		expect(parseDurationToDays('P6W')).toBe(42) // 6 * 7
		expect(parseDurationToDays('P2M')).toBe(60) // 2 * 30
		expect(parseDurationToDays('P1Y')).toBe(365)
	})

	it('sums combined components (year=365, month=30, week=7, day=1)', () => {
		expect(parseDurationToDays('P1Y2M3D')).toBe(365 + 60 + 3) // 428
		expect(parseDurationToDays('P2M15D')).toBe(60 + 15) // 75
	})

	it('is case-insensitive on the P/units', () => {
		expect(parseDurationToDays('p30d')).toBe(30)
	})

	it('returns null for empty, non-string, unparseable, or zero-total', () => {
		expect(parseDurationToDays('')).toBeNull()
		expect(parseDurationToDays(null)).toBeNull()
		expect(parseDurationToDays(42)).toBeNull()
		expect(parseDurationToDays('30 days')).toBeNull()
		expect(parseDurationToDays('P0D')).toBeNull() // total 0 -> null
	})
})

describe('getProcessingDays', () => {
	it('counts whole calendar days between start and end', () => {
		expect(
			getProcessingDays({ startDate: '2026-01-01', endDate: '2026-01-31' }),
		).toBe(30)
		expect(
			getProcessingDays({ startDate: '2026-03-01', endDate: '2026-03-01' }),
		).toBe(0)
	})

	it('ignores the time-of-day component (normalised to midnight)', () => {
		expect(
			getProcessingDays({
				startDate: '2026-01-01T23:59:00',
				endDate: '2026-01-03T00:01:00',
			}),
		).toBe(2)
	})

	it('floors negative ranges to 0', () => {
		expect(
			getProcessingDays({ startDate: '2026-02-10', endDate: '2026-02-01' }),
		).toBe(0)
	})

	it('returns null when a date is missing', () => {
		expect(getProcessingDays({ startDate: '2026-01-01' })).toBeNull()
		expect(getProcessingDays({ endDate: '2026-01-01' })).toBeNull()
		expect(getProcessingDays({})).toBeNull()
	})
})

describe('buildCaseTypeMap / getSlaTargetDays', () => {
	const caseTypes = [
		{ id: 'ct1', title: 'Permit', processingDeadline: 'P8W' },
		{ id: 'ct2', title: 'Objection', processingDeadline: 'P6W' },
		{ id: 'ct3', title: 'No deadline' },
	]
	const map = buildCaseTypeMap(caseTypes)

	it('maps id -> caseType object', () => {
		expect(map.get('ct1').title).toBe('Permit')
		expect(map.size).toBe(3)
	})

	it('resolves the SLA target days from processingDeadline', () => {
		expect(getSlaTargetDays({ caseType: 'ct1' }, map)).toBe(56) // 8 weeks
		expect(getSlaTargetDays({ caseType: 'ct2' }, map)).toBe(42) // 6 weeks
	})

	it('returns null for unknown case type or missing deadline', () => {
		expect(getSlaTargetDays({ caseType: 'ct3' }, map)).toBeNull()
		expect(getSlaTargetDays({ caseType: 'nope' }, map)).toBeNull()
	})
})

describe('computeSlaCompliance', () => {
	const caseTypes = [
		{ id: 'ct1', title: 'Permit', processingDeadline: 'P30D' },
		{ id: 'ct2', title: 'Objection', processingDeadline: 'P10D' },
	]
	const cases = [
		// ct1 (target 30): 20d within, 40d breach
		{ caseType: 'ct1', startDate: '2026-01-01', endDate: '2026-01-21' }, // 20 within
		{ caseType: 'ct1', startDate: '2026-01-01', endDate: '2026-02-10' }, // 40 breach
		// ct2 (target 10): 5d within
		{ caseType: 'ct2', startDate: '2026-01-01', endDate: '2026-01-06' }, // 5 within
		// excluded: no target
		{ caseType: 'none', startDate: '2026-01-01', endDate: '2026-01-05' },
		// excluded: no end date
		{ caseType: 'ct1', startDate: '2026-01-01' },
	]

	it('computes overall rate, counts, and per-type breakdown', () => {
		const r = computeSlaCompliance(cases, caseTypes)
		expect(r.total).toBe(3) // 2 ct1 + 1 ct2
		expect(r.withinSla).toBe(2) // 20d, 5d
		expect(r.excluded).toBe(2) // no-target + no-end
		expect(r.overallRate).toBe(67) // round(2/3 * 100)

		const ct1 = r.byType.find((b) => b.id === 'ct1')
		expect(ct1.total).toBe(2)
		expect(ct1.withinSla).toBe(1)
		expect(ct1.rate).toBe(50)
		expect(ct1.avgActual).toBe(30) // round((20 + 40) / 2)
		expect(ct1.targetDays).toBe(30)

		const ct2 = r.byType.find((b) => b.id === 'ct2')
		expect(ct2.rate).toBe(100)
		expect(ct2.avgActual).toBe(5)
	})

	it('returns null overallRate when nothing qualifies', () => {
		const r = computeSlaCompliance([], caseTypes)
		expect(r.overallRate).toBeNull()
		expect(r.total).toBe(0)
		expect(r.byType).toEqual([])
	})
})

describe('computeProcessingTimeDistribution', () => {
	const caseTypes = [{ id: 'ct1', title: 'Permit', processingDeadline: 'P14D' }]

	it('buckets processing days into the default bins with inclusive edges', () => {
		const cases = [
			{ caseType: 'ct1', startDate: '2026-01-01', endDate: '2026-01-01' }, // 0 -> 0-7
			{ caseType: 'ct1', startDate: '2026-01-01', endDate: '2026-01-08' }, // 7 -> 0-7 (max inclusive)
			{ caseType: 'ct1', startDate: '2026-01-01', endDate: '2026-01-09' }, // 8 -> 8-14 (min inclusive)
			{ caseType: 'ct1', startDate: '2026-01-01', endDate: '2026-02-27' }, // 57 -> 57+
		]
		const r = computeProcessingTimeDistribution(cases, caseTypes)
		const counts = Object.fromEntries(r.bins.map((b) => [b.label, b.count]))
		expect(counts['0-7']).toBe(2)
		expect(counts['8-14']).toBe(1)
		expect(counts['57+']).toBe(1)
		expect(counts['15-21']).toBe(0)
	})

	it('emits the SLA target line only for a single unique target', () => {
		const single = computeProcessingTimeDistribution(
			[{ caseType: 'ct1', startDate: '2026-01-01', endDate: '2026-01-05' }],
			caseTypes,
		)
		expect(single.slaTargetDays).toBe(14)

		const twoTypes = [
			{ id: 'ct1', title: 'A', processingDeadline: 'P14D' },
			{ id: 'ct2', title: 'B', processingDeadline: 'P30D' },
		]
		const mixed = computeProcessingTimeDistribution(
			[
				{ caseType: 'ct1', startDate: '2026-01-01', endDate: '2026-01-05' },
				{ caseType: 'ct2', startDate: '2026-01-01', endDate: '2026-01-05' },
			],
			twoTypes,
		)
		expect(mixed.slaTargetDays).toBeNull()
	})
})

describe('computeMonthlyTrend', () => {
	it('produces one bucket per lookback month with computed rates', () => {
		// Anchor cases to the current month so they land in the last bucket.
		const now = new Date()
		const ym = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
		const day = (n) => `${ym}-${String(n).padStart(2, '0')}`
		const start = `${ym}-01`

		const caseTypes = [{ id: 'ct1', processingDeadline: 'P10D' }]
		const cases = [
			{ caseType: 'ct1', startDate: start, endDate: day(6) }, // 5d within
			{ caseType: 'ct1', startDate: start, endDate: day(20) }, // 19d breach
		]

		const trend = computeMonthlyTrend(cases, caseTypes, 3)
		expect(trend).toHaveLength(3)
		const current = trend[trend.length - 1]
		expect(current.month).toBe(ym)
		expect(current.total).toBe(2)
		expect(current.withinSla).toBe(1)
		expect(current.rate).toBe(50)

		// Earlier empty buckets are null-rate.
		expect(trend[0].total).toBe(0)
		expect(trend[0].rate).toBeNull()
	})
})

describe('getAtRiskCases', () => {
	const caseTypes = [{ id: 'ct1', title: 'Permit', processingDeadline: 'P10D' }]

	// Build a LOCAL-date 'YYYY-MM-DD' string n days before today. The helper
	// under test computes "today" at local midnight, so we must format the
	// date in local time (not toISOString, which is UTC and would drift the
	// elapsed-day count by one in non-UTC environments).
	function daysAgo(n) {
		const d = new Date()
		d.setHours(0, 0, 0, 0)
		d.setDate(d.getDate() - n)
		const y = d.getFullYear()
		const m = String(d.getMonth() + 1).padStart(2, '0')
		const day = String(d.getDate()).padStart(2, '0')
		return `${y}-${m}-${day}`
	}

	it('flags overdue and near-deadline cases, excludes healthy ones', () => {
		const open = [
			{ id: 'a', caseType: 'ct1', startDate: daysAgo(12) }, // overdue (elapsed 12 > 10)
			{ id: 'b', caseType: 'ct1', startDate: daysAgo(8) }, // 80% used, 20% left <= 25% -> at risk
			{ id: 'c', caseType: 'ct1', startDate: daysAgo(2) }, // 20% used, healthy
		]
		const r = getAtRiskCases(open, caseTypes, 0.25)
		const ids = r.map((x) => x.id)
		expect(ids).toContain('a')
		expect(ids).toContain('b')
		expect(ids).not.toContain('c')
	})

	it('sorts overdue first, then by least remaining days', () => {
		const open = [
			{ id: 'near', caseType: 'ct1', startDate: daysAgo(9) }, // remaining 1
			{ id: 'veryOverdue', caseType: 'ct1', startDate: daysAgo(20) }, // remaining -10
			{ id: 'slightOverdue', caseType: 'ct1', startDate: daysAgo(12) }, // remaining -2
		]
		const r = getAtRiskCases(open, caseTypes, 0.25)
		expect(r[0].id).toBe('veryOverdue')
		expect(r[1].id).toBe('slightOverdue')
		expect(r[2].id).toBe('near')
		expect(r[0].isOverdue).toBe(true)
		expect(r[2].isOverdue).toBe(false)
	})

	it('computes elapsed/remaining/percentUsed and caps percentUsed at 1.5', () => {
		const r = getAtRiskCases(
			[{ id: 'x', caseType: 'ct1', startDate: daysAgo(30) }], // elapsed 30, target 10
			caseTypes,
			0.25,
		)
		expect(r[0].elapsedDays).toBe(30)
		expect(r[0].remainingDays).toBe(-20)
		expect(r[0].percentUsed).toBe(1.5) // 3.0 capped at 1.5
	})

	it('excludes cases without a target or start date', () => {
		const r = getAtRiskCases(
			[
				{ id: 'noStart', caseType: 'ct1' },
				{ id: 'noTarget', caseType: 'other', startDate: daysAgo(50) },
			],
			caseTypes,
		)
		expect(r).toEqual([])
	})
})

describe('computePerformanceTable', () => {
	const caseTypes = [
		{ id: 'good', title: 'Good', processingDeadline: 'P30D' },
		{ id: 'warn', title: 'Warn', processingDeadline: 'P30D' },
		{ id: 'crit', title: 'Crit', processingDeadline: 'P30D' },
		{ id: 'none', title: 'NoTarget' },
	]

	it('derives status from compliance thresholds (>=90 good, >=70 warning, else critical)', () => {
		const cases = [
			// good: 10/10 within -> 100%
			...Array.from({ length: 10 }, () => ({
				caseType: 'good',
				startDate: '2026-01-01',
				endDate: '2026-01-11',
			})), // 10d
			// warn: 8 within + 2 breach -> 80%
			...Array.from({ length: 8 }, () => ({
				caseType: 'warn',
				startDate: '2026-01-01',
				endDate: '2026-01-11',
			})), // 10d within
			...Array.from({ length: 2 }, () => ({
				caseType: 'warn',
				startDate: '2026-01-01',
				endDate: '2026-03-01',
			})), // 59d breach
			// crit: 1 within + 4 breach -> 20%
			{ caseType: 'crit', startDate: '2026-01-01', endDate: '2026-01-11' },
			...Array.from({ length: 4 }, () => ({
				caseType: 'crit',
				startDate: '2026-01-01',
				endDate: '2026-03-01',
			})),
		]
		const rows = computePerformanceTable(cases, caseTypes)
		const byId = Object.fromEntries(rows.map((r) => [r.id, r]))

		expect(byId.good.complianceRate).toBe(100)
		expect(byId.good.status).toBe('good')
		expect(byId.good.avgActualDays).toBe(10)
		expect(byId.good.targetDays).toBe(30)

		expect(byId.warn.complianceRate).toBe(80)
		expect(byId.warn.status).toBe('warning')

		expect(byId.crit.complianceRate).toBe(20)
		expect(byId.crit.status).toBe('critical')

		// case type with no deadline -> no-target, null rate, but still listed
		expect(byId.none.status).toBe('no-target')
		expect(byId.none.complianceRate).toBeNull()
		expect(byId.none.targetDays).toBeNull()
	})

	it('includes case types with zero completed cases (null averages)', () => {
		const rows = computePerformanceTable([], caseTypes)
		expect(rows).toHaveLength(4)
		expect(rows.every((r) => r.total === 0)).toBe(true)
		expect(rows.every((r) => r.avgActualDays === null)).toBe(true)
	})
})
