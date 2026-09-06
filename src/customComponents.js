// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for dossiq's manifest-driven app shell.
//
// Every entry here is the "escape hatch" — pages or sidebar tabs that
// don't fit one of the manifest's built-in types/widgets. Keep this
// file SHORT. Adding entries should require explicit justification in
// the design doc; deleting them is the right direction.
//
// Resolution order at runtime:
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// See:
//   - openspec/changes/procest-manifest-v1/design.md
//   - @conduction/nextcloud-vue → docs/migrating-to-manifest.md

// --- Surviving custom pages — see design.md "Custom-fallback inventory". ---
import { createApp } from 'vue'
import CaseDocumentsTab from './components/tabs/CaseDocumentsTab.vue'
// --- Detail-tab custom components (one per cross-schema relation). ---
// Stubs for v1 — full implementations follow in `procest-case-relation-tabs`.
import CaseTasksTab from './components/tabs/CaseTasksTab.vue'
import ReassignSelectionDialog from './dialogs/ReassignSelectionDialog.vue'
// --- Case-email sidebar tab (leaf-first per ADR-022). ---
// @spec openspec/changes/case-email-integration/tasks.md#T12
import CaseEmailTab from './views/cases/components/CaseEmailTab.vue'
// --- ZGW DRC case dossier sidebar tab. ---
// @spec openspec/changes/document-zaakdossier/tasks.md#T10
import DossierTab from './views/cases/components/DossierTab.vue'
import DeelzaakDetail from './views/cases/DeelzaakDetail.vue'
// --- Deelzaak (sub-case) full-page views — manifest custom routes. ---
// @spec openspec/changes/deelzaak-support/tasks.md#T05
// @spec openspec/changes/deelzaak-support/tasks.md#T06
import DeelzaakList from './views/cases/DeelzaakList.vue'
// --- Leverancier-zaakportaal (external supplier portal) MOVED to Portaliq
//     (ADR-046, procest#162): the /leverancier Vue surface is retired here and
//     re-expressed as the `supplier` audience in
//     lib/Portal/PortalContributionProvider.php. The backend supplier services
//     + /api/leverancier-portaal/* endpoints stay; only the in-app portal views
//     and their nav/routes are removed. ---
// CaseMapView removed — superseded by manifest `type: 'map'` CnMapPage
// (see openspec/changes/case-map-overview/design.md).
import DtAtRiskWidget from './views/doorlooptijd/widgets/DtAtRiskWidget.vue'
import DtBreakdownWidget from './views/doorlooptijd/widgets/DtBreakdownWidget.vue'
import DtCaseTypeFilter from './views/doorlooptijd/widgets/DtCaseTypeFilter.vue'
import DtChartsWidget from './views/doorlooptijd/widgets/DtChartsWidget.vue'
import DtKpiWidget from './views/doorlooptijd/widgets/DtKpiWidget.vue'
import DtWooWidget from './views/doorlooptijd/widgets/DtWooWidget.vue'
import MyWorkView from './views/MyWorkCards.vue'
import PmBottleneckTableWidget from './views/processMining/PmBottleneckTableWidget.vue'
import PmCaseTypeFilter from './views/processMining/PmCaseTypeFilter.vue'
import PmDwellChartWidget from './views/processMining/PmDwellChartWidget.vue'
import PmKpiWidget from './views/processMining/PmKpiWidget.vue'
import PmThroughputChartWidget from './views/processMining/PmThroughputChartWidget.vue'
// Token-addressed advice-response surface for external advisory bodies
// (consultation-management TASK-CN-06). Declared as page
// `ExternalConsultationResponse` in src/manifest.d/consultation-public.json;
// its absence here rendered the manifest renderer's "This page is empty"
// placeholder on /public/consultations/:token instead of this component.
import ExternalConsultationResponsePage from './views/public/ExternalConsultationResponsePage.vue'
import PublicAppointmentPage from './views/public/PublicAppointmentPage.vue'
// Remote-org accept/reject for a federated zaakoverdracht (federated-case-collaboration).
import PublicFederatedTransferPage from './views/public/PublicFederatedTransferPage.vue'
import PublicStatusPage from './views/public/PublicStatusPage.vue'
// --- Store (ADR-080). A store item is a REMOTE object, so the manifest's
//     object-backed index renderer — which resolves a local register+schema —
//     cannot address it. Discovery itself is the engine's, not this file's.
// @spec openspec/changes/dossiq-store-surface/specs/dossiq-store-surface/spec.md
import StoreGallery from './views/store/StoreGallery.vue'
// --- Termijnbewaking + Tenant dashboards (chain-builds 06/2026). ---
// Archief dashboard retired (migrate-archival-to-or, ADR-022): the archivist
// views are owned by OpenRegister.
import TdAnnualWidget from './views/termijn/TdAnnualWidget.vue'
import TdCaseTypeFilter from './views/termijn/TdCaseTypeFilter.vue'
import TdKpiWidget from './views/termijn/TdKpiWidget.vue'
import TdQuarterlyWidget from './views/termijn/TdQuarterlyWidget.vue'
// Mobiel-inspectie offline views retired — "Veldinspecties" now surfaces the
// generic `field-inspection` OpenRegister integration leaf (a nc-vue builtin),
// registered with dossiq's offline schema mapping in src/main.js. The custom
// InspectieList/InspectieDetail views + their offline glue (offlineDb.js,
// syncReplayService.js) are deleted; the leaf owns the planning list, checklist
// completion, mutation queue and reconnect-replay.
// --- Features & Roadmap page — thin wrapper around the lib's
//     CnFeaturesAndRoadmapView (the in-product roadmap surface powered by
//     OpenRegister's github-issue-proxy). See ConductionNL/hydra#251. ---

