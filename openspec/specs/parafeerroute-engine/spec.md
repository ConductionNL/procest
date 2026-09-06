---
status: retired
retired_in: dossiq-adopt-or-abstractions
canonical_home: case-management/spec.md
---

> **RETIRED — route modification expressed as `requires` guards on
> lifecycle transitions.**
>
> Skip-step and ad-hoc-step semantics live in the consolidated
> `x-openregister-lifecycle` annotation on the case schema. Routes
> become a data attribute on case-types, not a separate engine. See
> ADR-022 and `case-management/spec.md`.
>
> This file is preserved as a historical appendix. Refer to
> `case-management/spec.md` for canonical route semantics.

## Purpose

@e2e exclude RETIRED spec; requirements consolidated into case-management/spec.md.

## Requirements

### Requirement: Parafeerroute Schema Registration

The system SHALL register a `parafeerroute` schema in the Dossiq OpenRegister configuration with properties: name (string), caseType (reference), voorstelType (enum: dt_advies, collegeadvies, raadsvoorstel), steps (array of parafeerstap objects). Each parafeerstap SHALL have: order (integer), type (enum: advies, parafering, accordering), actor (string, user UID or group/role name), actorType (enum: user, group, role), mandatory (boolean).

**Feature tier**: V1
**Schema.org type**: `schema:HowTo`
**CMMN concept**: PlanItemDefinition with ordered HumanTasks

#### Scenario: Schema is available after app install

- **WHEN** the Dossiq app is installed or updated
- **THEN** the `parafeerroute` schema SHALL be registered in the Dossiq register via the repair step
- **AND** the schema SHALL enforce required properties: name, steps

### Requirement: Sequential Step Routing

The system SHALL execute parafeerroute steps in sequential order. Each step SHALL complete before the next step is activated.

**Feature tier**: V1

#### Scenario: Sequential step execution

- **WHEN** a voorstel is submitted for parafering with a 5-step route
- **THEN** the system SHALL activate step 1 first
- **AND** step 2 SHALL NOT be activated until step 1 is completed
- **AND** each actor SHALL receive a Nextcloud notification when their step is activated

#### Scenario: Step completion advances to next

- **WHEN** the actor at step 3 completes their action (paraferen or adviseren)
- **THEN** the voorstel currentStep SHALL advance to 4
- **AND** the step 4 actor SHALL receive a Nextcloud notification
- **AND** the voorstel updatedAt SHALL be refreshed

### Requirement: Admin parafeerroute configuration

Approval routes SHALL be authored in the decision app. dossiq raises a voorstel's
chain there and records the outcome, and SHALL NOT offer a local authoring surface.

AMENDED 2026-09-02. dossiq#1666 moved the parafering RUNTIME to decidiq and retired
the local engine with no facade, including the dossiq-side flow projection that
dossiq#1632 had introduced. So there is no dossiq authoring surface left to keep,
and the `/settings/parafeerroutes` index page is retired with it: editing a route
object here would reach nothing that runs. `/settings/parafeerroutes/:id` stays
registered so a reader can still open a legacy route object, and so the frozen
`procest.parafering.*` audit trail that names `parafeerrouteId` keeps resolving.

**Feature tier**: V1

#### Scenario: Create a new approval route

- **WHEN** the beheerder authors the approval route in the decision app
- **THEN** the beheerder SHALL be able to create a new route with a name
- **AND** the beheerder SHALL be able to add steps with: step type (advies/parafering/accordering), actor type (user/group/role), actor selection, mandatory flag
- **AND** the beheerder SHALL be able to reorder steps on the canvas

#### Scenario: Link route to case type

- **WHEN** the beheerder creates a parafeerroute
- **THEN** the beheerder SHALL be able to link it to a case type and voorstel type
- **AND** when a steller creates a voorstel of that type on a case of that type, the linked route SHALL be loaded as default

#### Scenario: Edit existing parafeerroute

- **WHEN** the beheerder edits an existing parafeerroute that is not in active use
- **THEN** the beheerder SHALL be able to add, remove, or reorder steps
- **AND** voorstellen already using this route SHALL NOT be affected (they keep a snapshot of the route at submission time)

### Requirement: Override Route on Specific Voorstel

The system SHALL allow authorized users to modify the parafeerroute on a specific voorstel (skip steps, add ad-hoc steps) with mandatory reason.

**Feature tier**: V1

#### Scenario: Skip a step

- **WHEN** an authorized manager skips the "Adviseur vakinhoud" step on a specific voorstel
- **THEN** the step SHALL be marked as skipped
- **AND** a mandatory reason text SHALL be recorded
- **AND** the audit trail SHALL record: "Stap overgeslagen: [step name] door [manager], reden: [text]"

#### Scenario: Add ad-hoc step

- **WHEN** the steller adds an ad-hoc advisory step "Financieel adviseur" between steps 2 and 3
- **THEN** the route for this voorstel SHALL be adjusted: existing steps after insertion point SHALL be renumbered
- **AND** the audit trail SHALL record: "Stap toegevoegd: [step name] door [user]"
