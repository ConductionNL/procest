---
status: done
retrofit_extensions:
  - REQ-001
---

## Purpose

@e2e exclude Workflow template is a JSON data file imported via backend; no dedicated Playwright UI test surface.

## ADDED Requirements

### Requirement: Omgevingsvergunning workflow template

The system SHALL provide a pre-built workflow template for Omgevingsvergunning Bouwactiviteit (reguliere procedure) that can be imported via the workflow engine's import functionality. The template SHALL define the complete permit lifecycle from intake through decision and publication.

**Feature tier**: V1
**ZGW mapping**: Zaaktype "Omgevingsvergunning", StatusType per workflow step
**CMMN**: CasePlanModel with HumanTask stages and Milestone events

#### Scenario: Import Omgevingsvergunning regulier workflow

- **WHEN** the beheerder imports the "Omgevingsvergunning Bouwactiviteit (regulier)" workflow template via the workflow tab on the case type admin
- **THEN** the system SHALL create a workflowTemplate with the following steps in order:
  1. Ontvangen (initial) - auto-assigned to intake team
  2. Ontvankelijkheidstoets - checklist guard: required documents uploaded
  3. In behandeling - role guard: behandelaar assigned
  4. Advies - optional parallel step for requesting internal/external advice
  5. Inhoudelijke toets - checklist guard: all advice received
  6. Besluitvorming - role guard: mandated decision maker
  7. Bekendmaking - action: generate publication text
  8. Afgehandeld (final)
- **THEN** transitions SHALL include guards: checklist completion, required field validation, role-based access
- **THEN** the template SHALL set processingDeadline to P56D (8 weeks) with extension allowed (P42D / 6 weeks)

#### Scenario: Import Omgevingsvergunning uitgebreid workflow

- **WHEN** the beheerder imports the "Omgevingsvergunning Bouwactiviteit (uitgebreid)" workflow template
- **THEN** the system SHALL create a workflowTemplate with additional steps for zienswijze and ontwerp-besluit phases
- **THEN** the template SHALL set processingDeadline to P182D (26 weeks)
- **THEN** the Ontwerp-besluit step SHALL include a guard requiring a zienswijzetermijn of 6 weeks before proceeding to definitief besluit

### Requirement: Toezichtzaak workflow template

The system SHALL provide a pre-built workflow template for Toezichtzaak Bouw that models the multi-phase inspection lifecycle (fundering, ruwbouw, oplevering).

**Feature tier**: V1
**ZGW mapping**: Zaaktype "Toezichtzaak Bouw", StatusType per inspection phase
**CMMN**: CasePlanModel with repeatable HumanTask (inspection) and Milestone (phase completion)

#### Scenario: Import Toezichtzaak Bouw workflow

- **WHEN** the beheerder imports the "Toezichtzaak Bouw" workflow template
- **THEN** the system SHALL create a workflowTemplate with the following steps:
  1. Gepland (initial) - case created, inspection schedule set
  2. Inspectie fase 1 - Fundering - checklist guard: fundering checklist completed
  3. Inspectie fase 2 - Ruwbouw - checklist guard: ruwbouw checklist completed
  4. Inspectie fase 3 - Oplevering - checklist guard: oplevering checklist completed
  5. Rapport - action: generate final inspection report
  6. Opvolging - conditional: only if non-conformities found
  7. Afgehandeld (final)
- **THEN** each inspection step SHALL have a transition back to itself (re-inspection) if result is "niet-conform"
- **THEN** the Opvolging step SHALL only be reachable when at least one inspectieRapport has result "niet_conform"

#### Scenario: Skip inspection phases

- **WHEN** an inspection case only requires fundering and oplevering phases (no ruwbouw)
- **THEN** the beheerder SHALL be able to disable individual inspection steps in the workflow without removing them from the template
- **THEN** disabled steps SHALL be skipped during workflow execution

### Requirement: Handhavingszaak workflow template

