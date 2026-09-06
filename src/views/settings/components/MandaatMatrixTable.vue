<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="mandaat-matrix-table">
		<div class="mandaat-matrix-table__toolbar">
			<NcButton type="primary" @click="$emit('edit', null)">
				<template #icon>
					<Plus :size="18" />
				</template>
				{{ t('dossiq', 'New mandaat') }}
			</NcButton>
			<NcButton type="secondary" @click="$emit('import')">
				<template #icon>
					<Import :size="18" />
				</template>
				{{ t('dossiq', 'Import from Decidesk') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-if="!loading && matrices.length === 0"
			:name="t('dossiq', 'No mandate decisions')"
			:description="
				t(
					'dossiq',
					'No MandateringsBesluit entries yet. Create one or import an export.',
				)
			">
			<template #icon>
				<FileDocumentMultiple :size="48" />
			</template>
		</NcEmptyContent>

		<table
			v-if="!loading && matrices.length > 0"
			class="mandaat-matrix-table__table">
			<thead>
				<tr>
					<th scope="col">{{ t('dossiq', '#') }}</th>
					<th scope="col">{{ t('dossiq', 'Naam') }}</th>
					<th scope="col">{{ t('dossiq', 'Status') }}</th>
					<th scope="col">{{ t('dossiq', 'In werkingtreding') }}</th>
					<th scope="col">{{ t('dossiq', 'Expiry date') }}</th>
					<th scope="col">{{ t('dossiq', 'Acties') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(b, idx) in matrices" :key="b.id">
					<td>{{ idx + 1 }}</td>
					<td>{{ b.name || b.mandateNumber || b.id }}</td>
					<td>
						<span
							class="mandaat-matrix-table__badge"
							:class="badgeClass(b.status)"
							>{{ b.status || '—' }}</span
						>
					</td>
					<td>{{ b.inWerkingtreding || '—' }}</td>
					<td>{{ b.vervaldatum || '—' }}</td>
					<td>
						<NcButton size="small" @click="$emit('edit', b)">
							{{ t('dossiq', 'Edit') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import FileDocumentMultiple from 'vue-material-design-icons/FileDocumentMultiple.vue'
import Import from 'vue-material-design-icons/Import.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'MandaatMatrixTable',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Plus,
		Import,
		FileDocumentMultiple,
	},

	props: {
		matrices: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
	},

	emits: ['edit', 'import'],
	methods: {
		t,
		/**
		 * @param {string} status The status.
		 * @spec openspec/changes/mandaat-matrix-07-admin-ui/tasks.md
		 */
		badgeClass(status) {
			const s = (status || '').toLowerCase()
			if (s === 'active' || s === 'active')
				return 'mandaat-matrix-table__badge--ok'
			if (s === 'lapsed' || s === 'expired')
				return 'mandaat-matrix-table__badge--alert'
			if (s === 'draft' || s === 'draft')
				return 'mandaat-matrix-table__badge--neutral'
			return 'mandaat-matrix-table__badge--neutral'
		},
	},
}
</script>

<style scoped>
.mandaat-matrix-table__toolbar {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
}

.mandaat-matrix-table__table {
	width: 100%;
	border-collapse: collapse;
}

.mandaat-matrix-table__table th,
.mandaat-matrix-table__table td {
	padding: 8px 10px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.mandaat-matrix-table__table th {
	background: var(--color-background-dark);
	font-weight: 500;
}

.mandaat-matrix-table__badge {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 8px;
	display: inline-block;
}

.mandaat-matrix-table__badge--ok {
	background: var(--color-success);
	color: var(--color-main-background);
}

.mandaat-matrix-table__badge--alert {
	background: var(--color-error);
	color: var(--color-main-background);
}

.mandaat-matrix-table__badge--neutral {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
</style>
