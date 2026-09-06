# Proposal: friendly-case-create-form

kind: code. The New case dialog is aimed at a case handler filing a case, not at someone inspecting the case schema. The schema says which of its properties a person deals with, the button narrows to what somebody filing a case types, and a chosen case type brings its own questions with it.

## Why

The dashboard's New case button opened `CnAdvancedFormDialog`: a Properties/Data table listing every property the `case` schema declares, unordered, with `qualityScore`, `casePlanState`, `isFinalStatus` and `archiveNomination` among the first screenful. A municipal case handler has no business reading a schema to file a case.

Two things underneath it were also wrong, and neither is cosmetic.

**Eight properties were `visible: false` while something asked to display them.** `fieldsFromSchema` evaluates visibility BEFORE the include whitelist, so a widget that names such a property renders a blank cell and reports nothing. The case **description** was on no surface at all: not the create form, not the detail data widget, not the table. The case detail **Process** widget lists ten fields and six of them could never render.

**dossiq has modelled the ZGW *eigenschap* pattern since the beginning and never rendered it.** `propertyDefinition` describes a case type's extra questions and `caseProperty` stores the answers, but no surface collected them, so a functional admin could define a question nobody could ever be asked.

## What Changes

- **The case schema states its own shape.** Fourteen properties carry an `order`; twenty engine-written ones (`casePlanState`, `statusHistory`, `activity`, flow signal payloads, computed quality scores, denormalised projections) carry `visible: false`. Because every surface reads `fieldsFromSchema`, this orders the create form, the detail data widget and generated table columns from one place.
- **Eight properties are un-hidden**, by a mechanical rule rather than taste: a property that carries an `order`, or that a manifest `include`/`columns` list names, must not be hidden.
- **The New case action narrows to nine fields** via `includeFields`. The detail page still edits the rest; one schema, two surfaces.
- **`case.caseType` declares `x-openregister-extends-form`**, so choosing a case type fetches that type's `propertyDefinition` records, renders them as ordinary form fields, and writes the answers as `caseProperty` rows once the case exists.
- **The properties tab stops writing three fields into a void.** `format` was never a `propertyDefinition` field (the schema calls it `propertyType`); `maxLength` and `requiredAtStatus` were not declared at all; `requiredAtStatus` stored a display name where a reference belongs; and a Required toggle is new, since without `isRequired` no question could ever be made mandatory.

## Capabilities

### New Capabilities
- `friendly-case-create-form`: the New case dialog asks a case handler for the fields a case handler fills, and a chosen case type contributes its own questions, whose answers are stored as `caseProperty` rows.

## Impact

- **Schema**: `lib/Settings/dossiq_register.json`, `case` ordering/visibility plus the `x-openregister-extends-form` declaration (1.10.0 → 1.11.0); `propertyDefinition` gains `maxLength` and `requiredAtStatus` (1.0.0 → 1.1.0).
- **Manifest**: `src/manifest.json`, `includeFields` on the `new-case` action.
- **Frontend**: `src/views/settings/tabs/PropertiesTab.vue`.
- **Tests**: `tests/e2e/case-create-form.spec.ts` (new).
- Depends on `@conduction/nextcloud-vue` 2.31.0, which carries the plain-form `open-form` dialog, the `x-openregister-extends-form` mechanism, and the manifest-schema keys that let an action narrow itself.
