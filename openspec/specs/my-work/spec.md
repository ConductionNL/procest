---
status: done
---

# My Work Specification

## Purpose

My Work is the personal starting point for a case handler: the list of cases
assigned to the signed-in user. It answers the daily question "what is on my
plate?" by scoping the standard case index to `assignee == currentUser` and
rendering it as a card list (with a table toggle).

It is one of three surfaces in the My work group, and each answers a different
question. **Queue** (`/queue`) holds what nobody has picked up: no `assignee` and
`isFinalStatus` false. **Assigned to me** (this page) holds what is the signed-in
user's. **All cases** holds everything. Assigning a case moves it from the Queue to
this page; All cases shows it either way. See `openspec/changes/add-work-queue`. It deliberately reuses the
same index engine as the "All cases" view rather than a bespoke board, so
filtering, sorting, the sidebar and navigation behave identically.

**Scope note (2026-07):** My Work was simplified from a bespoke cases+tasks
"werkvoorraad" board (urgency grouping, filter tabs, show-completed) to a
standard `CnIndexPage` card list of assigned cases. Task aggregation, urgency
grouping and cross-app (Pipelinq) workload were dropped from this view; the
personal-workload dashboard widgets (below) remain the at-a-glance surface.

**Competitive context**: Dimpact ZAC provides a configurable worklist with
signaling cards and real-time updates; xxllnc Zaken uses phase-bound task
lists; Flowable offers a unified task inbox with claiming and delegation.
Dossiq takes a deliberately simple approach: the current user's cases in the
standard index, plus dashboard widgets for tasks/overdue at-a-glance.

## Data Sources

