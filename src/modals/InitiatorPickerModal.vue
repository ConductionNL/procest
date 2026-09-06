<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Initiator picker modal for the case create flow (StartCaseWidget):
  optional selection — the case remains creatable without an initiator
  ("Skip"). Confirming emits the unified initiator result; the caller maps
  it onto the case projection fields (initiatorProjection()). Modal
  isolation per ADR-004: lives in src/modals/.

  @spec openspec/specs/initiator-selection/spec.md
-->
<template>
	<NcModal
		size="normal"
		:name="t('dossiq', 'Who is the initiator?')"
		@close="$emit('close')">
		<div class="initiator-picker-modal">
			<!-- No <h2> here: NcModal's `name` prop already renders the dialog
			     heading (h2.modal-header__name) and wires it as the dialog's
			     accessible name. Repeating it in the body announced the title
			     twice to a screen reader and made every
			     getByRole('heading', …) query ambiguous. -->
			<p class="initiator-picker-modal__hint">
				{{
					t(
						'dossiq',
						'Link the case to the person, company, or contact who submitted it. You can also skip this and add the initiator later.',
					)
				}}
			</p>

			<InitiatorPicker :value="selection" @select="selection = $event" />

			<div v-if="selection" class="initiator-picker-modal__selection">
				{{ t('dossiq', 'Selected:') }}
				<strong>{{ selection.displayName }}</strong> ({{
					selection.sourceId
				}})
			</div>

			<div class="initiator-picker-modal__actions">
				<NcButton type="tertiary" @click="$emit('skip')">
					{{ t('dossiq', 'Skip') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!selection"
					@click="$emit('confirm', selection)">
					{{ t('dossiq', 'Use as initiator') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'
import InitiatorPicker from '../components/initiator/InitiatorPicker.vue'

export default {
	name: 'InitiatorPickerModal',
	components: {
		NcButton,
		NcModal,
		InitiatorPicker,
	},

	emits: ['close', 'confirm', 'skip'],

	data() {
		return {
			selection: null,
		}
	},
}
</script>

<style scoped lang="scss">
.initiator-picker-modal {
	padding: calc(var(--default-grid-baseline) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
