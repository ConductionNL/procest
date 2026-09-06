<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - StufEnvelopeDialog is the envelope inspector opened from StufAuditLog. It
  - renders the request/response SOAP envelopes plus the retries[] history and
  - fout payload of a single StUF audit-log row.
  -
  - @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-audit-log
  -
  - @visual exclude Admin-only read-only envelope inspector opened from StufAuditLog; it renders StUF SOAP envelope rows fetched from /api/stuf/messages, which only exist after real outbound/inbound traffic against a seeded zaaksysteem. Without that traffic the dialog is never reachable, so a screenshot baseline would capture nothing meaningful. Covered by the env-gated live-e2e job; the cell formatting (pretty) is unit-testable JS, not a stable pixel surface.
-->
<template>
	<NcDialog
		:name="t('dossiq', 'StUF envelope')"
		:open="!!row"
		size="large"
		@closing="$emit('close')">
		<div class="stuf-audit-log__details">
			<h4>{{ t('dossiq', 'Request envelope') }}</h4>
			<pre class="stuf-audit-log__pre">{{
				row.envelopeXml || t('dossiq', '(no envelope)')
			}}</pre>
			<h4 v-if="row.responseEnvelopeXml">
				{{ t('dossiq', 'Response envelope') }}
			</h4>
			<pre v-if="row.responseEnvelopeXml" class="stuf-audit-log__pre">{{
				row.responseEnvelopeXml
			}}</pre>
			<h4 v-if="hasRetries(row)">
				{{ t('dossiq', 'Retries') }}
			</h4>
			<table v-if="hasRetries(row)" class="stuf-audit-log__retries">
				<thead>
					<tr>
						<th scope="col">{{ t('dossiq', 'Attempt') }}</th>
						<th scope="col">{{ t('dossiq', 'Timestamp') }}</th>
						<th scope="col">{{ t('dossiq', 'HTTP') }}</th>
						<th scope="col">{{ t('dossiq', 'Duration (ms)') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(retry, index) in row.retries" :key="index">
						<td>{{ retry.attempt }}</td>
						<td>{{ retry.timestamp }}</td>
						<td>{{ retry.httpStatus || '—' }}</td>
						<td>{{ retry.durationMs || '—' }}</td>
					</tr>
				</tbody>
			</table>
			<h4 v-if="row.fout">
				{{ t('dossiq', 'Error') }}
			</h4>
			<pre v-if="row.fout" class="stuf-audit-log__pre">{{
				pretty(row.fout)
			}}</pre>
		</div>
	</NcDialog>
</template>

<script>
import { NcDialog } from '@nextcloud/vue'

export default {
	name: 'StufEnvelopeDialog',
	components: { NcDialog },
	props: {
		row: { type: Object, required: true },
	},

	emits: ['close'],
	methods: {
		hasRetries(row) {
			return Array.isArray(row.retries) && row.retries.length > 0
		},

		/**
		 * Pretty-print a value as indented JSON for display.
		 *
		 * @param {unknown} value The value to render.
		 * @spec exclude presentational JSON formatter — no business logic
		 */
		pretty(value) {
			try {
				return JSON.stringify(value, null, 2)
			} catch (e) {
				return String(value)
			}
		},
	},
}
</script>

<style scoped>
.stuf-audit-log__details h4 {
	margin: 16px 0 4px;
}

.stuf-audit-log__pre {
	background: var(--color-background-dark);
	padding: 8px;
	border-radius: var(--border-radius);
	overflow: auto;
	font-size: 11px;
	max-height: 320px;
	white-space: pre-wrap;
	word-break: break-all;
}

.stuf-audit-log__retries {
	width: 100%;
	border-collapse: collapse;
}

.stuf-audit-log__retries th,
.stuf-audit-log__retries td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}
</style>
