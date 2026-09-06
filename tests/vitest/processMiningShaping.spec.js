/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the process-mining dashboard chart/table data-shaping
 * helpers in src/views/processMining/processMiningShaping.js.
 *
 * These pure functions map the backend `/api/reports/process-mining`
 * payload into CnChartWidget series/categories and the bottleneck-table
 * rows the dashboard renders — output only ever observed through a
 * rendered dashboard, so this suite locks the exact shaping: series/
 * category alignment, empty-data branches, the flatten-and-sort of
 * per-case-type bottlenecks, and the transition-weighted rework average.
 *
 * Runs in Vitest's `node` environment.
 */

import { describe, expect, it } from 'vitest'
import {
	buildBottleneckRows,
	buildDwellCategories,
	buildDwellSeries,
	buildKpiSummary,
	buildThroughputCategories,
	buildThroughputSeries,
} from '../../src/views/processMining/processMiningShaping.js'

const dwellTime = [
	{
		statusId: 'intake',
		statusName: 'Intake',
		medianHours: 4,
		p90Hours: 8,
		meanHours: 5,
		visitCount: 10,
	},
	{
		statusId: 'review',
		statusName: 'In Review',
		medianHours: 72,
		p90Hours: 120,
		meanHours: 80,
		visitCount: 10,
	},
]

describe('buildDwellSeries / buildDwellCategories', () => {
	it('emits one median-hours value per status, in order', () => {
		expect(buildDwellSeries(dwellTime, 'Median hours')).toEqual([
			{ name: 'Median hours', data: [4, 72] },
		])
	})

	it('emits status names aligned with the series', () => {
		expect(buildDwellCategories(dwellTime)).toEqual(['Intake', 'In Review'])
	})

	it('returns an empty series when there is no dwell data', () => {
		expect(buildDwellSeries([], 'Median hours')).toEqual([])
		expect(buildDwellSeries(null, 'Median hours')).toEqual([])
	})
})

const throughputTrend = [
	{ week: '2026-W22', count: 3 },
	{ week: '2026-W23', count: 5 },
]

describe('buildThroughputSeries / buildThroughputCategories', () => {
	it('emits cases-closed counts per week, in order', () => {
		expect(buildThroughputSeries(throughputTrend, 'Cases closed')).toEqual([
			{ name: 'Cases closed', data: [3, 5] },
		])
	})

	it('emits ISO week labels aligned with the series', () => {
		expect(buildThroughputCategories(throughputTrend)).toEqual([
			'2026-W22',
			'2026-W23',
		])
	})

	it('returns an empty series when there is no throughput data', () => {
		expect(buildThroughputSeries([], 'Cases closed')).toEqual([])
	})
})

describe('buildBottleneckRows', () => {
	const caseTypes = [
		{
			title: 'Omgevingsvergunning',
			bottlenecks: [
				{
					statusName: 'Review',
					medianHours: 72,
					visitCount: 10,
					score: 720,
				},
				{ statusName: 'Intake', medianHours: 4, visitCount: 10, score: 40 },
			],
		},
		{
			title: 'Bezwaar',
			bottlenecks: [
				{
					statusName: 'Advies',
					medianHours: 200,
					visitCount: 3,
					score: 600,
				},
			],
		},
	]

	it("flattens every case type's bottlenecks and sorts by score descending", () => {
		const rows = buildBottleneckRows(caseTypes)
		expect(rows.map((r) => r.statusName)).toEqual(['Review', 'Advies', 'Intake'])
		expect(rows[0].caseTypeTitle).toBe('Omgevingsvergunning')
	})

	it('caps the result to the given limit', () => {
		expect(buildBottleneckRows(caseTypes, 1)).toHaveLength(1)
	})

	it('returns an empty array for an empty report', () => {
		expect(buildBottleneckRows([])).toEqual([])
		expect(buildBottleneckRows(undefined)).toEqual([])
	})
})

describe('buildKpiSummary', () => {
	it('sums case volume and computes a transition-weighted overall rework %', () => {
		const report = {
			caseTypes: [
				{
					caseVolume: 10,
					reworkPercent: 10,
					transitionCount: 20,
					bottlenecks: [
						{
							statusName: 'Review',
							medianHours: 72,
							visitCount: 10,
							score: 720,
						},
					],
					title: 'A',
				},
				{
					caseVolume: 5,
					reworkPercent: 40,
					transitionCount: 5,
					bottlenecks: [
						{
							statusName: 'Advies',
							medianHours: 200,
							visitCount: 3,
							score: 600,
						},
					],
					title: 'B',
				},
			],
		}

		const summary = buildKpiSummary(report)

		expect(summary.totalCases).toBe(15)
		expect(summary.caseTypeCount).toBe(2)
		// (10*20 + 40*5) / 25 = 16.
		expect(summary.overallReworkPercent).toBe(16)
		expect(summary.topBottleneck.statusName).toBe('Review')
	})

	it('handles a null/empty report without throwing', () => {
		expect(buildKpiSummary(null)).toEqual({
			totalCases: 0,
			overallReworkPercent: 0,
			topBottleneck: null,
			caseTypeCount: 0,
		})
		expect(buildKpiSummary({ caseTypes: [] }).topBottleneck).toBeNull()
	})
})
