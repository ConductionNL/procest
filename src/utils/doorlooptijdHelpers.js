/**
 * Doorlooptijd (processing time) analytics helper utilities.
 *
 * Pure functions for SLA compliance calculations, processing time distribution,
 * monthly trends, at-risk case identification, and performance table data.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Parse an ISO 8601 duration string to calendar days.
 *
 * Supports: P30D, P6W, P2M, P1Y, P1Y2M3D, P2M15D etc.
 * Months are approximated as 30 days, years as 365 days.
 *
 * @param {string} duration ISO 8601 duration (e.g., "P30D")
 * @return {number|null} Number of days, or null if unparseable
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
export function parseDurationToDays(duration) {
	if (!duration || typeof duration !== 'string') return null

	const match = duration.match(/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?$/i)
	if (!match) return null

	const years = parseInt(match[1], 10) || 0
	const months = parseInt(match[2], 10) || 0
	const weeks = parseInt(match[3], 10) || 0
	const days = parseInt(match[4], 10) || 0

	const total = years * 365 + months * 30 + weeks * 7 + days
	return total > 0 ? total : null
}

/**
 * Calculate the actual processing time in days for a completed case.
 *
 * @param {object} caseObj Case object with startDate and endDate
 * @return {number|null} Processing days, or null if dates are missing
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
export function getProcessingDays(caseObj) {
	if (!caseObj.startDate || !caseObj.endDate) return null

	const start = new Date(caseObj.startDate)
	const end = new Date(caseObj.endDate)
	start.setHours(0, 0, 0, 0)
	end.setHours(0, 0, 0, 0)

	const days = Math.floor((end - start) / (1000 * 60 * 60 * 24))
	return Math.max(0, days)
}

/**
 * Get the SLA target days for a case based on its case type.
 *
 * @param {object} caseObj Case object with caseType field
 * @param {Map<string, object>} caseTypeMap Map of caseType id to caseType object
 * @return {number|null} Target days from processingDeadline, or null
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
export function getSlaTargetDays(caseObj, caseTypeMap) {
	const ct = caseTypeMap.get(caseObj.caseType)
	if (!ct || !ct.processingDeadline) return null
	return parseDurationToDays(ct.processingDeadline)
}

/**
 * Build a Map of caseType id to caseType object.
 *
 * @param {object[]} caseTypes Array of case type objects
 * @return {Map<string, object>}
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
export function buildCaseTypeMap(caseTypes) {
	const map = new Map()
	for (const ct of caseTypes) {
		map.set(ct.id, ct)
	}
	return map
}

/**
 * Compute overall SLA compliance and per-case-type breakdown.
 *
 * @param {object[]} completedCases Cases with final status and endDate
 * @param {object[]} caseTypes All case types
 * @return {{ overallRate: number|null, withinSla: number, total: number, excluded: number, byType: Array<{ id, name, total, withinSla, rate, avgActual, targetDays }> }}
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
export function computeSlaCompliance(completedCases, caseTypes) {
	const caseTypeMap = buildCaseTypeMap(caseTypes)
	const byType = new Map()
	let withinSla = 0
	let total = 0
	let excluded = 0

	for (const c of completedCases) {
		const targetDays = getSlaTargetDays(c, caseTypeMap)
		const actualDays = getProcessingDays(c)

		if (targetDays === null) {
			excluded++
			continue
		}

		if (actualDays === null) {
			excluded++
			continue
		}

		total++
		const isWithin = actualDays <= targetDays
		if (isWithin) withinSla++

		const ctId = c.caseType
		if (!byType.has(ctId)) {
			const ct = caseTypeMap.get(ctId)
			byType.set(ctId, {
				id: ctId,
				name: ct?.title || ct?.name || t('dossiq', 'Unknown'),
				total: 0,
				withinSla: 0,
				totalDays: 0,
				targetDays,
			})
		}

		const entry = byType.get(ctId)
		entry.total++
		entry.totalDays += actualDays
		if (isWithin) entry.withinSla++
	}

	const breakdown = Array.from(byType.values()).map((entry) => ({
		id: entry.id,
		name: entry.name,
		total: entry.total,
		withinSla: entry.withinSla,
		rate:
			entry.total > 0
				? Math.round((entry.withinSla / entry.total) * 100)
				: null,
		avgActual:
			entry.total > 0 ? Math.round(entry.totalDays / entry.total) : null,
		targetDays: entry.targetDays,
	}))

	return {
		overallRate: total > 0 ? Math.round((withinSla / total) * 100) : null,
		withinSla,
		total,
		excluded,
		byType: breakdown,
	}
}

/**
 * Default histogram bins for processing time distribution.
 */
