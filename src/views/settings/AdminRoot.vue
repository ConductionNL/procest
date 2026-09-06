<template>
	<CnAdminSettingsShell appId="dossiq" appName="Dossiq" @reimported="onReimported">
		<Settings />

		<CnSettingsSection
			:name="t('dossiq', 'Case Type Management')"
			:description="t('dossiq', 'Manage case types and their configurations')"
			:loading="!storesReady">
			<CaseTypeAdmin v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'ZGW API Mapping')"
			:description="
				t(
					'dossiq',
					'Configure property mappings between English OpenRegister fields and Dutch ZGW API fields',
				)
			"
			:loading="!storesReady">
			<ZgwMappingSettings v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'VTH Inspection Checklists')"
			:description="
				t(
					'dossiq',
					'Configure reusable inspection checklists for VTH cases (Toezicht). Checklists are versioned and linked to case types.',
				)
			"
			:loading="!storesReady">
			<ChecklistsTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'AI-Assisted Processing')"
			:description="
				t(
					'dossiq',
					'Configure AI features for document classification, data extraction, Q&A, summarization, routing and decision support',
				)
			"
			:loading="!storesReady">
			<AiSettingsTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'AWB Term Definitions')"
			:description="
				t(
					'dossiq',
					'Configure statutory term definitions per zaaktype for AWB termijnbewaking (legal basis, duration, validity). Versioning is enforced on save.',
				)
			"
			:loading="!storesReady">
			<TermijnDefinitiesTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Mandate Matrix — Administration')"
			:description="
				t(
					'dossiq',
					'Configure mandate decisions, organisational roles, role assignments, and import legacy mandate exports',
				)
			"
			:loading="!storesReady">
			<MandaatMatrixTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Mandate Matrix — System Settings')"
			:description="
				t(
					'dossiq',
					'Awb art. 10:3 mandate administration: Decidesk import, role hierarchy, waarnemer assignments.',
				)
			"
			:loading="!storesReady">
			<MandaatMatrixSettingsTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Store registry')"
			:description="
				t(
					'dossiq',
					'Connect the OpenRegister instance the Store page browses. Without one, dossiq stays offline and shows only its own templates.',
				)
			"
			:loading="!storesReady">
			<StoreSettingsTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Consultation Management')"
			:description="
				t(
					'dossiq',
					'Adviesaanvragen: advisory body registry, mandatory-gate config, n8n webhook contracts and external response settings.',
				)
			"
			:loading="!storesReady">
			<ConsultationSettingsTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Case Email — Shared Mailbox')"
			:description="
				t(
					'dossiq',
					'Shared functional mailbox ingest (IMAP) and transport for case correspondence. Outbound mail and per-user accounts are owned by Nextcloud Mail.',
				)
			"
			:loading="!storesReady">
			<EmailSettings v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'KCC-werkplek Integration')"
			:description="
				t(
					'dossiq',
					'Burger identification, case-voorblad limits, sentiment trigger words, and belplan overflow thresholds for the KCC contact-center bridge.',
				)
			"
			:loading="!storesReady">
			<KccIntegrationSettings v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Decision Tables (DMN)')"
			:description="
				t(
					'dossiq',
					'Configure DMN-style decision tables (inputs, outputs, rules and a hit policy) that domain experts can maintain without a developer. A workflow step can invoke a decision by key, and decisions are also evaluable via the REST API.',
				)
			"
			:loading="!storesReady">
			<DecisionTablesTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Financial Integration — Dwangsom Callback')"
			:description="
				t(
					'dossiq',
					'Configure the shared secret used to validate ERP payment-confirmation callbacks for dwangsom (penalty payment) uitbetalingen.',
				)
			"
			:loading="!storesReady">
			<FinancialIntegrationTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'StUF-ZKN Endpoints')"
			:description="
				t(
					'dossiq',
					'Outbound StUF-ZKN/BG zaaksysteem endpoints per gemeente, with per-endpoint circuit-breaker health. Endpoints, WSSE credentials and mTLS certificates are managed by the platform operator.',
				)
			"
			:loading="!storesReady">
			<StufEndpoints v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'Tenant onboarding')"
			:description="
				t(
					'dossiq',
					'Track a tenant through the seven onboarding steps, run the go-live readiness check and activate the tenant. One-time setup, so it belongs here rather than in the daily navigation.',
				)
			"
			:loading="!storesReady">
			<TenantOnboardingTab v-if="storesReady" />
		</CnSettingsSection>

		<CnSettingsSection
			:name="t('dossiq', 'StUF-ZKN Audit Log')"
			:description="
				t(
					'dossiq',
					'Per-call audit log for outbound and inbound StUF SOAP envelopes (full XML, HTTP status, duration, retry history).',
				)
			"
			:loading="!storesReady">
			<StufAuditLog v-if="storesReady" />
		</CnSettingsSection>
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell, CnSettingsSection } from '@conduction/nextcloud-vue'
import CaseTypeAdmin from './CaseTypeAdmin.vue'
import EmailSettings from './EmailSettings.vue'
import KccIntegrationSettings from './KccIntegrationSettings.vue'
import Settings from './Settings.vue'
import StufAuditLog from './StufAuditLog.vue'
import StufEndpoints from './StufEndpoints.vue'
import AiSettingsTab from './tabs/AiSettingsTab.vue'
import ChecklistsTab from './tabs/ChecklistsTab.vue'
import ConsultationSettingsTab from './tabs/ConsultationSettingsTab.vue'
import DecisionTablesTab from './tabs/DecisionTablesTab.vue'
import FinancialIntegrationTab from './tabs/FinancialIntegrationTab.vue'
import MandaatMatrixSettingsTab from './tabs/MandaatMatrixSettingsTab.vue'
import MandaatMatrixTab from './tabs/MandaatMatrixTab.vue'
import StoreSettingsTab from './tabs/StoreSettingsTab.vue'
import TenantOnboardingTab from './tabs/TenantOnboardingTab.vue'
import TermijnDefinitiesTab from './tabs/TermijnDefinitiesTab.vue'
import ZgwMappingSettings from './ZgwMappingSettings.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		TenantOnboardingTab,
		CnAdminSettingsShell,
		CnSettingsSection,
		Settings,
		CaseTypeAdmin,
		ZgwMappingSettings,
		AiSettingsTab,
		ChecklistsTab,
		TermijnDefinitiesTab,
		MandaatMatrixTab,
		MandaatMatrixSettingsTab,
		ConsultationSettingsTab,
		StoreSettingsTab,
		FinancialIntegrationTab,
		EmailSettings,
		KccIntegrationSettings,
		DecisionTablesTab,
		StufEndpoints,
		StufAuditLog,
	},

	data() {
		return {
			storesReady: false,
		}
	},

	/** @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md */
	async created() {
		await initializeStores()
		this.storesReady = true
	},

	methods: {
		/**
		 * Refresh the app stores after the shell re-imports the OpenRegister configuration.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-admin-settings/tasks.md
		 */
		async onReimported() {
			this.storesReady = false
			await initializeStores()
			this.storesReady = true
		},
	},
}
</script>
