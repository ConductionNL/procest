// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// V2 component registry for dossiq.
//
// Every entry here corresponds to a manifest `type: "custom"` page or a
// sidebar tab that uses `component:` instead of `widgets[]`. The registry
// maps the string key used in the manifest to a `{ kind, component }` entry
// so CnAppRoot can resolve the component at render time.
//
// Recognised kinds: page, modal, widget, form-field, cell-renderer
//
// Migration notes:
// - The visual workflow editor (`WorkflowEditor.vue`) is not a registry entry
//   — it is a plain child component mounted by `WorkflowTab.vue` inside the
//   case-type detail page's "Workflow" tab, not a manifest `type:"custom"`
//   page or sidebar-tab component. See openspec/specs/visual-workflow-editor.
//   A second, @vue-flow-based implementation (Vue-3-only, incompatible with
//   this app's Vue 2.7 build) was removed by workflow-editor-integration.
// - `MapComponent` is kept in customComponents.js for backward compat with any
//   manifest entries that reference it by string outside the registry. No
//   current manifest pages reference MapComponent by key directly; retained as
//   a pass-through.

import BesluitPublicatiePanel from './components/besluitvorming/BesluitPublicatiePanel.vue'
// Case-list CSV/Excel export via the OR export leaf — actions-slot component
// on the Cases page (manifest `pages[].actionsComponent`). Builds the OR
// export-leaf URL client-side; no dossiq-side serialization (ADR-022).
// @spec openspec/specs/case-list-export-via-or-export-leaf/spec.md
import CaseListExportAction from './components/export/CaseListExportAction.vue'
// The task half of the flow waiting relationship (case-flow-human-steps 6.1).
// @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
import TaskWaitingCaseSection from './components/flow/TaskWaitingCaseSection.vue'
// Initiator (indiener) selection + display — brp-kvk-register-sets.
// @spec openspec/specs/initiator-selection/spec.md
import InitiatorPicker from './components/initiator/InitiatorPicker.vue'
import InitiatorSection from './components/initiator/InitiatorSection.vue'
// "Besluitvorming" decision-making is owned by decidesk and surfaced here as
// an OR integration leaf (decidesk-decisions) on the case-detail sidebar.
// @spec openspec/changes/consume-decidesk-besluitvorming-leaf/tasks.md
import BesluitvormingLeafTab from './components/tabs/BesluitvormingLeafTab.vue'
import CaseDocumentsTab from './components/tabs/CaseDocumentsTab.vue'
// Detail-tab components (used as `component:` in sidebarTabs[])
import CaseTasksTab from './components/tabs/CaseTasksTab.vue'
import SubstitutionAdminView from './views/admin/SubstitutionAdmin.vue'
// VTH-specific case detail panels
import AdviceRequestPanel from './views/cases/components/AdviceRequestPanel.vue'
import AdviesPanel from './views/cases/components/AdviesPanel.vue'
// Case-assistant chat panel — conversational assistance delegated to Hermiq
// (fleet rule: AI functionality lives in Hermiq; dossiq is a thin consumer).
// @spec openspec/specs/case-assistant-via-hermiq/spec.md
// Case-email integration — leaf-first per ADR-022. The sidebar tab
// wraps the EmailThread component (display only), reuses NC Mail as
// the email engine, and triggers prefillDraft via the case-email API.
// @spec openspec/changes/case-email-integration/tasks.md#T12
import CaseEmailTab from './views/cases/components/CaseEmailTab.vue'
import CaseNotesTab from './views/cases/components/CaseNotesTab.vue'
// Federated case sharing/transfer/activity — federated-case-collaboration.
// @spec openspec/specs/federated-case-collaboration/spec.md
import CaseSharingTab from './views/cases/components/CaseSharingTab.vue'
// CMMN adaptive case-plan panel — sibling to the BPMN status-transition
// engine, for caseTypes with handlingModel = 'cmmn' (cmmn-adaptive-case).
// @spec openspec/specs/cmmn-adaptive-case/spec.md
import InspectionChecklistPanel from './views/cases/components/InspectionChecklistPanel.vue'
import InspectionPanel from './views/cases/components/InspectionPanel.vue'
import DeelzaakDetail from './views/cases/DeelzaakDetail.vue'
// Deelzaak (sub-case) full-page views — wired via manifest routes
// /cases/:id/deelzaken (list) and /cases/:parentId/deelzaken/:id (detail).
// Modal isolation per ADR-004: DeelzaakCreateModal lives in src/modals/.
// @spec openspec/changes/deelzaak-support/tasks.md#T05
// @spec openspec/changes/deelzaak-support/tasks.md#T06
import DeelzaakList from './views/cases/DeelzaakList.vue'
// Cases-on-map — full-screen multi-object overview. Consumes OpenRegister's
// page-level maps-overview leaf (OR #154): OR owns the geometry extraction,
// RBAC scoping, and base-layer config; the markers render through the lib's
// `CnMapWidget`. No bespoke Leaflet / WMS / WFS stack in dossiq (ADR-022).
// @spec openspec/specs/case-map-overview/spec.md
import CasesOnMapView from './views/CasesOnMapView.vue'
import FlowDetailSidebar from './views/flows/FlowDetailSidebar.vue'
import MyWorkView from './views/MyWorkCards.vue'
import PublicAppointmentPage from './views/public/PublicAppointmentPage.vue'
import PublicFederatedTransferPage from './views/public/PublicFederatedTransferPage.vue'
import PublicStatusPage from './views/public/PublicStatusPage.vue'
import WorkflowBoardView from './views/workflow-board/WorkflowBoard.vue'
import { leafTab } from './integrations/leafTabs.js'

