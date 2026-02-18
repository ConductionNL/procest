<template>
	<div class="case-list">
		<div class="case-list__header">
			<h2>{{ t('procest', 'Cases') }}</h2>
			<NcButton type="primary" @click="createNew">
				{{ t('procest', 'New case') }}
			</NcButton>
		</div>

		<div class="case-list__search">
			<NcTextField
				:value="searchTerm"
				:label="t('procest', 'Search')"
				:show-trailing-button="searchTerm !== ''"
				@update:value="onSearch"
				@trailing-button-click="clearSearch" />
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="cases.length === 0" class="case-list__empty">
			<p>{{ t('procest', 'No cases found') }}</p>
		</div>

		<table v-else class="case-list__table">
			<thead>
				<tr>
					<th>{{ t('procest', 'Title') }}</th>
					<th>{{ t('procest', 'Status') }}</th>
					<th>{{ t('procest', 'Assignee') }}</th>
					<th>{{ t('procest', 'Created') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="caseItem in cases"
					:key="caseItem.id"
					class="case-list__row"
					@click="openCase(caseItem.id)">
					<td>{{ caseItem.title || '-' }}</td>
					<td>{{ caseItem.status || '-' }}</td>
					<td>{{ caseItem.assignee || '-' }}</td>
					<td>{{ formatDate(caseItem.created) }}</td>
				</tr>
			</tbody>
		</table>

		<div v-if="pagination.pages > 1" class="case-list__pagination">
			<NcButton
				:disabled="pagination.page <= 1"
				@click="loadPage(pagination.page - 1)">
				{{ t('procest', 'Previous') }}
			</NcButton>
			<span>{{ pagination.page }} / {{ pagination.pages }}</span>
			<NcButton
				:disabled="pagination.page >= pagination.pages"
				@click="loadPage(pagination.page + 1)">
				{{ t('procest', 'Next') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'CaseList',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
	},
	data() {
		return {
			searchTerm: '',
			searchTimeout: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		cases() {
			return this.objectStore.getCollection('case')
		},
		loading() {
			return this.objectStore.isLoading('case')
		},
		pagination() {
			return this.objectStore.getPagination('case')
		},
	},
	mounted() {
		this.fetchCases()
	},
	methods: {
		async fetchCases(params = {}) {
			await this.objectStore.fetchCollection('case', {
				_limit: 20,
				_offset: 0,
				...params,
			})
		},
		openCase(id) {
			this.$emit('navigate', 'case-detail', id)
		},
		createNew() {
			this.$emit('navigate', 'case-detail', 'new')
		},
		onSearch(value) {
			this.searchTerm = value
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.fetchCases({ _search: this.searchTerm })
			}, 300)
		},
		clearSearch() {
			this.searchTerm = ''
			this.fetchCases()
		},
		loadPage(page) {
			const offset = (page - 1) * this.pagination.limit
			this.fetchCases({
				_offset: offset,
				_search: this.searchTerm || undefined,
			})
		},
		formatDate(dateStr) {
			if (!dateStr) return '-'
			try {
				return new Date(dateStr).toLocaleDateString()
			} catch {
				return dateStr
			}
		},
	},
}
</script>

<style scoped>
.case-list {
	padding: 20px;
}

.case-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.case-list__search {
	margin-bottom: 16px;
	max-width: 400px;
}

.case-list__table {
	width: 100%;
	border-collapse: collapse;
}

.case-list__table th,
.case-list__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.case-list__row {
	cursor: pointer;
}

.case-list__row:hover {
	background: var(--color-background-hover);
}

.case-list__empty {
	padding: 40px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.case-list__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	margin-top: 16px;
}
</style>
