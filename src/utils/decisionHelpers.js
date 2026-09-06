/**
 * Decision helper utilities for validity calculations and display.
 */

/**
 * Get the validity status of a decision.
 *
 * @param {object} decision Decision object with effectiveDate and expiryDate
 * @return {{ status: string, label: string, style: string, remaining: string|null }}
 * @spec openspec/specs/roles-decisions/spec.md
 */
export function getDecisionValidity(decision) {
	const today = new Date()
	today.setHours(0, 0, 0, 0)

	if (decision.effectiveDate) {
		const effective = new Date(decision.effectiveDate)
		effective.setHours(0, 0, 0, 0)
		if (effective > today) {
			return {
				status: 'not_effective',
				label: t('dossiq', 'Not yet effective'),
				style: 'validity--pending',
				remaining: t('dossiq', 'Effective from {date}', {
					date: formatDecisionDate(decision.effectiveDate),
				}),
			}
		}
	}

	if (decision.expiryDate) {
		const expiry = new Date(decision.expiryDate)
		expiry.setHours(0, 0, 0, 0)

		if (expiry < today) {
			return {
				status: 'expired',
				label: t('dossiq', 'Expired'),
				style: 'validity--expired',
				remaining: null,
			}
		}

		const diffMs = expiry - today
		const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))

		if (diffDays <= 30) {
			return {
				status: 'expiring_soon',
				label: t('dossiq', 'Expires in {days} days', { days: diffDays }),
				style: 'validity--warning',
				remaining: t('dossiq', 'Expires {date}', {
					date: formatDecisionDate(decision.expiryDate),
				}),
			}
		}

		return {
			status: 'active',
			label: t('dossiq', 'Active'),
			style: 'validity--active',
			remaining: t('dossiq', 'Valid until {date}', {
				date: formatDecisionDate(decision.expiryDate),
			}),
		}
	}

	// No expiry date — indefinitely valid
	if (decision.effectiveDate) {
		return {
			status: 'active',
			label: t('dossiq', 'Active'),
			style: 'validity--active',
			remaining: t('dossiq', 'From {date}', {
				date: formatDecisionDate(decision.effectiveDate),
			}),
		}
	}

	return {
		status: 'unknown',
		label: '',
		style: '',
		remaining: null,
	}
}

/**
 * Format a date string for decision display.
 *
 * @param {string} dateString ISO date string
 * @return {string}
 * @spec openspec/specs/roles-decisions/spec.md
 */
export function formatDecisionDate(dateString) {
	if (!dateString) return '—'
	const date = new Date(dateString)
	return date.toLocaleDateString(undefined, {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	})
}

/**
 * Validate a decision form.
 *
 * @param {object} form Decision form data
 * @return {{ valid: boolean, errors: object }}
 * @spec openspec/specs/roles-decisions/spec.md
 */
export function validateDecision(form) {
	const errors = {}

	if (!form.title || !form.title.trim()) {
		errors.title = t('dossiq', 'Title is required')
	}

	if (form.effectiveDate && form.expiryDate) {
		const effective = new Date(form.effectiveDate)
		const expiry = new Date(form.expiryDate)
		if (expiry <= effective) {
			errors.expiryDate = t(
				'dossiq',
				'Expiry date must be after effective date',
			)
		}
	}

	return {
		valid: Object.keys(errors).length === 0,
		errors,
	}
}
