/**
 * Advice API service for Dossiq.
 *
 * CRUD lives on OpenRegister (manifest renderer pattern):
 *   GET    /apps/openregister/api/objects/dossiq/adviesAanvraag
 *   POST   /apps/openregister/api/objects/dossiq/adviesAanvraag
 *   GET    /apps/openregister/api/objects/dossiq/adviesAanvraag/{id}
 *   PUT    /apps/openregister/api/objects/dossiq/adviesAanvraag/{id}
 *   DELETE /apps/openregister/api/objects/dossiq/adviesAanvraag/{id}
 *
 * Workflow actions stay on the Dossiq controller:
 *   POST   /apps/dossiq/api/advice/{id}/transition  — fires notification
 *   POST   /apps/dossiq/api/advice/{id}/remind      — dispatches reminder
 *
 * Uses @nextcloud/axios so CSRF tokens are attached automatically.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER = 'dossiq'
const SCHEMA = 'adviesAanvraag'
const OR_BASE = `apps/openregister/api/objects/${REGISTER}/${SCHEMA}`
const ACTION_BASE = 'apps/dossiq/api/advice'

/**
 * Build an OpenRegister objects URL.
 *
 * @param {string} path Optional sub-path (id)
 * @return {string} Fully qualified Nextcloud URL
 */
function orUrl(path = '') {
	const suffix = path ? `/${path}` : ''
	return generateUrl(`/${OR_BASE}${suffix}`)
}

/**
 * Build a Dossiq workflow-action URL.
 *
 * @param {string} path Sub-path (id/action)
 * @return {string} Fully qualified Nextcloud URL
 */
function actionUrl(path) {
	return generateUrl(`/${ACTION_BASE}/${path}`)
}

/**
 * Get advice requests for a case (via manifest renderer / OR filter).
 *
 * @param {string} caseId Case UUID
 * @return {Promise<Array>} List of advice records
 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
 */
export async function getAdviceForCase(caseId) {
	const response = await axios.get(orUrl(), {
		params: { _filters: JSON.stringify({ case: caseId }), _limit: 200 },
	})
	// `x?.results || x || []` admits a STRING: a non-empty body that is not JSON
	// (Nextcloud answers an unmatched app route with an HTML page under HTTP 200)
	// falls through to the raw body, and a `v-for` over a string renders one row
	// per character. See procest#784.
	const body = response.data
	if (Array.isArray(body)) {
		return body
	}
	if (body !== null && typeof body === 'object' && Array.isArray(body.results)) {
		return body.results
	}
	return []
}

/**
 * Transition the status of an advice request.
 *
 * Use { to: 'requested' } right after creating an advice object to fire
 * the notification to the adviseur (workflow side-effect).
 *
 * Use { to: 'received', adviceDocument: '<fileId>' } to mark received.
 *
 * @param {string} id   Advice UUID
 * @param {object} body Transition payload (to, adviesDocument, ...)
 * @return {Promise<object>} Updated record
 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
 */
export async function transitionStatus(id, body) {
	const response = await axios.post(actionUrl(`${id}/transition`), body)
	return response.data
}

/**
 * Dispatch a manual reminder to the adviseur.
 *
 * @param {string} id Advice UUID
 * @return {Promise<object>} Server confirmation
 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
 */
export async function dispatchReminder(id) {
	const response = await axios.post(actionUrl(`${id}/remind`))
	return response.data
}

/**
 * Create an advice request (CRUD via manifest renderer) and fire the
 * "requested" notification via transitionStatus.
 *
 * Kept as a convenience for the case-detail dialog so callers do not need
 * to chain two requests manually.
 *
 * @param {object} data Advice payload (case, adviseur, type, deadline, ...)
 * @return {Promise<object>} Created record
 */
/**
 * Create an advice request and raise the decidesk advice Decision.
 *
 * Routes through the dossiq `advice#createForCase` controller endpoint
 * (AdviceService::requestAdvice) instead of writing the object directly to
 * OpenRegister, so the advice request raises a decidesk `advice` Decision via
 * the ADR-019 integration registry (procest-delegate-remaining-decisions-to-decidesk,
 * REQ-PDRD-001) and fails CLOSED server-side when decidesk is unavailable
 * (REQ-PDRD-002). The advice outcome is consumed as a projection.
 *
 * @param {object} data Advice payload (case, adviseur, type, deadline, ...)
 * @return {Promise<object>} Created record
 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
 * @spec openspec/specs/remaining-decision-delegation/spec.md
 */
export async function createAdviceWithNotification(data) {
	const caseId = data.case || data.caseRef || data.case
	const url = generateUrl('/apps/dossiq/api/vth/cases/{caseId}/advice-requests', {
		caseId,
	})
	const created = await axios.post(url, {
		...data,
		requestedAt: new Date().toISOString(),
	})
	return created.data
}

export default {
	getAdviceForCase,
	createAdviceWithNotification,
	transitionStatus,
	dispatchReminder,
}