My Work queries one OpenRegister schema in the `dossiq` register:
- **Cases**: schema `case`, base filter `assignee == currentUser` (the signed-in
  user's uid, resolved client-side from `@nextcloud/auth`). No status filter is
  applied — every case assigned to the user is listed regardless of lifecycle
  state, so a handler sees their full assigned load.

## Requirements

### Requirement: Personal Case Index [MVP]

The system MUST provide a "My Work" navigation entry that opens a case index
scoped to the current user's assignments, implemented as a thin `CnIndexPage`
wrapper in `src/views/MyWorkCards.vue` (register `dossiq`, schema `case`,
base filter `{ assignee: <current uid> }`). It is a `type: "custom"` manifest
page because the stock index base-filter resolves only `@route.*` tokens, not
the `@me` current-user token; the wrapper injects the resolved uid.

@e2e exclude Requires cases pre-assigned to the current user; the data-dependent
list contents are not assertable without pre-seeded per-user data.

#### Scenario: View assigned cases
- GIVEN user "Jan" is `assignee` on 3 cases and on 0 other cases
- WHEN Jan navigates to "My Work"
- THEN the system MUST display exactly those 3 cases
- AND a case where Jan is NOT the assignee MUST NOT appear

#### Scenario: Card and table view
- GIVEN Jan is viewing My Work
- THEN the list MUST default to card view and offer a card/table toggle
- AND the table view MUST show the columns: identifier, title, case type,
  status, deadline

### Requirement: Card Display [MVP]

Each case card MUST present the case in human-readable form, implemented in
`src/views/MyWorkCaseCard.vue`.

@e2e exclude Requires an assigned case with a case type + status; card field
rendering is data-dependent.

#### Scenario: Card fields
- GIVEN an assigned case with a caseType and a status
- THEN the card MUST display:
  - The case title
  - A truncated description (when present)
  - The identifier (e.g. "ZAAK-2026-0118")
  - The **case-type name** (not its raw UUID) resolved from the caseType map
  - The **status name** (not its raw UUID) resolved from the statusType map
  - The deadline date when set
- AND a case whose deadline is in the past MUST show the deadline in an error
  colour (overdue), not relying on colour alone (the "Deadline:" label remains)

#### Scenario: Case-type / status name resolution
- GIVEN card view does not apply column formatters
- WHEN My Work renders its cards
- THEN the parent index MUST load the `caseType` and `statusType` collections
  once and pass UUID→name maps to each card so names render, never raw UUIDs

### Requirement: Item Navigation [MVP]

Opening a case from My Work MUST navigate to that case's detail view.

@e2e exclude Requires an assigned case to click; data-dependent navigation.

#### Scenario: Open a case
- GIVEN case ZAAK-2026-0118 appears in My Work
- WHEN the user clicks the card (or the table row)
- THEN the system MUST navigate to the `CaseDetail` route for that case id

### Requirement: Empty State [MVP]

When the current user has no assigned cases, My Work MUST show the standard
index empty state (provided by `CnIndexPage`) rather than an error or a blank
page.

#### Scenario: No assigned cases
- GIVEN the current user is the assignee on no cases
- WHEN they navigate to "My Work"
- THEN the system MUST display the index empty state and MUST NOT error

### Requirement: Personal-Workload Dashboard Widgets [MVP]

Independently of the My Work index, the system MUST provide Nextcloud dashboard
widgets that summarise the user's workload at a glance.

@e2e exclude NC dashboard widget IWidget PHP classes + Vue bundle loading;
covered by PHPUnit + smoke tests, not Playwright browser assertions.

#### Scenario: My Tasks / Overdue widgets
- GIVEN the Nextcloud dashboard is displayed
- THEN the Dossiq "My Tasks" widget (`lib/Dashboard/MyTasksWidget.php`) MUST
  summarise the user's assigned tasks
- AND the "Overdue Cases" widget (`lib/Dashboard/OverdueCasesWidget.php`) MUST
  summarise overdue cases with a red indicator
- AND clicking a widget MUST navigate into the app

#### Scenario: Dashboard preview panel
- GIVEN the user opens the Dossiq app dashboard (home view)
- THEN `src/views/dashboard/MyWorkPreview.vue` MUST show a summary of the
  user's assigned work

## Non-Functional Requirements

- **Performance**: My Work reuses the index self-fetch; it MUST page/limit like
  the standard case index rather than loading unbounded results.
- **Accessibility**: Cards MUST be keyboard-operable (focusable, Enter/Space to
  open) and overdue state MUST NOT rely on colour alone (the "Deadline:" text
  label is always present). Content MUST meet WCAG AA.
- **Localization**: All labels MUST support English + Dutch via `t()`.
- **Responsiveness**: The card grid MUST adapt to narrow viewports.

---

### Current Implementation Status

**Implemented (MVP).**

- **My Work index**: `src/views/MyWorkCards.vue` — a `CnIndexPage` card list
  (card default + table toggle) over `dossiq`/`case`, base filter
  `{ assignee: <uid from @nextcloud/auth> }`, wired as the `MyWork`
  `type: "custom"` manifest page (`component: MyWorkView`).
- **Card**: `src/views/MyWorkCaseCard.vue` — title, description, identifier,
  case-type + status names (resolved via parent-supplied UUID→name maps because
  card view does not apply column formatters), deadline with overdue
  highlighting; an urgency chip (see below); click emits `open` → `CaseDetail`.
- **Sort toggle + urgency chip** (see capability `werkvoorraad-intelligent-queue`):
  a server-computed urgency score (deadline incl. termijn extensions/pauses,
  priority, case age) drives an Urgency/Newest sort toggle and a per-card
  urgency chip, sourced from `GET /api/work-queue`. This is deliberately
  narrower than the retired board below — the list itself stays a plain
  `CnIndexPage`; only the ordering signal and the chip are new.
- **Dashboard widgets** (unchanged, still present): `lib/Dashboard/MyTasksWidget.php`,
  `lib/Dashboard/OverdueCasesWidget.php`, `lib/Dashboard/CasesOverviewWidget.php`
  + `src/views/dashboard/MyWorkPreview.vue`.

**Deliberately dropped (was the old werkvoorraad board):**
- Task aggregation, All/Cases/Tasks filter tabs, client-side urgency grouping
  (Overdue/Due-this-week/Upcoming/No-deadline), the show-completed toggle, and
  cross-app (Pipelinq) workload. The `Werkvoorraad` work-queue page was also
  retired (the Workflow Board covers the in-progress view). Sorting-by-priority
  was later reintroduced in server-computed form (see above) — the ad-hoc
  client-side board it originally shipped in was not.

**Not implemented:**
- `@me` support in the nc-vue index base filter (would let My Work be a pure
  manifest `type: "index"` page instead of a wrapper).

### Standards & References

- **ZGW APIs (VNG Realisatie)**: Cases correspond to `Zaak`; `assignee` is the
  handler (behandelaar).
- **WCAG 2.1 AA**: Overdue indicators use text + colour, not colour alone.
- **NL Design System**: CSS variables for colours/spacing supporting theming.
