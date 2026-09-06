<!-- SPDX-License-Identifier: EUPL-1.2 -->
# besluitvorming-workflow delta

## REMOVED Requirements

### Removed Requirement: REQ-BVW-002 Parafering chain MUST activate automatically when a voorstel is submitted

**Reason:** the `voorstel` it activates for no longer exists. The requirement is
written entirely in terms of the retired `proposal`, `parafeerroute` and
`parafeeractie` schemas: it names `voorstel.routeSnapshot`, `voorstel.currentStep`,
`voorstel.returnedFromStep` and `BesluitvormingParafeerService.activate()`, none of
which this app declares any more.

What the requirement was reaching for survives, in the place that owns it. A case
type that requires a decision carries a flow, the flow's human steps are the
sign-offs, and `DossiqRequestDecisionNode` raises the decision in decidiq and waits
for `DecisionConcludedEvent`. Sequencing, delegation and the mandate record are
properties of the decision app's approval engine, and decidiq's own
`approval-routes` and `parafering-route-runtime` specs carry them.

Scenario REQ-BVW-002-D is the one worth naming separately. It required a paraaf
given under a mandate to record delegate, principal and mandate reference. That
record is administrative-law evidence, not a UI detail, and it is not dropped: it
is decidiq's to keep, on the ApprovalRoute stage. dossiq keeps `MandaatGuard` and
`MandaatValidationService`, which REQ-BVW-007 still covers.

### Removed Requirement: REQ-BVW-003 Case MUST auto-transition to "Gereed voor agendering" when all parafen are collected

**Reason:** same cause, and one consequence worth stating plainly. The transition
from `Parafering` to `Gereed voor agendering` still exists in all three shipped
bundles. What it no longer has is an automatic trigger and a guard, because both
were expressed against the retired schemas: the `besluitvormingActivate` automatic
action and the `voorstelStatus` guard.

Leaving the guard in place would have been worse than removing it. An unknown guard
type evaluates as `passed: false` in `GuardRegistry`, so every besluitvorming case
on every install would have stranded at `Parafering` with no way out, and the log
line would have said only "Onbekende guard".

The step is therefore advanced by a person now, which is what it is once the chain
runs elsewhere. The agenda queue this requirement fed already moved: decidiq owns
agenda building, and `BesluitvormingAgenda` was retired from this app on
2026-09-02.

## MODIFIED Requirements

### Modified Requirement: REQ-BVW-001 Zaaktype templates MUST be pre-configured for the three core besluitvorming types

Scenario REQ-BVW-001-B loses one line. It required the Raadsbesluit bundle to seed
a `parafeerroute` whose final step is the Griffier. The bundle seeds no
parafeerroute now, so the line asserted a write that cannot happen.

The Griffier itself is not lost, and that is the point of amending rather than
removing. The next line of the same scenario requires a `roleType` for "Griffier"
on the caseType, and the bundle still ships it. Who signs last is a property of the
approval route, which decidiq holds; that the role exists on this case type is a
property of the case type, which is here.

### Modified Requirement: REQ-BVW-008 Archival MUST link all case documents in the dossier before closing

Scenario REQ-BVW-008-B loses the line requiring the archived dossier to include
"the parafering record (via linked `parafeeractie` objects)". There are no
`parafeeractie` objects to link.

The other five entries in that list are untouched, including the voorstelnotitie
itself, which is a document in the dossier and always was. The sign-off history for
a case decided from now on lives with the ApprovalRoute in decidiq.
