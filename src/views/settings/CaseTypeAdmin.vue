<template>
	<div class="case-type-admin">
		<CaseTypeDetail
			v-if="currentView === 'detail'"
			:case-type-id="currentId"
			@back="showList"
			@saved="onSaved" />
		<CaseTypeList
			v-else
			@select="openDetail"
			@create="openCreate" />
	</div>
</template>

<script>
import CaseTypeList from './CaseTypeList.vue'
import CaseTypeDetail from './CaseTypeDetail.vue'

export default {
	name: 'CaseTypeAdmin',
	components: {
		CaseTypeList,
		CaseTypeDetail,
	},
	data() {
		return {
			currentView: 'list',
			currentId: null,
		}
	},
	methods: {
		openDetail(id) {
			this.currentId = id
			this.currentView = 'detail'
		},
		openCreate() {
			this.currentId = null
			this.currentView = 'detail'
		},
		showList() {
			this.currentView = 'list'
			this.currentId = null
		},
		onSaved(id) {
			this.currentId = id
		},
	},
}
</script>
