---
kind: code
---

# Proposal: dossiq-decisions-to-decidiq

## Summary

dossiq drops all of its own decision functionality and relies on decidiq for it. dossiq owns cases; decidiq owns decisions. `DossiqRequestDecisionNode` is the pattern: raise the decision in decidiq over the typed event seam, suspend, and resume on `DecisionConcludedEvent`. This change is the umbrella that (1) records the complete audit of everything decision-shaped left in dossiq, (2) retires the unambiguous leftovers now, (3) pins the boundary with a structural test so no new local decision evaluation can ship, and (4) names every grey area and every blocked retirement instead of guessing at them.

## Motivation

The migration is far along but scattered over five sibling changes in different states of completion (`dossiq-delegation-via-events`, `consume-decidesk-besluitvorming-leaf`, `migrate-committees-to-decidiq`, `parafering-to-decidiq`, `dossiq-consumes-shared-dmn`). Nothing asserts the end state. Two local decision-authoring surfaces still sit in the bundle with no mount point, the DMN stack still offers a local rule-evaluation door, and nothing mechanical stops a new handler from computing a verdict of its own. A directive without an invariant erodes one convenient commit at a time.

## Scope

### In scope, implemented now (category B)

1. **Remove `CaseDecisionsTab.vue`.** A sidebar tab offering create, edit and delete of local `decision` records. No manifest page mounts it, no test references it. The decidiq leaf (`BesluitvormingLeafTab`, `case-decidesk-decisions`) is the authoring surface; the read-only `case-decisions` widget stays for outcomes.
2. **Remove `VoorstelCreateDialog.vue`.** A local proposal-creation dialog with zero importers, zero registry entries and zero tests. The leaf's create-proposal action is the equivalent.
3. **Deprecate the DMN decision-table stack in place.** Ruling received: rule evaluation moves to OpenRegister's `flow-decision-tables`, which is being built in parallel. dossiq's stack (`decisionTable` schema, `DecisionTableService`, `DecisionTableController`, `EvaluateDecisionHandler`, admin tab) is marked deprecated now and retired when that change lands. Nothing is deleted yet: deleting a working evaluator on the strength of one that has not shipped would repeat the mistake `dossiq-consumes-shared-dmn` documents.
4. **The structural test.** `LocalDecisionAuthoringTest` defines "decision-shaped" mechanically and fails on any NEW file that authors decision records or evaluates decision tables outside a closed, reason-bearing allowlist. See design.md for the exact signature.

### In scope, recorded but NOT implemented

- The full audit classification table (design.md): every decision-shaped item in lib/, lib/Settings and src/, classified A (already delegating), B (retired now), or C (grey area with a recommendation for Ruben).
- Blocked retirements, each named with the missing capability (tasks.md): the DMN stack (blocked on openregister `flow-decision-tables`) and the WOO besluit raise (blocked on a `woo-decision` type in decidiq's hub vocabulary).

### Out of scope

- Anything owned by an open sibling change: committee schema retirement (`migrate-committees-to-decidiq`, 6/10), parafeerroute definition (`parafering-to-decidiq`, 8/10), the event transport itself (`dossiq-delegation-via-events`, implemented on development but not yet checked off).
- The grey areas listed in design.md section 3. Each carries a recommendation; none is retired without Ruben's call.
- `CaseFieldWriter` and the flows page manifest config, deliberately: sibling PRs #1647 and #1649 touch them.

## Affected Projects

- [x] Project: `dossiq`. This change.
- [ ] Project: `decidiq`. Nothing is asked of it now; the blocked WOO item names what would be (a `woo-decision` entry in `DecisionIntegrationService::ALLOWED_TYPES` and the schema enum homes it mirrors).
- [ ] Project: `openregister`. `flow-decision-tables` is being built in parallel; dossiq's DMN retirement waits on it.

## Non-goals

This change does not promise "zero decision-shaped code in dossiq". A case legitimately carries the outcome decidiq concluded (the ZGW Besluit projection), the Awb validity rules that run before a raise, and the ZGW Besluiten API record store. Those are case data and API compliance, not decision-making, and design.md says so explicitly per item.
