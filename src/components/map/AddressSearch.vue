<template>
	<div class="address-search">
		<NcTextField
			:modelValue="query"
			:label="t('dossiq', 'Search address...')"
			:placeholder="t('dossiq', 'Street, postcode, or city')"
			trailingButtonIcon="close"
			:showTrailingButton="query.length > 0"
			@update:value="onInput"
			@trailingButtonClick="clear"
			@keydown.enter="onEnter" />

		<ul v-if="results.length > 0" class="address-search__results">
			<li
				v-for="result in results"
				:key="result.id"
				class="address-search__item"
				role="button"
				tabindex="0"
				@click="selectResult(result)"
				@keydown.enter="selectResult(result)"
				@keydown.space.prevent="selectResult(result)">
				<span class="address-search__icon">
					{{ getTypeIcon(result.type) }}
				</span>
				<span class="address-search__text">
					<span class="address-search__name">{{
						result.weergavenaam
					}}</span>
					<span
						v-if="result.gemeentenaam"
						class="address-search__municipality">
						{{ result.gemeentenaam }}
					</span>
				</span>
			</li>
		</ul>

		<p v-if="loading" class="address-search__loading">
			{{ t('dossiq', 'Searching...') }}
		</p>
	</div>
</template>

<script>
import { NcTextField } from '@nextcloud/vue'
import {
	extractCoordinates,
	formatAddress,
	free,
	lookup,
	suggest,
} from '../../services/pdokService.js'

export default {
	name: 'AddressSearch',
	components: { NcTextField },
	emits: ['select'],
	data() {
		return {
			query: '',
			results: [],
			loading: false,
		}
	},

	methods: {
		/**
		 * @param {string|number|boolean|object} value The new value.
		 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
		 */
		async onInput(value) {
			this.query = value
			if (value.length < 3) {
				this.results = []
				return
			}
			this.loading = true
			try {
				this.results = await suggest(value)
			} catch {
				this.results = []
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		async onEnter() {
			if (this.query.length < 3) return
			this.loading = true
			try {
				this.results = await free(this.query)
			} catch {
				this.results = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} result The result.
		 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
		 */
		async selectResult(result) {
			// Look up full details if we have an ID
			let fullResult = result
			if (result.id) {
				try {
					const looked = await lookup(result.id)
					if (looked) {
						fullResult = looked
					}
				} catch {
					// Use the suggest result as fallback
				}
			}

			const coords = extractCoordinates(fullResult)
			const address = formatAddress(fullResult)

			this.$emit('select', {
				result: fullResult,
				coordinates: coords,
				address,
			})

			this.query = address
			this.results = []
		},

		/** @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md */
		clear() {
			this.query = ''
			this.results = []
		},

		/**
		 * @param {string} type The type.
		 * @spec openspec/changes/retrofit-2026-05-25-map-component/tasks.md
		 */
		getTypeIcon(type) {
			switch (type) {
				case 'address':
					return '\uD83C\uDFE0'
				case 'weg':
					return '\uD83D\uDEB6'
				case 'city':
					return '\uD83C\uDFD9'
				case 'postcode':
					return '\uD83D\uDCEE'
				default:
					return '\uD83D\uDCCD'
			}
		},
	},
}
</script>

<style scoped>
.address-search {
	position: relative;
	width: 100%;
}

.address-search__results {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	z-index: 1001;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 0 0 8px 8px;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
	list-style: none;
	margin: 0;
	padding: 0;
	max-height: 300px;
	overflow-y: auto;
}

.address-search__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	cursor: pointer;
}

.address-search__item:hover {
	background: var(--color-background-hover);
}

.address-search__icon {
	font-size: 16px;
	flex-shrink: 0;
}

.address-search__text {
	display: flex;
	flex-direction: column;
}

.address-search__name {
	font-size: 13px;
}

.address-search__municipality {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.address-search__loading {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	padding: 8px 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
