# Tasks: friendly-case-create-form

- [x] Give fourteen handler-facing `case` properties an `order` and mark twenty engine-written ones `visible: false`
- [x] Un-hide the eight properties that carry an `order` or that a manifest `include`/`columns` list names
- [x] Declare `x-openregister-extends-form` on `case.caseType`, naming the `propertyDefinition` definitions and the `caseProperty` values schema
- [x] Narrow the `new-case` action to the nine create-time fields via `includeFields`
- [x] Declare `maxLength` and `requiredAtStatus` on `propertyDefinition`, and bump both schema versions
- [x] Make the properties tab write `propertyType` (the schema's own enum) instead of the undeclared `format`
- [x] Store `requiredAtStatus` as a status reference and resolve it back to a name in the list
- [x] Add a Required toggle writing `isRequired`, so a case type question can be mandatory
- [x] Add the eight new source strings to `en.json` and `nl.json` and rebuild the `.js` mirrors
- [x] Add `tests/e2e/case-create-form.spec.ts` covering the dialog shape, the narrowed field set, the case-type questions, and the answers being written
- [x] Bump `@conduction/nextcloud-vue` to `^2.31.0`, the release carrying the mechanism and the manifest-schema keys

## Acceptance criteria

- The New case dialog is the plain form: no Properties tab, no Data tab.
- It asks for exactly the nine create-time fields, and for none of the engine-written ones.
- Choosing a case type adds that type's property definitions as fields, each with the widget its declared type implies and its default filled in; changing the case type drops the previous type's answers.
- Filing a case writes the case AND one `caseProperty` row per answered question.
- No property that carries an `order`, or that a manifest `include`/`columns` list names, is `visible: false`.
- A type chosen in the properties tab survives a save and reads back as chosen.