// ADR-049 dissolution: the manifest Dashboard page's signal widgets (open /
// overdue / stalled cases, my tasks, task reminders, deadline alerts) and the
// two charts (cases-by-status, cases-by-type) no longer resolve through this
// registry. They are declared inline on the Dashboard page as built-in
// `object-table` (with `source.extend:["calculations"]` for the OpenRegister
// virtual calc columns daysOverdue / daysSinceActivity / daysUntilDue /
// daysUntilDeadline) and `chart` (aggregate + drilldown) widgets — CnDashboardPage
// resolves those from the shared dashboard-widget catalog, so no app-registry
// entry or `slots` mapping is needed. The self-fetching `src/views/widgets/*.vue`
// components and their `src/*Widget.js` native-dashboard entry points survive
// UNCHANGED for the native Nextcloud Dashboard (which has no manifest).

// Leverancier-zaakportaal external supplier portal MOVED to Portaliq (ADR-046,
// procest#162): the /leverancier Vue surface + the citizen "Mijn gemeente"
// pages (MijnZakenView / MijnNotificatiesView) are retired and re-expressed as
// the `supplier` and `citizen` audiences in
// lib/Portal/PortalContributionProvider.php. The backend supplier + zaakportaal
// services and their /api/* endpoints stay; only the in-app portal views and
// their nav/routes are removed.

// ADR-049 dissolution: the `audit-trail` registry adapter (AuditTrailWidget.vue)
// was a thin reimplementation of the library's built-in CnAuditTrailWidget.
// It has been removed — the manifest `audit-trail` widget key now resolves to
// the library built-in (BUILT_IN_WIDGETS for the slot CnWidgetGrid path, and the
// shared dashboard-widget catalog for CnDetailPage's config-grid body). The
// built-in resolves register/schema/objectId from the same detail object-context
// injects/props, so detail-page audit trails are unchanged.
//
// `version-history` (nc-vue #216, ncvue-w2-leaves-adoption): unlike
// `audit-trail`/`audit`, this integration id is NOT one of the four hardcoded
// keys in CnObjectSidebar's BUILTIN_WIDGETS map (`data`, `metadata`,
// `audit`/`audit-trail`, `object-table`), so a manifest sidebar tab cannot
// resolve it via `widgets: [{ "type": "version-history" }]` the way `audit`
// does — it would silently fail to render. It IS a real
// `builtinIntegrations` descriptor though (same registry `notes`/`calendar`/
// `forms`/`photos` live in), so it resolves the same way those leaves do:
// through `leafTab()` into a `component:` sidebar tab. See
// src/integrations/leafTabs.js.
// @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md

