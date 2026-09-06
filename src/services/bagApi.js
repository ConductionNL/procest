/**
 * BAG (Basisregistratie Adressen en Gebouwen) lookup shim.
 *
 * Thin fetch wrapper over dossiq's own `bag#*` routes
 * (`/api/external/bag/{address,pand/{id},verblijfsobject/{id}}`), backed
 * by the authoritative Kadaster BAG API Individuele Bevragingen v2 adapter
 * (`BagApiAdapter`) — dormant by default until `integration.bag.mode` is
 * configured. Unlike `pdokService.js`, these routes are dossiq's own
 * (not proxied through openconnector), because the BRP/KvK adapters this
 * change mirrors also call their upstream directly via
 * `OCP\Http\Client\IClientService`.
 *
 * No UI component consumes this yet — see
 * openspec/changes/bag-register-adapter/design.md Decision 3. Exported so
 * a future case-location enrichment panel (validating `location.source
 * = bag` / `nummeraanduidingId`) can be wired without a backend change.
 *
 * The backend never turns "not configured" or "not found" into an HTTP
 * error — every response is 200 with a `lookupStatus` field
 * (`FOUND | NOT_FOUND | INVALID_INPUT | LOOKUP_DEFERRED | LOOKUP_ERROR`).
 * Callers should branch on `lookupStatus`, not on HTTP status, except for
 * 400 (malformed request) and 401 (no session).
 *
 * @see openspec/changes/bag-register-adapter/design.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const BASE_URL = generateUrl('/apps/dossiq/api/external/bag')

/**
 * Look up address record(s) by postcode + huisnummer.
 *
 * @param {string} postcode Dutch postcode, e.g. `1234AB`.
 * @param {string} huisnummer House number.
 * @param {object} [options] Optional extra query params.
 * @param {string} [options.huisletter] House letter.
 * @param {string} [options.huisnummertoevoeging] House number addition.
 * @return {Promise<{lookupStatus: string, address: object, dormant: boolean, extras: object}>}
 *   The lookup result envelope.
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 */
export async function lookupAddress(postcode, huisnummer, options = {}) {
	const params = { postcode, huisnummer }
	if (options.huisletter) {
		params.huisletter = options.huisletter
	}
	if (options.huisnummertoevoeging) {
		params.huisnummertoevoeging = options.huisnummertoevoeging
	}
	const response = await axios.get(`${BASE_URL}/address`, { params })
	return response.data
}

/**
 * Look up a pand (building) by its BAG identificatie.
 *
 * @param {string} id BAG pand identificatie.
 * @return {Promise<{lookupStatus: string, address: object, dormant: boolean, extras: object}>}
 *   The lookup result envelope.
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 */
export async function lookupPand(id) {
	const response = await axios.get(`${BASE_URL}/pand/${encodeURIComponent(id)}`)
	return response.data
}

/**
 * Look up a verblijfsobject by its BAG identificatie.
 *
 * @param {string} id BAG verblijfsobject identificatie.
 * @return {Promise<{lookupStatus: string, address: object, dormant: boolean, extras: object}>}
 *   The lookup result envelope.
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 */
export async function lookupVerblijfsobject(id) {
	const response = await axios.get(
		`${BASE_URL}/verblijfsobject/${encodeURIComponent(id)}`,
	)
	return response.data
}
