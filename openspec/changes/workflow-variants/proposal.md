---
kind: code
---

## Why

dossiq ships two enforcement workflows for one case type, and installing them turns one of them off.

`handhavingstraject` is the ordinary route under Awb 5:24. Announce the intent, hear the offender, decide, run the recovery period. `spoedig-herstel` is the Awb 5:31 route: act on the spot, put the decision in writing afterwards. Both are real law, a municipality runs both, and which one a case follows is settled at constatering by whether there is immediate danger. Both name `handhavingszaak` as their case type.

One published definition per case type is the workflow-definition model. So the second template the seeder publishes deprecates the first. Nothing errors. A workflow stops backing new cases, and the only trace is one line in a repair summary.

Two ways out were rejected. Minting a sixth VTH case type changes what a municipality registers as a zaaktype, to solve a modelling problem the municipality does not have. Leaving the deprecation in place ships an enforcement route that is dark on every install.

There is a third thing the model could say and does not: these are two routes through one case type. This change teaches it to say that.

## What Changes

- **A definition names its route.** `workflowTemplate` gains `variant`, a slug naming which route through the case type this definition describes. A definition that names no route is on the route called `standaard`, which is every definition that exists today.
- **The uniqueness rule moves to the route.** One published definition per `(case type, variant)` instead of per case type. Publishing deprecates the previously active definition of the same route, and leaves the other routes alone. With one route per case type this is the old rule, unchanged.
- **A case type has a default route.** `caseType.workflowDefinition`, which already exists and already points at a definition, is that default. A case that has not been given a route of its own follows it.
- **Resolution follows the case, not the case type.** `WorkflowTemplateLoader` returns the definition a case is pinned to, falling back to the case type's default. Today it takes the first active row it finds, which is correct only while there is exactly one.
- **Both enforcement templates land active.** `handhavingstraject` declares the `regulier` route and is the default for `handhavingszaak`. `spoedig-herstel` declares `spoedeisend`. Neither deprecates the other.
- **Picking a route at creation is staged, not guessed.** Cases are created through the generic OpenRegister form, so where the picker lives is a product decision. Until it is taken, every new case follows the default route, which is exactly what happens today.

## Capabilities

### New Capabilities
- `workflow-variants`: what a route through a case type is, how a definition names one, how a case follows one, and what the default route is for.

### Modified Capabilities
- `workflow-definition-model`: at most one active definition per `(case type, variant)`; version selection resolves through the case before the case type.
- `vth-workflow-templates`: the two Handhavingszaak templates are two routes, not a collision.

## Impact

| Area | Change |
|---|---|
| `lib/Settings/dossiq_register.json` | `variant` on `workflowTemplate`; `caseType.workflowDefinition` documented as the default route |
| `lib/Service/WorkflowDefinitionService.php` | per-route deprecation, per-route lookup, an explicit default-route setter |
| `lib/Service/Workflow/WorkflowLifecycleGuard.php` | `variantOf()`, the normaliser that makes a definition without a route readable |
| `lib/Service/Workflow/WorkflowDefinitionRepository.php` | read a case type, so the default route can be resolved |
| `lib/Service/WorkflowTemplateLoader.php` | resolve for a case, and pick deterministically when a case type has several routes |
| `lib/Service/StatusTransitionService.php` | ask for the case's definition rather than the case type's |
| `lib/Settings/seed/vth-workflow-templates/*.json` | `variant` on both enforcement entries, `isDefaultVariant` on the ordinary one |
| `lib/Repair/SeedVthWorkflowTemplates.php`, `lib/Repair/Vth/VthCatalogueReport.php` | seed the route, set the default, report what a publish actually displaced |
| Frontend | staged. No picker, no variant column, no route badge in this change |

**Out of scope, deliberately:** `workflowTemplate.parentWorkflow` and the Enterprise-tier inheritance it describes. A route does not inherit from another route, and this change must not be read as an implementation of that field. See `design.md`, Non-Goals.

**Risk owned by this change:** two active definitions on one case type make the resolution order load-bearing, where before any answer was the only answer. The loader change is not optional decoration, it is the thing that keeps the second route from stealing the first one's cases.
