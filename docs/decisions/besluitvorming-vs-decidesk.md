<!-- SPDX-License-Identifier: EUPL-1.2 -->
# Decision: besluitvorming, keep in dossiq, integrate with decidesk (do not consolidate)

Status: **SUPERSEDED** (2026-09-03) by `openspec/changes/proposals-are-cases`.
Originally a **Recommendation** (2026-06-22), backlog item #7.

## Why this is superseded, and what to read instead

This page recommended keeping besluitvorming in dossiq and NOT folding it into
decidesk. That recommendation is why the voorstel surface stayed, and it stopped
being true well before anyone came back to it.

Five later changes moved the other way: `dossiq-decisions-to-decidiq`,
`migrate-committees-to-decidiq`, `consume-decidesk-besluitvorming-leaf`,
`parafering-to-decidiq` and `parafering-runtime-to-decidiq`. Nobody revoked this
page, so it kept telling readers the opposite of what the fleet was doing.

The recommendation rested on one distinction: that a voorstel is the internal
approval stage preceding a formal decision, and so a different lifecycle stage
rather than a duplicate. The distinction is real. What it does not support is
modelling the voorstel as its own record. A voorstel is a case whose case type
requires a decision, and dossiq already carried that: `caseType.decisionTypes`,
`caseType.workflowDefinition` and `case.decisions`.

So the boundary this page defends is kept, and drawn one level up. Dossiq owns
cases. Decidiq owns decisions and the sign-off chain that precedes one. The
`proposal`, `parafeerroute`, `parafeeractie` and `paraferingAuditEntry` schemas
are retired.

Read `docs/Features/besluitvorming-workflow.md` for how besluitvorming works now.

The original text follows, unchanged, because the reasoning is worth keeping even
where the conclusion did not hold.

---


## Question
Dossiq has a "Besluitvorming" nav group (Voorstellen = proposals, Advice =
advice requests). Decidesk is the fleet's decision/meeting platform. Should
dossiq's Besluitvorming consume decidesk instead of re-implementing it?

## What each app actually does
- **Dossiq Besluitvorming** is a *pre-decision routing* workflow:
  - `Voorstellen` (`voorstel` schema 110): concept → in_parafering →
    ter_accordering → geaccordeerd → aangeboden → besloten. A signature-chain
    (parafeerroute) approval attached to a case. No voting, no amendments.
  - `Advice` (`adviesAanvraag` schema 126): opinion requests on a case.
- **Decidesk** is *formal governance decision-making*: meetings, agenda items,
  motions/amendments, voting rounds + individual votes, minutes, decisions
  (universal `decision` supertype, ADR-005), governance bodies. Decision/Meeting
  are top-level; storage is CalDAV-first for action items.

## Recommendation: keep separate, integrate one-way
Dossiq's voorstel is the *internal approval stage that precedes* a formal
decision; decidesk records the *body's formal outcome*. They are different
lifecycle stages, not duplicates:
- voorstel has no voting/amendments; a rejected voorstel returns to the steller.
- dossiq is case-centric (voorstellen attach to a case); decidesk is
  governance-body-centric (decisions are body outcomes).

**Do NOT** fold Besluitvorming into decidesk. **Do** consider two light,
optional, one-way integrations (separate future changes, only if usage warrants):
1. When a voorstel reaches `besloten`, emit a downstream decidesk `decision`
   record to preserve the formal outcome (dossiq → decidesk, write-only).
2. Replace dossiq `task` tracking with decidesk's CalDAV-VTODO `action-item`
   model for better Nextcloud-native task integration (orthogonal to
   Besluitvorming; evaluate fleet-wide).

## Why not consolidate
Mixing them blurs the domain boundary (is a decidesk decision a formal body
outcome or a dossiq internal approval?), and dossiq's parafering chain does
not map onto decidesk's voting/amendment model. Keep boundaries clear.

## Next step
No code change now. If desired, raise integration (1) as an OpenSpec change in
both repos (dossiq emitter + decidesk consumer), gated on real demand.
