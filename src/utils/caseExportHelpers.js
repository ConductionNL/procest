/**
 * URL construction for the case-list CSV/Excel export
 * (case-list-export-via-or-export-leaf).
 *
 * OpenRegister ships the export leaf `GET
 * /apps/openregister/api/objects/{register}/{schema}/export?format=csv|json|excel`
 * (ObjectsController::export -> ExportService), which honours request
 * filters and the current user. Per ADR-022 dossiq consumes this leaf
 * rather than serialising CSV/Excel itself — this module only builds the
 * URL; the browser download and the actual export run entirely in
 * openregister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/case-list-export-via-or-export-leaf/spec.md
 */
import { generateUrl } from '@nextcloud/router'

/**
 * The (dossiq, case) OpenRegister export-leaf endpoint.
 *
 * @type {string}
 */
export const CASE_EXPORT_ENDPOINT =
	'/apps/openregister/api/objects/dossiq/case/export'

/**
 * Build the OpenRegister export-leaf URL for the case list.
 *
 * The current route's query object is passed through unmodified as filter
 * parameters (openregister applies them and enforces access as the current
 * user); `format` is always set first from the given format argument, and
 * array-valued query entries are repeated as multiple `key=value` pairs.
 *
 * @param {string} format Export format, e.g. `'csv'` or `'excel'`.
 * @param {Object<string, string>} [query] The current `$route.query` (or `{}` when unavailable).
 * @return {string} The export-leaf URL including the query string.
 *
 * @spec openspec/specs/case-list-export-via-or-export-leaf/spec.md
 */
export function buildCaseExportUrl(format, query = {}) {
	const params = new URLSearchParams()
	params.set('format', format)

	Object.entries(query || {}).forEach(([key, value]) => {
		if (value === undefined || value === null) {
			return
		}
		if (Array.isArray(value)) {
			value.forEach((entry) => params.append(key, entry))
		} else {
			params.append(key, value)
		}
	})

	return `${generateUrl(CASE_EXPORT_ENDPOINT)}?${params.toString()}`
}
