<!--
  ReassignSelectionDialog — pick a handler for the cases a user selected.

  Opened by the Cases page's `reassign` bulk action. The selection travels in
  as a prop rather than being re-read here: an action that goes and finds out
  what was selected is one re-render away from acting on a different set than
  the user saw highlighted.

  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<NcDialog
		:name="title"
		:open="open"
		size="normal"
		data-testid="reassign-selection-dialog"
		@update:open="$emit('update:open', $event)">
		<div class="reassign">
			<p class="reassign__lead">
				{{ lead }}
			</p>

			<NcTextField
				v-model="handler"
				:label="t('dossiq', 'Reassign to')"
				:placeholder="t('dossiq', 'User id of the receiving handler')"
				data-testid="reassign-selection-handler" />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="busy" @click="$emit('update:open', false)">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || handler.trim() === ''"
				data-testid="reassign-selection-submit"
				@click="submit">
				{{ t('dossiq', 'Reassign') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcTextField } from '@nextcloud/vue'

export default {
	name: 'ReassignSelectionDialog',

	components: { NcButton, NcDialog, NcNoteCard, NcTextField },

	props: {
		/** Whether the dialog is open. */
		open: { type: Boolean, default: false },
		/** The case ids the user selected. */
		selectedIds: { type: Array, default: () => [] },
	},

	emits: ['update:open', 'reassigned'],

	data() {
		return { handler: '', busy: false, error: '' }
	},

	computed: {
		/**
		 * @return {string} The dialog title.
		 *
		 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md#REQ-RBA-004
		 */
		title() {
			return t('dossiq', 'Reassign cases')
		},

		/**
		 * @return {string} What is about to happen, with the count.
		 *
		 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md#REQ-RBA-004
		 */
		lead() {
			return t(
				'dossiq',
				'Reassign {count} selected case(s) to another handler.',
				{
					count: this.selectedIds.length,
				},
			)
		},
	},

	methods: {
		t,

		/**
		 * Send the selection to the reassignment endpoint.
		 *
		 * @return {Promise<void>} Resolves when the request settles.
		 *
		 * @spec openspec/changes/reassignment-is-a-bulk-action/specs/reassignment-bulk-action/spec.md#REQ-RBA-001
		 */
		async submit() {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl('/apps/dossiq/api/reassignments/selection'),
					{ caseIds: this.selectedIds, toUser: this.handler.trim() },
				)

				// Report what actually happened, not what was asked for. A
				// partial run that announced full success is how somebody
				// believes cases moved that did not.
				const moved = Number(data?.succeeded ?? 0)
				const asked = Number(data?.requested ?? this.selectedIds.length)
				if (moved < asked) {
					showError(
						t('dossiq', 'Reassigned {moved} of {asked} cases.', {
							moved,
							asked,
						}),
					)
				} else {
					showSuccess(
						t('dossiq', 'Reassigned {moved} case(s).', { moved }),
					)
				}

				this.$emit('reassigned', data)
				this.$emit('update:open', false)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| t('dossiq', 'The reassignment failed.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.reassign {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.reassign__lead {
	color: var(--color-text-maxcontrast);
}
</style>
