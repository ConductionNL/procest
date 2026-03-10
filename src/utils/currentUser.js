/**
 * Get the current user ID. Uses OC.getCurrentUser() (replacement for deprecated OC.currentUser).
 *
 * @param {string} [fallback=''] - Value to return when user is not available
 * @return {string}
 */
export function getCurrentUserId(fallback = '') {
	if (typeof OC === 'undefined' || !OC.getCurrentUser) {
		return fallback
	}
	const user = OC.getCurrentUser()
	return user?.uid ?? fallback
}