/**
 * V2 component registry.
 *
 * Keys must match the `component` strings used in the manifest.
 * All full-page custom routes and sidebar-tab components are kind: "page" —
 * the v2 renderer resolves any `component` key from this registry regardless
 * of whether it appears in a top-level page or in a sidebarTab entry.
 *
 * @type {Record<string, { kind: string, component: object }>}
 */
const registry = {
	// --- Case-list CSV/Excel export via the OR export leaf. ---
	// @spec openspec/specs/case-list-export-via-or-export-leaf/spec.md
	CaseListExportAction: {
		kind: 'page',
		component: CaseListExportAction,
		_note: 'Cases-page actions-slot "Export" menu (CSV/Excel); receives no props (CnIndexPage\'s #actions slot is unscoped). Builds the OR export-leaf URL client-side — no dossiq-side serialization (ADR-022).',
	},

	// --- Genuine exceptions: no abstract manifest analogue. ---
	MyWorkView: {
		kind: 'page',
		component: MyWorkView,
		_note: 'Current-user case index (assignee = current uid) in card view; a thin CnIndexPage wrapper injecting the resolved uid because the stock index base-filter does not resolve the @me token.',
	},

	// --- Cases-on-map overview (case-map-overview). ---
	// @spec openspec/specs/case-map-overview/spec.md
	CasesOnMapView: {
		kind: 'page',
		component: CasesOnMapView,
		_note: "Full-screen multi-object cases-on-map overview. Markers come from OpenRegister's page-level maps-overview surface (RBAC-scoped, OR #154) and render through the lib's CnMapWidget — no bespoke Leaflet/WMS/WFS plumbing (ADR-022).",
	},

	// --- Workflow Board — Kanban with drag-to-advance status transitions. ---
	// @spec openspec/specs/dashboard/spec.md
	WorkflowBoardView: {
		kind: 'page',
		component: WorkflowBoardView,
		_note: 'Kanban board: column per non-final status, drag-to-advance via saveObject (RBAC-enforced). No declarative board page type in lib yet.',
	},

	// --- Flows (ADR-110 Decision 4). ---
	// The shared CnFlowIndexPage / CnFlowDetail surfaces over OpenRegister's
	// native flow store, scoped `app: "dossiq"` so this app sees only its own.
	// Only the SIDEBAR is an app component now. The list and the canvas are the
	// shared `flows` / `flow-detail` manifest page types (nextcloud-vue 2.19.0),
	// so this app no longer carries wrapper copies of them — the three apps that
	// did each carried the same dead `@rowClick` listener.
	// @spec openspec/specs/automatic-actions/spec.md
	FlowDetailSidebar: {
		kind: 'page',
		component: FlowDetailSidebar,
		_note: 'CnFlowSidebar in the NC app sidebar; shares useFlowStore with the canvas.',
	},

	// --- Handler vervanging/waarneming (handler-vervanging-waarneming). ---
	// @spec openspec/specs/handler-vervanging-waarneming/spec.md
	// @spec openspec/specs/handler-vervanging-waarneming/spec.md
	SubstitutionAdminView: {
		kind: 'page',
		component: SubstitutionAdminView,
		_note: 'Coordinator substitution admin + bulk reassignment + capacity action list. Coordinator-gated server-side.',
	},

	// --- Initiator selection + display (brp-kvk-register-sets). ---
	// @spec openspec/specs/initiator-selection/spec.md
	InitiatorPicker: {
		kind: 'form-field',
		component: InitiatorPicker,
		_note: 'Cross-source initiator picker (Person=brpPerson / Company=kvkCompany register sets via the object store, Contact=core contactsmenu with graceful empty state). Also used inline by InitiatorPickerModal in the StartCaseWidget create flow.',
	},
	// @spec openspec/specs/initiator-display/spec.md
	InitiatorSection: {
		kind: 'widget',
		component: InitiatorSection,
		_note: 'CaseDetail overview widget: initiator name + type + source id deep-linking to the seeded brpPerson/kvkCompany record in OpenRegister. Renders nothing when the case has no initiator.',
	},

	// --- The task half of the flow waiting relationship (case-flow-human-steps 6.1). ---
	// @spec openspec/changes/case-flow-human-steps/specs/task-management/spec.md
	TaskWaitingCaseSection: {
		// @custom-widget-ratchet exclude a conditional cross-object link: it renders only when task.flowRun is set and links to the CASE, and no built-in fits (banner visibleWhen fetches by endpoint/source and its route is a page id without the case param; data/object-list cannot hide on a field)
		kind: 'widget',
		component: TaskWaitingCaseSection,
		_note: 'TaskDetail section: names the case a suspended flow run is holding on this task and links to it. Renders NOTHING for a task without a flowRun, so pre-existing tasks are unchanged. The case half (run + stage on CaseDetail) is deliberately absent: it waits on the fleet-generic subject-scoped runs widget (openregister flow-runs-subject-scope).',
	},

	// --- Case assistant via Hermiq (case-assistant-via-hermiq). ---
	// @spec openspec/specs/case-assistant-via-hermiq/spec.md

	// --- CMMN adaptive case plan (cmmn-adaptive-case). ---
	// @spec openspec/specs/cmmn-adaptive-case/spec.md

	// --- Besluitvorming workflow views. ---
	// The agenda compiler and the vergadering detail view were retired: decidiq
	// owns agenda-building and meetings, and surfaces them on a case through the
	// `decidesk-decisions` integration leaf rather than through pages here.
	BesluitPublicatiePanel: {
		kind: 'page',
		component: BesluitPublicatiePanel,
		_note: 'DROP/LVBB publication status + retry; embeddable as a case-detail sidebar tab component.',
	},

	// --- Anonymous-public routes (no auth, no main menu). ---
	// The bespoke public case-view (PublicCaseView) was removed by
	// migrate-public-share-to-shares-leaf: its password/comment/contribute
	// model was tied to the bespoke share-token controller. The citizen
	// "track your case" status page (PublicStatusPage) now resolves through
	// OpenRegister's shares-leaf #[PublicPage] case-token endpoint (ADR-022).
	PublicAppointmentPage: {
		kind: 'page',
		component: PublicAppointmentPage,
	},
	PublicStatusPage: {
		kind: 'page',
		component: PublicStatusPage,
	},
	// Remote-org accept/reject for a federated zaakoverdracht — authenticated
	// via the transfer-scoped OR federated-share bearer token in the URL,
	// not a local session (federated-case-collaboration).
	PublicFederatedTransferPage: {
		kind: 'page',
		component: PublicFederatedTransferPage,
	},

	// --- Detail-tab components (sidebar component: entries). ---
	// These resolve when a sidebarTab uses `component: "<key>"` instead of
	// a `widgets[]` array. CnDetailPage injects the resolved component into
	// the tab panel slot.
	CaseTasksTab: {
		kind: 'page',
		component: CaseTasksTab,
		_note: 'Tasks where task.case === parent.id',
	},
	// CaseDecisionsTab was retired by dossiq-decisions-to-decidiq: it offered
	// create/edit/delete of local decision records while mounted on no page.
	// Decisions are authored in decidiq (besluitvorming leaf); the read-only
	// case-decisions widget displays the outcomes stored on the case.
	CaseDocumentsTab: {
		kind: 'page',
		component: CaseDocumentsTab,
		_note: 'Documents where document.case === parent.id',
	},

	// --- Deelzaak (sub-case) full-page views — manifest routes. ---
	// @spec openspec/changes/deelzaak-support/tasks.md#T05
	DeelzaakList: {
		kind: 'page',
		component: DeelzaakList,
		_note: 'Sub-case list for a parent case; mounted under /cases/:id/deelzaken.',
	},
	// @spec openspec/changes/deelzaak-support/tasks.md#T06
	DeelzaakDetail: {
		kind: 'page',
		component: DeelzaakDetail,
		_note: 'Sub-case detail with parent breadcrumb; mounted under /cases/:parentId/deelzaken/:id.',
	},

	// --- Case-email sidebar tab — leaf-first per ADR-022. ---
	// @spec openspec/changes/case-email-integration/tasks.md#T12
	CaseEmailTab: {
		kind: 'page',
		component: CaseEmailTab,
		_note: 'Sidebar tab that surfaces email correspondence linked to the case; consumes the email leaf for display + uses prefillDraft for compose.',
	},

	// --- Case-appointment (internal calendar) sidebar tab — leaf-first per ADR-022. ---
	// The former bespoke LocalBackend scheduling surface is replaced by OR's
	// `calendar` integration leaf (CalendarProvider): the leaf owns event
	// list/create/link/unlink/delete and fetches straight from OR using the
	// objectId/register/schema/apiBase that CnObjectSidebar injects. Dossiq
	// keeps only zaak-specific metadata + external Qmatic/JCC (ADR-022 exception).
	// @spec openspec/changes/migrate-appointments-to-calendar-leaf/tasks.md#P1.2
	CalendarLeafTab: {
		kind: 'page',
		component: leafTab('calendar'),
		_note: 'OR calendar integration leaf (CnCalendarTab) surfaced on the case detail; replaces the bespoke LocalBackend appointment UI (ADR-022).',
	},

	// --- Version history sidebar tab (ncvue-w2-leaves-adoption, nc-vue #216). ---
	// Field-by-field diff viewer over the same audit-trail data as the
	// existing "audit" tab, resolved via leafTab('version-history') because
	// CnObjectSidebar's BUILTIN_WIDGETS map does not carry a
	// 'version-history' key (see the ADR-049-adjacent comment above). Wired
	// as a `component:` sidebar tab beside "audit" on every detail page's
	// manifest sidebar.tabs[] (src/manifest.json).
	// @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	VersionHistoryLeafTab: {
		kind: 'page',
		component: leafTab('version-history'),
		_note: 'nc-vue built-in version-history integration leaf (CnVersionHistory) surfaced on every detail page sidebar beside the existing audit-trail tab.',
	},

	// --- Notes sidebar tab with @mention notifications (ncvue-w2-leaves-adoption, nc-vue #207). ---
	// The existing "case-notes" CaseDetail body widget renders CnNotesCard,
	// which predates @mention and does not emit it. This sidebar tab renders
	// the full CnNotesTab (which does emit `mention`) and forwards the event
	// to dossiq's own notification endpoint — see CaseNotesTab.vue for the
	// full rationale. Wired as a `component:` sidebar tab on CaseDetail.
	// @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	CaseNotesTab: {
		kind: 'page',
		component: CaseNotesTab,
		_note: "Mention-aware notes sidebar tab: wraps the library CnNotesTab (via leafTab('notes')) and POSTs mention payloads to /api/notes/mention. Zero note/mention UI logic reimplemented — see CaseNotesTab.vue.",
	},
	// --- Sharing/transfer sidebar tab (federated-case-collaboration). ---
	// Wires the previously-orphaned ShareTab/CreateShareDialog/
	// CaseTransferDialog components (zero references anywhere before this
	// change) plus the new federated-share/activity UI into the real
	// case-detail sidebar. See CaseSharingTab.vue + design.md §7.
	// @spec openspec/specs/federated-case-collaboration/spec.md#the-case-detail-sharing-surface-is-wired-not-orphaned
	CaseSharingTab: {
		kind: 'page',
		component: CaseSharingTab,
		_note: 'Partner + federated case sharing, transfer and activity — container that wires ShareTab/CreateShareDialog/CaseTransferDialog/CreateFederatedShareDialog/FederatedActivityPanel to the backend API.',
	},
	AdviesPanel: {
		kind: 'page',
		component: AdviesPanel,
		_note: 'Advice/advies panel used in CaseDetail and BezwaarDetail sidebar tabs',
	},

	// --- Besluitvorming (decision-making) sidebar tab — decidesk leaf. ---
	// "decidesk owns it; dossiq shows a leaf" (ADR-019 / ADR-022). The
	// decidesk `decidesk-decisions` integration leaf (registered cross-app on
	// the shared OR integration registry by decidesk's global init script)
	// surfaces proposals/advice/decisions linked to this case via decidesk's
	// subjectId back-reference. The wrapper resolves the registered provider's
	// tab at render time and forwards the case `{ register, schema, objectId }`
	// context that CnObjectSidebar injects. Retires dossiq's former standalone
	// Voorstellen/Advies/Agenda nav.
	// @spec openspec/changes/consume-decidesk-besluitvorming-leaf/tasks.md
	BesluitvormingLeafTab: {
		kind: 'page',
		component: BesluitvormingLeafTab,
		_note: 'decidesk decisions integration leaf (decidesk-decisions) surfaced on the case detail; replaces the standalone Besluitvorming nav (ADR-019/ADR-022).',
	},

	// --- VTH module: case detail sidebar tabs. ---
	// @spec openspec/changes/vth-module/tasks.md#task-7
	AdviceRequestPanel: {
		kind: 'page',
		component: AdviceRequestPanel,
		_note: 'VTH advice request panel — shows open/received/overdue adviesAanvragen on VTH case detail',
	},
	InspectionChecklistPanel: {
		kind: 'page',
		component: InspectionChecklistPanel,
		_note: 'VTH checklist panel — shows inspection checklist completion status on Toezichtzaak',
	},
	InspectionPanel: {
		kind: 'page',
		component: InspectionPanel,
		_note: 'VTH inspection panel — shows completed inspectionResult records for a case',
	},

	// --- Forms + Photos leaves — leaf-first per ADR-022. ---
	// Inspection checklist / advice forms render through OR's `forms` leaf
	// (FormsProvider / CnFormsTab), inspection photos through OR's `photos`
	// leaf (PhotosProvider / CnPhotosTab). Both are resolved from the lib's
	// builtinIntegrations registry and fetch straight from OpenRegister using
	// the objectId/register/schema/apiBase CnObjectSidebar injects. The
	// checklist photo-gate + append-only immutability stay in-app (domain rules).
	// @spec openspec/changes/migrate-inspection-forms-to-forms-leaf/tasks.md#P1.2
	// @spec openspec/changes/migrate-inspection-forms-to-forms-leaf/tasks.md#P1.3
	FormsLeafTab: {
		kind: 'page',
		component: leafTab('forms'),
		_note: 'OR forms integration leaf (CnFormsTab) — renders checklist/advice forms on the case detail; replaces the bespoke hand-rendered checklist inputs (ADR-022).',
	},
	PhotosLeafTab: {
		kind: 'page',
		component: leafTab('photos'),
		_note: 'OR photos integration leaf (CnPhotosTab) — stores/shows inspection photos as files attached to the object; replaces inline photos[] payloads (ADR-022).',
	},

	// --- Maps leaf — leaf-first per ADR-022 (per-case map surface). ---
	// The case's location is rendered by OR's `maps` integration leaf
	// (MapsProvider / CnMapsTab): the leaf owns tiles, layers, zoom and
	// marker interaction and fetches straight from OpenRegister using the
	// objectId/register/schema/apiBase CnObjectSidebar injects. Replaces the
	// bespoke per-case Leaflet surface (LocationTab → CaseMap). The
	// multi-object cases-on-map overview (CasesOnMapView / /map page) is OUT
	// OF SCOPE here — tracked as a separate OR maps-overview follow-up.
	// @spec openspec/changes/migrate-maps-to-maps-leaf/tasks.md#P1.2
	MapsLeafTab: {
		kind: 'page',
		component: leafTab('maps'),
		_note: 'OR maps integration leaf (CnMapsTab) — renders the case location marker on the case detail; replaces the bespoke per-case LocationTab/CaseMap (ADR-022).',
	},

	// --- Zaakportaal "Mijn gemeente" citizen portal MOVED to Portaliq
	//     (ADR-046, procest#162): re-expressed as the `citizen` audience in
	//     lib/Portal/PortalContributionProvider.php. See import-section comment. ---

	// --- Dashboard signal widgets + charts + header actions DISSOLVED (ADR-049). ---
	// casesOverview / overdueCases / stalledCases / myTasks / taskReminders /
	// deadlineAlerts are now built-in `object-table` widgets, statusChart /
	// casesByType are built-in `chart` widgets, and DashboardHeaderActions is
	// now a declarative `config.headerActions[]` array — all declared inline on
	// the manifest Dashboard page (src/manifest.json) and resolved by the
	// library, so they no longer need an app-registry entry. The self-fetching
	// `src/views/widgets/*.vue` components stay for the native NC Dashboard
	// (registered via the `src/*Widget.js` OCA.Dashboard entry points).

	// --- Leverancier-zaakportaal external supplier portal MOVED to Portaliq
	//     (ADR-046, procest#162) — see import-section comment. ---
}

export default registry
