<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The task half of the waiting relationship (case-flow-human-steps 6.1).

  A flow task is the thing a suspended case run is blocked on, and the person
  holding it should not have to know that from a hidden field. This section
  says so, and links to the case. It renders NOTHING for a task no run is
  waiting on, so every pre-existing task looks exactly as it did before.

  The case half (the run and its current stage on the case detail) is NOT
  built here: a fleet-generic subject-scoped runs widget is being spec'd in
  openregister (flow-runs-subject-scope, PR #3250) and a bespoke copy would
  drift from it.

  Self-fetching from the route task id, same pattern as InitiatorSection.

  @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
-->
<template>
	<div
		v-if="waitingCaseId"
		class="task-waiting-case"
		data-testid="task-waiting-case">
		<div class="task-waiting-case__row">
			<CheckboxMarkedCircleOutline
				:size="20"
				class="task-waiting-case__icon" />
			<span class="task-waiting-case__label">{{
				t('dossiq', 'A case is waiting on this task')
			}}</span>
		</div>
		<p class="task-waiting-case__hint">
			{{ t('dossiq', 'Complete the task and the case moves on.') }}
		</p>
		<router-link
			class="task-waiting-case__link"
			:to="caseRoute"
			data-testid="task-waiting-case-link">
			{{ caseLabel }}
		</router-link>
	</div>
</template>

<script>
import CheckboxMarkedCircleOutline from 'vue-material-design-icons/CheckboxMarkedCircleOutline.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'
import { caseRouteFor, waitingCaseIdFrom } from '../../utils/flowTaskHelpers.js'

export default {
	name: 'TaskWaitingCaseSection',
	components: {
		CheckboxMarkedCircleOutline,
	},

	data() {
		return {
			task: null,
			caseTitle: '',
		}
	},

	computed: {
		/** @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md */
		waitingCaseId() {
			return waitingCaseIdFrom(this.task)
		},

		/** @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md */
		caseRoute() {
			return caseRouteFor(this.waitingCaseId)
		},

		/** @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md */
		caseLabel() {
			return this.caseTitle || t('dossiq', 'Open the case')
		},
	},

	/**
	 * Resolve the stores, then load the task and its waiting case.
	 *
	 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
	 */
	async mounted() {
		// CnAppRoot mounts manifest slot widgets before App.vue's
		// initializeStores() has resolved the app-config, so the 'task'
		// object type may not be registered yet — await it here
		// (idempotent), same pattern as InitiatorSection.
		await initializeStores()
		await this.load()
	},

	methods: {
		/**
		 * Load the task, then the waiting case's title, best-effort.
		 *
		 * A failed case read still renders the section: the relationship is a
		 * fact of the task, the title is only a nicer label for the link.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
		 */
		async load() {
			const taskId = this.$route?.params?.id
			if (!taskId) {
				return
			}

			try {
				this.task =
					(await this.objectStore.fetchObject('caseTask', taskId)) || null
			} catch {
				// An unreadable task renders nothing, same as a non-flow task.
				this.task = null
				return
			}

			const caseId = waitingCaseIdFrom(this.task)
			if (!caseId) {
				return
			}

			try {
				const caseObject = await this.objectStore.fetchObject('case', caseId)
				this.caseTitle = String(caseObject?.title ?? '').trim()
			} catch {
				// Best-effort: the link still works without the title.
				this.caseTitle = ''
			}
		},
	},
}
</script>

<style scoped lang="scss">
.task-waiting-case {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 2);

	&__row {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__label {
		font-weight: bold;
	}

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__link {
		color: var(--color-primary-element);
		text-decoration: underline;
	}
}
</style>
