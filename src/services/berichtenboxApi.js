import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/dossiq/api/berichtenbox')

/**
 * @param {object} data The data.
 * @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md
 */
export async function sendMessage(data) {
	const response = await axios.post(`${baseUrl}/send`, data)
	return response.data
}

/**
 * @param {string} caseId Identifier of the case id.
 * @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md
 */
export async function listMessages(caseId) {
	const response = await axios.get(`${baseUrl}/messages`, { params: { caseId } })
	return response.data
}

/** @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md */
export async function getTypeCodes() {
	const response = await axios.get(`${baseUrl}/types`)
	return response.data
}

/**
 * @param {string} messageId Identifier of the message id.
 * @spec openspec/changes/retrofit-2026-05-24-berichtenbox-integration/tasks.md
 */
export async function pollReadStatus(messageId) {
	const response = await axios.post(`${baseUrl}/poll/${messageId}`)
	return response.data
}
