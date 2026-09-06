/**
 * WOZ (Waardering Onroerende Zaken) value lookup shim.
 *
 * Thin fetch wrapper over dossiq's own `woz#*` routes
 * (`/api/external/woz/{value,value/{wozobjectnummer}}`), backed by the
 * authoritative Kadaster Haal Centraal WOZ Bevragen API adapter
 * (`WozApiAdapter`) — dormant by default until `integration.woz.mode` is
 * configured. Mirrors `bagApi.js` exactly.
 *
 * Deliberately NOT wired to the public WOZ-waardeloket (wozwaardeloket.nl)
 * — it has no programmatic API (web-only individual-consultation viewer) —
 * see openspec/changes/brk-woz-register-adapters/design.md Decision 2.
 *
 * No UI component consumes this yet — mirrors the BAG/BRP/KvK precedent.
 *
 * The backend never turns "not configured" or "not found" into an HTTP
 * error — every response is 200 with a `lookupStatus` field
 * (`FOUND | NOT_FOUND | INVALID_INPUT | LOOKUP_DEFERRED | LOOKUP_ERROR`).
 * Callers should branch on `lookupStatus`, not on HTTP status, except for
 * 400 (malformed request) and 401 (no session).
 *
 * @see openspec/changes/brk-woz-register-adapters/design.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const BASE_URL = generateUrl('/apps/dossiq/api/external/woz')

/**
 * Look up WOZ object(s) by postcode + huisnummer.
 *
 * @param {string} postcode Dutch postcode, e.g. `1234AB`.
 * @param {string} huisnummer House number.
 * @param {object} [options] Optional extra query params.
 * @param {string} [options.huisletter] House letter.
 * @param {string} [options.huisnummertoevoeging] House number addition.
 * @return {Promise<{lookupStatus: string, wozObject: object, dormant: boolean, extras: object}>}
 *   The lookup result envelope.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
export async function lookupWozValue(postcode, huisnummer, options = {}) {
	const params = { postcode, huisnummer }
	if (options.huisletter) {
		params.huisletter = options.huisletter
	}
	if (options.huisnummertoevoeging) {
		params.huisnummertoevoeging = options.huisnummertoevoeging
	}
	const response = await axios.get(`${BASE_URL}/value`, { params })
	return response.data
}

/**
 * Look up WOZ object(s) by BAG nummeraanduiding identificatie — preferred
 * when a caller already holds one (e.g. from `bagApi.js`), avoiding a
 * second address search.
 *
 * @param {string} nummeraanduidingId BAG nummeraanduiding identificatie.
 * @return {Promise<{lookupStatus: string, wozObject: object, dormant: boolean, extras: object}>}
 *   The lookup result envelope.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
export async function lookupWozValueByNummeraanduiding(nummeraanduidingId) {
	const response = await axios.get(`${BASE_URL}/value`, {
		params: { nummeraanduidingId },
	})
	return response.data
}

/**
 * Look up a single WOZ object by its wozobjectnummer.
 *
 * @param {string} wozobjectnummer WOZ object number.
 * @return {Promise<{lookupStatus: string, wozObject: object, dormant: boolean, extras: object}>}
 *   The lookup result envelope.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
export async function lookupWozObject(wozobjectnummer) {
	const response = await axios.get(
		`${BASE_URL}/value/${encodeURIComponent(wozobjectnummer)}`,
	)
	return response.data
}
