---
kind: code
---

# Proposal: retire-cmmn-caseplanstate

## Summary

dossiq carries its own CMMN runtime: nine classes and 2,004 lines under `lib/Service/Cmmn/` (CaseModelEngine, PlanItemStateMachine, PlanItemTransitions, SentryEvaluator, PlanItemCascade, PlanItemTree, CaseModelLoader, CasePlanRepository, IllegalPlanItemTransitionException), a five-route `CmmnCaseController`, and a runtime state blob in `case.casePlanState`. openregister's merged change `flow-cmmn-case-semantics` now provides the same case layer natively: plan-item rows anchored to the OpenRegister object, the same six-state lifecycle table, sentries over the engine's expression and event primitives, stages, milestones, discretionary and ad-hoc items, all served on `/api/cases`. That change names this retirement by slug as its promised follow-up. This change drains dossiq onto the openregister case layer and retires the local runtime and the blob.

## Motivation

Under ADR-065 Decision 8 a leaf app that owns an execution engine is a gate failure, and under ADR-098 Decision 1 dossiq's CMMN engine is one of the seven runtimes that converge into openregister. The convergence target exists now: all 20 tasks of `flow-cmmn-case-semantics` are checked off on openregister's development branch. Keeping the local engine from here on means two lifecycle tables, two sentry evaluators and two authorization answers for one concept, drifting apart one convenient commit at a time.

The blob is its own reason. `case.casePlanState` is one string field holding the state of every plan item in the case. Nothing can query it: "which cases have an active advice task" means decoding every case. Two concurrent transitions are a read-modify-write over the whole blob. openregister stores the same state as rows in `openregister_case_items` with an append-only audit table, indexed and transitionable one item at a time.

## Scope

### In scope

1. **Consume.** For caseTypes with `handlingModel: cmmn`, dossiq reads and transitions the case plan through openregister's `/api/cases` surface. A published `caseModel` is projected onto openregister plan items at case start by a new `CasePlanProjectionService`, a mapping in the shape of the existing `WorkflowTemplateFlowMigrator`, not an engine.
2. **Drain.** A migration (`occ dossiq:cmmn:migrate-case-plans` plus a repair step) converts every in-flight case with a populated `casePlanState` blob into openregister case-item rows, convergently and with a report of what it could not map. design.md carries the full story.
3. **Retire.** `lib/Service/Cmmn/` (all nine classes), `CmmnCaseController` and its five `/api/case/{caseId}/cmmn-plan*` routes, `src/services/cmmnApi.js`, `CmmnCasePlanPanel.vue` and `CmmnPlanItemNode.vue` with their registry and manifest entries, the engine's unit tests, and the `casePlanState` property in the case schema.
4. **Guard.** A structural test that fails on any remaining or new reference to `OCA\Dossiq\Service\Cmmn` classes or to `casePlanState`, with a closed, reason-bearing allowlist that shrinks to empty at the removal release.

### Out of scope

- **The `caseModel` schema and its authoring surface.** The definition is data, not an engine. It stays in `lib/Settings/register.d/70-cmmn-case-model.json` as the projection source. Whether authoring later moves to openregister's zaaktype skeleton mapper is a separate decision.
- **The BPMN side.** `workflowTemplate`, `StatusTransitionService` and everything `handlingModel: bpmn` selects are untouched. Their own convergence is `workflow-definitions-to-flow`.
- **openregister itself.** Nothing is asked of it. The case layer, its API and its authorization already exist.

## Affected projects

- [x] Project: `dossiq`. This change.
- [ ] Project: `openregister`. Consumer only; `flow-cmmn-case-semantics` is merged and its proposal explicitly leaves this retirement to a dossiq-side change with its own backfill and its own rollback.

## Risks

The sharp edges live in design.md. The sharpest one is named here because it decides the task order: openregister silently drops any property the schema does not declare. Removing `casePlanState` from the case schema while any case still carries a populated blob destroys that blob on the case's next ordinary save. The property is therefore removed last, gated on a drain report showing zero populated blobs.
