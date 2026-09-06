---
status: done
status-note: Reverse-synced 2026-06-13 from an archived fully-implemented change; capability code confirmed present on development.
---
# besluitvorming-workflow Specification

## Purpose
Provides end-to-end decision-making workflows for municipal College-besluit, Raadsbesluit, and Mandaatbesluit cases, shipping pre-configured case-type and workflow templates that run from drafting through parafering, agenda compilation, decision recording, publication, and archival. Drives a sequential signing (parafering) chain, auto-transitions cases to agenda-ready status once all signatures are collected, records formal decisions with voting results and attending members, validates mandate authority, and dispatches publication payloads to DROP/LVBB before archiving the complete dossier.
## Requirements
### Requirement: REQ-BVW-001 Zaaktype templates MUST be pre-configured for the three core besluitvorming types

The system SHALL ship pre-configured `caseType` + `workflowTemplate` bundles for College-besluit, Raadsbesluit, and Mandaatbesluit, activated via the repair step. Each bundle MUST include `statusType`, `propertyDefinition`, `roleType`, `documentType`, and `resultType` records.

#### Scenario REQ-BVW-001-A: Activate College-besluit template

- **GIVEN** the workflow engine is installed and the besluitvorming templates are present
- **WHEN** an administrator activates the "College-besluit" template via `POST /api/besluitvorming/templates/college-besluit/activate`
- **THEN** a `caseType` object MUST be created with `title = 'College-besluit'` and `publicationRequired = true`
- **AND** a `workflowTemplate` MUST be created with the nine standard process steps in order (Voorstel opstellen, Ambtelijk advies, Parafering, Gereed voor agendering, Geagendeerd, Vergadering, Besluit genomen, Bekendmaking, Gearchiveerd)
- **AND** `statusType` records MUST be created for each step with the correct `order` and `isFinal` values
- **AND** `propertyDefinition` records MUST be created for: `stemuitslag`, `portefeuillehouder`, `vergadergremium`, `agendanummer`, `publicatieReferentie`
- **AND** `roleType` records MUST be created for: Steller, Portefeuillehouder, Beleidsadviseur, Afdelingshoofd
- **AND** the activation MUST be idempotent (re-running does not create duplicate records)

#### Scenario REQ-BVW-001-B: Raadsbesluit template includes griffier role and extended deadline

- **GIVEN** the besluitvorming templates are installed
- **WHEN** an administrator activates the "Raadsbesluit" template
- **THEN** the resulting `caseType` MUST have `processingDeadline = 'P60D'`
- **AND** a `roleType` for "Griffier" MUST exist on this `caseType`

#### Scenario REQ-BVW-001-C: Mandaatbesluit template has confidentiality set to intern

- **GIVEN** the besluitvorming templates are installed
- **WHEN** an administrator activates the "Mandaatbesluit" template
- **THEN** the resulting `caseType` MUST have `confidentiality = 'intern'` and `publicationRequired = false`
- **AND** the workflow MUST include a mandate-authority guard on the "Besluit genomen" transition

---

### Requirement: REQ-BVW-004 Agenda compiler MUST support hamerstukken and bespreekstukken with configurable ordering

The system SHALL allow an agenda manager to compile multiple ready-for-agendering cases into a meeting agenda, classify each item as `hamerstuk` or `bespreekstuk`, and reorder items.

#### Scenario REQ-BVW-004-A: Compile cases into a vergadering agenda

- **GIVEN** 4 College-besluit cases with status "Gereed voor agendering"
- **WHEN** the agenda manager opens the `AgendaCompilerView` and selects a meeting date
- **THEN** the 4 cases MUST be listed as available agenda items
- **AND** the manager MUST be able to drag cases into the agenda and set their order
- **AND** each item MUST be classifiable as `hamerstuk` or `bespreekstuk` via a toggle
- **AND** the classification and order MUST be stored as `caseProperty` values (`agendanummer`, `behandeling`)

#### Scenario REQ-BVW-004-B: Cases transition to "Geagendeerd" when added to agenda

- **GIVEN** the agenda manager adds case "Vaststelling Beleidsplan Duurzaamheid" to the College vergadering of 2026-06-10
- **WHEN** the manager confirms the agenda
- **THEN** the case MUST transition to status "Geagendeerd"
- **AND** `caseProperty.agendanummer` MUST be set (e.g. `'5.2'`)
- **AND** the steller and portefeuillehouder of the case MUST receive a notification

#### Scenario REQ-BVW-004-C: Generate agenda document via Docudesk