The system SHALL provide pre-built workflow templates for Handhavingszaak covering the enforcement lifecycle from constatering through hercontrole, following the Landelijke Handhavingsstrategie (LHS).

`handhavingszaak` SHALL carry **two routes**, both published and active:

| Route | Template | Statutory basis | What it is |
|---|---|---|---|
| `regulier` | Handhavingstraject | Awb 5:24 and the LHS | Announce, hear the offender, decide, run the recovery period, re-inspect. The default route. |
| `spoedeisend` | Spoedig herstel (Awb 5:31) | Awb 5:31 | Act on the spot, write the decision afterwards. |

Neither route SHALL deprecate the other. `regulier` SHALL be the case type's default route, because Awb 5:31 is the exception in law: acting first is what you do when the ordinary route is too slow. A route is defined by `workflow-variants`; it is not a case type, and it is not workflow inheritance.

A catalogue entry sharing a case type with another SHALL declare a `variant`, and the variants on one case type SHALL be distinct. An entry SHALL still name every entry it shares a case type with in `_sharesItsCaseTypeWith`. A third enforcement template can therefore land, and it has to say which route it is.

**Feature tier**: V1
**ZGW mapping**: Zaaktype "Handhavingszaak", StatusType per enforcement phase
**CMMN**: CasePlanModel with EventListener (begunstigingstermijn) and HumanTask (hercontrole)

#### Scenario: Import Handhavingszaak workflow

- **WHEN** the beheerder imports the "Handhavingszaak" workflow template
- **THEN** the system SHALL create a workflowTemplate with the following steps:
  1. Constatering (initial) - action: link to source inspection rapport
  2. Vooraankondiging - action: generate vooraankondigingsbrief, set zienswijzetermijn
  3. Zienswijze - guard: zienswijzetermijn expired or zienswijze received
  4. Handhavingsbesluit - role guard: mandated beslisser, checklist guard: LHS matrix classification completed
  5. Begunstigingstermijn - timer guard: begunstigingstermijn days elapsed
  6. Hercontrole - checklist guard: hercontrole inspection completed
  7. Afgehandeld (final) - conditional: overtreding resolved OR dwangsom verbeurd
- **THEN** the Begunstigingstermijn step SHALL automatically create a follow-up task when the timer expires
- **THEN** transitions from Hercontrole SHALL branch: "Overtreding opgeheven" -> Afgehandeld, "Overtreding voortdurend" -> next enforcement cycle

#### Scenario: Enforcement escalation path

- **WHEN** the hercontrole shows the overtreding persists after last onder dwangsom
- **THEN** the workflow SHALL support escalation transitions: last onder dwangsom -> verbeuring -> bestuursdwang
- **THEN** each escalation step SHALL require a new handhavingsactie record with updated ernst/gedrag classification

#### Scenario: Both enforcement routes land active on a fresh install

- **WHEN** the VTH workflow template seed runs on an instance carrying the `handhavingszaak` case type
- **THEN** both `handhavingstraject` and `spoedig-herstel` SHALL be published and active
- **AND** neither SHALL be deprecated
- **AND** the case type's default route SHALL be `handhavingstraject`

#### Scenario: Re-running the seed changes nothing

- **GIVEN** both enforcement routes are published and active
- **WHEN** the seed runs again
- **THEN** both SHALL be reported as already present
- **AND** neither SHALL be deprecated, republished or duplicated

#### Scenario: A catalogue entry landing on an occupied case type declares its route

- **GIVEN** two catalogue entries naming the same case type
- **WHEN** the shipped catalogue is read
- **THEN** each entry SHALL declare a `variant`
- **AND** the two variants SHALL differ
- **AND** each entry SHALL name the other in `_sharesItsCaseTypeWith`

#### Notes: how the two enforcement routes stopped deprecating each other

The catalogue has always shipped two templates against `handhavingszaak`:
`handhavingstraject`, the ordinary enforcement route, and `spoedig-herstel`, the
Awb 5:31 route where the authority acts first and issues the decision
afterwards. Both are real, a municipality runs both, and which one a case
follows is decided at constatering rather than at configuration time.

