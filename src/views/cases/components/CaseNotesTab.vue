<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CaseNotesTab — sidebar tab wired as `component:` on the case-detail
  sidebar so that notes typed here go through the library's full
  `CnNotesTab` (not the compact `CnNotesCard` used for the existing
  "case-notes" body-grid widget). `CnNotesCard` predates the @mention
  feature (nc-vue #207) and only emits `note-added` / `note-deleted` /
  `show-all` — `CnNotesTab` is the component that actually parses
  `@mention` tokens and emits a `mention` event, so a mention-aware
  surface requires this tab, not the grid widget.

  Zero note/mention logic is reimplemented here (ADR-Leaf-First):
  `CnNotesTab` owns storage, autocomplete and chip rendering end to
  end. This wrapper only adds the one thing the library component
  cannot know about — dossiq's own NC notification — by listening for
  the `mention` event and forwarding its payload
  ({ objectId, register, schema, noteId, mentionedUserIds }) to
  POST /api/notes/mention. Resolves `CnNotesTab` via the existing
  `leafTab()` helper (see src/integrations/leafTabs.js) — the same
  mechanism already used for the calendar/forms/photos/maps leaf tabs
  — because `CnNotesTab` is not exported from the package root; it is
  only reachable through the integration registry's `notes` descriptor.

  Registered in src/registry.js as `CaseNotesTab` and wired as a
  `component:` sidebar tab (alongside the "audit" widgets-tab) on
  CaseDetail in src/manifest.json.

  @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
-->
<template>
	<!-- CnNotesTabComponent is a COMPUTED component (leafTab('notes')),
	     guarded by the v-if below. The rule resolves registered
	     components only and cannot see a computed one. -->
	<!-- eslint-disable-next-line vue/no-undef-components -->
	<CnNotesTabComponent
		v-if="CnNotesTabComponent"
		:objectId="objectId"
		:register="register"
		:schema="schema"
		:apiBase="apiBase"
		@mention="onMention" />
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { leafTab } from '../../../integrations/leafTabs.js'

export default {
	name: 'CaseNotesTab',

	props: {
		/** Object UUID; forwarded by CnObjectSidebar's sharedTabProps. */
		objectId: {
			type: String,
			default: '',
		},

		/** OpenRegister register slug; forwarded by sharedTabProps. */
		register: {
			type: String,
			default: '',
		},

		/** OpenRegister schema slug; forwarded by sharedTabProps. */
		schema: {
			type: String,
			default: '',
		},

		/** OpenRegister API base; forwarded by sharedTabProps. */
		apiBase: {
			type: String,
			default: '/apps/openregister/api',
		},
	},

	data() {
		return {
			// Resolved once — the integration registry is populated at
			// library bootstrap time, well before this tab ever mounts.
			CnNotesTabComponent: leafTab('notes'),
		}
	},

	methods: {
		/**
		 * Forward a saved note's mentions to dossiq's own notification
		 * endpoint. Best-effort: the note itself is already saved by
		 * `CnNotesTab` at this point, so a failed notification must never
		 * surface as an error to the user typing the note.
		 *
		 * @param {object} payload `{ objectId, register, schema, noteId, mentionedUserIds }`
		 *
		 * @spec openspec/specs/case-management/spec.md
		 */
		async onMention(payload) {
			try {
				await axios.post(
					generateUrl('/apps/dossiq/api/notes/mention'),
					payload,
				)
			} catch (e) {
				// eslint-disable-next-line no-console
				console.warn(
					'[CaseNotesTab] Failed to dispatch mention notification',
					e,
				)
			}
		},
	},
}
</script>
