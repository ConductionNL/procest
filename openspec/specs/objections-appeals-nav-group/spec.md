---
status: done
---

# objections-appeals-nav-group Specification

## Purpose
Presents the objections-and-appeals (bezwaar & beroep) domain as a single top-level navigation group instead of six scattered flat entries, with its five transactional surfaces rendered as children in a coherent workflow order. Every grouped page stays routable at its existing route, and the change is a pure information-architecture edit limited to src/menu-layout.json and src/manifest.json ordering, touching no schema, controller, route, or decision flow.
## Requirements
### Requirement: REQ-POAG-001 — The Objections-And-Appeals Domain Is One Top-Level Nav Group

dossiq SHALL present the objections-and-appeals (bezwaar & beroep) domain as a single top-level
navigation group `BezwaarBeroepGroup` (label "Bezwaar & Beroep"). The transactional surfaces
`Bezwaren`, `Beroepen`, `BezwaarDecisions` (Beslissingen op bezwaar), `BezwaarAdviceRequests`
(BAC-adviezen), and `BezwaarCommitteesMenu` (Bezwaaradviescommissies) SHALL render as **children**
of that group, and SHALL NOT render as flat top-level (or stray settings-section) siblings.

#### Scenario: Sidebar shows one group, not six flat entries

- **GIVEN** the dossiq left navigation after the manifest fragments merge and
  `applyMenuRelocations` runs
- **WHEN** the sidebar renders
- **THEN** there SHALL be exactly one top-level entry for the bezwaar/beroep domain — the
  "Bezwaar & Beroep" (`BezwaarBeroepGroup`) group
- **AND** `Bezwaren`, `Beroepen`, `BezwaarDecisions`, `BezwaarAdviceRequests`, and
  `BezwaarCommitteesMenu` SHALL all appear as children of that group
- **AND** none of those five SHALL appear as a flat top-level menu entry

#### Scenario: Bezwaaradviescommissies joins the group instead of the settings section

- **GIVEN** `BezwaarCommitteesMenu` was previously a `section: "settings"` entry outside the group
- **WHEN** `src/menu-layout.json#relocations` maps `"BezwaarCommitteesMenu": "BezwaarBeroepGroup"`
- **THEN** `BezwaarCommitteesMenu` SHALL render as a child of `BezwaarBeroepGroup`
- **AND** it SHALL NOT render as a standalone settings-section bezwaar entry outside the group

---

### Requirement: REQ-POAG-002 — Group Children Render In A Coherent Workflow Order

dossiq SHALL order the five children of `BezwaarBeroepGroup` as a contiguous, workflow-meaningful
sequence: Bezwaren, then Beroepen, then Beslissingen op bezwaar, then BAC-adviezen, then
Bezwaaradviescommissies. The leaf `order` values in `src/manifest.json` SHALL be set to a
contiguous run (`Bezwaren`=45, `Beroepen`=46, `BezwaarDecisions`=47, `BezwaarAdviceRequests`=48,
`BezwaarCommitteesMenu`=49) and the `BezwaarBeroepGroup` header SHALL keep `order`=45.

#### Scenario: Children appear in the declared sequence

- **GIVEN** the grouped "Bezwaar & Beroep" navigation
- **WHEN** the group is expanded
- **THEN** the children SHALL appear in the order Bezwaren → Beroepen → Beslissingen op bezwaar →
  BAC-adviezen → Bezwaaradviescommissies
- **AND** no child SHALL retain the previous scattered order (45/46/47/76/99) that broke the
  sequence

---

### Requirement: REQ-POAG-003 — Every Grouped Page Stays Routable (Relocation Moves The Entry, Not The Page)

dossiq SHALL keep every objections-and-appeals DETAIL page routable at its existing route.
Relocation moves only the menu entry, so `/bezwaar-decisions` (+`/bezwaar-decisions/:id`),
`/bezwaar-advice-requests` (+`/bezwaar-advice-requests/:id`), `/settings/bezwaar-committees`
(+`/settings/bezwaar-committees/:id`), `/bezwaren/:id` and `/beroepen/:id` SHALL remain registered
and reachable by direct URL and e2e specs.

AMENDED 2026-09-02. The `/bezwaren` and `/beroepen` INDEX pages are retired outright, not hidden.
Each was an index over register `dossiq` and schema `case` whose only narrowing was
`filter: { caseType: <uuid> }`, and Cases carries the same register, the same schema and the same
narrowing through its `folderSidebar` (`filterField: caseType`). A second list over identical data
is the duplication this grouping set out to remove, so hiding the entry stopped half way. Detail
routes stay, so every objection and appeal link keeps resolving. The original clause forbidding a
`src/menu-layout.json#removals` entry now holds for a different reason: there is no entry left to
remove.

#### Scenario: Deep links to grouped pages still resolve

- **GIVEN** the grouping has shipped
- **WHEN** a user navigates directly to `/bezwaar-decisions`, `/bezwaar-advice-requests`, or
  `/settings/bezwaar-committees`
- **THEN** the corresponding page SHALL load
- **AND** the page route SHALL be unchanged from before the grouping

#### Scenario: Detail routes remain reachable

- **GIVEN** a `:id` detail route such as `/bezwaren/:id` or `/settings/bezwaar-committees/:id`
- **WHEN** it is visited by direct URL
- **THEN** the detail page SHALL load (the grouping did not touch `pages[]`)

---

### Requirement: REQ-POAG-004 — Grouping Introduces No Schema, Controller, Or Decision-Flow Change

dossiq SHALL implement this grouping as a pure information-architecture change limited to
`src/menu-layout.json` (the relocation map) and `src/manifest.json` (`menu[]` `order` values). It
SHALL NOT add or modify any schema, controller, route, or page, and it SHALL NOT delegate or alter
the decision flow behind the "Beslissingen op bezwaar" or "BAC-adviezen" surfaces — that delegation
is the separate change `dossiq-delegate-remaining-decisions-to-decidesk`.

#### Scenario: No backend or flow change ships with the grouping

- **GIVEN** the dossiq-objections-appeals-group change
- **WHEN** its diff is inspected
- **THEN** only `src/menu-layout.json` and `src/manifest.json` `menu[]` ordering SHALL change
- **AND** no schema, controller, route, or `pages[]` entry SHALL be added, removed, or modified
- **AND** the decision flow behind the grouped decision/advice pages SHALL be left for the sibling
  delegation change to retarget

