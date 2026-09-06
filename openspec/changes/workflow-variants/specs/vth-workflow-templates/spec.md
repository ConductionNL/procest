# VTH workflow templates, two enforcement routes delta

**Spec refs**: `vth-workflow-templates` (Handhavingszaak requirement, REQ-001), `workflow-variants`.

## MODIFIED Requirements

### Requirement: Handhavingszaak workflow template

The system SHALL provide pre-built workflow templates for Handhavingszaak covering the enforcement lifecycle from constatering through hercontrole, following the Landelijke Handhavingsstrategie (LHS).

`handhavingszaak` SHALL carry **two routes**, both published and active:

| Route | Template | Statutory basis | What it is |
|---|---|---|---|
| `regulier` | Handhavingstraject | Awb 5:24 and the LHS | Announce, hear the offender, decide, run the recovery period, re-inspect. The default route. |
| `spoedeisend` | Spoedig herstel (Awb 5:31) | Awb 5:31 | Act on the spot, write the decision afterwards. |

Neither route SHALL deprecate the other. `regulier` SHALL be the case type's default route, because Awb 5:31 is the exception in law: acting first is what you do when the ordinary route is too slow.

A catalogue entry sharing a case type with another SHALL declare a `variant`, and the variants on one case type SHALL be distinct. An entry SHALL still name every entry it shares a case type with. A third enforcement template can therefore land, and it has to say which route it is, which is a stronger guarantee than refusing the pairing outright.

#### Scenario: Import Handhavingszaak workflow

- **WHEN** the beheerder imports the "Handhavingszaak" workflow template
- **THEN** the system SHALL create a workflowTemplate with the following steps:
  1. Constatering (initial), action: link to source inspection rapport
  2. Vooraankondiging, action: generate vooraankondigingsbrief, set zienswijzetermijn
  3. Zienswijze, guard: zienswijzetermijn expired or zienswijze received
  4. Handhavingsbesluit, role guard: mandated beslisser, checklist guard: LHS matrix classification completed
  5. Begunstigingstermijn, timer guard: begunstigingstermijn days elapsed
  6. Hercontrole, checklist guard: hercontrole inspection completed
  7. Afgehandeld (final), conditional: overtreding resolved OR dwangsom verbeurd
- **THEN** the Begunstigingstermijn step SHALL automatically create a follow-up task when the timer expires
- **THEN** transitions from Hercontrole SHALL branch: "Overtreding opgeheven" to Afgehandeld, "Overtreding voortdurend" to the next enforcement cycle

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

#### Scenario: Enforcement escalation path

- **WHEN** the hercontrole shows the overtreding persists after last onder dwangsom
- **THEN** the workflow SHALL support escalation transitions: last onder dwangsom, verbeuring, bestuursdwang
- **THEN** each escalation step SHALL require a new handhavingsactie record with updated ernst/gedrag classification

### REQ-001: SeedVthWorkflowTemplates repair step SHALL idempotently seed the VTH workflow catalog from bundled JSON files

`OCA\Dossiq\Repair\SeedVthWorkflowTemplates` SHALL implement `IRepairStep` and SHALL run on every app enable / upgrade, with the behaviour the existing requirement describes: graceful no-ops when OpenRegister or the catalog directory is missing, per-file error containment, a per-entry summary, and idempotency keyed on case type plus title.

It SHALL additionally:
- pass each catalogue entry's `variant` to the definition it creates;
- set the case type's default route from the entry declaring `isDefaultVariant`, rather than letting file order decide it;
- report the route each entry landed on;
- report a publish as having displaced something only when it displaced a previous version **of the same route**;
- name a catalogue entry it finds `deprecated` and say how to bring it back, without republishing it.

An entry found deprecated SHALL NOT be republished by the seeder. A row is deprecated whether the old rule retired it or an administrator did, and the stored data cannot tell those apart.

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
