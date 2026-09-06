/**
 * Case validation utilities for create and update operations.
 */

/**
 * Check whether a case type is usable for creating cases.
 * Must be published (not draft), validFrom <= today, and validUntil >= today or null.
 *
 * @param {object} caseType Case type object
 * @return {boolean}
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
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
 * Get a specific unusable reason for a case type.
 *
 * @param {object} caseType Case type object
 * @return {string|null} Reason why the case type cannot be used, or null if usable
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function getCaseTypeUnusableReason(caseType) {
	if (!caseType) return t('dossiq', 'Case type not found')

	if (caseType.isDraft === true || caseType.isDraft === 'true') {
		return t(
			'dossiq',
			'Cannot create a case with a draft case type. The case type must be published first.',
		)
	}

	const today = new Date()
	today.setHours(0, 0, 0, 0)

	if (caseType.validFrom) {
		const validFrom = new Date(caseType.validFrom)
		validFrom.setHours(0, 0, 0, 0)
		if (validFrom > today) {
			const dateStr = caseType.validFrom.split('T')[0]
			return t(
				'dossiq',
				'Cannot create a case with a case type that is not yet valid. The case type is valid from {date}.',
				{ date: dateStr },
			)
		}
	}

	if (caseType.validUntil) {
		const validUntil = new Date(caseType.validUntil)
		validUntil.setHours(0, 0, 0, 0)
		if (validUntil < today) {
			const dateStr = caseType.validUntil.split('T')[0]
			return t(
				'dossiq',
				'Cannot create a case with an expired case type. The case type was valid until {date}.',
				{ date: dateStr },
			)
		}
	}

	return null
}

/**
 * Validate a case creation form.
 *
 * @param {object} form The form data with title, caseType, etc.
 * @param {object[]} caseTypes Available case types for validation context
 * @return {{ valid: boolean, errors: object }} Validation result
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function validateCaseCreate(form, caseTypes = []) {
	const errors = {}

	if (!form.title || !form.title.trim()) {
		errors.title = t('dossiq', 'Title is required')
	}

	if (!form.caseType) {
		errors.caseType = t('dossiq', 'Case type is required')
	} else {
		const caseType = caseTypes.find(
			(ct) => ct.id === form.caseType || ct.id === form.caseType?.id,
		)
		if (caseType) {
			const reason = getCaseTypeUnusableReason(caseType)
			if (reason) {
				errors.caseType = reason
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
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function validateCaseUpdate(form) {
	const errors = {}

	if (!form.title || !form.title.trim()) {
		errors.title = t('dossiq', 'Title is required')
	}

	return {
		valid: Object.keys(errors).length === 0,
		errors,
	}
}

/**
 * Validate a status change operation.
 *
 * @param {object} targetStatus The status type to transition to
 * @param {object} caseObj The case object
 * @param {object[]} statusTypes Available status types for the case type
 * @return {{ valid: boolean, error: string|null }} Validation result
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
export function validateStatusChange(targetStatus, caseObj, statusTypes) {
	if (!targetStatus) {
		return { valid: false, error: t('dossiq', 'Target status is required') }
	}

	// Verify the target status belongs to this case type
	const validStatus = statusTypes.find((st) => st.id === targetStatus.id)
	if (!validStatus) {
		return {
			valid: false,
			error: t(
				'dossiq',
				"Status '{status}' is not defined for this case type",
				{ status: targetStatus.name },
			),
		}
	}

	return { valid: true, error: null }
}
