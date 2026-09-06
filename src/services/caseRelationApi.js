/**
 * Case-relation (typed peer / relevanteAndereZaken) API service for Dossiq.
 *
 * Wraps the typed peer-relation endpoints exposed by
 * `lib/Controller/CaseRelationController.php`:
 *   GET    /apps/dossiq/api/cases/{caseId}/relations
 *   POST   /apps/dossiq/api/cases/{caseId}/relations
 *   DELETE /apps/dossiq/api/cases/{caseId}/relations/{targetId}/{natureRelationship}
 *
 * Relations are typed (`vervolg` | `onderwerp` | `bijdrage`), bidirectionally
 * consistent (written symmetrically server-side), and guarded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// Re-export the pure presentation helpers (kept NC-network-free for testing).
export {
	AARD_RELATIE_TYPES,
	relationErrorMessage,
	relationTypeLabel,
} from '../utils/caseRelationHelpers.js'

/**
 *
 * @param {string} caseId Identifier of the case id.
 */
function base(caseId) {
	return generateUrl(
		`/apps/dossiq/api/cases/${encodeURIComponent(caseId)}/relations`,
	)
}

/**
 * Fetch the typed peer relations of a case.
 *
 * @param {string} caseId Case UUID.
 * @return {Promise<Array>} Relation entries {caseId, aardRelatie, toelichting?}.
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
export async function fetchRelations(caseId) {
	const { data } = await axios.get(base(caseId))
	return data.results || []
}

/**
 * Create a typed peer relation between two cases.
 *
 * @param {string} caseId Origin case UUID.
 * @param {object} params Relation params.
 * @param {string} params.targetId Target case UUID.
 * @param {string} params.aardRelatie Relation type.
 * @param {string} [params.toelichting] Optional clarification.
 * @return {Promise<{ok: boolean, reason?: string, detail?: string}>}
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
export async function addRelation(caseId, { targetId, aardRelatie, toelichting }) {
	try {
		const { data } = await axios.post(base(caseId), {
			targetId,
			aardRelatie,
			toelichting,
		})
		return data
	} catch (err) {
		if (err?.response?.data) {
			return err.response.data
		}
		return { ok: false, reason: 'unknown_error' }
	}
}

/**
 * Remove a typed peer relation between two cases (two-sided).
 *
 * @param {string} caseId Origin case UUID.
 * @param {string} targetId Target case UUID.
 * @param {string} aardRelatie Relation type.
 * @return {Promise<{ok: boolean, reason?: string}>}
 *
 * @spec openspec/specs/related-case-linking/spec.md
 */
export async function removeRelation(caseId, targetId, aardRelatie) {
	try {
		const url = `${base(caseId)}/${encodeURIComponent(targetId)}/${encodeURIComponent(aardRelatie)}`
		const { data } = await axios.delete(url)
		return data
	} catch (err) {
		if (err?.response?.data) {
			return err.response.data
		}
		return { ok: false, reason: 'unknown_error' }
	}
}
