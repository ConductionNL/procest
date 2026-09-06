import { generateUrl } from '@nextcloud/router'
/**
 * Workflow store module for Dossiq.
 *
 * Manages workflow templates, guard evaluation, transition execution,
 * version management, and automatic action dispatch.
 *
 * Uses the object store (createObjectStore) for CRUD operations on
 * workflowTemplate objects in OpenRegister.
 */
import { defineStore } from 'pinia'
import { validateWorkflowGraph } from '../../utils/workflowGraphValidation.js'
import { useObjectStore } from './object.js'

/**
 * Generate a UUID v4.
 *
 * @return {string} A new UUID
 */
function generateUUID() {
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
		const r = (Math.random() * 16) | 0
		const v = c === 'x' ? r : (r & 0x3) | 0x8
		return v.toString(16)
	})
}

export const useWorkflowStore = defineStore('workflow', {
	state: () => ({
		/** @type {object|null} Currently loaded workflow template */
		currentTemplate: null,
		/** @type {Array} All versions of the current case type's workflow */
		versions: [],
		/** @type {boolean} Loading state */
		loading: false,
		/** @type {string|null} Error message */
		error: null,
		/** @type {Array} Validation errors for the current template */
		validationErrors: [],
	}),

	getters: {
		/**
		 * Get the active workflow version from loaded versions.
		 *
		 * @param {object} state The store state
		 * @return {object|null} The active version or null
		 */
		activeVersion: (state) => {
			return state.versions.find((v) => v.isActive && !v.isDraft) || null
		},

		/**
		 * Get the draft version from loaded versions.
		 *
		 * @param {object} state The store state
		 * @return {object|null} The draft version or null
		 */
		draftVersion: (state) => {
			return state.versions.find((v) => v.isDraft) || null
		},

		/**
		 * Get parsed steps from current template.
		 *
		 * @param {object} state The store state
		 * @return {Array} Parsed steps array
		 */
		parsedSteps: (state) => {
			if (!state.currentTemplate?.steps) return []
			try {
				return typeof state.currentTemplate.steps === 'string'
					? JSON.parse(state.currentTemplate.steps)
					: state.currentTemplate.steps
			} catch {
				return []
			}
		},

		/**
		 * Get parsed transitions from current template.
		 *
		 * @param {object} state The store state
		 * @return {Array} Parsed transitions array
		 */
		parsedTransitions: (state) => {
			if (!state.currentTemplate?.transitions) return []
			try {
				return typeof state.currentTemplate.transitions === 'string'
					? JSON.parse(state.currentTemplate.transitions)
					: state.currentTemplate.transitions
			} catch {
				return []
			}
		},

		/**
		 * Get parsed node positions from current template.
		 *
		 * @param {object} state The store state
		 * @return {object} Map of status UUID to {x, y}
		 */
		parsedNodePositions: (state) => {
			if (!state.currentTemplate?.nodePositions) return {}
			try {
				return typeof state.currentTemplate.nodePositions === 'string'
					? JSON.parse(state.currentTemplate.nodePositions)
					: state.currentTemplate.nodePositions
			} catch {
				return {}
			}
		},
	},

	actions: {
		/**
		 * List all workflow template versions for a case type.
		 *
		 * @param {string} caseTypeId UUID of the case type
		 * @return {Promise<Array>} Array of workflow templates
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async listVersions(caseTypeId) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const results = await objectStore.fetchCollection(
					'workflowTemplate',
					{
						caseType: caseTypeId,
						_limit: 100,
						_order: { version: 'desc' },
					},
				)
				this.versions = results || []
				return this.versions
			} catch (err) {
				this.error = err.message
				return []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get a specific workflow template by ID.
		 *
		 * @param {string} templateId UUID of the workflow template
		 * @return {Promise<object|null>} The workflow template or null
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async getTemplate(templateId) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const template = await objectStore.fetchObject(
					'workflowTemplate',
					templateId,
				)
				this.currentTemplate = template
				return template
			} catch (err) {
				this.error = err.message
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get the active workflow version for a case type.
		 *
		 * @param {string} caseTypeId UUID of the case type
		 * @return {Promise<object|null>} The active workflow template or null
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async getActiveVersion(caseTypeId) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const results = await objectStore.fetchCollection(
					'workflowTemplate',
					{
						caseType: caseTypeId,
						isActive: true,
						isDraft: false,
						_limit: 1,
					},
				)
				const active = results && results.length > 0 ? results[0] : null
				return active
			} catch (err) {
				this.error = err.message
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new workflow template for a case type.
		 *
		 * @param {string} caseTypeId UUID of the case type
		 * @param {string} title      Name of the workflow
		 * @return {Promise<object|null>} The created template or null
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async createTemplate(caseTypeId, title) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const template = await objectStore.saveObject('workflowTemplate', {
					title,
					caseType: caseTypeId,
					version: 1,
					isActive: false,
					isDraft: true,
					steps: '[]',
					transitions: '[]',
					nodePositions: '{}',
				})
				this.currentTemplate = template
				return template
			} catch (err) {
				this.error = err.message
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save the current workflow template.
		 *
		 * @param {object} templateData The template data to save
		 * @return {Promise<object|null>} The saved template or null
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async saveTemplate(templateData) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				// Ensure steps/transitions/nodePositions are stringified
				const data = { ...templateData }
				if (typeof data.steps !== 'string') {
					data.steps = JSON.stringify(data.steps)
				}
				if (typeof data.transitions !== 'string') {
					data.transitions = JSON.stringify(data.transitions)
				}
				if (typeof data.nodePositions !== 'string') {
					data.nodePositions = JSON.stringify(data.nodePositions)
				}
				const result = await objectStore.saveObject('workflowTemplate', data)
				this.currentTemplate = result
				return result
			} catch (err) {
				this.error = err.message
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Delete a workflow template.
		 *
		 * @param {string} templateId UUID of the template
		 * @return {Promise<boolean>} Success
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async deleteTemplate(templateId) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				await objectStore.deleteObject('workflowTemplate', templateId)
				if (this.currentTemplate?.id === templateId) {
					this.currentTemplate = null
				}
				return true
			} catch (err) {
				this.error = err.message
				return false
			} finally {
				this.loading = false
			}
		},

		/**
		 * Publish a draft workflow template, making it the active version.
		 *
		 * Calls the canonical `POST /api/workflow-definitions/{id}/publish`
		 * endpoint (`WorkflowDefinitionController::publish` ->
		 * `WorkflowDefinitionService::publish()`) instead of writing
		 * isDraft/isActive flags directly through the generic object store.
		 * That backend method is the ONLY place that enforces referential
		 * integrity (transitions must reference statuses of the same case
		 * type), freezes role-authorization group ids onto transitions
		 * (ADR-022), atomically deprecates the previously active version, and
		 * pins `caseType.workflowDefinition` — none of which a flag-only
		 * write through `objectStore.saveObject()` would trigger. Client
		 * validation still runs first for fast, itemised feedback; the
		 * backend call remains the authoritative backstop.
		 *
		 * @param {string} templateId UUID of the template to publish
		 * @param {Array} [statusNodes] `statusType` graph nodes for client-side
		 *  validation (see `validateWorkflow`) — defense in depth; the caller
		 *  (`WorkflowTab.vue::publish()`) already validates via the editor's
		 *  `validate()` before invoking this action.
		 *
		 * @return {Promise<object|null>} The published template or null
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-publish-uses-the-canonical-write-path
		 */
		async publishVersion(templateId, statusNodes = []) {
			this.error = null

			// Validate first
			const errors = this.validateWorkflow(statusNodes)
			if (errors.length > 0) {
				this.validationErrors = errors
				this.error = t('dossiq', 'Workflow validation failed')
				return null
			}

			this.loading = true
			try {
				const response = await fetch(
					generateUrl(
						`/apps/dossiq/api/workflow-definitions/${templateId}/publish`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					},
				)

				const body = await response.json().catch(() => null)
				if (!response.ok || !body?.success) {
					this.error =
						body?.error
						|| t('dossiq', 'Could not publish workflow definition')
					return null
				}

				this.currentTemplate = body.definition
				this.validationErrors = []
				return body.definition
			} catch (err) {
				this.error = err.message
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new draft version by copying an existing published version.
		 *
		 * @param {string} sourceTemplateId UUID of the source template
		 * @return {Promise<object|null>} The new draft version or null
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async createDraftFromVersion(sourceTemplateId) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const source = await objectStore.fetchObject(
					'workflowTemplate',
					sourceTemplateId,
				)
				if (!source) {
					this.error = t('dossiq', 'Source workflow template not found')
					return null
				}

				// Determine next version number
				const maxVersion = this.versions.reduce(
					(max, v) => Math.max(max, v.version || 0),
					0,
				)

				// Create copy as draft
				const draft = await objectStore.saveObject('workflowTemplate', {
					title: source.title,
					description: source.description,
					caseType: source.caseType,
					version: maxVersion + 1,
					isActive: false,
					isDraft: true,
					steps: source.steps,
					transitions: source.transitions,
					nodePositions: source.nodePositions,
					parentWorkflow: source.parentWorkflow || null,
				})
				this.currentTemplate = draft
				return draft
			} catch (err) {
				this.error = err.message
				return null
			} finally {
				this.loading = false
			}
		},

		// --- Guard Evaluation ---

		/**
		 * Compute available transitions for a case given its data and user roles.
		 *
		 * @param {object} caseData     The case object
		 * @param {Array}  userRoles    Array of role type UUIDs the current user holds
		 * @param {object} workflow     The workflow template (parsed)
		 * @param {Array}  caseTasks    Tasks linked to this case
		 * @param {Array}  caseDocuments Documents linked to this case
		 * @return {Array} Available transitions with guard status
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		computeAvailableTransitions(
			caseData,
			userRoles,
			workflow,
			caseTasks,
			caseDocuments,
		) {
			if (!workflow?.transitions) return []

			const transitions =
				typeof workflow.transitions === 'string'
					? JSON.parse(workflow.transitions)
					: workflow.transitions

			const steps =
				typeof workflow.steps === 'string'
					? JSON.parse(workflow.steps)
					: workflow.steps || []

			const currentStatus = caseData.status

			return transitions
				.filter((t) => t.fromStatus === currentStatus)
				.map((transition) => {
					// Check role access
					const roleAllowed =
						!transition.allowedRoles
						|| transition.allowedRoles.length === 0
						|| transition.allowedRoles.some((r) => userRoles.includes(r))

					if (!roleAllowed) {
						return null // completely hidden
					}

					// Evaluate guards
					const guardResults = this.evaluateGuards(
						transition.guards || [],
						caseData,
						caseTasks,
						caseDocuments,
						steps,
						currentStatus,
					)

					// Check required steps completion
					const requiredStepsResult = this.evaluateRequiredSteps(
						steps,
						currentStatus,
						caseTasks,
					)

					const allGuardsMet =
						guardResults.every((g) => g.met) && requiredStepsResult.met

					const unmetConditions = [
						...guardResults.filter((g) => !g.met).map((g) => g.message),
						...(requiredStepsResult.met
							? []
							: requiredStepsResult.messages),
					]

					return {
						...transition,
						available: allGuardsMet,
						unmetConditions,
					}
				})
				.filter(Boolean)
		},

		/**
		 * Evaluate all guards on a transition.
		 *
		 * @param {Array}  guards        Array of guard definitions
		 * @param {object} caseData      The case object
		 * @param {Array}  caseTasks     Tasks linked to this case
		 * @param {Array}  caseDocuments Documents linked to this case
		 * @param {Array}  steps         Workflow steps
		 * @param {string} currentStatus Current status UUID
		 * @return {Array} Array of {met: boolean, message: string}
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		evaluateGuards(
			guards,
			caseData,
			caseTasks,
			caseDocuments,
			steps,
			currentStatus,
		) {
			return guards.map((guard) => {
				switch (guard.type) {
					case 'checklist':
						return this.evaluateChecklistGuard(guard, caseTasks)
					case 'requiredField':
						return this.evaluateRequiredFieldGuard(guard, caseData)
					case 'requiredDocument':
						return this.evaluateRequiredDocumentGuard(
							guard,
							caseDocuments,
						)
					case 'roleGuard':
						// Role guards are handled at the transition level (filter)
						return { met: true, message: '' }
					default:
						return { met: true, message: '' }
				}
			})
		},

		/**
		 * Evaluate a checklist guard by checking if all checklist items are checked.
		 *
		 * @param {object} guard     The checklist guard definition
		 * @param {Array}  caseTasks Tasks linked to the case
		 * @return {object} {met: boolean, message: string}
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		evaluateChecklistGuard(guard, caseTasks) {
			// Find tasks with checklists and check completion
			const tasksWithChecklists = caseTasks.filter((task) => {
				if (!task.checklist) return false
				const checklist =
					typeof task.checklist === 'string'
						? JSON.parse(task.checklist)
						: task.checklist
				return checklist.length > 0
			})

			for (const task of tasksWithChecklists) {
				const checklist =
					typeof task.checklist === 'string'
						? JSON.parse(task.checklist)
						: task.checklist
				const unchecked = checklist.filter((item) => !item.checked)
				if (unchecked.length > 0) {
					return {
						met: false,
						message: t(
							'dossiq',
							'{count} checklist item(s) not completed: {items}',
							{
								count: unchecked.length,
								items: unchecked.map((i) => i.label).join(', '),
							},
						),
					}
				}
			}

			return { met: true, message: '' }
		},

		/**
		 * Evaluate a required field guard.
		 *
		 * @param {object} guard    The required field guard definition
		 * @param {object} caseData The case object
		 * @return {object} {met: boolean, message: string}
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		evaluateRequiredFieldGuard(guard, caseData) {
			const fieldName = guard.fieldName
			const value = caseData[fieldName]
			if (!value || (typeof value === 'string' && value.trim() === '')) {
				return {
					met: false,
					message: t('dossiq', 'Required field missing: {field}', {
						field: fieldName,
					}),
				}
			}
			return { met: true, message: '' }
		},

		/**
		 * Evaluate a required document guard.
		 *
		 * @param {object} guard         The required document guard definition
		 * @param {Array}  caseDocuments Documents linked to the case
		 * @return {object} {met: boolean, message: string}
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		evaluateRequiredDocumentGuard(guard, caseDocuments) {
			const requiredType = guard.documentTypeName
			const hasDocument = caseDocuments.some(
				(doc) =>
					doc.documentType === requiredType || doc.title === requiredType,
			)
			if (!hasDocument) {
				return {
					met: false,
					message: t('dossiq', 'Required document missing: {type}', {
						type: requiredType,
					}),
				}
			}
			return { met: true, message: '' }
		},

		/**
		 * Evaluate whether all required steps for the current status are completed.
		 *
		 * @param {Array}  steps         Workflow steps
		 * @param {string} currentStatus Current status UUID
		 * @param {Array}  caseTasks     Tasks linked to the case
		 * @return {object} {met: boolean, messages: Array<string>}
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		evaluateRequiredSteps(steps, currentStatus, caseTasks) {
			const stepsInStatus = steps.filter(
				(s) => s.status === currentStatus && s.isRequired,
			)
			const messages = []

			for (const step of stepsInStatus) {
				const matchingTask = caseTasks.find(
					(t) => t.workflowStepId === step.id,
				)
				if (!matchingTask || matchingTask.status !== 'completed') {
					messages.push(
						t('dossiq', 'Required step not completed: {step}', {
							step: step.title,
						}),
					)
				}
			}

			return {
				met: messages.length === 0,
				messages,
			}
		},

		// --- Automatic Actions ---

		/**
		 * Dispatch all automatic actions for a transition or step.
		 *
		 * @param {Array}  actions    Array of action definitions
		 * @param {object} caseData   The case object
		 * @param {object} transition The transition that triggered the actions
		 * @return {Promise<Array>} Array of {action, success, error} results
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async dispatchActions(actions, caseData, transition) {
			const results = []
			for (const action of actions) {
				try {
					switch (action.type) {
						case 'sendEmail':
							await this.dispatchEmailAction(
								action,
								caseData,
								transition,
							)
							results.push({ action: action.type, success: true })
							break
						case 'createTask':
							await this.dispatchCreateTaskAction(action, caseData)
							results.push({ action: action.type, success: true })
							break
						case 'createSubCase':
							await this.dispatchCreateSubCaseAction(action, caseData)
							results.push({ action: action.type, success: true })
							break
						case 'webhook':
							await this.dispatchWebhookAction(
								action,
								caseData,
								transition,
							)
							results.push({ action: action.type, success: true })
							break
						case 'setField':
							await this.dispatchSetFieldAction(action, caseData)
							results.push({ action: action.type, success: true })
							break
						case 'notify':
							await this.dispatchNotifyAction(action, caseData)
							results.push({ action: action.type, success: true })
							break
						default:
							results.push({
								action: action.type,
								success: false,
								error: 'Unknown action type',
							})
					}
				} catch (err) {
					console.error(`Workflow action ${action.type} failed:`, err)
					results.push({
						action: action.type,
						success: false,
						error: err.message,
					})
				}
			}
			return results
		},

		/**
		 * Dispatch a sendEmail action via n8n webhook.
		 *
		 * @param {object} action     The email action configuration
		 * @param {object} caseData   The case object
		 * @param {object} transition The transition context
		 * @return {Promise<void>}
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async dispatchEmailAction(action, caseData, transition) {
			// Delegate to n8n webhook for email sending
			const webhookUrl =
				action.webhookUrl || '/apps/dossiq/api/workflow/actions/email'
			const body = {
				recipient: action.recipient,
				subject: this.interpolateTemplate(
					action.subject || '',
					caseData,
					transition,
				),
				template: this.interpolateTemplate(
					action.template || '',
					caseData,
					transition,
				),
				caseId: caseData.id,
				caseTitle: caseData.title,
			}

			const response = await fetch(webhookUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
					'OCS-APIREQUEST': 'true',
				},
				body: JSON.stringify(body),
			})

			if (!response.ok) {
				throw new Error(`Email action failed: ${response.statusText}`)
			}
		},

		/**
		 * Dispatch a createTask action.
		 *
		 * @param {object} action   The task creation action
		 * @param {object} caseData The case object
		 * @return {Promise<void>}
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async dispatchCreateTaskAction(action, caseData) {
			const objectStore = useObjectStore()
			await objectStore.saveObject('caseTask', {
				title: action.title || t('dossiq', 'New task'),
				description: action.description || '',
				case: caseData.id,
				status: 'available',
				priority: action.priority || 'normal',
				assignee: action.assignee || null,
			})
		},

		/**
		 * Dispatch a createSubCase action.
		 *
		 * @param {object} action   The sub-case creation action
		 * @param {object} caseData The parent case object
		 * @return {Promise<void>}
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async dispatchCreateSubCaseAction(action, caseData) {
			const objectStore = useObjectStore()
			await objectStore.saveObject('case', {
				title:
					action.title
					|| t('dossiq', 'Sub-case of {title}', {
						title: caseData.title,
					}),
				caseType: action.caseTypeId,
				parentCase: caseData.id,
				priority: caseData.priority || 'normal',
			})
		},

		/**
		 * Dispatch a webhook action.
		 *
		 * @param {object} action     The webhook action
		 * @param {object} caseData   The case object
		 * @param {object} transition The transition context
		 * @return {Promise<void>}
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async dispatchWebhookAction(action, caseData, transition) {
			const controller = new AbortController()
			const timeoutId = setTimeout(() => controller.abort(), 10000)

			try {
				const response = await fetch(action.url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						caseId: caseData.id,
						caseTitle: caseData.title,
						status: caseData.status,
						transition: transition
							? {
									label: transition.label,
									fromStatus: transition.fromStatus,
									toStatus: transition.toStatus,
								}
							: null,
					}),
					signal: controller.signal,
				})
				if (!response.ok) {
					throw new Error(`Webhook returned ${response.status}`)
				}
			} finally {
				clearTimeout(timeoutId)
			}
		},

		/**
		 * Dispatch a setField action.
		 *
		 * @param {object} action   The setField action
		 * @param {object} caseData The case object
		 * @return {Promise<void>}
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async dispatchSetFieldAction(action, caseData) {
			const objectStore = useObjectStore()
			await objectStore.saveObject('case', {
				...caseData,
				[action.fieldName]: action.value,
			})
		},

		/**
		 * Dispatch a Nextcloud notification action.
		 *
		 * @param {object} action   The notify action
		 * @param {object} caseData The case object
		 * @return {Promise<void>}
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async dispatchNotifyAction(action, caseData) {
			// Use Nextcloud OCS notification API
			await fetch(
				'/ocs/v2.php/apps/admin_notifications/api/v1/notifications/admin',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify({
						shortMessage: this.interpolateTemplate(
							action.message || '',
							caseData,
							null,
						),
						longMessage: '',
					}),
				},
			)
		},

		/**
		 * Interpolate template variables in a string.
		 *
		 * @param {string} template   Template string with {{var}} placeholders
		 * @param {object} caseData   The case object
		 * @param {object} transition The transition context (optional)
		 * @return {string} Interpolated string
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		interpolateTemplate(template, caseData, transition) {
			return template.replace(/\{\{(\w+(?:\.\w+)*)\}\}/g, (match, path) => {
				const parts = path.split('.')
				let value = null
				if (parts[0] === 'case') {
					value = caseData
					for (let i = 1; i < parts.length; i++) {
						value = value?.[parts[i]]
					}
				} else if (parts[0] === 'transition' && transition) {
					value = transition
					for (let i = 1; i < parts.length; i++) {
						value = value?.[parts[i]]
					}
				}
				return value ?? match
			})
		},

		// --- Workflow Validation ---

		/**
		 * Validate the current workflow template against the real engine
		 * constraints (see `src/utils/workflowGraphValidation.js`): missing/
		 * unreachable final status, dangling/duplicate transitions, orphan
		 * nodes, cycles with no exit. Delegates to the pure util so the same
		 * rules are unit-testable without a store/Pinia instance.
		 *
		 * @param {Array} [statusNodes] The `statusType` graph nodes — only the
		 *  component (which loads them) has this list, so callers must pass
		 *  it through; defaults to [] which yields no NO_FINAL_STATUS-style
		 *  findings (nothing to validate against yet).
		 *
		 * @return {Array} Array of {type, code, message} issue objects
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation
		 */
		validateWorkflow(statusNodes = []) {
			return validateWorkflowGraph({
				statusNodes,
				transitions: this.parsedTransitions,
			})
		},

		// --- Import/Export ---

		/**
		 * Export the current workflow template as a portable JSON object.
		 *
		 * @param {object}   template    The workflow template
		 * @param {Array}    statusTypes Status types of the case type
		 * @param {Array}    roleTypes   Role types of the case type
		 * @param {Array}    docTypes    Document types of the case type
		 * @return {object} Portable workflow definition
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		exportWorkflow(template, statusTypes, roleTypes, docTypes) {
			const statusMap = {}
			statusTypes.forEach((s) => {
				statusMap[s.id] = s.name
			})
			const roleMap = {}
			roleTypes.forEach((r) => {
				roleMap[r.id] = r.name
			})
			const docMap = {}
			docTypes.forEach((d) => {
				docMap[d.id] = d.name
			})

			const steps =
				typeof template.steps === 'string'
					? JSON.parse(template.steps)
					: template.steps || []
			const transitions =
				typeof template.transitions === 'string'
					? JSON.parse(template.transitions)
					: template.transitions || []

			// Replace UUIDs with names for portability
			const portableSteps = steps.map((s) => ({
				...s,
				status: statusMap[s.status] || s.status,
				assigneeRole: s.assigneeRole
					? roleMap[s.assigneeRole] || s.assigneeRole
					: null,
			}))

			const portableTransitions = transitions.map((t) => ({
				...t,
				fromStatus: statusMap[t.fromStatus] || t.fromStatus,
				toStatus: statusMap[t.toStatus] || t.toStatus,
				allowedRoles: (t.allowedRoles || []).map((r) => roleMap[r] || r),
			}))

			return {
				// Provenance tag only — nothing in PHP or JS reads or validates
				// `_format`, so it is not a compatibility surface. Renamed with
				// the app id, in step with the six seed templates in
				// lib/Settings/vth-templates/*.json. (Contrast `seriesKey` and
				// `CASES_ON_MAP_KEY`, which OpenRegister UPSERTS on and which are
				// therefore frozen at the old prefix.)
				_format: 'dossiq-workflow-v1',
				title: template.title,
				description: template.description,
				version: template.version,
				steps: portableSteps,
				transitions: portableTransitions,
				nodePositions: template.nodePositions,
				manifest: {
					statusTypes: Object.values(statusMap),
					roleTypes: Object.values(roleMap),
					documentTypes: Object.values(docMap),
				},
			}
		},

		/**
		 * Import a workflow definition and create a new draft version.
		 *
		 * @param {object} importData   The imported JSON data
		 * @param {string} caseTypeId   UUID of the target case type
		 * @param {Array}  statusTypes  Status types of the target case type
		 * @param {Array}  roleTypes    Role types of the target case type
		 * @param {Array}  docTypes     Document types of the target case type
		 * @return {object} {success, template, missingTypes}
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async importWorkflow(
			importData,
			caseTypeId,
			statusTypes,
			roleTypes,
			docTypes,
		) {
			// Build reverse maps (name -> UUID)
			const statusNameMap = {}
			statusTypes.forEach((s) => {
				statusNameMap[s.name] = s.id
			})
			const roleNameMap = {}
			roleTypes.forEach((r) => {
				roleNameMap[r.name] = r.id
			})

			// Check for missing types
			const missingStatuses = (importData.manifest?.statusTypes || []).filter(
				(name) => !statusNameMap[name],
			)
			const missingRoles = (importData.manifest?.roleTypes || []).filter(
				(name) => !roleNameMap[name],
			)

			if (missingStatuses.length > 0 || missingRoles.length > 0) {
				return {
					success: false,
					missingTypes: {
						statusTypes: missingStatuses,
						roleTypes: missingRoles,
					},
					template: null,
				}
			}

			// Map names back to UUIDs
			const steps = (importData.steps || []).map((s) => ({
				...s,
				id: generateUUID(),
				status: statusNameMap[s.status] || s.status,
				assigneeRole: s.assigneeRole
					? roleNameMap[s.assigneeRole] || s.assigneeRole
					: null,
			}))

			const transitions = (importData.transitions || []).map((t) => ({
				...t,
				id: generateUUID(),
				fromStatus: statusNameMap[t.fromStatus] || t.fromStatus,
				toStatus: statusNameMap[t.toStatus] || t.toStatus,
				allowedRoles: (t.allowedRoles || []).map((r) => roleNameMap[r] || r),
			}))

			// Determine next version number
			const maxVersion = this.versions.reduce(
				(max, v) => Math.max(max, v.version || 0),
				0,
			)

			const objectStore = useObjectStore()
			const template = await objectStore.saveObject('workflowTemplate', {
				title: importData.title || t('dossiq', 'Imported workflow'),
				description: importData.description || '',
				caseType: caseTypeId,
				version: maxVersion + 1,
				isActive: false,
				isDraft: true,
				steps: JSON.stringify(steps),
				transitions: JSON.stringify(transitions),
				nodePositions: importData.nodePositions || '{}',
			})

			this.currentTemplate = template
			return { success: true, template, missingTypes: null }
		},

		// --- Step/Transition Helpers ---

		/**
		 * Add a step to the current workflow template.
		 *
		 * @param {string} statusId UUID of the status to add the step to
		 * @param {object} stepData Step properties (optional overrides)
		 * @return {object} The new step
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		addStep(statusId, stepData = {}) {
			const steps = [...this.parsedSteps]
			const stepsInStatus = steps.filter((s) => s.status === statusId)
			const newStep = {
				id: generateUUID(),
				title: stepData.title || t('dossiq', 'New step'),
				description: stepData.description || '',
				status: statusId,
				order: stepsInStatus.length + 1,
				assigneeRole: stepData.assigneeRole || null,
				isRequired: stepData.isRequired || false,
				checklist: stepData.checklist || [],
				automaticActions: stepData.automaticActions || [],
			}
			steps.push(newStep)
			if (this.currentTemplate) {
				this.currentTemplate.steps = JSON.stringify(steps)
			}
			return newStep
		},

		/**
		 * Remove a step from the current workflow template.
		 *
		 * @param {string} stepId UUID of the step to remove
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		removeStep(stepId) {
			let steps = [...this.parsedSteps]
			const removed = steps.find((s) => s.id === stepId)
			steps = steps.filter((s) => s.id !== stepId)

			// Recalculate order for remaining steps in the same status
			if (removed) {
				const siblingSteps = steps
					.filter((s) => s.status === removed.status)
					.sort((a, b) => a.order - b.order)
				siblingSteps.forEach((s, i) => {
					s.order = i + 1
				})
			}

			if (this.currentTemplate) {
				this.currentTemplate.steps = JSON.stringify(steps)
			}
		},

		/**
		 * Update a step in the current workflow template.
		 *
		 * @param {string} stepId  UUID of the step to update
		 * @param {object} updates Properties to update
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		updateStep(stepId, updates) {
			const steps = [...this.parsedSteps]
			const index = steps.findIndex((s) => s.id === stepId)
			if (index >= 0) {
				steps[index] = { ...steps[index], ...updates }
				if (this.currentTemplate) {
					this.currentTemplate.steps = JSON.stringify(steps)
				}
			}
		},

		/**
		 * Add a transition to the current workflow template.
		 *
		 * @param {string} fromStatus UUID of the source status
		 * @param {string} toStatus   UUID of the target status
		 * @param {object} data       Optional transition properties
		 * @return {object} The new transition
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		addTransition(fromStatus, toStatus, data = {}) {
			const transitions = [...this.parsedTransitions]
			const newTransition = {
				id: generateUUID(),
				fromStatus,
				toStatus,
				label: data.label || '',
				guards: data.guards || [],
				automaticActions: data.automaticActions || [],
				allowedRoles: data.allowedRoles || [],
			}
			transitions.push(newTransition)
			if (this.currentTemplate) {
				this.currentTemplate.transitions = JSON.stringify(transitions)
			}
			return newTransition
		},

		/**
		 * Remove a transition from the current workflow template.
		 *
		 * @param {string} transitionId UUID of the transition to remove
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		removeTransition(transitionId) {
			const transitions = this.parsedTransitions.filter(
				(t) => t.id !== transitionId,
			)
			if (this.currentTemplate) {
				this.currentTemplate.transitions = JSON.stringify(transitions)
			}
		},

		/**
		 * Update a transition in the current workflow template.
		 *
		 * @param {string} transitionId UUID of the transition to update
		 * @param {object} updates      Properties to update
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		updateTransition(transitionId, updates) {
			const transitions = [...this.parsedTransitions]
			const index = transitions.findIndex((t) => t.id === transitionId)
			if (index >= 0) {
				transitions[index] = { ...transitions[index], ...updates }
				if (this.currentTemplate) {
					this.currentTemplate.transitions = JSON.stringify(transitions)
				}
			}
		},

		/**
		 * Update node position on the canvas.
		 *
		 * @param {string} statusId UUID of the status
		 * @param {number} x        X coordinate
		 * @param {number} y        Y coordinate
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		updateNodePosition(statusId, x, y) {
			const positions = { ...this.parsedNodePositions }
			positions[statusId] = { x, y }
			if (this.currentTemplate) {
				this.currentTemplate.nodePositions = JSON.stringify(positions)
			}
		},

		/**
		 * Remove a status node from the working copy of the current workflow
		 * template: drops its steps, its incident transitions (as source or
		 * target) and its node position. Does NOT delete the underlying
		 * `statusType` object itself — the caller (`WorkflowEditor.vue::
		 * onDeleteStatusNode()`) does that via `objectStore.deleteObject()`
		 * after the same "at least one final status must remain" guard
		 * `StatusesTab.vue::deleteStatusType()` already enforces.
		 *
		 * @param {string} statusId UUID of the status to remove
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-drag-and-drop-workflow-canvas
		 */
		removeStatusNode(statusId) {
			const steps = this.parsedSteps.filter((s) => s.status !== statusId)
			const transitions = this.parsedTransitions.filter(
				(t) => t.fromStatus !== statusId && t.toStatus !== statusId,
			)
			const positions = { ...this.parsedNodePositions }
			delete positions[statusId]

			if (this.currentTemplate) {
				this.currentTemplate.steps = JSON.stringify(steps)
				this.currentTemplate.transitions = JSON.stringify(transitions)
				this.currentTemplate.nodePositions = JSON.stringify(positions)
			}
		},
	},
})