const DEFAULT_BINS = [
	{ label: '0-7', min: 0, max: 7 },
	{ label: '8-14', min: 8, max: 14 },
	{ label: '15-21', min: 15, max: 21 },
	{ label: '22-28', min: 22, max: 28 },
	{ label: '29-42', min: 29, max: 42 },
	{ label: '43-56', min: 43, max: 56 },
	{ label: '57+', min: 57, max: Infinity },
]

/**
 * Compute processing time distribution for a histogram.
 *
 * @param {object[]} completedCases Completed cases with startDate/endDate
 * @param {object[]} caseTypes All case types
 * @param {Array<{ label, min, max }>} [bins] Custom bin definitions
 * @return {{ bins: Array<{ label, count }>, slaTargetDays: number|null }}
 */
/**
 * @param {Array} completedCases The completed cases.
 * @param {Array} caseTypes The case types.
 * @param {Array} bins The bins.
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
export function computeProcessingTimeDistribution(completedCases, caseTypes, bins) {
	const useBins = bins || DEFAULT_BINS
	const caseTypeMap = buildCaseTypeMap(caseTypes)

	const result = useBins.map((b) => ({
		label: b.label,
		count: 0,
		min: b.min,
		max: b.max,
	}))

	let slaTargetDays = null
	const targetSet = new Set()

	for (const c of completedCases) {
		const days = getProcessingDays(c)
		if (days === null) continue

		const target = getSlaTargetDays(c, caseTypeMap)
		if (target !== null) targetSet.add(target)

		for (const bin of result) {
			if (days >= bin.min && days <= bin.max) {
				bin.count++
				break
			}
		}
	}

	// Show SLA target line only when there is exactly one unique target (single case type filtered)
	if (targetSet.size === 1) {
		slaTargetDays = targetSet.values().next().value
	}

	return {
		bins: result.map((b) => ({ label: b.label, count: b.count })),
		slaTargetDays,
	}
}

/**
 * Compute monthly SLA compliance trend.
 *
 * @param {object[]} completedCases Completed cases
 * @param {object[]} caseTypes All case types
 * @param {number} [months] Number of months to look back (defaults to 12)
 * @return {Array<{ month: string, rate: number|null, withinSla: number, total: number }>}
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
export function computeMonthlyTrend(completedCases, caseTypes, months) {
	const lookback = months || 12
	const caseTypeMap = buildCaseTypeMap(caseTypes)
	const now = new Date()

	// Build month buckets
	const buckets = []
	for (let i = lookback - 1; i >= 0; i--) {
		const d = new Date(now.getFullYear(), now.getMonth() - i, 1)
		const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
		buckets.push({ month: key, withinSla: 0, total: 0 })
	}

	const bucketMap = new Map()
	for (const b of buckets) {
		bucketMap.set(b.month, b)
	}

	for (const c of completedCases) {
		if (!c.endDate) continue
		const endMonth = c.endDate.slice(0, 7)
		const bucket = bucketMap.get(endMonth)
		if (!bucket) continue

		const targetDays = getSlaTargetDays(c, caseTypeMap)
		const actualDays = getProcessingDays(c)
		if (targetDays === null || actualDays === null) continue

		bucket.total++
		if (actualDays <= targetDays) bucket.withinSla++
	}

	return buckets.map((b) => ({
		month: b.month,
		rate: b.total > 0 ? Math.round((b.withinSla / b.total) * 100) : null,
		withinSla: b.withinSla,
		total: b.total,
	}))
}

/**
 * Get at-risk open cases: cases with less than thresholdPct of processing time remaining.
 *
 * @param {object[]} openCases Open (non-final) cases
 * @param {object[]} caseTypes All case types
 * @param {number} [thresholdPct] Threshold as fraction (defaults to 0.25 = 25%)
 * @return {Array<{ id, title, identifier, caseTypeName, targetDays, elapsedDays, remainingDays, percentUsed, isOverdue }>}
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
export function getAtRiskCases(openCases, caseTypes, thresholdPct) {
	const threshold = thresholdPct ?? 0.25
	const caseTypeMap = buildCaseTypeMap(caseTypes)
	const today = new Date()
	today.setHours(0, 0, 0, 0)
	const results = []

	for (const c of openCases) {
		const targetDays = getSlaTargetDays(c, caseTypeMap)
		if (targetDays === null) continue

		if (!c.startDate) continue
		const start = new Date(c.startDate)
		start.setHours(0, 0, 0, 0)
		const elapsedDays = Math.floor((today - start) / (1000 * 60 * 60 * 24))
		const remainingDays = targetDays - elapsedDays
		const percentUsed = elapsedDays / targetDays
		const isOverdue = remainingDays < 0

		// Include if overdue or remaining time is less than threshold
		if (isOverdue || 1 - percentUsed <= threshold) {
			const ct = caseTypeMap.get(c.caseType)
			results.push({
				id: c.id,
				title: c.title || '',
				identifier: c.identifier || '',
				caseTypeName: ct?.title || ct?.name || t('dossiq', 'Unknown'),
				targetDays,
				elapsedDays,
				remainingDays,
				percentUsed: Math.min(percentUsed, 1.5), // Cap at 150% for display
				isOverdue,
			})
		}
	}

	// Sort: overdue first (most overdue at top), then by least remaining time
	results.sort((a, b) => {
		if (a.isOverdue && !b.isOverdue) return -1
		if (!a.isOverdue && b.isOverdue) return 1
		return a.remainingDays - b.remainingDays
	})

	return results
}

/**
 * Compute per-case-type performance table rows.
 *
 * @param {object[]} completedCases Completed cases
 * @param {object[]} caseTypes All case types
 * @return {Array<{ id, name, targetDays, avgActualDays, complianceRate, total, withinSla, status: 'good'|'warning'|'critical'|'no-target' }>}
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
export function computePerformanceTable(completedCases, caseTypes) {
	const byType = new Map()

	// Initialize all case types (even those with no completed cases)
	for (const ct of caseTypes) {
		const targetDays = ct.processingDeadline
			? parseDurationToDays(ct.processingDeadline)
			: null
		byType.set(ct.id, {
			id: ct.id,
			name: ct.title || ct.name || t('dossiq', 'Unknown'),
			targetDays,
			totalDays: 0,
			total: 0,
			withinSla: 0,
		})
	}

	for (const c of completedCases) {
		const entry = byType.get(c.caseType)
		if (!entry) continue

		const actualDays = getProcessingDays(c)
		if (actualDays === null) continue

		entry.total++
		entry.totalDays += actualDays
		if (entry.targetDays !== null && actualDays <= entry.targetDays) {
			entry.withinSla++
		}
	}

	return Array.from(byType.values()).map((entry) => {
		const avgActualDays =
			entry.total > 0 ? Math.round(entry.totalDays / entry.total) : null
		const complianceRate =
			entry.total > 0 && entry.targetDays !== null
				? Math.round((entry.withinSla / entry.total) * 100)
				: null

		let status = 'no-target'
		if (entry.targetDays !== null && complianceRate !== null) {
			if (complianceRate >= 90) status = 'good'
			else if (complianceRate >= 70) status = 'warning'
			else status = 'critical'
		}

		return {
			id: entry.id,
			name: entry.name,
			targetDays: entry.targetDays,
			avgActualDays,
			complianceRate,
			total: entry.total,
			withinSla: entry.withinSla,
			status,
		}
	})
}
