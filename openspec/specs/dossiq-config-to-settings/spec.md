---
status: done
---

# dossiq-config-to-settings Specification

## Purpose
Groups all of dossiq's configuration and admin navigation leaves under a single Settings menu node so they no longer clutter the top level of the primary navigation. Operational surfaces such as live fee calculations and the Termijnbewaking KPI dashboard stay in the working navigation, and the relocation touches only the menu structure — every relocated page remains reachable by its existing route, with no new pages, schemas, or business logic introduced.
## Requirements
### Requirement: REQ-PCTS-001 — Configuration Surfaces Live Under A Single Settings Group

dossiq SHALL render every configuration / admin navigation leaf as a child of a single
`SettingsGroup` menu node, and SHALL NOT render any configuration leaf at the top level of the
primary navigation. The grouping SHALL be expressed in `src/menu-layout.json#relocations`
(`sourceId -> "SettingsGroup"`) per ADR-037, with the `SettingsGroup` shell defined in
`src/manifest.json#menu`; the configuration leaves are `CaseTypesMenu`, `LegesverordeningenMenu`,
`PartnersMenu`, `TenantsMenu`, `ParafeerroutesMenu`, `WmsLayersMenu`, `WorkflowDefinitionsMenu`,
`AutomaticActionsMenu`, `LhsMatricesMenu`, `LhsRecommendationsMenu`, `LocationsMenu`,
`StatusRecordsMenu`, `BezwaarCommitteesMenu`, `ArchiefDashboardMenu`, `TenantOnboardingMenu`,
`SubstitutionMenu`, `SubstitutionAdminMenu`, `SettingsMenu`, and the leges fragment leaf
`LegesVerordeningen`.

#### Scenario: Case-type and fee-schedule config render under the Settings group

- **GIVEN** dossiq's nav is built (manifest + fragments merged, `menu-layout.json` applied)
- **WHEN** a user opens the primary navigation
- **THEN** `CaseTypesMenu` and `LegesverordeningenMenu` SHALL render as children of `SettingsGroup`
- **AND** neither SHALL render at the top level of the primary navigation

#### Scenario: All listed config leaves are children of one Settings group

- **GIVEN** the relocations map folds each listed configuration leaf into `SettingsGroup`
- **WHEN** the nav is rendered
- **THEN** exactly one `SettingsGroup` node SHALL exist
- **AND** `TenantsMenu`, `ParafeerroutesMenu`, `WmsLayersMenu`, `WorkflowDefinitionsMenu`,
  `AutomaticActionsMenu`, `LhsMatricesMenu`, `TenantOnboardingMenu` and the other listed leaves
  SHALL all appear as its children

---

### Requirement: REQ-PCTS-002 — Top-Level Leges Admin Leaves Are Folded In, Not Left Top-Level

dossiq SHALL relocate the leges *tariff-admin* fragment leaf `LegesVerordeningen`
(`src/manifest.d/30-leges.json`, currently top-level with no `section`) under `SettingsGroup` via the
relocations map, without editing the fragment's page definition. The page route `/leges/verordeningen`
SHALL be unchanged.

#### Scenario: The leges fragment admin leaf moves under Settings

- **GIVEN** `src/manifest.d/30-leges.json` declares the top-level `LegesVerordeningen` menu leaf
- **WHEN** `menu-layout.json#relocations` maps `LegesVerordeningen -> "SettingsGroup"`
- **THEN** the leaf SHALL render under `SettingsGroup`
- **AND** it SHALL NOT render at the top level of the primary navigation
- **AND** the fragment's page (route `/leges/verordeningen`) SHALL be unmodified

---

### Requirement: REQ-PCTS-003 — Operational Surfaces Stay In The Working Navigation

dossiq SHALL keep operational surfaces in the working (non-Settings) navigation and SHALL NOT
relocate them under `SettingsGroup`. Specifically, the live per-case fee-calculation list
(`Legesberekeningen` / `LegesberekeningenMenu`) SHALL render in the working nav with its
`"section": "settings"` flag removed, and the operational Termijnbewaking KPI dashboard
(`TermijnDashboardMenu`, route `/termijn-dashboard`) SHALL remain relocated to `AnalyticsGroup`.
Neither SHALL be added to the `SettingsGroup` relocations.

#### Scenario: Live fee calculations are not demoted to Settings

- **GIVEN** `legesberekening` rows are live per-case calculation output (case, total, status,
  calculatedBy, calculatedAt)
- **WHEN** the `Legesberekeningen` menu leaf is configured
- **THEN** `LegesberekeningenMenu` SHALL NOT carry `"section": "settings"`
- **AND** `LegesberekeningenMenu` SHALL NOT appear in `menu-layout.json#relocations` targeting `SettingsGroup`
- **AND** it SHALL render in the working navigation

#### Scenario: The Termijnbewaking dashboard stays operational

- **GIVEN** `TermijnDashboardMenu` is the operational AWB-termijnbewaking KPI dashboard
- **WHEN** the nav is rendered
- **THEN** `TermijnDashboardMenu` SHALL remain a child of `AnalyticsGroup`
- **AND** it SHALL NOT be relocated under `SettingsGroup`

---

### Requirement: REQ-PCTS-004 — Relocated Pages Stay Routable

Every page whose nav leaf is relocated SHALL remain reachable by its existing route after the change.

AMENDED 2026-09-02. One exception: the `/settings/parafeerroutes` INDEX page is retired outright.
dossiq#1666 moved parafering to the decision app and retired the local engine, so the design screen
invited edits that reach nothing that runs. `/settings/parafeerroutes/:id` stays registered, so a
reader can still open a legacy route object, and the audit context naming `parafeerrouteId` keeps
resolving.
dossiq SHALL NOT change any page `id`, `route`, `type` or `component` as part of this relocation; the
change SHALL touch only the menu structure (`src/manifest.json#menu`, `src/menu-layout.json`) and the
`Legesberekeningen` section flag.

#### Scenario: Deep links to relocated config pages still resolve

- **GIVEN** the configuration leaves have been relocated under `SettingsGroup`
- **WHEN** a user navigates directly to `/settings/tenants`, `/settings/parafeerroutes/:id`,
  `/settings/wms-layers`, `/settings/workflow-definitions`, `/settings/automatic-actions`,
  `/settings/lhs-matrices`, `/legesverordeningen`, `/tenant-onboarding`, `/leges/verordeningen` or `/settings`
- **THEN** each route SHALL resolve to its existing page
- **AND** no page `route`/`type`/`component` SHALL have changed

#### Scenario: No page definitions are modified by the relocation

- **GIVEN** the change edits only menu structure and one section flag
- **WHEN** the diff is inspected
- **THEN** no entry under `src/manifest.json#pages` (or the leges fragment's `pages`) SHALL be added,
  removed or modified

---

### Requirement: REQ-PCTS-005 — No New Capability Is Introduced (ADR-012)

This change SHALL NOT add any page, schema, controller, service, background job, or business logic; it
SHALL only relocate existing menu leaves and correct one `section` flag. It SHALL reuse dossiq's
existing relocation engine rather than introducing new navigation infrastructure.

#### Scenario: The change is nav-only

- **GIVEN** the change set
- **WHEN** it is reviewed for new capability
- **THEN** the only files changed SHALL be `src/manifest.json`, `src/menu-layout.json` and
  (relocations only) the reference to `src/manifest.d/30-leges.json`'s leaf
- **AND** no `lib/` PHP file, schema, or `src/views`/`src/components` file SHALL be added or changed
- **AND** no `lib/Repair/*` migration SHALL be required (no data is moved)

