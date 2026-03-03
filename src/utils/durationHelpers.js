/**
 * ISO 8601 duration helpers for parsing, formatting, and validating durations.
 *
 * Supports: P{n}Y, P{n}M, P{n}W, P{n}D, and combinations like P1Y6M.
 */
import { translate as t } from '@nextcloud/l10n'

const DURATION_REGEX = /^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?$/

/**
 * Check whether a string is a valid ISO 8601 duration.
 *
 * @param {string} value The string to validate
 * @return {boolean}
 */
export function isValidDuration(value) {
	if (!value || typeof value !== 'string') return false
	return DURATION_REGEX.test(value) && value !== 'P'
}

/**
 * Parse an ISO 8601 duration into its components.
 *
 * @param {string} iso ISO 8601 duration string (e.g., "P56D", "P2M", "P1Y6M")
 * @return {{ years: number, months: number, weeks: number, days: number } | null}
 */
export function parseDuration(iso) {
	if (!isValidDuration(iso)) return null
	const match = iso.match(DURATION_REGEX)
	return {
		years: parseInt(match[1] || '0', 10),
		months: parseInt(match[2] || '0', 10),
		weeks: parseInt(match[3] || '0', 10),
		days: parseInt(match[4] || '0', 10),
	}
}

/**
 * Format an ISO 8601 duration as a human-readable string.
 *
 * @param {string} iso ISO 8601 duration string
 * @return {string} Human-readable text (e.g., "56 days", "2 months", "1 year, 6 months")
 */
export function formatDuration(iso) {
	const parsed = parseDuration(iso)
	if (!parsed) return iso || ''

	const parts = []

	if (parsed.years === 1) {
		parts.push(t('procest', '1 year'))
	} else if (parsed.years > 1) {
		parts.push(t('procest', '{n} years', { n: parsed.years }))
	}

	if (parsed.months === 1) {
		parts.push(t('procest', '1 month'))
	} else if (parsed.months > 1) {
		parts.push(t('procest', '{n} months', { n: parsed.months }))
	}

	if (parsed.weeks === 1) {
		parts.push(t('procest', '1 week'))
	} else if (parsed.weeks > 1) {
		parts.push(t('procest', '{n} weeks', { n: parsed.weeks }))
	}

	if (parsed.days === 1) {
		parts.push(t('procest', '1 day'))
	} else if (parsed.days > 1) {
		parts.push(t('procest', '{n} days', { n: parsed.days }))
	}

	return parts.length > 0 ? parts.join(', ') : iso
}

/**
 * Get a validation error message for a duration field, or empty string if valid.
 *
 * @param {string} value The value to validate
 * @return {string} Error message or empty string
 */
export function getDurationError(value) {
	if (!value) return ''
	if (!isValidDuration(value)) {
		return t('procest', 'Must be a valid ISO 8601 duration (e.g., P56D for 56 days, P8W for 8 weeks, P2M for 2 months)')
	}
	return ''
}