Until 2026-09-05 the model could not say that. One published definition per case
type meant whichever the seeder reached last deprecated the other, silently.
Two ways out were rejected: minting a sixth VTH case type changes what a
municipality registers as a zaaktype, and leaving the deprecation ships an
enforcement route that is dark on every install.

The rule is now one published definition per `(case type, route)`, and these two
entries declare different routes. `workflowTemplate.parentWorkflow` is still not
that mechanism: it is Enterprise tier, unimplemented, and describes a hierarchy
BETWEEN case types. See `openspec/specs/workflow-variants/spec.md`.

### Requirement: VTH workflow template library

The system SHALL provide a browsable library of VTH workflow templates that administrators can preview and import into their case types.

**Feature tier**: V1

#### Scenario: Browse VTH template library

- **WHEN** the beheerder navigates to the workflow tab on a case type admin page
- **THEN** the system SHALL display an "Importeer VTH sjabloon" button
- **THEN** clicking it SHALL show a list of available VTH templates: Omgevingsvergunning (regulier), Omgevingsvergunning (uitgebreid), Toezichtzaak Bouw, Toezichtzaak Milieu, Handhavingstraject, Spoedig herstel (Awb 5:31), Sloopmelding
- **THEN** the two enforcement templates SHALL be listed as two routes through Handhavingszaak, not as one entry
- **THEN** each template SHALL show: name, description, number of steps, estimated processing time

#### Scenario: Preview template before import

- **WHEN** the beheerder clicks on a template in the library
- **THEN** the system SHALL display a read-only preview of the workflow diagram (using the visual workflow editor in view-only mode)
- **THEN** the preview SHALL show all steps, transitions, guards, and actions
- **THEN** the beheerder SHALL be able to confirm import or go back to the library

#### Scenario: Customize imported template

- **WHEN** the beheerder imports a VTH template
- **THEN** the imported workflow SHALL be editable via the standard visual workflow editor
- **THEN** the beheerder SHALL be able to add, remove, or modify steps and transitions
- **THEN** the original template SHALL remain unchanged in the library for future imports

---

### REQ-001: SeedVthWorkflowTemplates repair step SHALL idempotently seed the VTH workflow catalog from bundled JSON files

`OCA\Dossiq\Repair\SeedVthWorkflowTemplates` SHALL implement `IRepairStep` and SHALL run on every app enable / upgrade. The `run(IOutput $output)` method SHALL:
- Short-circuit with a warning when `SettingsService::isOpenRegisterAvailable()` returns false — never throw.
- Short-circuit with a warning when the bundled catalog directory (`SeedVthWorkflowTemplates::CATALOG_DIR`) does not exist — never throw.
- Glob the catalog dir for `*.json` files. If no files match, emit a warning and return.
- Iterate every catalog file, delegating to `processCatalogFile()` which returns one of `seeded` / `skipped` / `crossLink` / `failed`. Per-file Throwables SHALL be caught, logged with file basename + exception message, surfaced via `$output->warning()`, and counted in the `failed` bucket — they SHALL NOT propagate to the repair runner.
- After every file is processed, emit a single summary `$output->info()` line with all four counters.

The step SHALL be IDEMPOTENT: `isAlreadySeeded(string $caseTypeId, string $title)` SHALL check for an existing workflow row by (caseType + title) before inserting, and SHALL increment the `skipped` counter rather than re-create. Deterministic IDs SHALL be generated via `deterministicId(string $template, string $child)` so re-runs produce identical UUIDs and downstream references stay stable.

The step SHALL additionally:
- pass each catalogue entry's `variant` to the definition it creates;
- set the case type's default route from the entry declaring `isDefaultVariant`, rather than letting file order decide it;
- report the route each entry landed on;
- report a publish as having displaced something only when it displaced a previous version OF THE SAME ROUTE;
- name a catalogue entry it finds `deprecated`, say how to bring it back, and NOT republish it. A row reads `deprecated` whether the old one-per-case-type rule retired it or an administrator did, and the stored data cannot tell those apart.