- **GIVEN** a confirmed agenda with 6 items (3 hamerstukken, 3 bespreekstukken) for the College vergadering of 2026-06-10
- **WHEN** the agenda manager clicks "Agenda genereren"
- **THEN** a `document` MUST be created via Docudesk with the agenda in PDF format
- **AND** the document MUST list hamerstukken first, followed by bespreekstukken, each with the correct `agendanummer`
- **AND** the document MUST be linked to the vergadering case via `caseDocument`

#### Scenario REQ-BVW-004-D: Multiple vergadergremia each have independent agendas

- **GIVEN** the municipality has configured College B&W and Gemeenteraad as separate vergadergremia
- **WHEN** the agenda manager compiles an agenda for the Gemeenteraad
- **THEN** only cases with `caseType.title = 'Raadsbesluit'` MUST appear in the available items list
- **AND** College-besluit cases MUST NOT appear in the Raadsbesluit agenda compiler

---

### Requirement: REQ-BVW-005 Decision MUST be recorded with structured metadata including stemuitslag and attending members

When a vergadering concludes, the system SHALL require the recording of the formal `decision` object including stemuitslag, governingBody, and attending members before allowing the case to advance.

#### Scenario REQ-BVW-005-A: Record decision with stemuitslag after vergadering

- **GIVEN** a College-besluit case in status "Vergadering" for the College meeting of 2026-06-10
- **WHEN** the griffier or secretaris opens the `VergaderingDetailView` and records the outcome
- **THEN** a `decision` object MUST be created with:
  - `case`: reference to this case
  - `decisionDate`: the date of the meeting
  - `governingBody`: the configured vergadergremium (e.g. "College van Burgemeester en Wethouders")
  - `decisionType`: reference to the applicable `decisionType` (goedgekeurd/verworpen/aangehouden)
  - `explanation`: the decision text
- **AND** `caseProperty.stemuitslag` MUST be set (e.g. "Unaniem", "5 voor / 2 tegen")
- **AND** the attending members MUST be recorded as `role` objects with roleType "Aanwezig lid"
- **AND** the case MUST transition to "Besluit genomen" only after the `decision` object is saved

#### Scenario REQ-BVW-005-B: Raadsbesluit records voting result with voor/tegen counts

- **GIVEN** a Raadsbesluit case in status "Vergadering" with 31 raadsleden present
- **WHEN** the griffier records the stemming with 23 voor and 8 tegen
- **THEN** `caseProperty.stemuitslag` MUST store `'23 voor / 8 tegen'`
- **AND** the `decision.explanation` MUST include the stemuitslag text
- **AND** the case MUST transition to "Besluit genomen"

#### Scenario REQ-BVW-005-C: Aangehouden besluit does not proceed to Bekendmaking

- **GIVEN** a College-besluit case in status "Vergadering"
- **WHEN** the decision is recorded with `decisionType` set to "Aangehouden" (decision deferred)
- **THEN** the case MUST NOT transition to "Bekendmaking"
- **AND** the case status MUST change to a terminal-like "Aangehouden" sub-status or cycle back to "Gereed voor agendering" for a future meeting
- **AND** a notification MUST be sent to the steller and portefeuillehouder indicating the deferral

---

### Requirement: REQ-BVW-006 Publication MUST provide an integration point for DROP/LVBB with required metadata

When a besluit must be published, the system SHALL assemble the publication payload and dispatch it to the configured DROP or LVBB endpoint, then store the publication reference on the case.

#### Scenario REQ-BVW-006-A: Trigger DROP publication on Bekendmaking transition

- **GIVEN** a College-besluit case in status "Besluit genomen" with a signed `decision` object and an attached besluitdocument
- **WHEN** the handler advances the case to "Bekendmaking"
- **THEN** `PublicationService.dispatch()` MUST be triggered automatically by the workflow engine's auto-action
- **AND** the service MUST assemble a publication payload containing:
  - `title`: from `decision.title`
  - `decisionDate`: from `decision.decisionDate`
  - `effectiveDate`: from `decision.effectiveDate`
  - `governingBody`: from `decision.governingBody`
  - `documentUrl`: the signed besluitdocument URL
  - `caseIdentifier`: the case `identifier`
- **AND** the payload MUST be dispatched to the configured DROP/LVBB endpoint via OpenConnector
- **AND** on success, `decision.publicationDate` MUST be set and `caseProperty.publicatieReferentie` MUST store the returned URI

#### Scenario REQ-BVW-006-B: Publication failure is surfaced without blocking the case

- **GIVEN** the DROP/LVBB endpoint is unavailable
- **WHEN** `PublicationService.dispatch()` fails with a connection error
- **THEN** the case MUST NOT be blocked in status "Bekendmaking"
- **AND** a failed publication event MUST be logged in the case `activity` trail
- **AND** the handler MUST be notified of the failure with a retry button in `BesluitPublicatiePanel.vue`
- **AND** the handler MUST be able to manually trigger a retry via `POST /api/besluitvorming/cases/{id}/publish`

