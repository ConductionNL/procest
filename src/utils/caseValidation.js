/**
 * Case validation utilities for create and update operations.
 */

/**
 * Check whether a case type is usable for creating cases.
 * Must be published (not draft), validFrom <= today, and validUntil >= today or null.
 *
 * @param {object} caseType Case type object
 * @return {boolean}
 */
export function isCaseTypeUsable(caseType) {
	if (!caseType) return false
	if (caseType.isDraft === true || caseType.isDraft === 'true') return false

	const today = new Date()
	today.setHours(0, 0, 0, 0)

	if (caseType.validFrom) {
		const validFrom = new Date(caseType.validFrom)
		validFrom.setHours(0, 0, 0, 0)
		if (validFrom > today) return false
	}

	if (caseType.validUntil) {
		const validUntil = new Date(caseType.validUntil)
		validUntil.setHours(0, 0, 0, 0)
		if (validUntil < today) return false
	}

	return true
}

/**
 * Validate a case creation form.
 *
 * @param {object} form The form data with title, caseType, etc.
 * @param {object[]} caseTypes Available case types for validation context
 * @return {{ valid: boolean, errors: object }} Validation result
 */
export function validateCaseCreate(form, caseTypes = []) {
	const errors = {}

	if (!form.title || !form.title.trim()) {
		errors.title = t('procest', 'Title is required')
	}

	if (!form.caseType) {
		errors.caseType = t('procest', 'Case type is required')
	} else {
		const caseType = caseTypes.find(ct => ct.id === form.caseType || ct.id === form.caseType?.id)
		if (caseType) {
			if (caseType.isDraft === true || caseType.isDraft === 'true') {
				errors.caseType = t('procest', 'Case type \'{name}\' is a draft and cannot be used to create cases', { name: caseType.title })
			} else if (!isCaseTypeUsable(caseType)) {
				const today = new Date()
				today.setHours(0, 0, 0, 0)
				if (caseType.validFrom) {
					const validFrom = new Date(caseType.validFrom)
					validFrom.setHours(0, 0, 0, 0)
					if (validFrom > today) {
						errors.caseType = t('procest', 'Case type is not yet valid (valid from {date})', { date: caseType.validFrom.split('T')[0] })
					}
				}
				if (!errors.caseType && caseType.validUntil) {
					const validUntil = new Date(caseType.validUntil)
					validUntil.setHours(0, 0, 0, 0)
					if (validUntil < today) {
						errors.caseType = t('procest', 'Case type has expired (valid until {date})', { date: caseType.validUntil.split('T')[0] })
					}
				}
			}
		}
	}

	return {
		valid: Object.keys(errors).length === 0,
		errors,
	}
}

/**
 * Validate a case update form.
 *
 * @param {object} form The form data with title
 * @return {{ valid: boolean, errors: object }} Validation result
 */
export function validateCaseUpdate(form) {
	const errors = {}

	if (!form.title || !form.title.trim()) {
		errors.title = t('procest', 'Title is required')
	}

	return {
		valid: Object.keys(errors).length === 0,
		errors,
	}
}
