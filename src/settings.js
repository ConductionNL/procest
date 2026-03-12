import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import pinia from './pinia.js'
import AdminRoot from './views/settings/AdminRoot.vue'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)

loadTranslations('procest', () => {
	new Vue({
		pinia,
		render: h => h(AdminRoot),
	}).$mount('#procest-settings')
})