#### Scenario: OpenRegister missing -> graceful no-op
- **GIVEN** the `openregister` app is not installed
- **WHEN** `SeedVthWorkflowTemplates::run()` executes during `occ app:enable dossiq`
- **THEN** the step SHALL emit `$output->warning('OpenRegister is not available. Skipping VTH workflow templates seed.')`
- **AND** SHALL return without globbing the catalog directory

#### Scenario: Catalog directory missing -> graceful no-op
- **GIVEN** OpenRegister is installed but `CATALOG_DIR` does not exist (deleted by a misconfigured build)
- **WHEN** the repair step runs
- **THEN** the step SHALL emit a warning with the missing path
- **AND** SHALL return without touching OpenRegister

#### Scenario: Re-running the seeder is idempotent
- **GIVEN** the seeder has already run and 4 workflow templates exist
- **WHEN** the seeder runs again on app upgrade
- **THEN** the summary line SHALL report `4 skipped` and `0 seeded`
- **AND** no duplicate rows SHALL be inserted

#### Scenario: The summary names the route
- **WHEN** the seed publishes a catalogue entry that declares a variant
- **THEN** the summary line for that entry SHALL name the route it landed on

#### Scenario: A publish that displaces nothing says nothing about deprecation
- **GIVEN** a case type whose only active definition is on another route
- **WHEN** a new route is seeded and published for it
- **THEN** the summary line SHALL NOT report a deprecation

#### Scenario: A deprecated entry is reported, not resurrected
- **GIVEN** an instance where a catalogue entry sits at `deprecated` from an earlier install
- **WHEN** the seed runs
- **THEN** the entry SHALL still be deprecated afterwards
- **AND** the summary SHALL name it, its route, and how an administrator brings it back

#### Scenario: One bad catalog file does not block the rest
- **GIVEN** 4 catalog files exist, one of which contains invalid JSON
- **WHEN** the seeder runs
- **THEN** the bad file's exception SHALL be logged with `app=dossiq` + the file basename + the exception message
- **AND** the user-facing summary SHALL report `1 failed`
- **AND** the other 3 catalog files SHALL still be processed

#### Notes
- The 9 private helpers (`processCatalogFile`, `resolveCaseTypeId`, `isAlreadySeeded`, `buildStatusMap`, `resolveSteps`, `resolveTransitions`, `deterministicId`, `extractFirstId`, `normalizeRow`) are not separately observable — they support the single `run()` contract above. Splitting them into separate REQs would inflate the spec without adding testable surface.
- `crossLink` is reserved for templates that reference an unresolved caseType; the seeder logs the reference and counts it but does not block the run.
## Requirements
### Requirement: VTH workflow template activation service

The system SHALL provide a `VTHWorkflowService` that loads and activates the three VTH workflow templates declared by the config-foundation member, creating each template's statuses and roles, and SHALL be idempotent on re-activation.

**Spec ref**: REQ-VTH-001, REQ-VTH-002, REQ-VTH-003

#### Scenario: Activate Omgevingsvergunning template

- **WHEN** an administrator activates the Omgevingsvergunning workflow template
- **THEN** the service SHALL create the template's statuses (Aanvraag ontvangen … Afgehandeld) and roles (Vergunningverlener, Juridisch adviseur, Administratief medewerker)

#### Scenario: Activate Toezichtzaak and Handhavingszaak templates

- **WHEN** an administrator activates the Toezichtzaak or Handhavingszaak template
- **THEN** the service SHALL create the corresponding statuses and roles defined in the respective template JSON

#### Scenario: Idempotent re-activation

- **WHEN** a template that has already been activated is activated again
- **THEN** the service SHALL NOT create duplicate statuses or roles

