// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
/**
 * WOO publication (via OpenCatalogi) API service.
 *
 * Wraps the dossiq /api/cases/{id}/woo/publish and /woo/withdraw endpoints.
 * All HTTP traffic uses @nextcloud/axios for CSRF + auth interop.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 *
 * @param {string} caseId Identifier of the case id.
 * @param {string} path The path.
 */
function base(caseId, path) {
	return generateUrl('/apps/dossiq/api/cases/' + caseId + '/woo' + path)
}

/**
 * Publish a WOO decision to OpenCatalogi.
 *
 * @param {string} caseId The case UUID.
 * @param {string} decisionId The decision UUID.
 * @return {Promise<object>} `{available, reason?, publicationId?, publicationUrl?}`.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
export async function publishWooDecision(caseId, decisionId) {
	const response = await axios.post(base(caseId, '/publish'), { decisionId })
	return response.data
}

/**
 * Withdraw (depublish) a WOO decision from OpenCatalogi.
 *
 * @param {string} caseId The case UUID.
 * @param {string} decisionId The decision UUID.
 * @return {Promise<object>} `{available, reason?}`.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */
export async function withdrawWooPublication(caseId, decisionId) {
	const response = await axios.post(base(caseId, '/withdraw'), { decisionId })
	return response.data
}
