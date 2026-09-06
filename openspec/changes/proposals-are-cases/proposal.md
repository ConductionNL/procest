---
kind: code
---

# Proposal: proposals-are-cases

## Summary

A voorstel is not a thing in its own right. It is a case whose case type requires
a decision, and it was modelled a second time as a standalone object with its own
list page, its own status vocabulary and its own sign-off engine. That second model
retires completely: the `proposal`, `parafeerroute`, `parafeeractie` and
`paraferingAuditEntry` schemas, the `/voorstellen` pages, the menu entry, the
parafering runtime, and every test written against them.

## Motivation

The reported symptom was narrow: a `Proposals` entry in the dossiq menu that nobody
could account for. Following it back produced three findings, and the third is the
one that decided the shape of this change.

**Nothing moved it here from decidiq.** `voorstel` has been in this app since its
earliest schema work, under the old `procest` name, long before decidiq existed as
a target. `docs/decisions/besluitvorming-vs-decidesk.md` is the record: on
2026-06-22 it recommended keeping besluitvorming in dossiq and explicitly NOT
folding it into decidesk, on the argument that a voorstel is "the internal approval
stage that precedes a formal decision". Five later changes moved the other way and
nobody went back and revoked the doc, so it kept telling readers the opposite of
what the fleet was doing. It is updated here.

**The menu entry was a gate artefact, not a decision.** `src/menu-layout.json` says
so: `Voorstellen` had been removed, and was RESTORED on 2026-09-02 because the
case-detail consolidation dropped the `case-voorstellen` widget, which was the only
navigation edge gate-53 could follow to `/voorstellen`. Faced with an orphaned
route, the fix chosen was to put the menu entry back. The note recorded that no
replacement surface could be named truthfully, and asked whichever change retired
the proposals surface for real to re-remove the entry with a waiver that was true
at the time.

**The abstraction was already here, in three pieces.** `caseType.decisionTypes`
says which decision a case type needs. `caseType.workflowDefinition` is the flow
whose human steps are the sign-offs. `case.decisions` holds what decidiq concluded,
raised over the typed seam by `DossiqRequestDecisionNode`, which suspends and
resumes on `DecisionConcludedEvent`. The three besluitvorming case types
(College-besluit, Raadsbesluit, Mandaatbesluit) are that abstraction already
shipped. `proposal` duplicated it.

So the honest reading is that this is not a migration. There is nothing to move.

## What replaces the surface

| The voorstel surface did | It is done here now |
|---|---|
| list the proposals | `Cases`, narrowed by case type through `folderSidebar.filterField` |
| show one proposal and its chain | `CaseDetail`, and its `case-decidesk-decisions` leaf |
| say which decision is needed | `caseType.decisionTypes` |
| run the sign-off chain | decidiq's ApprovalRoute engine |
| record the outcome on the case | `case.decisions` |

## Two defects the retirement found

Neither is the point of the change, and both are worth recording because they say
something about how the surface was maintained.

**The reminder row action posted to a route that never existed.**
`customComponents.js` shipped a `voorstelReminder` handler POSTing to
`/apps/dossiq/api/notifications/parafering-reminder`. That URL appears nowhere in
`appinfo/routes.php`, at any commit. Every click answered 404 into an empty catch
that logged to the console. The action was on the `Voorstellen` index, which is why
nobody hit it.

**A guard that would have stranded every besluitvorming case.** The three shipped
bundles declared a `voorstelStatus` guard on the `Parafering` transition. An unknown
guard type evaluates as `passed: false` in `GuardRegistry`, so deleting the guard
class alone would have locked every besluitvorming case at `Parafering` on every
install, with "Onbekende guard" as the only evidence. The bundles are amended in the
same change for exactly this reason.

## Affected Projects

- [x] Project: `dossiq`. This change.
- [ ] Project: `decidiq`. Nothing is asked of it. `approval-routes` shipped the
  engine and `parafering-route-runtime` absorbed the status vocabulary, the return
  notification, the accordering effects and the mandate validation. This change
  depends on none of that landing, because it delegates nothing: it deletes.

## Scope

### In scope

1. The four schemas, from both the register and the mock register, with their seed
   objects and the `74-parafering-to-decidiq` fragment.
2. The `/voorstellen`, `/voorstellen/:id` and `/settings/parafeerroutes/:id` pages,
   the `Voorstellen` menu entry, and the `proposal` deepLink.
3. The parafering runtime: 20 PHP classes across controllers, services, listeners,
   repair steps, a flow node and a transition guard, plus the two repair steps in
   `appinfo/info.xml` and the routes in `appinfo/routes.php`.
4. The frontend: the detail view and its three components, two API clients, the
   besluit-registration dialog, five cell formatters and the row-action handler.
5. The three bvw bundles, which lose the `parafeerroute` block, the
   `voorstelStatus` guard and the `besluitvormingActivate` automatic action.
6. Every test written against the above, and the spec delta in this change.

### Out of scope, deliberately

**Stored rows are not deleted.** Retiring a schema from the register does not remove
what an install already has, and this change does not try to. `RenameDutchColumns`
and `RenameDutchSchemaSlugs` keep their `voorstel` entries: they are historical
migrations that run over rows an upgrading install may still hold, and stripping
them would make those rows less legible, not more.

**The word stays where it is the case type's own.** The bundles keep their
`Voorstel opstellen` status, their `Steller` role and their voorstel-/adviesdocument
document types. A besluitvorming case IS about a voorstel. What retires is the
pretence that the voorstel is a record you file somewhere else.

## Sibling changes this supersedes

Five changes in `openspec/changes/` existed to move, project or rebuild the surface
that this change deletes. They are removed rather than left standing, because a
change that plans work on a retired concept reads as pending work:

- `parafering-to-decidiq`, `parafering-runtime-to-decidiq`, `parafering-runs-as-a-flow`
  and `approval-routes-are-flows` all move the parafering engine somewhere.
  Deleting it is the shorter path to the same end state and asks nothing of decidiq.
- `bw-voorstellen-view` existed to BUILD the bespoke list view that
  `/voorstellen` never had, and un-skip three e2e tests waiting on it. Building a
  list for a concept being retired is the one thing that would clearly be waste.

Six specs go with them: `voorstel-management`, `parafering-actions`,
`parafering-audit-trail`, `parafering-audit-via-or`, `parafering-dashboard` and
`parafering-via-or-approval`. No surviving file carries an `@spec` pointing at any
of them.
