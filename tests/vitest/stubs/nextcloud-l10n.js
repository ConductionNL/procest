/**
 * SPDX-FileCopyrightText: 2026 Conduction / Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lightweight stub for @nextcloud/l10n used by the Vitest unit suite.
 *
 * The real package resolves the active locale from the Nextcloud runtime,
 * which does not exist under Vitest. For pure-logic tests we only need a
 * deterministic translate() that returns the English source string with
 * {placeholder} substitution, so assertions can target exact output.
 */

/**
 * Substitute {key} placeholders from a vars object into a string.
 *
 * @param {string} text Source string with optional {placeholders}
 * @param {object} [vars] Replacement values keyed by placeholder name
 * @return {string}
 */
function interpolate(text, vars) {
	if (!vars) return text
	return text.replace(/\{(\w+)\}/g, (match, key) =>
		Object.hasOwn(vars, key) ? String(vars[key]) : match,
	)
}

export function translate(app, text, vars) {
	return interpolate(text, vars)
}

export function translatePlural(app, singular, plural, count, vars) {
	return interpolate(count === 1 ? singular : plural, { count, ...vars })
}

export const t = translate
export const n = translatePlural

// --- Locale/calendar metadata -----------------------------------------
//
// The real @nextcloud/l10n reads these from `document.documentElement`,
// which is only present under jsdom (the component smoke tests) and would
// throw under the default `node` environment (the pure-logic tests). The
// `@nextcloud/vue` component library (`NcActions`/`NcDateTimePicker` and
// friends, pulled in transitively whenever a `.vue` file imports so much as
// `NcButton`) imports these at module-eval time regardless of whether the
// mounted component ever uses locale-aware formatting, so fixed English/UTC
// defaults are supplied unconditionally.

export const getLanguage = () => 'en'
export const getCanonicalLocale = () => 'en'
export const isRTL = () => false
export const getFirstDay = () => 1
export function getDayNames() {
	return [
		'Sunday',
		'Monday',
		'Tuesday',
		'Wednesday',
		'Thursday',
		'Friday',
		'Saturday',
	]
}
export function getDayNamesShort() {
	return ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
}
export const getDayNamesMin = () => ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']
export function getMonthNames() {
	return [
		'January',
		'February',
		'March',
		'April',
		'May',
		'June',
		'July',
		'August',
		'September',
		'October',
		'November',
		'December',
	]
}
export function getMonthNamesShort() {
	return [
		'Jan',
		'Feb',
		'Mar',
		'Apr',
		'May',
		'Jun',
		'Jul',
		'Aug',
		'Sep',
		'Oct',
		'Nov',
		'Dec',
	]
}
export const formatRelativeTime = (date) => String(date)