/**
 * Bulk-action handler for the Cases index: reassign the selected cases.
 *
 * CnIndexPage calls a function-typed `customComponents[handler]` with
 * `{ actionId, selectedIds, count }`, so the SELECTION arrives as an argument.
 * That matters: a handler that went and re-read the selection itself would be
 * one re-render away from acting on a different set than the user saw
 * highlighted.
 *
 * The dialog is mounted here rather than declared in the manifest because the
 * library's declarative modal path emits `open-modal` and nothing consumes it.
 *
 * @param {{actionId: string, selectedIds: Array<string>, count: number}} scope The selection.
 * @return {void}
 */
function reassignSelection({ selectedIds }) {
	const ids = Array.isArray(selectedIds) ? selectedIds : []
	if (ids.length === 0) {
		return
	}

	const host = document.createElement('div')
	document.body.appendChild(host)

	const app = createApp(ReassignSelectionDialog, {
		open: true,
		selectedIds: ids,
		'onUpdate:open': (open) => {
			if (open === false) {
				app.unmount()
				host.remove()
			}
		},
		onReassigned: () => {
			// The index has to re-read: the rows the user just moved are no
			// longer theirs, and leaving them on screen invites a second
			// reassignment of cases that already moved.
			window.dispatchEvent(new CustomEvent('dossiq:cases-changed'))
		},
	})
	app.mount(host)
}

export default {
	// --- Genuine exceptions: no abstract analogue. ---
	// The Cases page's `reassign` bulk action. A FUNCTION handler, not the
	// manifest's declarative `handler: "open-modal"` path: that path emits an
	// `open-modal` event and nothing in the library listens for it yet, so
	// declaring it would ship a bulk action that does nothing when clicked.
	reassignSelection,
	MyWorkView, // current-user case index (assignee=uid) in card view — CnIndexPage wrapper
	StoreGallery, // remote store cards — index renderer cannot address a REMOTE object
	// CaseMapView removed — see import comment above.

	// --- Lib gaps: would migrate once lib gains the missing primitive. ---
	// Processing time is a type:"dashboard" page; these are its slots.
	DtCaseTypeFilter, // header-actions slot: SLA-bearing case types only
	DtKpiWidget, // KPI row + the three guidance states
	DtChartsWidget, // donut / histogram / trend / throughput
	DtWooWidget, // Woo statutory-deadline panel
	DtAtRiskWidget, // open cases within 25% of deadline
	DtBreakdownWidget, // per-case-type performance table
	// Deadline monitoring is a type:"dashboard" page; these are its slots.
	TdCaseTypeFilter, // header-actions slot: case-type filter
	TdKpiWidget, // headline KPI tiles (CnKpiGrid + CnStatsBlock)
	TdQuarterlyWidget, // quarterly report table + CSV export
	TdAnnualWidget, // annual dwangsom audit summary
	// Process mining is a type:"dashboard" page; these are its widget slots.
	// The page owns the heading and both filters — a widget that drew its own
	// heading would be the dashboard-in-dashboard antipattern (hydra#316).
	PmCaseTypeFilter, // header-actions slot: case-type filter (pageFilters cannot bind dynamic options)
	PmKpiWidget, // headline KPI tiles (CnKpiGrid + CnStatsBlock)
	PmDwellChartWidget, // dwell time by status (CnChartWidget bar)
	PmThroughputChartWidget, // weekly throughput (CnChartWidget line)
	PmBottleneckTableWidget, // bottleneck ranking (ad-hoc row shape, no object-list leaf applies)

	// --- Anonymous-public routes (no auth, no main menu). ---
	PublicAppointmentPage,
	PublicStatusPage,
	PublicFederatedTransferPage,
	ExternalConsultationResponsePage,

	// --- Leverancier-zaakportaal external supplier portal MOVED to Portaliq
	//     (ADR-046, procest#162) — see import-section comment. ---

	// --- Detail-tab components (one per case-detail cross-schema relation). ---
	CaseTasksTab, // tasks where task.case === parent.id
	// CaseDecisionsTab was retired by dossiq-decisions-to-decidiq: decisions
	// are authored in decidiq (besluitvorming leaf); the read-only
	// case-decisions widget displays the outcomes stored on the case.
	CaseDocumentsTab, // documents where document.case === parent.id

	// --- Deelzaak (sub-case) views (manifest /cases/:id/deelzaken[/...]). ---
	DeelzaakList, // sub-case list for a parent case
	DeelzaakDetail, // sub-case detail with parent breadcrumb

	// --- Mobiel-inspectie retired — see import-section comment; "Veldinspecties"
	//     is now a dashboard page surfacing the `field-inspection` leaf. ---

	// --- Case-email sidebar tab (display via leaf, compose via NC Mail draft). ---
	CaseEmailTab,

	// --- ZGW DRC case dossier tab (document-zaakdossier). ---
	DossierTab,

	// --- Features & Roadmap page (lib's CnFeaturesAndRoadmapView). ---
}
