<template>
	<div class="workflow-palette">
		<h4 class="workflow-palette__title">
			{{ t('dossiq', 'Elements') }}
		</h4>

		<div
			class="workflow-palette__item"
			draggable="true"
			@dragstart="onDragStart('status', $event)">
			<span class="workflow-palette__icon"> &#x25A1; </span>
			<span>{{ t('dossiq', 'Status node') }}</span>
		</div>

		<!-- Keyboard alternative to dragging the item above onto the canvas
			(drag-and-drop has no keyboard equivalent). -->
		<NcButton
			type="secondary"
			class="workflow-palette__add-button"
			@click="$emit('add-status')">
			{{ t('dossiq', 'Add status node') }}
		</NcButton>

		<div class="workflow-palette__help">
			<p>
				{{
					t(
						'dossiq',
						'Drag a status node onto the canvas to add it, or use the "Add status node" button.',
					)
				}}
			</p>
			<p>
				{{
					t(
						'dossiq',
						"Connect nodes by dragging from one port to another, or use a node's keyboard actions menu.",
					)
				}}
			</p>
			<p>
				{{
					t(
						'dossiq',
						'Click a node to select it, double-click a transition to edit.',
					)
				}}
			</p>
		</div>

		<h4 class="workflow-palette__title">
			{{ t('dossiq', 'Controls') }}
		</h4>
		<div class="workflow-palette__help">
			<p>
				<strong>{{ t('dossiq', 'Pan') }}:</strong>
				{{ t('dossiq', 'Click and drag on empty canvas') }}
			</p>
			<p>
				<strong>{{ t('dossiq', 'Zoom') }}:</strong>
				{{ t('dossiq', 'Scroll wheel') }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'WorkflowPalette',
	components: {
		NcButton,
	},

	emits: ['drag-start', 'add-status'],
	methods: {
		/**
		 * @param {string} type The type.
		 * @param {Event} event The originating DOM event.
		 * @spec openspec/specs/workflow-definition-model/spec.md
		 */
		onDragStart(type, event) {
			event.dataTransfer.setData('text/plain', type)
			event.dataTransfer.effectAllowed = 'copy'
			this.$emit('drag-start', type)
		},
	},
}
</script>

<style scoped>
.workflow-palette {
	padding: 12px;
	background: var(--color-main-background);
}

.workflow-palette__title {
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px 0;
}

.workflow-palette__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: grab;
	font-size: 13px;
	margin-bottom: 8px;
}

.workflow-palette__item:hover {
	background: var(--color-background-hover);
	border-color: var(--color-primary);
}

.workflow-palette__item:active {
	cursor: grabbing;
}

.workflow-palette__add-button {
	width: 100%;
	margin-bottom: 12px;
}

.workflow-palette__icon {
	font-size: 18px;
	width: 24px;
	text-align: center;
}

.workflow-palette__help {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
	margin-bottom: 16px;
}

.workflow-palette__help p {
	margin: 4px 0;
}
</style>
