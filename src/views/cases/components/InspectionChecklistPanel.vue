<template>
	<div class="inspection-checklist">
		<h4 class="inspection-checklist__title">
			{{ t('dossiq', 'Inspection Checklist') }}
		</h4>

		<!--
			ADR-022 leaf-first: inspection checklist questions are rendered and
			captured by OpenRegister's `forms` integration leaf, and inspection
			photos by the `photos` leaf — both surfaced as the "Forms" and
			"Photos" tabs on the case detail. This panel no longer hand-renders
			question inputs or inline photo affordances; it explains where to
			capture the run and surfaces the in-app domain gate that validates
			the leaf-captured data (photo-gate `fotoRequired`, append-only
			immutability — REQ-IC-8).
			@spec openspec/changes/migrate-inspection-forms-to-forms-leaf/tasks.md#P2.4
		-->
		<NcEmptyContent
			:name="t('dossiq', 'Capture inspections via the Forms tab')"
			:description="
				t(
					'dossiq',
					'Inspection checklist items are filled in through the Forms tab and photos are attached through the Photos tab. Dossiq validates the photo requirement and append-only rules against the captured data.',
				)
			">
			<template #icon>
				<ClipboardCheckMultipleOutline :size="48" />
			</template>
		</NcEmptyContent>

		<ul class="inspection-checklist__rules">
			<li>
				{{
					t(
						'dossiq',
						'Photo gate: required photos are checked against attachments in the Photos tab.',
					)
				}}
			</li>
			<li>
				{{
					t(
						'dossiq',
						'A submitted run is append-only and can no longer be edited.',
					)
				}}
			</li>
		</ul>
	</div>
</template>

<script>
import { NcEmptyContent } from '@nextcloud/vue'
import ClipboardCheckMultipleOutline from 'vue-material-design-icons/ClipboardCheckMultipleOutline.vue'

export default {
	name: 'InspectionChecklistPanel',
	components: {
		NcEmptyContent,
		ClipboardCheckMultipleOutline,
	},

	props: {
		/** Case UUID — kept for parent contract; the leaf tabs use the object context. */
	},
}
</script>

<style scoped>
.inspection-checklist__title {
	margin-bottom: 12px;
}

.inspection-checklist__rules {
	margin: 12px 0 0;
	padding-left: 18px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.inspection-checklist__rules li {
	margin-bottom: 4px;
}
</style>
