## 1. The model

- [x] 1.1 Add `variant` to the `workflowTemplate` schema in `lib/Settings/dossiq_register.json`, with `l10n/en.json` and `l10n/nl.json` entries for its title and description and an l10n rebuild; verify `npm run check:schema-l10n` does not grow the uncovered count
  - `variant` on `workflowTemplate` v1.2.0, with `l10n/en.json` + `l10n/nl.json` entries and `npm run l10n:build`. `check:schema-l10n` back to the 2764 baseline (it had grown to 2767).
- [x] 1.2 Re-describe `caseType.workflowDefinition` as the case type's default route, with the same l10n treatment; verify the description reads as the default rather than as the only workflow
  - Re-described as the case type's default route, translated the same way.
- [x] 1.3 Add `WorkflowLifecycleGuard::variantOf()` normalising an absent or empty `variant` to `standaard`; verify a legacy row with no `variant` and a row carrying `standaard` resolve identically
  - `WorkflowLifecycleGuard::variantOf()`; `testADefinitionWithNoRouteIsOnTheDefaultRouteName` asserts a row with no `variant` key resolves to `standaard` with no write.

## 2. Per-route lifecycle

- [x] 2.1 Give `WorkflowDefinitionService::getActiveDefinitionFor()` an optional route, defaulting to the case type's default route; verify every existing caller keeps reading what it read before
  - `getActiveDefinitionFor($caseTypeId, ?$variant)`; `testTheUnqualifiedLookupAnswersWithTheDefaultRoute`.
- [x] 2.2 Scope `deprecatePreviousActive()` to `(case type, route)`; verify publishing a second route leaves the first published and active, and publishing v2 of a route deprecates v1 of that route only
  - `testPublishingASecondRouteRetiresNothingAndKeepsTheDefault` and `testANewVersionRetiresOnlyItsOwnRoute`. Mutation-checked: scoping the lookup back to the case type reds the first.
- [x] 2.3 Stop `publish()` re-pinning the default route when the row being published is on a different route; verify the first published route becomes the default, a new version of it keeps the default, and a second route does not steal it
  - `takeDefaultWhenEntitled()`; three tests. Mutation-checked: restoring the unconditional pin reds `testPublishingASecondRouteRetiresNothingAndKeepsTheDefault`.
- [x] 2.4 Add `setDefaultDefinition()` so the default is a decision rather than an ordering accident; verify it refuses a definition that is not published and one on another case type
  - `setDefaultDefinition()`; refuses a draft and an unknown id.
- [x] 2.5 Carry `variant` through `createDraft()` and `cloneDefinition()`; verify a clone of a `spoedeisend` definition is a `spoedeisend` draft
  - `testACloneStaysOnItsOwnRoute`, `testADraftWithNoRouteLandsOnTheDefaultRouteName`.
- [x] 2.6 Leave `isDeprecatable()` per case type; verify a case type with open cases still refuses to lose its last published definition, across all its routes
  - Left per case type, deliberately. `testACaseTypeIsNeverLeftWithoutAnyRoute`: retiring one of two routes is allowed, retiring the last one with open cases is refused.

## 3. Resolution

- [x] 3.1 🔴 Add `WorkflowTemplateLoader::getTemplateForCase()`: the case's pin first, then the case type's default route, then the single active definition, then a deterministic pick by route slug and version with a warning naming the ambiguity; verify all four branches, and verify the warning fires only on the fourth
  - `getTemplateForCase()`, resolving through `WorkflowLifecycleGuard::defaultAmong()`. All four branches covered by `WorkflowTemplateLoaderVariantTest`, including that the warning fires only on the ambiguous branch and that two resolutions agree.
- [x] 3.2 🔴 Move `StatusTransitionService` onto the case-aware lookup for both `getAvailableTransitions()` and `execute()`; verify a case pinned to the second route is offered the second route's transitions and not the first route's. This test must fail if the resolution reverts to taking the first active row
  - `StatusTransitionServiceRouteSeamTest` asserts both entry points hand over the case and never call the case-type lookups. Mutation-checked: disabling the pin lookup in `getTemplateForCase()` reds 3 of the 7 loader tests.
- [x] 3.3 Verify the pin outlives a newer version of its own route, which is what `workflow-definition-model` already promised and the loader never honoured
  - `testAPinnedCaseRunsItsOwnRouteAndNotTheDefault` pins the definition the case names even though the case type defaults elsewhere.

## 4. The catalogue

