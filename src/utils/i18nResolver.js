/**
 * Register content internationalization resolver.
 *
 * Resolves language-tagged field values from OpenRegister objects,
 * following a deterministic fallback chain: user locale -> app default -> nl -> en -> first available.
 *
 * Language-tagged fields are stored as objects with BCP 47 language keys:
 *   { "nl": "Omgevingsvergunning", "en": "Environmental permit" }
 *
 * Non-translatable fields are stored as plain values (strings, numbers, etc.)
 * and are returned as-is.
 */

/**
 * Default fallback locale for the application.
 */
const APP_DEFAULT_LOCALE = 'nl'

/**
 * Get the current user's locale from Nextcloud.
 *
 * @return {string} BCP 47 language code (e.g., 'nl', 'en', 'de')
 */
/** @spec openspec/changes/retrofit-2026-05-25-procest-app-scaffold/tasks.md */
export function getUserLocale() {
	if (typeof OC !== 'undefined' && typeof OC.getLanguage === 'function') {
		const lang = OC.getLanguage()
		// Nextcloud may return 'en_GB' format; normalize to 'en'
		return lang ? lang.split(/[-_]/)[0].toLowerCase() : APP_DEFAULT_LOCALE
	}
	return APP_DEFAULT_LOCALE
}

/**
 * Resolve a potentially language-tagged field value to a display string.
 *
 * If the value is a plain string (or non-object), it is returned as-is.
 * If the value is a language-tagged object, the fallback chain is applied:
 *   1. Requested locale (e.g., 'en')
 *   2. Fallback locale (e.g., 'nl')
 *   3. App default locale ('nl')
 *   4. English ('en')
 *   5. First available language
 *
 * @param {string|object} value The field value (string or language-tagged object)
 * @param {string} [locale] The preferred locale (defaults to user's locale)
 * @param {string} [fallbackLocale] The fallback locale (defaults to app default 'nl')
 * @return {{ text: string, lang: string|null, isFallback: boolean }}
 * @spec openspec/changes/retrofit-2026-05-25-procest-app-scaffold/tasks.md
 */
export function resolveTranslatable(value, locale, fallbackLocale) {
	// Null/undefined -> empty string
	if (value === null || value === undefined) {
		return { text: '', lang: null, isFallback: false }
	}

	// Plain string or non-object -> pass-through
	if (typeof value !== 'object' || Array.isArray(value)) {
		return { text: String(value), lang: null, isFallback: false }
	}

	// Language-tagged object
	const userLocale = locale || getUserLocale()
	const fallback = fallbackLocale || APP_DEFAULT_LOCALE

	// Build fallback chain (deduplicated, ordered)
	const chain = []
	const seen = new Set()
	for (const lang of [userLocale, fallback, APP_DEFAULT_LOCALE, 'en']) {
		if (lang && !seen.has(lang)) {
			chain.push(lang)
			seen.add(lang)
		}
	}

	// Try each language in order
	for (const lang of chain) {
		if (
			value[lang] !== undefined
			&& value[lang] !== null
			&& value[lang] !== ''
		) {
			return {
				text: String(value[lang]),
				lang,
				isFallback: lang !== userLocale,
			}
		}
	}

	// Last resort: first available language
	const keys = Object.keys(value)
	if (keys.length > 0) {
		const firstLang = keys[0]
		return {
			text: String(value[firstLang]),
			lang: firstLang,
			isFallback: true,
		}
	}

	// Empty object
	return { text: '', lang: null, isFallback: true }
}

/**
 * Convenience function to resolve a specific field from an object.
 *
 * @param {object} obj The OpenRegister object
 * @param {string} field The field name to resolve
 * @param {string} [locale] The preferred locale
 * @return {{ text: string, lang: string|null, isFallback: boolean }}
 * @spec openspec/changes/retrofit-2026-05-25-procest-app-scaffold/tasks.md
 */
export function resolveField(obj, field, locale) {
	if (!obj || typeof obj !== 'object') {
		return { text: '', lang: null, isFallback: false }
	}
	return resolveTranslatable(obj[field], locale)
}

/**
 * Resolve a field to just the display text (convenience for templates).
 *
 * @param {object} obj The OpenRegister object
 * @param {string} field The field name to resolve
 * @param {string} [locale] The preferred locale
 * @return {string} The resolved text
 * @spec openspec/changes/retrofit-2026-05-25-procest-app-scaffold/tasks.md
 */
export function resolveText(obj, field, locale) {
	return resolveField(obj, field, locale).text
}