#### Scenario REQ-BVW-006-C: Mandaatbesluit skips publication when publicationRequired is false

- **GIVEN** a Mandaatbesluit case with `caseType.publicationRequired = false`
- **WHEN** the case reaches "Besluit genomen"
- **THEN** the workflow MUST skip the "Bekendmaking" step automatically
- **AND** the case MUST transition directly to "Gearchiveerd" (or the applicable next step)
- **AND** no DROP/LVBB payload MUST be dispatched

---

### Requirement: REQ-BVW-007 Mandaatbesluit MUST validate signing authority against the mandaatregister

Before a Mandaatbesluit case can advance to "Besluit genomen", the system SHALL verify that the signing official has sufficient delegated authority for the subject matter of the decision.

#### Scenario REQ-BVW-007-A: Valid mandate allows transition to Besluit genomen

- **GIVEN** a Mandaatbesluit case for "vergunningverlening kleine bouwwerken" where the signing official is the Afdelingshoofd Vergunningen
- **WHEN** the workflow guard checks the mandate via `MandaatValidationService.validate()`
- **AND** the mandaatregister confirms the Afdelingshoofd has authority for category "VTH-M-04" (small permits up to EUR 250.000)
- **THEN** the transition guard MUST pass
- **AND** the case MUST advance to "Besluit genomen"

#### Scenario REQ-BVW-007-B: Insufficient mandate blocks transition with clear error

- **GIVEN** a Mandaatbesluit case for a decision exceeding the mandate limit of the signing official
- **WHEN** the workflow guard queries the mandaatregister
- **AND** the mandaatregister returns that the official's mandate does not cover the decision scope
- **THEN** the transition to "Besluit genomen" MUST be blocked
- **AND** the case handler MUST see an error message: "De ondertekenende ambtenaar heeft onvoldoende mandaat voor dit besluit. Raadpleeg het mandaatregister."
- **AND** a link to the relevant mandaatregister entry MUST be shown

#### Scenario REQ-BVW-007-C: Mandaatregister unreachable falls back to manual confirmation

- **GIVEN** the mandaatregister endpoint is configured but currently unreachable
- **WHEN** the workflow guard attempts validation
- **THEN** the guard MUST NOT silently pass
- **AND** the handler MUST be prompted to confirm manually that the signing official has sufficient authority
- **AND** the manual confirmation MUST be logged in the case audit trail

---

### Requirement: REQ-BVW-008 Archival MUST link all case documents in the dossier before closing

When a besluitvorming case is archived, the system SHALL verify that all required documents (voorstel, adviezen, parafen, besluit, bekendmaking record) are linked in the case dossier before setting the final archived status.

#### Scenario REQ-BVW-008-A: Archiving requires all mandatory documents to be present

- **GIVEN** a College-besluit case in status "Bekendmaking" with `publicationRequired = true`
- **WHEN** the handler advances the case to "Gearchiveerd"
- **THEN** the workflow guard MUST check that the following `documentType` records are satisfied:
  - Collegeadvies (the voorstel document)
  - Besluitdocument (signed)
  - Bekendmakingsbewijs (publication confirmation)
- **AND** if any required document is missing, the transition MUST be blocked with a list of missing documents
- **AND** when all documents are present, the case `archiveStatus` MUST be set to `gearchiveerd` and `archiveNomination` MUST be populated per the configured `resultType.archivalPeriod`

#### Scenario REQ-BVW-008-B: Archived case dossier is accessible via case API

- **GIVEN** a completed and archived College-besluit case "Vaststelling Beleidsplan Duurzaamheid 2027-2031"
- **WHEN** an authorized user retrieves the case via the OpenRegister API
- **THEN** the `files` collection MUST include references to:
  - The primary voorstelnotitie
  - All received adviesdocumenten (via linked `adviesAanvraag.adviesDocument`)
  - The signed besluitdocument
  - The bekendmakingsbewijs (DROP/LVBB publication confirmation)
- **AND** the case `statusHistory` MUST show all status transitions with timestamps

#### Scenario REQ-BVW-008-C: Archival period is set per resultType configuration

- **GIVEN** a College-besluit case with `resultType` "Besluit genomen" configured with `archivalPeriod = 'P20Y'` and `archivalAction = 'keep'`
- **WHEN** the case is archived
- **THEN** `case.archiveActionDate` MUST be set to today + 20 years
- **AND** `case.archiveNomination` MUST be set to `'blijvend_bewaren'`

---

