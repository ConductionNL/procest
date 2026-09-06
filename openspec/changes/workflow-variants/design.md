## Context

See `proposal.md`, Why. The constraints that shape the approach, each verified in the tree rather than assumed:

- **`case` already carries the pin.** `case.workflowTemplate` (a reference to a definition) and `case.workflowVersion` exist on the schema today. `WorkflowDefinitionService::getDefinitionForCase()` already prefers the pin over the case type. Nothing writes the pin at creation, and `MigrateWorkflowDefinitions` backfills it for migrated cases.
- **The engine does not use the pin.** `StatusTransitionService` resolves through `WorkflowTemplateLoader::getActiveTemplate($caseTypeId)`, which searches `caseType = X AND isActive = true` and takes `$templates[0]`. With one active row that is right by accident. With two it is whichever OpenRegister returns first.
- **`caseType.workflowDefinition` exists and is written on every publish.** `WorkflowDefinitionService::publish()` calls `pinWorkflowDefinition()` unconditionally, so today the last template seeded owns the pin.
- **`parentWorkflow` is on the schema and nowhere else.** No PHP reads or writes it. Its own description says Enterprise tier, and the archived VTH change describes it as a hierarchy between case types, generic bezwaar to VTH bezwaar. It is not a variant mechanism and cannot be bent into one.
- **Cases are created by the generic OpenRegister form.** dossiq has no case-create service to hang a route picker on. `StartCaseWidget.vue` and the OpenRegister object store are the creation path.
- **Idempotency keys on title.** `VthSeedLookup::findSeeded($caseTypeId, $title)` decides whether a catalogue entry is already seeded. The two enforcement entries have different titles, so it keeps working per route without change.

## Goals / Non-Goals

**Goals:**
- Both enforcement routes published and active on `handhavingszaak`, neither deprecating the other.
- A case that follows the second route runs the second route's transitions, not the first route's.
- An instance with one workflow per case type behaves byte for byte as it does today.
- Nothing in flight strands, including cases created before this change.

**Non-Goals:**
- **Workflow inheritance (`parentWorkflow`).** A variant is a sibling, not a child. There is no base definition, no override of steps, no merge. Inheritance is a different feature with a different data model and its own failure modes, and quietly absorbing it here would leave a half-built version of both. The field keeps its current description and its zero implementations.
- **A sixth VTH case type.** Rejected on the product side: the zaaktype a municipality registers should not change because dossiq needs somewhere to put a second graph.
- **Automatic route selection.** Nothing in this change reads case data and decides which route applies. That is a decision table, and dossiq already has one (`dmn-decision-tables`) if we later want it.
- **Moving a case between routes mid-flight.** Wanted, argued below, and staged rather than guessed.
- **The operator surface.** No picker, no variant column, no badge. Staged with the reason each one is staged.

## Decisions

### D1 · A variant is a named route through one case type, and nothing else

A variant is one alternative path from intake to closure inside a single case type. It shares the case type's identity: the same zaaktype, the same statuses, the same result types, the same deadlines, the same ZGW mapping, the same registration. What differs is the graph: which statuses are visited, in what order, under what guards, on what timers.

That is exactly what `handhavingstraject` and `spoedig-herstel` are. Both walk `handhavingszaak` statuses. `spoedig-herstel` reaches `Handhavingsbesluit` after the recovery has already happened, in four steps rather than seven, on a `PT4H` clock rather than a `P7D` one.

The model change is one property. `workflowTemplate.variant` holds a route slug. Two definitions with the same case type and the same variant are versions of each other, which is the existing rule. Two definitions with the same case type and different variants are siblings, which is new.

*Alternative rejected:* a `workflowVariant` entity of its own, with a title, a description, an order and a case-type reference. It buys a place to hang a label and costs a schema, a repair step, referential integrity and a second thing to keep true. The route's human name is the definition's own `title`, which already reads "Handhavingstraject" and "Spoedig herstel (Awb 5:31)". If routes later need their own metadata, promoting a slug to an entity is a smaller change than unpicking one we did not need.

