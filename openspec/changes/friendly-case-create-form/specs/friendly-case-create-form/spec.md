# friendly-case-create-form Specification

**Status:** proposed
**Scope:** dossiq

## Purpose

The New case dialog asks a case handler for the fields a case handler fills. The case schema states which of its properties a person deals with and which are engine plumbing, the New case action narrows to what somebody filing a case types, and a chosen case type contributes its own questions, whose answers are stored as `caseProperty` rows.

## ADDED Requirements

### Requirement: REQ-FCF-001 The New Case Dialog Is The Plain Form

The `new-case` header action SHALL open the plain schema-driven form, never the properties-and-JSON table. The action SHALL declare `includeFields` naming exactly the fields collected at create time: `caseType`, `title`, `description`, `assignee`, `priority`, `confidentiality`, `intakeChannel`, `startDate`, `plannedEndDate`. The case detail page remains the surface for every other property.

#### Scenario: The dialog carries no schema-inspection tabs

- **GIVEN** a case handler is on the dossiq dashboard
- **WHEN** they press "New case"
- **THEN** the dialog SHALL show neither a "Properties" tab nor a "Data" tab
- **AND** it SHALL offer a "Create" button, disabled until the required fields are answered

#### Scenario: The dialog asks only for create-time fields

- **GIVEN** the New case dialog is open
- **THEN** it SHALL render a field for each of the nine declared `includeFields`
- **AND** it SHALL render no field for `result`, `workflowTemplate`, `archiveNomination`, `qualityScore`, `casePlanState`, `statusHistory` or `portalSubject`

### Requirement: REQ-FCF-002 The Schema States Which Properties A Person Deals With

The `case` schema SHALL carry an `order` on every property a handler reads or edits, and `visible: false` on every property written by an engine, computed by OpenRegister, or carried as a flow signal payload.

A property that carries an `order`, or that a manifest `include`/`columns` list names, SHALL NOT be `visible: false`. `fieldsFromSchema` evaluates visibility before the include whitelist, so a hidden property named by a widget renders a blank cell and reports nothing.

#### Scenario: A displayed property is never hidden

- **GIVEN** the case detail Process widget lists `workflowVersion`, `extensionCount` and `handoffSource` in its `include`
- **THEN** none of those properties SHALL be `visible: false`
- **AND** the case `description`, carrying `order: 3`, SHALL render on the create form, the detail data widget and the table

### Requirement: REQ-FCF-003 A Case Type Brings Its Own Questions

`case.caseType` SHALL declare `x-openregister-extends-form`, naming `propertyDefinition` as the definitions schema filtered by the chosen case type, and `caseProperty` as the values schema keyed `case` / `propertyDefinition` / `value`.

When a case type is chosen, the form SHALL render one field per property definition of that type, each with the widget its declared `propertyType` implies and its `defaultValue` seeded. A definition name that is identifier-shaped SHALL be rendered in sentence case, keeping acronyms whole; a name containing a space SHALL be shown exactly as it was typed. Changing the case type SHALL drop the previous type's answers. Answers SHALL be written as `caseProperty` rows AFTER the case exists, never as properties of the case itself.

#### Scenario: Choosing a case type adds its questions

- **GIVEN** the case type "Cultuursubsidie 2026" has property definitions `plafond` (number, default 800000) and `interimReportFrequency` (enum)
- **WHEN** a handler chooses that case type in the New case dialog
- **THEN** the form SHALL gain a number field labelled "Plafond" holding 800000
- **AND** a dropdown labelled "Interim report frequency" offering that definition's enum values

#### Scenario: Answers are stored against the case, not in it

- **GIVEN** a handler has answered a case type question and pressed Create
- **THEN** the case SHALL be created without any dynamic key among its own properties
- **AND** one `caseProperty` row SHALL exist referencing the created case, the property definition, and the answer

### Requirement: REQ-FCF-004 The Properties Tab Writes What The Schema Declares

The case-type properties tab SHALL write only fields the `propertyDefinition` schema declares. It SHALL write the data type as `propertyType`, using that schema's own enum, and SHALL offer a Required toggle writing `isRequired`. `maxLength` and `requiredAtStatus` SHALL be declared on the schema; `requiredAtStatus` SHALL store a status reference and be resolved back to a name for display.

#### Scenario: A chosen type survives a save

- **GIVEN** a functional admin adds a property definition and chooses the type "Date"
- **WHEN** they save it and the list reloads
- **THEN** the definition SHALL read back as "Date", not as the default

### Requirement: REQ-FCF-005 the form answers what the case type already knows

`case.caseType` SHALL declare `x-openregister-prefill`, mapping the case's `title`, `status` and `assignee` to the chosen case type's `title`, `initialStatus` and `defaultAssignee`.

Only an EMPTY target SHALL be written, so a value the person typed survives both the first choice and every later one. Prefill SHALL run in create mode only: in edit mode a blank field is a decision someone already made about an existing record. A source the chosen record leaves empty SHALL write nothing rather than blanking the target.

`status` SHALL be prefilled without being offered as a field, because a handler filing a case does not choose the status it starts in.

#### Scenario: The case type fills the title

- **GIVEN** a handler has opened the New case dialog and typed nothing
- **WHEN** they choose a case type called "Subsidieaanvraag"
- **THEN** the title field SHALL read "Subsidieaanvraag"

#### Scenario: A typed title survives

- **GIVEN** a handler has typed a title of their own
- **WHEN** they choose a case type
- **THEN** the title SHALL still read what they typed
- **AND** the fields they left empty SHALL still take the case type's answers

#### Scenario: The starting status is stored but never asked for

- **GIVEN** a case type whose `initialStatus` is "Ontvangen"
- **WHEN** a handler files a case of that type
- **THEN** the New case dialog SHALL NOT show a status field
- **AND** the created case SHALL hold that status

### Requirement: REQ-FCF-006 the dialog reads as a form, not a schema

The New case action SHALL open a wide dialog laying its fields out in two columns. A multi-line widget (textarea, JSON or code) SHALL span both columns, and the layout SHALL collapse to a single column below 700px.

Two columns SHALL be opt-in per action, so that no other form in the fleet is reflowed.

#### Scenario: The create form uses two columns

- **GIVEN** a handler opens the New case dialog
- **THEN** the single-line fields SHALL occupy two distinct columns
- **AND** the description textarea SHALL span the full width

### Requirement: REQ-FCF-007 a field kept off the create form stays reachable on the case

A property the New case action does not ask for SHALL remain editable on the case detail page unless it is platform plumbing.

`parentCase` SHALL NOT appear on the New case form, because a case is not filed as somebody's sub-case, and SHALL appear on the case detail page, because a case becomes one later. `decisions` SHALL appear on neither: they are decidiq objects reached through the Besluitvorming widget, and a raw reference list is not an edit surface for them.

#### Scenario: Parent case is an edit-time field

- **GIVEN** a handler opens the New case dialog
- **THEN** there SHALL be no parent case field
- **WHEN** they open an existing case
- **THEN** the core case data SHALL offer a parent case field
