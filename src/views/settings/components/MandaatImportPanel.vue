<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Mandate import panel — paste/upload a Decidesk export, preview, then
  approve. Wraps the member-04 backend import workflow.
-->
<template>
	<div class="mandaat-import">
		<h3>{{ t('dossiq', 'Import mandate export') }}</h3>
		<p class="mandaat-import__description">
			{{
				t(
					'dossiq',
					'Paste or upload a Decidesk mandate export (CSV/JSON). The preview shows which mandaten will be created, updated, or skipped before you approve the import.',
				)
			}}
		</p>

		<div class="mandaat-import__upload">
			<label for="mandaat-import-file">{{ t('dossiq', 'Upload file') }}</label>
			<input
				id="mandaat-import-file"
				type="file"
				accept=".csv,.json,application/json,text/csv"
				@change="onFileChange" />
		</div>

		<div class="mandaat-import__paste">
			<label for="mandaat-import-paste">{{
				t('dossiq', 'Or paste content')
			}}</label>
			<textarea
				id="mandaat-import-paste"
				v-model="raw"
				class="mandaat-import__textarea"
				:placeholder="t('dossiq', 'Paste CSV or JSON here…')"
				rows="8" />
		</div>

		<div class="mandaat-import__actions">
			<NcButton :disabled="!raw || running" type="primary" @click="preview">
				<template #icon>
					<NcLoadingIcon v-if="running" :size="18" />
					<EyeOutline v-else :size="18" />
				</template>
				{{ t('dossiq', 'Preview') }}
			</NcButton>
			<NcButton
				v-if="previewResult"
				:disabled="!importId || approving"
				type="success"
				@click="approve">
				<template #icon>
					<CheckBold :size="18" />
				</template>
				{{ t('dossiq', 'Approve & import') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="previewResult" class="mandaat-import__preview">
			<h4>{{ t('dossiq', 'Preview') }}</h4>
			<div class="mandaat-import__counters">
				<span class="mandaat-import__counter mandaat-import__counter--good">
					{{
						t('dossiq', '{n} new', {
							n: previewResult.summary?.create || 0,
						})
					}}
				</span>
				<span class="mandaat-import__counter mandaat-import__counter--warn">
					{{
						t('dossiq', '{n} update', {
							n: previewResult.summary?.update || 0,
						})
					}}
				</span>
				<span
					class="mandaat-import__counter mandaat-import__counter--neutral">
					{{
						t('dossiq', '{n} skip', {
							n: previewResult.summary?.skip || 0,
						})
					}}
				</span>
				<span class="mandaat-import__counter mandaat-import__counter--alert">
					{{
						t('dossiq', '{n} conflicts', {
							n: previewResult.summary?.conflicts || 0,
						})
					}}
				</span>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import CheckBold from 'vue-material-design-icons/CheckBold.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'

export default {
	name: 'MandaatImportPanel',
	components: { NcButton, NcLoadingIcon, NcNoteCard, EyeOutline, CheckBold },
	emits: ['imported'],
	data() {
		return {
			raw: '',
			running: false,
			approving: false,
			error: null,
			previewResult: null,
			importId: null,
		}
	},

	methods: {
		t,
		/**
		 * @param {Event} e The originating DOM event.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		onFileChange(e) {
			const f = e.target.files?.[0]
			if (!f) return
			const reader = new FileReader()
			reader.onload = (ev) => {
				this.raw = String(ev.target.result || '')
			}
			reader.readAsText(f)
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		async preview() {
			this.running = true
			this.error = null
			this.previewResult = null
			this.importId = null
			try {
				const res = await axios.post(
					generateUrl('/apps/dossiq/api/mandate/import'),
					{ payload: this.raw },
				)
				this.previewResult = res.data
				this.importId = res.data?.importId || res.data?.id || null
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e.message
					|| t('dossiq', 'Preview failed')
			} finally {
				this.running = false
			}
		},

		/** @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md */
		async approve() {
			if (!this.importId) return
			this.approving = true
			this.error = null
			try {
				await axios.post(
					generateUrl(
						'/apps/dossiq/api/mandate/import/'
							+ encodeURIComponent(this.importId)
							+ '/approve',
					),
				)
				this.$emit('imported')
				this.raw = ''
				this.previewResult = null
				this.importId = null
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e.message
					|| t('dossiq', 'Approve failed')
			} finally {
				this.approving = false
			}
		},
	},
}
</script>

<style scoped>
.mandaat-import {
	max-width: 720px;
}

.mandaat-import__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.mandaat-import__upload,
.mandaat-import__paste {
	margin-bottom: 12px;
}

.mandaat-import__upload label,
.mandaat-import__paste label {
	display: block;
	margin-bottom: 4px;
	font-weight: 500;
}

.mandaat-import__textarea {
	width: 100%;
	padding: 8px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 12px;
}

.mandaat-import__actions {
	display: flex;
	gap: 8px;
	margin: 12px 0;
}

.mandaat-import__preview {
	margin-top: 12px;
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.mandaat-import__counters {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.mandaat-import__counter {
	font-size: 12px;
	padding: 4px 10px;
	border-radius: 8px;
}

.mandaat-import__counter--good {
	background: var(--color-success);
	color: var(--color-main-background);
}

.mandaat-import__counter--warn {
	background: var(--color-warning);
	color: var(--color-main-background);
}

.mandaat-import__counter--alert {
	background: var(--color-error);
	color: var(--color-main-background);
}

.mandaat-import__counter--neutral {
	background: var(--color-background-darker);
}
</style>
