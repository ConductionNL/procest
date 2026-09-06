/**
 * OpenRegister analytics-series client for Dossiq.
 *
 * Consumes OpenRegister's page-level analytics-series integration surface
 * (ADR-022) — the leaf-foundation render contract landed on openregister
 * development (PR #151):
 *
 *   POST /apps/openregister/api/integrations/analytics/series
 *        — register / refresh a pre-computed series ({ seriesKey, labels,
 *          datasets, title, chartType, visibility }). OR persists it and
 *          declares a matching `chart` page-widget on the IntegrationRegistry.
 *   GET  /apps/openregister/api/integrations/analytics/series/{seriesKey}
 *        — fetch the RBAC-scoped render contract.
 *
 * Dossiq OWNS the SLA maths (deadlines / breaches / throughput in
 * doorlooptijdHelpers.js + chartShaping.js) and hands the resulting series to
 * OR; OR owns persistence + the chart-ready render contract. The chart is then
 * drawn by `@conduction/nextcloud-vue`'s declarative `CnChartWidget` (which
 * owns the chart engine) — dossiq embeds no chart library of its own.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/sla-charts-via-analytics-leaf/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER_URL = generateUrl(
	'/apps/openregister/api/integrations/analytics/series',
)

/**
 * Register (or refresh) a pre-computed page-level series with OpenRegister's
 * analytics-series surface. Idempotent on `seriesKey` (OR upserts).
 *
 * @param {object} series The series to register.
 * @param {string} series.seriesKey Stable key (e.g. `procest-sla-compliance-by-type`).
 *   These keys are FROZEN at the old `procest-` prefix across the app-id
 *   rename: OR upserts on `seriesKey`, so renaming one orphans the stored
 *   series rather than moving it.
 *
 * @param {string[]} series.labels Category / slice labels.
 * @param {Array} series.datasets Chart datasets ([{ name, data }] or number[] for pie/donut).
 * @param {string} [series.title] Human-readable chart title.
 * @param {string} [series.chartType] One of line|bar|pie|doughnut|area|radar.
 * @param {string} [series.visibility] private|group|public (default private).
 * @return {Promise<object|null>} The stored render contract, or null on failure
 *   (the dashboard degrades to its empty state — never throws into the view).
 *
 * @spec openspec/specs/sla-charts-via-analytics-leaf/spec.md
 */
export async function registerSeries({
	seriesKey,
	labels = [],
	datasets = [],
	title = null,
	chartType = 'line',
	visibility = 'private',
}) {
	try {
		const response = await axios.post(REGISTER_URL, {
			seriesKey,
			labels,
			datasets,
			title,
			chartType,
			visibility,
		})
		return response.data
	} catch (err) {
		console.warn('[dossiq] analytics-series register failed for', seriesKey, err)
		return null
	}
}

/**
 * Fetch a previously registered series' render contract from OpenRegister.
 *
 * @param {string} seriesKey The series key.
 * @return {Promise<object|null>} The render contract { seriesKey, title,
 *   chartType, labels, datasets, visibility }, or null when unknown / not
 *   readable (uniform 404, fail-closed) / OR unavailable.
 *
 * @spec openspec/specs/sla-charts-via-analytics-leaf/spec.md
 */
export async function fetchSeries(seriesKey) {
	try {
		const response = await axios.get(
			`${REGISTER_URL}/${encodeURIComponent(seriesKey)}`,
		)
		return response.data
	} catch (err) {
		return null
	}
}
