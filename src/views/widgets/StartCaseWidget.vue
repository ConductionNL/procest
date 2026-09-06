<template>
	<div class="start-case-widget">
		<div v-if="loading" class="start-case-widget__loading">
			<NcLoadingIcon :size="44" />
		</div>
		<NcEmptyContent
			v-else-if="caseTypes.length === 0"
			:title="t('dossiq', 'No case types configured')"
			:description="
				t('dossiq', 'Configure case types in Dossiq admin settings')
			">
			<template #icon>
				<BriefcaseVariantOutline />
			</template>
		</NcEmptyContent>
		<div v-else class="start-case-widget__grid">
			<button
				v-for="caseType in caseTypes"
				:key="caseType.id"
				class="start-case-widget__card"
				:disabled="creating"
				:title="caseType.description || caseType.title"
				@click="startCase(caseType)">
				<BriefcaseVariantOutline
					:size="24"
					class="start-case-widget__card-icon" />
				<span class="start-case-widget__card-title">{{
					caseType.title
				}}</span>
				<NcLoadingIcon v-if="creatingId === caseType.id" :size="20" />
			</button>
		</div>

		<!-- Optional initiator selection (brp-kvk-register-sets): picking a
		     case type first asks who submitted it; Skip creates the case
		     without an initiator (existing flow unchanged). -->
		<InitiatorPickerModal
			v-if="pendingCaseType"
			@confirm="onInitiatorConfirmed"
			@skip="onInitiatorSkipped"
			@close="pendingCaseType = null" />
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import BriefcaseVariantOutline from 'vue-material-design-icons/BriefcaseVariantOutline.vue'
import InitiatorPickerModal from '../../modals/InitiatorPickerModal.vue'
import { initiatorProjection } from '../../services/initiatorSearch.js'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'StartCaseWidget',
	components: {
		NcEmptyContent,
		NcLoadingIcon,
		BriefcaseVariantOutline,
		InitiatorPickerModal,
	},

	props: {
		// Declared on purpose. The mount script passes `title: widget.title`, and
		// the Nextcloud dashboard host renders the heading; rendering it here too
		// is the dashboard-in-dashboard antipattern (hydra#316). Dropping the
		// declaration would not remove the prop, it would make it a fallthrough
		// attribute and put a title="" tooltip on the root element.
		// eslint-disable-next-line vue/no-unused-properties
		title: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: false,
			creating: false,
			creatingId: null,
			caseTypes: [],
			pendingCaseType: null,
		}
	},

	computed: {
		/** @spec openspec/specs/signalering-widgets/spec.md */
		objectStore() {
			return useObjectStore()
		},
	},

	mounted() {
		this.fetchCaseTypes()
	},

	methods: {
		/**
		 * Fetch available case types from OpenRegister.
		 *
		 * @return {Promise<void>}
		 */
		/** @spec openspec/specs/signalering-widgets/spec.md */
		async fetchCaseTypes() {
			this.loading = true
			try {
				const results = await this.objectStore.fetchCollection('caseType', {
					_limit: 50,
					isDraft: false,
				})
				this.caseTypes = results || []
			} catch (err) {
				console.error('[StartCaseWidget] Failed to fetch case types:', err)
				this.caseTypes = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the optional initiator step for the chosen case type.
		 * The case is created by onInitiatorConfirmed / onInitiatorSkipped.
		 *
		 * @param {object} caseType The case type to start
		 * @return {void}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		startCase(caseType) {
			if (this.creating) {
				return
			}
			this.pendingCaseType = caseType
		},

		/**
		 * Create the case carrying the picked initiator projection.
		 *
		 * @param {object} initiator The unified initiator result
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		async onInitiatorConfirmed(initiator) {
			const caseType = this.pendingCaseType
			this.pendingCaseType = null
			await this.createCase(caseType, initiatorProjection(initiator))
		},

		/**
		 * Create the case without an initiator (existing flow unchanged).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		async onInitiatorSkipped() {
			const caseType = this.pendingCaseType
			this.pendingCaseType = null
			await this.createCase(caseType, {})
		},

		/**
		 * Create a new case of the given type and navigate to it.
		 *
		 * @param {object} caseType The case type to start
		 * @param {object} extraFields Additional case fields (initiator projection)
		 * @return {Promise<void>}
		 * @spec openspec/specs/signalering-widgets/spec.md
		 */
		async createCase(caseType, extraFields = {}) {
			if (!caseType || this.creating) {
				return
			}
			this.creating = true
			this.creatingId = caseType.id
			try {
				const today = new Date().toISOString().split('T')[0]
				const newCase = await this.objectStore.saveObject('case', {
					title: caseType.title,
					caseType: caseType.id,
					startDate: today,
					...extraFields,
				})
				if (newCase?.id) {
					window.location.href = generateUrl(
						`/apps/dossiq/cases/${newCase.id}`,
					)
				}
			} catch (err) {
				console.error('[StartCaseWidget] Failed to create case:', err)
			} finally {
				this.creating = false
				this.creatingId = null
			}
		},
	},
}
</script>

<style scoped>
.start-case-widget {
	padding: 8px;
	height: 100%;
}

.start-case-widget__loading {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}

.start-case-widget__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
	gap: 8px;
}

.start-case-widget__card {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 6px;
	padding: 12px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	cursor: pointer;
	transition:
		background-color 0.15s ease,
		border-color 0.15s ease;
}

.start-case-widget__card:hover:not(:disabled) {
	background: var(--color-background-hover);
	border-color: var(--color-primary-element);
}

.start-case-widget__card:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.start-case-widget__card-icon {
	color: var(--color-primary-element);
}

.start-case-widget__card-title {
	font-size: var(--default-font-size);
	font-weight: 500;
	color: var(--color-main-text);
	text-align: center;
	overflow: hidden;
	text-overflow: ellipsis;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
}

@media (prefers-reduced-motion: reduce) {
	.start-case-widget__card {
		transition: none;
	}
}
</style>
