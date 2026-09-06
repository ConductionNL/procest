<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Initiator (indiener) picker — cross-source search with Person / Company /
  Contact tabs (brp-kvk-register-sets). Person and Company search the seeded
  brpPerson / kvkCompany register sets through the object store (thin client,
  register tier only — the live-adapter fallback is owned by
  external-integrations-test-environments); Contact searches Nextcloud
  contacts via the core contactsmenu endpoint and degrades to an explicit
  empty state when unavailable. Emits `select` with the unified result.

  @spec openspec/specs/initiator-selection/spec.md
-->
<template>
	<div class="initiator-picker">
		<div class="initiator-picker__tabs" role="tablist">
			<NcCheckboxRadioSwitch
				v-for="tab in tabs"
				:key="tab.id"
				v-model="activeTab"
				:value="tab.id"
				name="initiator-type"
				type="radio"
				buttonVariant
				buttonVariantGrouped="horizontal">
				{{ tab.label }}
			</NcCheckboxRadioSwitch>
		</div>

		<NcTextField
			v-model="query"
			class="initiator-picker__search"
			:label="t('dossiq', 'Search initiator')"
			:placeholder="searchPlaceholder"
			trailingButtonIcon="close"
			:showTrailingButton="query !== ''"
			@trailingButtonClick="query = ''"
			@update:modelValue="onQueryChanged" />

		<NcLoadingIcon v-if="searching" :size="24" />

		<NcEmptyContent
			v-else-if="query.trim() !== '' && results.length === 0"
			:name="emptyTitle"
			:description="emptyDescription" />

		<ul v-else class="initiator-picker__results">
			<li v-for="result in results" :key="`${result.type}-${result.sourceId}`">
				<button
					type="button"
					class="initiator-picker__result"
					:class="{
						'initiator-picker__result--selected': isSelected(result),
					}"
					@click="select(result)">
					<component
						:is="typeIcon(result.type)"
						:size="20"
						class="initiator-picker__result-icon" />
					<span class="initiator-picker__result-name">{{
						result.displayName
					}}</span>
					<span class="initiator-picker__result-detail">{{
						result.detail
					}}</span>
				</button>
			</li>
		</ul>
	</div>
</template>

<script>
import {
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import CardAccountMailOutline from 'vue-material-design-icons/CardAccountMailOutline.vue'
import Domain from 'vue-material-design-icons/Domain.vue'
import {
	companyResult,
	personResult,
	searchContacts,
} from '../../services/initiatorSearch.js'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'InitiatorPicker',
	components: {
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
		AccountOutline,
		Domain,
		CardAccountMailOutline,
	},

	props: {
		/** Currently selected initiator (unified result) or null. */
		value: {
			type: Object,
			default: null,
		},
	},

	emits: ['select'],

	data() {
		return {
			activeTab: 'person',
			query: '',
			results: [],
			searching: false,
			searchTimer: null,
		}
	},

	computed: {
		/** @spec openspec/specs/initiator-selection/spec.md */
		objectStore() {
			return useObjectStore()
		},

		/** @spec openspec/specs/initiator-selection/spec.md */
		tabs() {
			return [
				{ id: 'person', label: t('dossiq', 'Person') },
				{ id: 'company', label: t('dossiq', 'Company') },
				{ id: 'contact', label: t('dossiq', 'Contact') },
			]
		},

		/** @spec openspec/specs/initiator-selection/spec.md */
		searchPlaceholder() {
			switch (this.activeTab) {
				case 'company':
					return t('dossiq', 'Trade name or KvK number')
				case 'contact':
					return t('dossiq', 'Contact name or email')
				default:
					return t('dossiq', 'Name or BSN')
			}
		},

		/** @spec openspec/specs/initiator-selection/spec.md */
		emptyTitle() {
			return this.activeTab === 'contact'
				? t('dossiq', 'No contacts found')
				: t('dossiq', 'No results')
		},

		/** @spec openspec/specs/initiator-selection/spec.md */
		emptyDescription() {
			return this.activeTab === 'contact'
				? t(
						'dossiq',
						'No matching contacts — the Contacts app may not be installed or holds no matching entries.',
					)
				: t('dossiq', 'No matching records in the seeded register set.')
		},
	},

	watch: {
		/** @spec openspec/specs/initiator-selection/spec.md */
		activeTab() {
			this.results = []
			if (this.query.trim() !== '') {
				this.runSearch()
			}
		},
	},

	methods: {
		/**
		 * Debounced search trigger for text input.
		 *
		 * @return {void}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		onQueryChanged() {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => this.runSearch(), 300)
		},

		/**
		 * Execute the search against the active source.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		async runSearch() {
			const query = this.query.trim()
			if (query === '') {
				this.results = []
				return
			}
			this.searching = true
			try {
				if (this.activeTab === 'person') {
					const rows = await this.objectStore.fetchCollection(
						'brpPerson',
						{ _search: query, _limit: 20 },
					)
					this.results = (rows || []).map(personResult)
				} else if (this.activeTab === 'company') {
					const rows = await this.objectStore.fetchCollection(
						'kvkCompany',
						{ _search: query, _limit: 20 },
					)
					this.results = (rows || []).map(companyResult)
				} else {
					this.results = await searchContacts(query)
				}
			} catch (err) {
				console.error('[InitiatorPicker] search failed', err)
				this.results = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * Whether a result matches the current selection.
		 *
		 * @param {object} result A unified initiator result.
		 * @return {boolean} True when selected.
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		isSelected(result) {
			return (
				!!this.value
				&& this.value.type === result.type
				&& this.value.sourceId === result.sourceId
			)
		},

		/**
		 * Emit the picked initiator.
		 *
		 * @param {object} result A unified initiator result.
		 * @return {void}
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		select(result) {
			this.$emit('select', result)
		},

		/**
		 * Icon component per initiator type.
		 *
		 * @param {string} type person | company | contact.
		 * @return {string} Icon component name.
		 * @spec openspec/specs/initiator-selection/spec.md
		 */
		typeIcon(type) {
			switch (type) {
				case 'company':
					return 'Domain'
				case 'contact':
					return 'CardAccountMailOutline'
				default:
					return 'AccountOutline'
			}
		},
	},
}
</script>

<style scoped lang="scss">
.initiator-picker {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);

	&__tabs {
		display: flex;
	}

	&__results {
		max-height: 40vh;
		overflow-y: auto;
	}

	&__result {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		width: 100%;
		padding: calc(var(--default-grid-baseline) * 2);
		border: none;
		border-radius: var(--border-radius);
		background: transparent;
		text-align: start;
		cursor: pointer;

		&:hover,
		&:focus-visible {
			background-color: var(--color-background-hover);
		}

		&--selected {
			background-color: var(--color-primary-element-light);
		}
	}

	&__result-name {
		font-weight: bold;
	}

	&__result-detail {
		color: var(--color-text-maxcontrast);
	}
}
</style>
