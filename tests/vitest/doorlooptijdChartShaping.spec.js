/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the doorlooptijd dashboard chart/table data-shaping helpers
 * in src/views/doorlooptijd/components/chartShaping.js.
 *
 * These pure functions are extracted from the doorlooptijd sub-components
 * (ComplianceCharts.vue, CaseTypeBreakdown.vue) during the monolith split.
 * They map aggregated helper output into ApexCharts series and sorted
 * performance rows — output only ever observed through a rendered dashboard,
 * so this suite locks the exact shaping: slice/label alignment, empty-data
 * branches, SLA-target bin matching, and the null-last sort comparator.
 *
 * Runs in Vitest's `node` environment.
 */

import { describe, expect, it } from 'vitest'
import {
	buildDonutLabels,
	buildDonutSeries,
	buildHistogramSeries,
	buildThroughputSeries,
	buildTrendSeries,
	findHistogramTargetBinIndex,
	sortPerformanceRows,
} from '../../src/views/doorlooptijd/components/chartShaping.js'

const slaData = {
	byType: [
		{ name: 'Bezwaar omgevingsvergunning', total: 12, withinSla: 10, rate: 83 },
		{ name: 'Bezwaar bijstandsuitkering', total: 0, withinSla: 0, rate: 0 },
		{ name: 'Woo-verzoek', total: 5, withinSla: 3, rate: 60 },
	],
}

describe('buildDonutSeries / buildDonutLabels', () => {
	it('emits one within-SLA value per case type that has completed cases', () => {
		expect(buildDonutSeries(slaData)).toEqual([10, 3])
	})

	it('aligns labels with the series, skipping zero-total types', () => {
		expect(buildDonutLabels(slaData)).toEqual([
			'Bezwaar omgevingsvergunning',
			'Woo-verzoek',
		])
	})

	it('tolerates missing/empty input', () => {
		expect(buildDonutSeries(undefined)).toEqual([])
		expect(buildDonutLabels({ byType: [] })).toEqual([])
	})
})

describe('buildHistogramSeries', () => {
	it('maps bin counts into a single named series', () => {
		const dist = {
			bins: [
				{ label: '0-7', count: 2 },
				{ label: '8-14', count: 5 },
			],
		}
		expect(buildHistogramSeries(dist, 'Cases')).toEqual([
			{ name: 'Cases', data: [2, 5] },
		])
	})

	it('returns an empty array when every bin is empty (drives empty state)', () => {
		const dist = {
			bins: [
				{ label: '0-7', count: 0 },
				{ label: '8-14', count: 0 },
			],
		}
		expect(buildHistogramSeries(dist, 'Cases')).toEqual([])
	})

	it('returns an empty array when there are no bins', () => {
		expect(buildHistogramSeries({ bins: [] }, 'Cases')).toEqual([])
	})
})

describe('findHistogramTargetBinIndex', () => {
	const bins = [
		{ label: '0-7', count: 1 },
		{ label: '8-14', count: 2 },
		{ label: '15-30', count: 3 },
		{ label: '31+', count: 4 },
	]

	it('locates the bin whose inclusive range contains the target', () => {
		expect(findHistogramTargetBinIndex(bins, 5)).toBe(0)
		expect(findHistogramTargetBinIndex(bins, 14)).toBe(1)
		expect(findHistogramTargetBinIndex(bins, 30)).toBe(2)
	})

	it('matches the open-ended "N+" final bin', () => {
		expect(findHistogramTargetBinIndex(bins, 99)).toBe(3)
	})

	it('falls back to the last bin when no labelled range matches', () => {
		const noOpenEnd = [
			{ label: '0-7', count: 1 },
			{ label: '8-14', count: 2 },
		]
		expect(findHistogramTargetBinIndex(noOpenEnd, 100)).toBe(1)
	})

	it('returns -1 when there are no bins', () => {
		expect(findHistogramTargetBinIndex([], 5)).toBe(-1)
	})
})

describe('buildTrendSeries / buildThroughputSeries', () => {
	it('extracts the rate values into a single trend series', () => {
		const trend = [
			{ month: '2026-03', rate: 80 },
			{ month: '2026-04', rate: null },
		]
		expect(buildTrendSeries(trend, 'SLA %')).toEqual([
			{ name: 'SLA %', data: [80, null] },
		])
	})

	it('extracts weekly counts into a single throughput series', () => {
		const tp = [
			{ weekLabel: 'W12', count: 3 },
			{ weekLabel: 'W13', count: 0 },
		]
		expect(buildThroughputSeries(tp, 'Closed')).toEqual([
			{ name: 'Closed', data: [3, 0] },
		])
	})

	it('tolerates undefined input', () => {
		expect(buildTrendSeries(undefined, 'x')).toEqual([{ name: 'x', data: [] }])
		expect(buildThroughputSeries(undefined, 'y')).toEqual([
			{ name: 'y', data: [] },
		])
	})
})

describe('sortPerformanceRows', () => {
	const rows = [
		{ id: 'a', name: 'Bezwaar', complianceRate: 80, total: 12 },
		{ id: 'b', name: 'Aanvraag', complianceRate: null, total: 5 },
		{ id: 'c', name: 'Woo', complianceRate: 60, total: 7 },
	]

	it('sorts numeric ascending with nulls last', () => {
		const out = sortPerformanceRows(rows, 'complianceRate', 'asc')
		expect(out.map((r) => r.id)).toEqual(['c', 'a', 'b'])
	})

	it('sorts numeric descending with nulls still last', () => {
		const out = sortPerformanceRows(rows, 'complianceRate', 'desc')
		expect(out.map((r) => r.id)).toEqual(['a', 'c', 'b'])
	})

	it('sorts strings with locale compare', () => {
		const out = sortPerformanceRows(rows, 'name', 'asc')
		expect(out.map((r) => r.name)).toEqual(['Aanvraag', 'Bezwaar', 'Woo'])
	})

	it('does not mutate the input array', () => {
		const snapshot = rows.map((r) => r.id)
		sortPerformanceRows(rows, 'total', 'desc')
		expect(rows.map((r) => r.id)).toEqual(snapshot)
	})
})
