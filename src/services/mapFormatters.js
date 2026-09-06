/**
 * Map marker formatters for manifest-driven `type: 'map'` pages.
 *
 * Registered into the lib's `mapFormatters` registry from `main.js`,
 * mirroring the `customComponents` resolution pattern. `CnMapPage`
 * looks up `config.marker.formatter` by name and invokes the resolved
 * function once per object in the result set.
 *
 * Each formatter receives a single domain object and returns either a
 * marker descriptor `{ lat, lon, color, icon, popup, onClick }` or
 * `null` to skip objects without usable geometry.
 *
 * @file src/services/mapFormatters.js
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * Status → NL Design System colour token. No hardcoded hex anywhere;
 * the lib resolves the CSS variable at render time so the same palette
 * adapts to light/dark/high-contrast themes.
 *
 * Priority for cluster colours (most severe first):
 *   blocked > in_progress > open > closed
 *
 * @param {string} status The case status.
 * @return {string} CSS variable reference.
 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
 */
export function statusColor(status) {
	switch (status) {
		case 'blocked':
			return 'var(--color-status-error)'
		case 'in_progress':
			return 'var(--color-status-warning)'
		case 'open':
			return 'var(--color-status-info)'
		case 'closed':
			return 'var(--color-text-maxcontrast)'
		default:
			return 'var(--color-text-maxcontrast)'
	}
}

/**
 * Status → Material Design icon glyph name.
 *
 * @param {string} status The case status.
 * @return {string} Icon glyph name.
 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
 */
export function statusIcon(status) {
	switch (status) {
		case 'blocked':
			return 'alert-circle'
		case 'in_progress':
			return 'progress-clock'
		case 'open':
			return 'map-marker'
		case 'closed':
			return 'check-circle'
		default:
			return 'map-marker'
	}
}

/**
 * Extract `{ lat, lon }` from a GeoJSON geometry. Supports:
 *
 *   - `Point`:   coordinates are `[lon, lat]` per RFC 7946.
 *   - `Polygon`: arithmetic-mean centroid of the outer ring (sufficient
 *                for pin placement — matches `case-location` REQ-LOC-03b).
 *
 * Any other geometry type, or missing/invalid input, returns `null`
 * so the caller can skip that object.
 *
 * @param {object} geometry GeoJSON geometry object.
 * @return {{lat: number, lon: number}|null} Coordinates or null.
 */
function extractCoords(geometry) {
	if (!geometry || typeof geometry !== 'object') {
		return null
	}
	if (
		geometry.type === 'Point'
		&& Array.isArray(geometry.coordinates)
		&& geometry.coordinates.length >= 2
	) {
		const [lon, lat] = geometry.coordinates
		if (Number.isFinite(lat) && Number.isFinite(lon)) {
			return { lat, lon }
		}
		return null
	}
	if (
		geometry.type === 'Polygon'
		&& Array.isArray(geometry.coordinates)
		&& Array.isArray(geometry.coordinates[0])
	) {
		const ring = geometry.coordinates[0]
		let sumLat = 0
		let sumLon = 0
		let count = 0
		for (const pair of ring) {
			if (
				Array.isArray(pair)
				&& pair.length >= 2
				&& Number.isFinite(pair[0])
				&& Number.isFinite(pair[1])
			) {
				sumLon += pair[0]
				sumLat += pair[1]
				count++
			}
		}
		if (count === 0) {
			return null
		}
		return { lat: sumLat / count, lon: sumLon / count }
	}
	return null
}

/**
 * Project a case object onto a Leaflet marker descriptor.
 *
 * @param {object} caseObj Case object from OpenRegister.
 * @return {object|null}   Marker descriptor or `null` if no geometry.
 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
 */
export function caseMarkerFormatter(caseObj) {
	if (!caseObj || typeof caseObj !== 'object') {
		return null
	}
	const coords = extractCoords(caseObj.geometry)
	if (!coords) {
		return null
	}
	return {
		lat: coords.lat,
		lon: coords.lon,
		color: statusColor(caseObj.status),
		icon: statusIcon(caseObj.status),
		popup: {
			title: caseObj.title,
			subtitle: caseObj.identifier,
			status: caseObj.status,
		},
		onClick: {
			route: 'CaseDetail',
			params: { id: caseObj.id },
		},
	}
}

/**
 * Shape OpenRegister maps-overview point rows into the GeoJSON Feature array
 * that `@conduction/nextcloud-vue`'s `CnMapWidget` renders inline
 * (`markers.features[]`). Each OR point is `{ id, label, lat, lng, register,
 * schema, geometry, ...rest }`; OR has already done the geometry extraction
 * and RBAC scoping, so this is pure presentation: it carries the case id +
 * label through to `properties` for the popup / click-through and colours the
 * marker by status when the row exposes one.
 *
 * Defensive: rows without a finite `lat`/`lng` are skipped (OR never emits
 * them, but a malformed payload yields a blank map rather than a throw).
 *
 * @param {Array<object>} points OR maps-overview point rows.
 * @return {Array<object>} GeoJSON Point Feature array for CnMapWidget.
 * @spec openspec/specs/case-map-overview/spec.md
 */
export function shapeMarkerFeatures(points) {
	if (!Array.isArray(points)) {
		return []
	}
	return points
		.filter((p) => p && Number.isFinite(p.lat) && Number.isFinite(p.lng))
		.map((p) => ({
			type: 'Feature',
			geometry: { type: 'Point', coordinates: [p.lng, p.lat] },
			properties: {
				caseId: p.id != null ? String(p.id) : null,
				title: p.label || '',
				status: p.status || null,
				color: statusColor(p.status),
				icon: statusIcon(p.status),
			},
		}))
}

export default {
	caseMarkerFormatter,
	shapeMarkerFeatures,
}
