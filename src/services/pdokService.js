/**
 * PDOK address lookup shim.
 *
 * Routes all PDOK Locatieserver access through the openconnector PDOK adapter
 * at /index.php/apps/openconnector/api/pdok/{suggest|lookup|free|reverse}.
 * Direct browser calls to api.pdok.nl are NOT permitted from this app — see
 * Hydra umbrella `shared-pdok-via-openconnector` (ADR-022).
 *
 * Exports six functions with the same signatures as the original
 * pdokService.js so existing dossiq callers do not need to change:
 *   suggest(query), lookup(id), free(query, rows), reverse(lat, lng),
 *   extractCoordinates(result), formatAddress(result).
 *
 * Degraded modes:
 *   - openconnector returns 503 (PDOK unavailable / circuit open): the calling
 *     function resolves with `null` and the response `message_key` is attached
 *     to the module's `lastWarning` for display by the caller.
 *   - openconnector not installed (HTTP 404): the shim sets a non-blocking
 *     warning and resolves with an empty result — the form must remain submittable.
 *
 * @see hydra/openspec/changes/shared-pdok-via-openconnector/design.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const BASE_URL = generateUrl('/apps/openconnector/api/pdok')

let debounceTimer = null

/**
 * Module-level last warning for the most recent degraded call.
 *
 * Components can read this after awaiting a shim call to surface an
 * inline message. It is reset to null at the start of every successful call.
 *
 * @type {{messageKey: string, status: number}|null}
 */
export let lastWarning = null

/**
 * Reset the module-level warning state.
 */
function clearWarning() {
	lastWarning = null
}

/**
 * Record a degraded-state warning that the caller can surface in the UI.
 *
 * @param {string} messageKey i18n key from the openconnector response body.
 * @param {number} status     HTTP status that triggered the degraded path.
 */
function recordWarning(messageKey, status) {
	lastWarning = { messageKey, status }
}

/**
 * Handle a shim network error.
 *
 * 503: returns the wrapped null result with the message_key surfaced.
 * 404: records the openconnector-absent warning and returns an empty result.
 * Other errors: rethrows so the caller can decide.
 *
 * @param {Error} error    The axios error.
 * @param {unknown} fallback The result shape to return on degraded paths.
 * @return {unknown} The fallback or null based on degraded type.
 * @throws {Error} For unhandled errors.
 */
function handleNetworkError(error, fallback) {
	const status = error?.response?.status
	if (status === 503) {
		const messageKey = error?.response?.data?.message_key || 'pdok.unavailable'
		recordWarning(messageKey, 503)
		return null
	}
	if (status === 404) {
		recordWarning('pdok.openconnector_missing', 404)
		return fallback
	}
	throw error
}

/**
 * Suggest addresses as the user types (autocomplete).
 *
 * Debounced at 200ms to avoid excessive API calls. Returns an array of
 * normalized suggestion objects from openconnector; on 503 returns null and
 * sets `lastWarning`; on 404 returns an empty array and sets `lastWarning`.
 *
 * @param {string} query Search query (min 3 characters).
 * @return {Promise<Array|null>} Suggestions array, empty array, or null.
 * @spec openspec/changes/retrofit-2026-05-25-pdok-integration/tasks.md
 */
export async function suggest(query) {
	if (!query || query.length < 3) {
		return []
	}
	return new Promise((resolve, reject) => {
		clearTimeout(debounceTimer)
		debounceTimer = setTimeout(async () => {
			clearWarning()
			try {
				const response = await axios.get(`${BASE_URL}/suggest`, {
					params: { q: query },
				})
				resolve(response.data?.docs || [])
			} catch (error) {
				try {
					resolve(handleNetworkError(error, []))
				} catch (rethrow) {
					reject(rethrow)
				}
			}
		}, 200)
	})
}

/**
 * Look up a specific result by its openconnector/PDOK id.
 *
 * @param {string} id The PDOK object id.
 * @return {Promise<object|null>} The full result object, or null when degraded.
 * @spec openspec/changes/retrofit-2026-05-25-pdok-integration/tasks.md
 */
export async function lookup(id) {
	if (!id) {
		return null
	}
	clearWarning()
	try {
		const response = await axios.get(`${BASE_URL}/lookup`, { params: { id } })
		return response.data?.docs?.[0] || null
	} catch (error) {
		return handleNetworkError(error, null)
	}
}

/**
 * Free-text search for addresses/locations.
 *
 * @param {string} query Search query.
 * @param {number} rows  Max results (default 10).
 * @return {Promise<Array|null>} Results array, empty array, or null.
 * @spec openspec/changes/retrofit-2026-05-25-pdok-integration/tasks.md
 */
export async function free(query, rows = 10) {
	if (!query) {
		return []
	}
	clearWarning()
	try {
		const response = await axios.get(`${BASE_URL}/free`, {
			params: { q: query, rows },
		})
		return response.data?.docs || []
	} catch (error) {
		return handleNetworkError(error, [])
	}
}

/**
 * Reverse-geocode coordinates to find the nearest address.
 *
 * @param {number} lat Latitude (WGS84).
 * @param {number} lng Longitude (WGS84).
 * @return {Promise<object|null>} Nearest address, or null when degraded.
 * @spec openspec/changes/retrofit-2026-05-25-pdok-integration/tasks.md
 */
export async function reverse(lat, lng) {
	clearWarning()
	try {
		const response = await axios.get(`${BASE_URL}/reverse`, {
			params: { lat, lng },
		})
		return response.data?.docs?.[0] || null
	} catch (error) {
		return handleNetworkError(error, null)
	}
}

/**
 * Extract WGS84 coordinates from a result's location or centroide_ll field.
 *
 * Supports two input shapes:
 *   - A canonical normalized PostalAddress with `location.coordinates = [lng, lat]`.
 *   - A raw PDOK document with `centroide_ll = "POINT(lng lat)"`.
 *
 * Pure utility — no network calls, no module state.
 *
 * @param {object|string} resultOrWkt A PDOK result object or a raw WKT string.
 * @return {{ lat: number, lng: number }|null} Coordinates or null.
 * @spec openspec/changes/retrofit-2026-05-25-pdok-integration/tasks.md
 */
export function extractCoordinates(resultOrWkt) {
	if (!resultOrWkt) {
		return null
	}
	if (typeof resultOrWkt === 'string') {
		return parseWkt(resultOrWkt)
	}
	if (resultOrWkt.location?.coordinates) {
		const [lng, lat] = resultOrWkt.location.coordinates
		return { lat, lng }
	}
	if (resultOrWkt.centroide_ll) {
		return parseWkt(resultOrWkt.centroide_ll)
	}
	return null
}

/**
 * Parse a WKT POINT(lng lat) string into {lat, lng}.
 *
 * @param {string} wkt The WKT input.
 * @return {{ lat: number, lng: number }|null} Parsed coordinates or null.
 */
function parseWkt(wkt) {
	const match = wkt.match(/POINT\(([^ ]+) ([^ ]+)\)/)
	if (!match) {
		return null
	}
	return {
		lng: parseFloat(match[1]),
		lat: parseFloat(match[2]),
	}
}

/**
 * Format a result as a human-readable address string.
 *
 * Pure utility — no network calls. Accepts both canonical normalized objects
 * (with `displayName`) and raw PDOK results (with `weergavenaam`).
 *
 * @param {object} result A result object.
 * @return {string} Formatted address.
 * @spec openspec/changes/retrofit-2026-05-25-pdok-integration/tasks.md
 */
export function formatAddress(result) {
	if (!result) {
		return ''
	}
	return result.displayName || result.weergavenaam || result.display || ''
}
