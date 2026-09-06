// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
/**
 * Besluitvorming API service.
 *
 * Wraps the dossiq /api/besluitvorming endpoints. All HTTP traffic uses
 *
 * @nextcloud/axios for CSRF + auth interop. Never use raw fetch().
 *
 * WHAT LEFT AND WHY. `addToAgenda`, `confirmAgenda` and `generateAgenda` went
 * with the agenda compiler: decidiq owns agenda-building and meetings, and
 * surfaces them on a case through the `decidesk-decisions` integration leaf.
 * `mandaatCheck` went too — it had no caller at all, while its route stays live
 * for the guard that uses it server-side.
 *
 * `generateAgenda` was worse than unused: it POSTed to `/agenda/generate`, for
 * which no route has ever been declared. It could only ever have 404'd.
 *
 * @spec openspec/specs/besluitvorming-workflow/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/dossiq/api/besluitvorming' + path)

/**
 * Trigger (retry) DROP/LVBB publication for a case.
 *
 * @param {string} caseId The case UUID.
 * @return {Promise<object>} The publication result.
 */
export async function publishBesluit(caseId) {
	const response = await axios.post(base('/cases/' + caseId + '/publish'), {})
	return response.data
}
