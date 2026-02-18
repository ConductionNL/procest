import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	const config = await settingsStore.fetchSettings()

	if (config) {
		if (config.register && config.case_schema) {
			objectStore.registerObjectType('case', config.case_schema, config.register)
		}
		if (config.register && config.task_schema) {
			objectStore.registerObjectType('task', config.task_schema, config.register)
		}
		if (config.register && config.status_schema) {
			objectStore.registerObjectType('status', config.status_schema, config.register)
		}
		if (config.register && config.role_schema) {
			objectStore.registerObjectType('role', config.role_schema, config.register)
		}
		if (config.register && config.result_schema) {
			objectStore.registerObjectType('result', config.result_schema, config.register)
		}
		if (config.register && config.decision_schema) {
			objectStore.registerObjectType('decision', config.decision_schema, config.register)
		}
	}

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