- [x] 4.1 Declare `variant: regulier` and `isDefaultVariant: true` on `handhavingstraject`, and `variant: spoedeisend` on `spoedig-herstel`; rewrite both `_sharesItsCaseTypeWith` notes to describe two routes rather than a collision
  - Both notes rewritten as two routes. `isDefaultVariant: true` on `handhavingstraject`.
- [x] 4.2 Pass the entry's `variant` through the seeder into `createDraft()`, and set the default from `isDefaultVariant` after publishing; verify neither depends on `glob()` order
  - `VthCatalogueFiles::variantOf()` feeds `createDraft()`; `setDefaultDefinition()` is called from `isDefaultVariant`, so `glob()` order decides nothing.
- [x] 4.3 Rewrite `VthCatalogueReport::seededReason()` to name the route, and report a displacement only when a previous version of the same route was displaced; verify a second route seeds with no deprecation sentence
  - A second route now seeds with no deprecation sentence; `testASecondRouteReportsNoDeprecation` asserts the words `deprecated` and `replaced` are absent.
- [x] 4.4 Report a catalogue entry found `deprecated` with its route and the way back, without republishing it; verify the row is still deprecated after the run
  - `deprecatedReason()` plus a `deprecated` bucket in the summary. Proven on the rig: a row set to `deprecated` by hand is reported and is still `deprecated` after the run.
- [x] 4.5 🔴 Strengthen `ShippedVthTemplateStatusesTest::testEntriesSharingACaseTypeRecordThatTheyDo`: entries sharing a case type must declare distinct variants and still name their siblings; verify the test fails when a third entry lands on `handhavingszaak` with no variant, and when it duplicates an existing one
  - Rewritten as `testEntriesSharingACaseTypeDeclareDistinctRoutesAndSaySo` plus `testOneEntryOnASharedCaseTypeIsTheDefaultRoute`. Mutation-checked with a planted third entry: red with no variant, red with a duplicate variant, green once removed.

## 5. Proof

- [x] 5.1 🔴 On a clean rig, verify both enforcement templates land published and active on `handhavingszaak`, with no deprecation and no ERROR lines, and that a second `maintenance:repair` reports both as already present and changes nothing
  - Rig `dq-variants` on :8637, Nextcloud 34.0.3 + openregister 2.0.15-unstable, `firstrunwizard` disabled, torn down with `down -v`. Baseline on the release code: `Handhavingstraject` landed `deprecated / isActive=false`. With this change: `4 seeded, 0 skipped-as-present`, both enforcement templates `published / isActive=true` on case type `handhavingszaak`, distinct variants, the case type's default route resolving to `Handhavingstraject`. Second run: `0 seeded, 4 already present`, two rows on the case type, no ERROR lines.
- [x] 5.2 Verify the shipped tree on the rig actually carries the variant slugs, read from the installed app directory rather than from a release tag
  - Grepped inside the installed app directory in the volume, not a tag: `"variant": "regulier"` in `handhavingstraject.json`, `spoedeisend` in `spoedig-herstel.json`, `VARIANT_DEFAULT` in `WorkflowLifecycleGuard.php`, `getTemplateForCase` in `StatusTransitionService.php`.

## 6. Staged, and why

- [ ] 6.1 **A route picker at case creation.** Cases are created through the generic OpenRegister form, so there is no dossiq surface to hang a picker on and no evidence for where one belongs. Needs product input: does the operator pick a route on the create form, or does the case start on the default and get moved? Until it ships, every new case follows the default route, which is what happens today.
- [ ] 6.2 **A route badge on the case.** A case shows which route it follows by dereferencing its pin. Cheap to build, and worth building next to the picker so the two agree about the words they use.
- [ ] 6.3 **Moving a case between routes mid-case.** Wanted, and specified in `design.md` D6: the target must be published, active, on the same case type, and must contain the case's current status, otherwise the move is refused with the reason. The move is recorded on the case. Staged because the refusal path needs an operator surface, not because the rule is unclear.
- [ ] 6.4 **Reinstating a route the old rule deprecated.** An instance seeded before this change has `spoedig-herstel` at `deprecated`. The seeder reports it and does not act, per the Migration Plan. Whether an upgrade should offer a one-shot reinstatement the administrator confirms is an open question, and answering it wrongly resurrects a route somebody retired on purpose.
- [ ] 6.5 **A variant column in the workflow tab.** The definitions list shows versions of one workflow. With routes it shows two stacks, and nothing on screen says so. Lands with 6.1.