### D2 · A definition that names no route is on the route called `standaard`

Every definition on every instance today has no `variant`. Reading absent as `standaard` means the whole existing corpus lands on one route per case type, and the per-route uniqueness rule reduces to the per-case-type rule it replaces. No backfill, no migration write, no upgrade step that can half-run.

`WorkflowLifecycleGuard::variantOf()` does the normalising, in the same class that already normalises `lifecycleStatus` out of the legacy `isDraft`/`isActive` pair. One place answers "what is this row really", which is the property that made the lifecycle fallback safe.

The slug is Dutch, like every other slug in this catalogue (`handhavingstraject`, `spoedig-herstel`, `toezichtbezoek`).

### D3 · The default route is `caseType.workflowDefinition`, which already exists

A case type with several routes needs one answer to "which route does a case follow when nobody picked one". That answer is already stored: `caseType.workflowDefinition` is a reference to a definition, written by `publish()`, and it means "the workflow of this case type". Under variants it means "the default route of this case type", and the definition it points at names the route.

*Alternative rejected:* an `isDefaultVariant` boolean on the definition. It would save the loader one read of the case type, and it would create a second source of truth for the same fact. Two rows claiming to be default is a state nothing prevents, and a boolean that disagrees with the pin is unfindable. One field, one read, memoised per request.

`publish()` changes from pinning always to pinning when it should:

- nothing is pinned yet, or the pinned definition no longer exists, so the first published route becomes the default;
- the pinned definition is of the same route, so publishing v2 of the default route keeps the default on v2.

It does not re-pin when the row being published is a different route from the pinned one. That is the bug this rule closes: today publishing a second route silently makes it the case type's workflow.

The catalogue says which route is default rather than leaving it to `glob()` order. `handhavingstraject` carries `isDefaultVariant: true`, and the seeder sets the default explicitly after publishing it. Ordering dependencies in this seeder are exactly what #1819 was about, and "the default route is whichever file sorts first" is one.

Ordinary enforcement is the default because Awb 5:31 is the exception in law. A spoedeisende bestuursdwang is what you do when the ordinary route is too slow.

### D4 · A case records its route by its pin, and there is no second field

`case.workflowTemplate` points at the definition the case follows. That definition names the route. So "which route is this case on" is one dereference, and there is nothing to keep in sync.

*Alternative rejected:* a `workflowVariant` string on the case, denormalised for listing and filtering without a join. It can disagree with the pin, and when it does, the two answers are both plausible and only one drives behaviour. If a case list later needs to filter by route without dereferencing, that is a read concern, and a read concern is not a reason to store a fact twice.

An unpinned case follows the case type's default route. Every case on every instance is unpinned today, so every existing case keeps resolving to exactly the definition it resolved to before.

### D5 · Resolution follows the case, and refuses to be arbitrary when it cannot

This is the load-bearing change, and without it the rest is a defect.

`StatusTransitionService` asks `WorkflowTemplateLoader` for a case type's active template and gets `$templates[0]`. Publish two routes and that becomes a coin flip: a case created for the spoedeisende route would be offered the ordinary route's transitions, with no error and nothing in the log.

So the loader gains a case-aware entry point:

1. `case.workflowTemplate` is set: return that definition. The pin wins even when a newer version of its route has been published, which is what `workflow-definition-model` already promises for versions and what the loader never honoured.
2. No pin: return the case type's default route, resolved through `caseType.workflowDefinition`.
3. The pin points at nothing, or the case type's pin points at nothing: fall back to the single active definition when there is exactly one.
4. Several active definitions and no usable pin: pick deterministically, by variant slug then by highest version, and log a warning naming the case type and the routes it chose between.

Step 4 is the one that matters for trust. An ambiguous instance is a misconfiguration, and the loader's job is to be reproducible and loud about it, not to be silently random.

### D6 · Changing route mid-case is wanted, and staged

An enforcement case genuinely escalates. An inspector acts under 5:31 on Tuesday and the file becomes an ordinary traject on Thursday. Refusing the move forever pushes municipalities into opening a second case for one enforcement action, which is the outcome the whole change is trying to avoid.

