/**
 * BRK (Basisregistratie Kadaster) parcel lookup shim.
 *
 * Thin fetch wrapper over dossiq's own `brk#*` routes
 * (`/api/external/brk/{parcel,parcel/{id}}`), backed by the authoritative
 * Kadaster Haal Centraal BRK Bevragen API v2 adapter (`BrkApiAdapter`) —
 * dormant by default until `integration.brk.mode` is configured. Mirrors
 * `bagApi.js` exactly.
 *
 * No UI component consumes this yet — mirrors the BAG/BRP/KvK precedent
 * (see openspec/changes/brk-woz-register-adapters/design.md).
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

const BASE_URL = generateUrl('/apps/dossiq/api/external/brk')

/**
 * Look up a parcel by kadastrale aanduiding.
 *
 * @param {string} kadastraleGemeenteCode Kadastrale gemeentecode.
 * @param {string} sectie Sectie (1-2 uppercase letters).
 * @param {string} perceelnummer Perceelnummer.
 * @param {object} [options] Optional extra query params.
 * @param {string} [options.appartementsrechtVolgnummer] Appartementsrecht volgnummer.
 * @return {Promise<{lookupStatus: string, parcel: object, dormant: boolean, extras: object}>}
 *   The lookup result envelope.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
export async function lookupParcel(
	kadastraleGemeenteCode,
	sectie,
	perceelnummer,
	options = {},
) {
	const params = { kadastraleGemeenteCode, sectie, perceelnummer }
	if (options.appartementsrechtVolgnummer) {
		params.appartementsrechtVolgnummer = options.appartementsrechtVolgnummer
	}
	const response = await axios.get(`${BASE_URL}/parcel`, { params })
	return response.data
}

/**
 * Look up a parcel by its Kadaster identificatie.
 *
 * @param {string} id BRK kadastraalOnroerendeZaak identificatie.
 * @return {Promise<{lookupStatus: string, parcel: object, dormant: boolean, extras: object}>}
 *   The lookup result envelope.
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */
export async function lookupParcelById(id) {
	const response = await axios.get(`${BASE_URL}/parcel/${encodeURIComponent(id)}`)
	return response.data
}
