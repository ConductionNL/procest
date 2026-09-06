<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Deelzaak delete-warning modal — isolated NcDialog-wrapped confirmation per
  ADR-004 (modal isolation). Shown before a parent case that HAS sub-cases is
  deleted: it warns that the sub-cases will be unlinked (orphaned), not
  cascade-deleted. On confirm it calls the deelzaak store's `unlinkSubCases`
  (PATCH parentCase: null on every child) and then deletes the parent through
  the object store, leaving each former sub-case as a standalone case.

  Usage:
    <DeelzaakDeleteWarningModal
      :parent-case-id="case.id"
      :sub-case-count="count"
      @deleted="onDeleted"
      @close="showWarning = false" />

  @spec openspec/changes/deelzaak-support/tasks.md#T11
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Delete case with sub-cases')"
		:open="true"
		size="normal"
		@update:open="onDialogClose"
		@closing="$emit('close')">
		<div class="deelzaak-delete-warning">
			<NcNoteCard type="warning" role="alert">
				{{ warningMessage }}
			</NcNoteCard>

			<p class="deelzaak-delete-warning__detail">
				{{
					t(
						'dossiq',
						'The sub-cases will remain accessible as standalone cases after deletion.',
					)
				}}
			</p>

			<NcNoteCard v-if="error" type="error" role="alert">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="busy" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton type="error" :disabled="busy" @click="confirmDelete">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('dossiq', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { useDeelzaakStore } from '../store/modules/deelzaak.js'
import { useObjectStore } from '../store/modules/object.js'
import { orphanWarningMessage } from '../utils/deelzaakHelpers.js'

export default {
	name: 'DeelzaakDeleteWarningModal',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** UUID of the parent case being deleted. */
		parentCaseId: {
			type: String,
			required: true,
		},

		/** Number of sub-cases attached to the parent (drives the copy). */
		subCaseCount: {
			type: Number,
			default: 0,
		},
	},

	emits: ['deleted', 'close'],
	data() {
		return {
			busy: false,
			error: null,
		}
	},

	computed: {
		/** @spec openspec/changes/deelzaak-support/tasks.md#T11 */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/changes/deelzaak-support/tasks.md#T11 */
		deelzaakStore() {
			return useDeelzaakStore()
		},

		/** @spec openspec/changes/deelzaak-support/tasks.md#T11 */
		warningMessage() {
			return orphanWarningMessage(this.subCaseCount)
		},
	},

	methods: {
		/**
		 * @param {boolean} open Whether the open is set.
		 * @spec openspec/changes/deelzaak-support/tasks.md#T11
		 */
		onDialogClose(open) {
			if (!open) {
				this.$emit('close')
			}
		},

		/**
		 * Unlink every sub-case, then delete the parent. Orphan cleanup runs
		 * before deletion so the children survive (REQ-DZS-006-B).
		 *
		 * ⚠️ The parent is deleted ONLY when the unlink reports `complete`.
		 * Previously this awaited a bare count and deleted the parent
		 * unconditionally, so any sub-case that failed to detach — or that fell
		 * outside the server's 200-record page — was left pointing at a case
		 * that no longer exists, with the UI reporting success (procest#793).
		 * Aborting here is the behaviour REQ-DZS-006-B actually asks for: the
		 * children survive, which they do not if the parent goes while they are
		 * still attached.
		 *
		 * @spec openspec/specs/deelzaak-support/spec.md
		 */
		async confirmDelete() {
			this.busy = true
			this.error = null
			try {
				const result = await this.deelzaakStore.unlinkSubCases(
					this.parentCaseId,
				)
				if (!result.complete) {
					this.error = t(
						'dossiq',
						'{failed} of {total} sub-cases could not be detached, so the case was not deleted. Detach them first, then try again.',
						{ failed: result.failed, total: result.total },
					)
					return
				}
				await this.objectStore.deleteObject('case', this.parentCaseId)
				this.$emit('deleted', this.parentCaseId)
			} catch (err) {
				console.error('[DeelzaakDeleteWarningModal] delete failed', err)
				this.error = t(
					'dossiq',
					'The case could not be deleted. Please try again.',
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.deelzaak-delete-warning {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.deelzaak-delete-warning__detail {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
