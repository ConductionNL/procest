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

/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
export function getOriginOptions() {
	return [
		{ id: 'internal', label: t('dossiq', 'Internal') },
		{ id: 'external', label: t('dossiq', 'External') },
	]
}

/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
export function getConfidentialityOptions() {
	return [
		{ id: 'public', label: t('dossiq', 'Public') },
		{ id: 'restricted', label: t('dossiq', 'Restricted') },
		{ id: 'internal', label: t('dossiq', 'Internal') },
		{ id: 'case_sensitive', label: t('dossiq', 'Case sensitive') },
		{ id: 'confidential', label: t('dossiq', 'Confidential') },
		{ id: 'highly_confidential', label: t('dossiq', 'Highly confidential') },
		{ id: 'secret', label: t('dossiq', 'Secret') },
		{ id: 'top_secret', label: t('dossiq', 'Top secret') },
	]
}

/**
 * Validate a case type object. Returns per-field errors.
 *
 * @param {object} data Case type data
 * @return {{ valid: boolean, errors: object }}
 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
 */
export function validateCaseType(data) {
	const errors = {}

	for (const field of REQUIRED_FIELDS) {
		if (
			!data[field]
			|| (typeof data[field] === 'string' && !data[field].trim())
		) {
			errors[field] = t('dossiq', '{field} is required', {
				field: getFieldLabel(field),
			})
		}
	}

	if (data.processingDeadline && !isValidDuration(data.processingDeadline)) {
		errors.processingDeadline = t(
			'dossiq',
			'Must be a valid ISO 8601 duration (e.g., P56D)',
		)
	}

	if (data.serviceTarget && !isValidDuration(data.serviceTarget)) {
		errors.serviceTarget = t(
			'dossiq',
			'Must be a valid ISO 8601 duration (e.g., P42D)',
		)
	}

	if (
		data.extensionAllowed
		&& (!data.extensionPeriod || !data.extensionPeriod.trim())
	) {
		errors.extensionPeriod = t(
			'dossiq',
			'Extension period is required when extension is allowed',
		)
	}

	if (data.extensionPeriod && !isValidDuration(data.extensionPeriod)) {
		errors.extensionPeriod = t(
			'dossiq',
			'Must be a valid ISO 8601 duration (e.g., P28D)',
		)
	}

	if (data.validFrom && data.validUntil && data.validUntil <= data.validFrom) {
		errors.validUntil = t('dossiq', "'Valid until' must be after 'Valid from'")
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
 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
 */
export function validateForPublish(caseType, statusTypes) {
	const errors = []

	const fieldValidation = validateCaseType(caseType)
	if (!fieldValidation.valid) {
		const missing = Object.keys(fieldValidation.errors)
			.map((f) => getFieldLabel(f))
			.join(', ')
		errors.push(
			t('dossiq', 'Missing required fields: {fields}', { fields: missing }),
		)
	}

	if (!caseType.validFrom) {
		errors.push(t('dossiq', "'Valid from' date must be set"))
	}

	if (!statusTypes || statusTypes.length === 0) {
		errors.push(t('dossiq', 'At least one status type must be defined'))
	} else {
		const hasFinal = statusTypes.some((st) => st.isFinal)
		if (!hasFinal) {
			errors.push(
				t('dossiq', 'At least one status type must be marked as final'),
			)
		}
	}

	return {
		valid: errors.length === 0,
		errors,
	}
}

/**
 *
 * @param {object} field The field.
 */
function getFieldLabel(field) {
	const labels = {
		title: t('dossiq', 'Title'),
		purpose: t('dossiq', 'Purpose'),
		trigger: t('dossiq', 'Trigger'),
		subject: t('dossiq', 'Subject'),
		processingDeadline: t('dossiq', 'Processing deadline'),
		origin: t('dossiq', 'Origin'),
		confidentiality: t('dossiq', 'Confidentiality'),
		responsibleUnit: t('dossiq', 'Responsible unit'),
		extensionPeriod: t('dossiq', 'Extension period'),
		serviceTarget: t('dossiq', 'Service target'),
		validUntil: t('dossiq', 'Valid until'),
	}
	return labels[field] || field
}
