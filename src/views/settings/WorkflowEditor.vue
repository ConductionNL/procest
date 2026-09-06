<template>
	<div class="workflow-editor" @dragover.prevent @drop="onCanvasDrop">
		<!-- Validation banner -->
		<WorkflowValidationBanner
			:errors="validationErrors"
			@dismiss="validationErrors = []" />

		<div class="workflow-editor__layout">
			<!-- Palette -->
			<WorkflowPalette
				class="workflow-editor__palette"
				@dragStart="onPaletteDragStart"
				@addStatus="onAddStatusKeyboard" />

			<!-- Canvas -->
			<div
				ref="canvas"
				class="workflow-editor__canvas"
				:style="canvasStyle"
				@mousedown="onCanvasMouseDown"
				@mousemove="onCanvasMouseMove"
				@mouseup="onCanvasMouseUp"
				@wheel="onCanvasWheel">
				<!-- SVG layer for transitions -->
				<svg class="workflow-editor__svg" :viewBox="svgViewBox">
					<!-- Existing transitions -->
					<WorkflowTransitionArrow
						v-for="transition in transitions"
						:key="transition.id"
						:transition="transition"
						:fromPos="getNodeCenter(transition.fromStatus)"
						:toPos="getNodeCenter(transition.toStatus)"
						:selected="selectedTransition === transition.id"
						@click="selectTransition(transition.id)"
						@dblclick="editTransition(transition.id)" />

					<!-- Connection being drawn -->
					<line
						v-if="drawingConnection"
						:x1="drawingConnection.startX"
						:y1="drawingConnection.startY"
						:x2="drawingConnection.currentX"
						:y2="drawingConnection.currentY"
						stroke="var(--color-primary)"
						stroke-width="2"
						stroke-dasharray="5,5" />
				</svg>

				<!-- Status nodes -->
				<WorkflowNode
					v-for="status in statusNodes"
					:key="status.id"
					:status="status"
					:steps="getStepsForStatus(status.id)"
					:position="nodePositions[status.id] || { x: 100, y: 100 }"
					:selected="selectedNode === status.id"
					:otherStatuses="statusNodes.filter((s) => s.id !== status.id)"
					:outgoingTransitions="
						transitions.filter((t) => t.fromStatus === status.id)
					"
					@select="selectNode(status.id)"
					@dragStart="onNodeDragStart(status.id, $event)"
					@connectionStart="onConnectionStart(status.id, $event)"
					@connectionEnd="onConnectionEnd(status.id)"
					@stepClick="onStepClick"
					@addStep="onAddStep(status.id)"
					@keyboardConnect="onConnectionKeyboard(status.id, $event)"
					@keyboardDisconnect="onDisconnectionKeyboard"
					@deleteStatus="onDeleteStatusNode(status.id)" />
			</div>
		</div>

		<!-- Side panels -->
		<StepConfigPanel
			v-if="selectedStep"
			:step="selectedStep"
			:roleTypes="roleTypes"
			:readOnly="isPublished"
			@update="onStepUpdate"
			@delete="onStepDelete"
			@close="selectedStep = null" />

		<TransitionConfigPanel
			v-if="selectedTransitionData"
			:transition="selectedTransitionData"
			:roleTypes="roleTypes"
			:documentTypes="documentTypes"
			@update="onTransitionUpdate"
			@delete="onTransitionDelete"
			@close="selectedTransition = null" />
	</div>
</template>

<script>
import StepConfigPanel from './components/StepConfigPanel.vue'
import TransitionConfigPanel from './components/TransitionConfigPanel.vue'
import WorkflowNode from './components/WorkflowNode.vue'
import WorkflowPalette from './components/WorkflowPalette.vue'
import WorkflowTransitionArrow from './components/WorkflowTransitionArrow.vue'
import WorkflowValidationBanner from './components/WorkflowValidationBanner.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { useWorkflowStore } from '../../store/modules/workflow.js'