It is staged rather than built because it needs a rule this change cannot derive from evidence: what happens to a case whose current status does not exist in the target route's graph. The constraint is written down now so the staged task is a specification and not a wish:

- the target definition MUST be published, active, and on the same case type;
- the case's current status MUST appear in the target definition's transitions, otherwise the move is refused and says why;
- the move MUST be recorded on the case, with who moved it and from which route, because a case that changed route halfway is a fact its audit trail has to carry.

### D7 · The catalogue guard gets stronger, not weaker

dossiq#1822 added `testEntriesSharingACaseTypeRecordThatTheyDo`, so a third template landing on an occupied case type cannot be silent. Under variants the hazard changes shape but does not go away: two entries on one case type with the *same* route still deprecate each other, exactly as before.

So the guard keeps its note requirement and gains a mechanical one. Entries sharing a case type MUST declare a `variant`, those variants MUST be distinct, and each entry MUST still name its siblings in `_sharesItsCaseTypeWith`. A third enforcement template can now land, and it has to say which route it is. That is a stronger property than the one it replaces, and it fails on exactly the case the old test caught.

### D8 · The repair summary names the route, and stops explaining the deprecation

`VthCatalogueReport::seededReason()` currently closes with "one published definition per case type is the model, and this case type has two catalogue entries". That sentence exists to explain a deprecation that no longer happens. It is replaced by the route the entry landed on, and a displacement is reported only when a publish actually displaced something, which now means a previous version of the same route.

## Risks / Trade-offs

- **Two active rows make ordering load-bearing.** Mitigated by D5, and by a test that asserts the pick is by variant then version rather than by whatever the store returns.
- **The default route is invisible in the UI.** An operator cannot see which route a case type defaults to without reading the case type. Staged in tasks, and honest in the repair summary meanwhile.
- **A route is reachable only by pin until the picker ships.** `spoedig-herstel` will be published and active and no new case will follow it. That is strictly better than today, where it is deprecated, and it is not the finished feature. Said plainly in tasks rather than implied to be done.
- **`variant` is free text.** Nothing validates that a slug is one a human meant. A typo makes a new route rather than an error. Accepted for the seeded catalogue, which is tested; called out for the editor, which is staged.

## Migration Plan

No data migration runs, by design.

| What exists | What happens |
|---|---|
| A definition with no `variant` | Read as route `standaard`. No write. |
| A case type with one published definition | One route, one active definition, the pre-existing rule. |
| A `caseType.workflowDefinition` pin | Kept, and now means "the default route". |
| A case with `case.workflowTemplate` set | Keeps that exact definition, now honoured by the engine as well as by `getDefinitionForCase()`. |
| A case with no pin | Follows the case type's default route, which resolves to the same definition it resolved to before. |
| `handhavingszaak` on an instance seeded before this change | Carries one active definition and one deprecated one. The repair summary names the deprecated route and the two clicks that bring it back. Nothing is written. |

No stored state changes. The last row is the one that deserves the argument.

An instance seeded before this change has `spoedig-herstel` sitting at `deprecated`, because the old rule turned it off. The tempting fix is for the seeder to publish it again, and that fix is wrong: a row is `deprecated` whether the model retired it or an operator did, and the data cannot tell those apart. Republishing on sight would resurrect a route an administrator deliberately retired, on an upgrade they did not ask for it on.

So the seeder reports instead of acting. A catalogue entry found deprecated gets its own summary line naming the route and the way back: clone it from the workflow tab and publish the copy. The clone carries the same `variant`, its route has no published definition, so publishing it deprecates nothing. Two clicks, taken by the person entitled to take them.

## Open Questions

- Where does the route picker live at case creation, given that cases are created by the generic OpenRegister form? Staged as task 6.1, needs product input.
- Should a route carry its own title and description once there are more than two? Deferred until a third route exists, per D1.
- Should an upgrade offer to reinstate a route the old rule deprecated, as a one-shot the administrator confirms? Staged as task 6.4. The seeder must not decide it for them, per the Migration Plan.
