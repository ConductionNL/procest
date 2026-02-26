import { translate as t } from '@nextcloud/l10n'
import { isValidDuration } from './durationHelpers.js'

export const REQUIRED_FIELDS = [
	'title',
	'purpose',
	'trigger',
	'subject',
	'processingDeadline',
	'origin',
	'confidentiality',
	'responsibleUnit',
]

export function getOriginOptions() {
	return [
		{ id: 'internal', label: t('procest', 'Internal') },
		{ id: 'external', label: t('procest', 'External') },
	]
}

export function getConfidentialityOptions() {
	return [
		{ id: 'public', label: t('procest', 'Public') },
		{ id: 'restricted', label: t('procest', 'Restricted') },
		{ id: 'internal', label: t('procest', 'Internal') },
		{ id: 'case_sensitive', label: t('procest', 'Case sensitive') },
		{ id: 'confidential', label: t('procest', 'Confidential') },
		{ id: 'highly_confidential', label: t('procest', 'Highly confidential') },
		{ id: 'secret', label: t('procest', 'Secret') },
		{ id: 'top_secret', label: t('procest', 'Top secret') },
	]
}

/**
 * Validate a case type object. Returns per-field errors.
 *
 * @param {object} data Case type data
 * @return {{ valid: boolean, errors: object }}
 */
export function validateCaseType(data) {
	const errors = {}

	for (const field of REQUIRED_FIELDS) {
		if (!data[field] || (typeof data[field] === 'string' && !data[field].trim())) {
			errors[field] = t('procest', '{field} is required', { field: getFieldLabel(field) })
		}
	}

	if (data.processingDeadline && !isValidDuration(data.processingDeadline)) {
		errors.processingDeadline = t('procest', 'Must be a valid ISO 8601 duration (e.g., P56D)')
	}

	if (data.serviceTarget && !isValidDuration(data.serviceTarget)) {
		errors.serviceTarget = t('procest', 'Must be a valid ISO 8601 duration (e.g., P42D)')
	}

	if (data.extensionAllowed && (!data.extensionPeriod || !data.extensionPeriod.trim())) {
		errors.extensionPeriod = t('procest', 'Extension period is required when extension is allowed')
	}

	if (data.extensionPeriod && !isValidDuration(data.extensionPeriod)) {
		errors.extensionPeriod = t('procest', 'Must be a valid ISO 8601 duration (e.g., P28D)')
	}

	if (data.validFrom && data.validUntil && data.validUntil <= data.validFrom) {
		errors.validUntil = t('procest', "'Valid until' must be after 'Valid from'")
	}

	return {
		valid: Object.keys(errors).length === 0,
		errors,
	}
}

/**
 * Validate whether a case type can be published.
 *
 * @param {object} caseType Case type data
 * @param {Array} statusTypes Array of status type objects linked to this case type
 * @return {{ valid: boolean, errors: string[] }}
 */
export function validateForPublish(caseType, statusTypes) {
	const errors = []

	const fieldValidation = validateCaseType(caseType)
	if (!fieldValidation.valid) {
		const missing = Object.keys(fieldValidation.errors)
			.map(f => getFieldLabel(f))
			.join(', ')
		errors.push(t('procest', 'Missing required fields: {fields}', { fields: missing }))
	}

	if (!caseType.validFrom) {
		errors.push(t('procest', "'Valid from' date must be set"))
	}

	if (!statusTypes || statusTypes.length === 0) {
		errors.push(t('procest', 'At least one status type must be defined'))
	} else {
		const hasFinal = statusTypes.some(st => st.isFinal)
		if (!hasFinal) {
			errors.push(t('procest', 'At least one status type must be marked as final'))
		}
	}

	return {
		valid: errors.length === 0,
		errors,
	}
}

function getFieldLabel(field) {
	const labels = {
		title: t('procest', 'Title'),
		purpose: t('procest', 'Purpose'),
		trigger: t('procest', 'Trigger'),
		subject: t('procest', 'Subject'),
		processingDeadline: t('procest', 'Processing deadline'),
		origin: t('procest', 'Origin'),
		confidentiality: t('procest', 'Confidentiality'),
		responsibleUnit: t('procest', 'Responsible unit'),
		extensionPeriod: t('procest', 'Extension period'),
		serviceTarget: t('procest', 'Service target'),
		validUntil: t('procest', 'Valid until'),
	}
	return labels[field] || field
}