export default {
	name: 'WorkflowEditor',
	components: {
		WorkflowNode,
		WorkflowTransitionArrow,
		WorkflowPalette,
		WorkflowValidationBanner,
		StepConfigPanel,
		TransitionConfigPanel,
	},

	props: {
		caseTypeId: {
			type: String,
			required: true,
		},

		templateId: {
			type: String,
			default: null,
		},
	},

	emits: ['dirty'],

	data() {
		return {
			/** @type {Array} Status type objects for the case type */
			statusNodes: [],
			/** @type {Array} Role type objects for the case type */
			roleTypes: [],
			/** @type {Array} Document type objects for the case type */
			documentTypes: [],
			/** @type {string|null} Selected node UUID */
			selectedNode: null,
			/** @type {string|null} Selected transition UUID */
			selectedTransition: null,
			/** @type {object|null} Selected step object */
			selectedStep: null,
			/** @type {Array} Validation errors */
			validationErrors: [],
			/** @type {object|null} Connection being drawn */
			drawingConnection: null,
			/** @type {object|null} Node being dragged */
			draggingNode: null,
			/** @type {boolean} Canvas is being panned */
			panning: false,
			/** @type {object} Pan offset */
			panOffset: { x: 0, y: 0 },
			/** @type {object} Pan start position */
			panStart: { x: 0, y: 0 },
			/** @type {number} Zoom level */
			zoom: 1,
			/** @type {string|null} Palette item being dragged */
			paletteDragType: null,
		}
	},

	computed: {
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		workflowStore() {
			return useWorkflowStore()
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		steps() {
			return this.workflowStore.parsedSteps
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		transitions() {
			return this.workflowStore.parsedTransitions
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		nodePositions() {
			return this.workflowStore.parsedNodePositions
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		selectedTransitionData() {
			if (!this.selectedTransition) return null
			return (
				this.transitions.find((t) => t.id === this.selectedTransition)
				|| null
			)
		},

		/**
		 * True when the loaded workflow template is published (not a draft).
		 *
		 * Used to render the step `Geavanceerd` panel read-only per
		 * process-step-configuration REQ-PSC-7-002. Falls back to false
		 * (editable) when no template is loaded yet, preserving the
		 * pre-existing creation flow.
		 *
		 * @return {boolean} Whether the current template is published.
		 */
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		isPublished() {
			const tpl = this.workflowStore.currentTemplate || null
			if (!tpl) return false
			// isDraft true ⇒ editable; isDraft false ⇒ published/deprecated
			return tpl.isDraft === false
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		canvasStyle() {
			return {
				transform: `translate(${this.panOffset.x}px, ${this.panOffset.y}px) scale(${this.zoom})`,
				transformOrigin: '0 0',
			}
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		svgViewBox() {
			return '0 0 2000 1500'
		},
	},

	async mounted() {
		await this.loadData()
	},

	methods: {
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async loadData() {
			// Load status types for this case type
			this.statusNodes =
				(await this.objectStore.fetchCollection('statusType', {
					caseType: this.caseTypeId,
					_limit: 100,
					_order: { order: 'asc' },
				})) || []

			// Load role types
			this.roleTypes =
				(await this.objectStore.fetchCollection('roleType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})) || []

			// Load document types
			this.documentTypes =
				(await this.objectStore.fetchCollection('documentType', {
					caseType: this.caseTypeId,
					_limit: 100,
				})) || []

			// Load or create workflow template
			if (this.templateId) {
				await this.workflowStore.getTemplate(this.templateId)
			}

			// Assign default positions for nodes without positions
			this.ensureNodePositions()
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		ensureNodePositions() {
			const positions = { ...this.nodePositions }
			let changed = false
			this.statusNodes.forEach((status, index) => {
				if (!positions[status.id]) {
					positions[status.id] = {
						x: 100 + (index % 4) * 250,
						y: 100 + Math.floor(index / 4) * 200,
					}
					changed = true
				}
			})
			if (changed && this.workflowStore.currentTemplate) {
				this.workflowStore.currentTemplate.nodePositions =
					JSON.stringify(positions)
			}
		},

		/**
		 * @param {string} statusId Identifier of the status id.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		getStepsForStatus(statusId) {
			return this.steps
				.filter((s) => s.status === statusId)
				.sort((a, b) => a.order - b.order)
		},

		/**
		 * @param {string} statusId Identifier of the status id.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		getNodeCenter(statusId) {
			const pos = this.nodePositions[statusId]
			if (!pos) return { x: 0, y: 0 }
			return {
				x: pos.x + 100, // half of node width (200px)
				y: pos.y + 40, // half of node height (80px)
			}
		},

		// --- Selection ---
		/**
		 * @param {string} statusId Identifier of the status id.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		selectNode(statusId) {
			this.selectedNode = statusId
			this.selectedTransition = null
			this.selectedStep = null
		},

		/**
		 * @param {string} transitionId Identifier of the transition id.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		selectTransition(transitionId) {
			this.selectedTransition = transitionId
			this.selectedNode = null
			this.selectedStep = null
		},

		/**
		 * @param {string} transitionId Identifier of the transition id.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		editTransition(transitionId) {
			this.selectTransition(transitionId)
		},

		/**
		 * @param {object} step The step.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onStepClick(step) {
			this.selectedStep = { ...step }
			this.selectedNode = null
			this.selectedTransition = null
		},

		// --- Node drag ---
		/**
		 * @param {string} statusId Identifier of the status id.
		 * @param {Event} event The originating DOM event.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onNodeDragStart(statusId, event) {
			this.draggingNode = {
				statusId,
				offsetX: event.offsetX || 0,
				offsetY: event.offsetY || 0,
			}
		},

		/**
		 * @param {Event} event The originating DOM event.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onCanvasMouseMove(event) {
			if (this.draggingNode) {
				const rect = this.$refs.canvas.getBoundingClientRect()
				const x =
					(event.clientX - rect.left - this.panOffset.x) / this.zoom
					- this.draggingNode.offsetX
				const y =
					(event.clientY - rect.top - this.panOffset.y) / this.zoom
					- this.draggingNode.offsetY
				this.workflowStore.updateNodePosition(
					this.draggingNode.statusId,
					Math.max(0, x),
					Math.max(0, y),
				)
			} else if (this.drawingConnection) {
				const rect = this.$refs.canvas.getBoundingClientRect()
				this.drawingConnection.currentX =
					(event.clientX - rect.left - this.panOffset.x) / this.zoom
				this.drawingConnection.currentY =
					(event.clientY - rect.top - this.panOffset.y) / this.zoom
			} else if (this.panning) {
				this.panOffset.x = event.clientX - this.panStart.x
				this.panOffset.y = event.clientY - this.panStart.y
			}
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		onCanvasMouseUp() {
			if (this.draggingNode) {
				this.draggingNode = null
				this.$emit('dirty')
			}
			if (this.drawingConnection) {
				this.drawingConnection = null
			}
			this.panning = false
		},

		/**
		 * @param {Event} event The originating DOM event.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onCanvasMouseDown(event) {
			// Only pan if clicking empty canvas area
			if (
				event.target === this.$refs.canvas
				|| event.target.classList.contains('workflow-editor__svg')
			) {
				this.panning = true
				this.panStart = {
					x: event.clientX - this.panOffset.x,
					y: event.clientY - this.panOffset.y,
				}
				this.selectedNode = null
				this.selectedTransition = null
				this.selectedStep = null
			}
		},

		/**
		 * @param {Event} event The originating DOM event.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onCanvasWheel(event) {
			event.preventDefault()
			const delta = event.deltaY > 0 ? -0.1 : 0.1
			this.zoom = Math.max(0.3, Math.min(2, this.zoom + delta))
		},

		// --- Connection drawing ---
		/**
		 * @param {string} statusId Identifier of the status id.
		 * @param {Event} event The originating DOM event.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onConnectionStart(statusId, event) {
			const center = this.getNodeCenter(statusId)
			this.drawingConnection = {
				fromStatus: statusId,
				startX: center.x,
				startY: center.y,
				currentX: center.x,
				currentY: center.y,
			}
		},

		/**
		 * @param {string} statusId Identifier of the status id.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onConnectionEnd(statusId) {
			if (
				this.drawingConnection
				&& this.drawingConnection.fromStatus !== statusId
			) {
				this.workflowStore.addTransition(
					this.drawingConnection.fromStatus,
					statusId,
				)
				this.$emit('dirty')
			}
			this.drawingConnection = null
		},

		// --- Palette drag & drop ---
		/**
		 * @param {string} type The type.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onPaletteDragStart(type) {
			this.paletteDragType = type
		},

		/**
		 * @param {Event} event The originating DOM event.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		async onCanvasDrop(event) {
			if (this.paletteDragType === 'status') {
				const rect = this.$refs.canvas.getBoundingClientRect()
				const x = (event.clientX - rect.left - this.panOffset.x) / this.zoom
				const y = (event.clientY - rect.top - this.panOffset.y) / this.zoom

				// Create a new status type
				const statusType = await this.objectStore.saveObject('statusType', {
					name: t('dossiq', 'New status'),
					caseType: this.caseTypeId,
					order: this.statusNodes.length + 1,
					isFinal: false,
				})

				if (statusType) {
					this.statusNodes.push(statusType)
					this.workflowStore.updateNodePosition(statusType.id, x, y)
					this.$emit('dirty')
				}
			}
			this.paletteDragType = null
		},

		/**
		 * Keyboard alternative to dragging a status node from the palette
		 * onto the canvas (`WorkflowPalette.vue`'s "Add status node"
		 * button). Creates a `statusType` the same way `onCanvasDrop`
		 * does, placed at the next default grid slot (same layout as
		 * `ensureNodePositions()`), and selects it so the properties panel
		 * opens immediately for a keyboard-only user.
		 */
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		async onAddStatusKeyboard() {
			const index = this.statusNodes.length
			const x = 100 + (index % 4) * 250
			const y = 100 + Math.floor(index / 4) * 200

			const statusType = await this.objectStore.saveObject('statusType', {
				name: t('dossiq', 'New status'),
				caseType: this.caseTypeId,
				order: this.statusNodes.length + 1,
				isFinal: false,
			})

			if (statusType) {
				this.statusNodes.push(statusType)
				this.workflowStore.updateNodePosition(statusType.id, x, y)
				this.$emit('dirty')
				this.selectNode(statusType.id)
			}
		},

		/**
		 * Draw a keyboard-initiated transition from `fromStatusId` to
		 * `toStatusId` — the keyboard equivalent of dragging from one
		 * node's output port to another's input port. Reuses the exact
		 * same store action (`addTransition`) the mouse path
		 * (`onConnectionEnd`) calls.
		 *
		 * @param {string} fromStatusId Source status UUID
		 * @param {string} toStatusId   Target status UUID
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-keyboard-operable-canvas
		 */
		onConnectionKeyboard(fromStatusId, toStatusId) {
			this.workflowStore.addTransition(fromStatusId, toStatusId)
			this.$emit('dirty')
		},

		/**
		 * Remove a transition via its keyboard-reachable "Disconnect
		 * from…" menu item. Reuses the same store action
		 * (`removeTransition`) the mouse path (`onTransitionDelete`)
		 * calls, and clears the selection if the removed transition was
		 * selected.
		 *
		 * @param {string} transitionId UUID of the transition to remove
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-keyboard-operable-canvas
		 */
		onDisconnectionKeyboard(transitionId) {
			this.workflowStore.removeTransition(transitionId)
			if (this.selectedTransition === transitionId) {
				this.selectedTransition = null
			}
			this.$emit('dirty')
		},

		/**
		 * Delete a status node: guards "at least one final status must
		 * remain" (mirrors `StatusesTab.vue::deleteStatusType()`),
		 * confirms with the user, deletes the underlying `statusType`
		 * object, then drops it (and its steps/incident transitions) from
		 * the working copy via `workflowStore.removeStatusNode()`.
		 *
		 * @param {string} statusId UUID of the status to delete
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-drag-and-drop-workflow-canvas
		 */
		async onDeleteStatusNode(statusId) {
			const target = this.statusNodes.find((s) => s.id === statusId)
			if (!target) return

			if (target.isFinal) {
				const otherFinals = this.statusNodes.filter(
					(s) => s.id !== statusId && s.isFinal,
				)
				if (otherFinals.length === 0) {
					this.validationErrors = [
						{
							type: 'error',
							message: t(
								'dossiq',
								'At least one status must be marked as final',
							),
						},
					]
					return
				}
			}

			if (
				!confirm(
					t(
						'dossiq',
						'Delete status "{name}"? This also removes its steps and transitions.',
						{ name: target.name },
					),
				)
			) {
				return
			}

			const ok = await this.objectStore.deleteObject('statusType', statusId)
			if (!ok) {
				this.validationErrors = [
					{
						type: 'error',
						message: t('dossiq', 'Failed to delete status'),
					},
				]
				return
			}

			this.workflowStore.removeStatusNode(statusId)
			this.statusNodes = this.statusNodes.filter((s) => s.id !== statusId)
			if (this.selectedNode === statusId) {
				this.selectedNode = null
			}
			this.$emit('dirty')
		},

		/**
		 * Delete a step from the selected node's step list. Reuses the
		 * same store action (`removeStep`) — previously implemented but
		 * never called from any component.
		 *
		 * @param {string} stepId UUID of the step to delete
		 * @spec openspec/specs/visual-workflow-editor/spec.md#requirement-step-configuration-panel
		 */
		onStepDelete(stepId) {
			this.workflowStore.removeStep(stepId)
			this.selectedStep = null
			this.$emit('dirty')
		},

		// --- Step management ---
		/**
		 * @param {string} statusId Identifier of the status id.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onAddStep(statusId) {
			const step = this.workflowStore.addStep(statusId)
			this.selectedStep = { ...step }
			this.$emit('dirty')
		},

		/**
		 * @param {object} updatedStep The updated step.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onStepUpdate(updatedStep) {
			this.workflowStore.updateStep(updatedStep.id, updatedStep)
			this.selectedStep = { ...updatedStep }
			this.$emit('dirty')
		},

		// --- Transition management ---
		/**
		 * @param {object} updatedTransition The updated transition.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onTransitionUpdate(updatedTransition) {
			this.workflowStore.updateTransition(
				updatedTransition.id,
				updatedTransition,
			)
			this.$emit('dirty')
		},

		/**
		 * @param {string} transitionId Identifier of the transition id.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onTransitionDelete(transitionId) {
			this.workflowStore.removeTransition(transitionId)
			this.selectedTransition = null
			this.$emit('dirty')
		},

		// --- Public API ---
		/**
		 * Validate the current graph against the real engine constraints,
		 * passing this component's `statusNodes` through — the store only
		 * holds `steps`/`transitions`, so it cannot see `isFinal` on its own.
		 *
		 * @return {boolean} True when the graph has no validation errors
		 */
		/** @spec openspec/specs/visual-workflow-editor/spec.md#requirement-workflow-editor-validation */
		validate() {
			this.validationErrors = this.workflowStore.validateWorkflow(
				this.statusNodes,
			)
			return this.validationErrors.length === 0
		},
	},
}
</script>

<style scoped>
.workflow-editor {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 500px;
}

.workflow-editor__layout {
	display: flex;
	flex: 1;
	overflow: hidden;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.workflow-editor__palette {
	width: 200px;
	flex-shrink: 0;
	border-right: 1px solid var(--color-border);
}

.workflow-editor__canvas {
	flex: 1;
	position: relative;
	overflow: hidden;
	background:
		linear-gradient(var(--color-border-dark) 1px, transparent 1px),
		linear-gradient(90deg, var(--color-border-dark) 1px, transparent 1px);
	background-size: 20px 20px;
	cursor: grab;
}

.workflow-editor__canvas:active {
	cursor: grabbing;
}

.workflow-editor__svg {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	pointer-events: none;
}

.workflow-editor__svg line,
.workflow-editor__svg path,
.workflow-editor__svg g {
	pointer-events: auto;
}
</style>
