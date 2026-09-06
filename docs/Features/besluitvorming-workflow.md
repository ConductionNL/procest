# Besluitvorming workflow

Dossiq handles formal decision-making for the college van burgemeester en wethouders (B&W) and the gemeenteraad. You work the case; the decision itself is taken in Decidiq.

## What a proposal is here

A proposal is not a separate record you file. It is a case whose case type requires a decision.

That matters for where you look. There is no proposals list and no proposals menu entry. You find a besluitvorming case under **All cases**, filtered by its case type in the sidebar. You open it like any other case.

Everything the decision needs sits on the case detail page. The **Besluitvorming** panel shows the decisions raised for this case, and their outcomes.

## The three case types

Dossiq ships three pre-configured case types. An administrator activates them once, from the besluitvorming templates.

| Case type | For | Deadline |
|---|---|---|
| College-besluit | A formal decision by the college of B&W | 30 days |
| Raadsbesluit | A decision by the gemeenteraad, with the griffier involved | 60 days |
| Mandaatbesluit | A decision taken under mandate, kept internal | 30 days |

Each one carries its own statuses, roles, document types and result types. Each one also carries a workflow, and that workflow is where the work happens.

## How a case moves

The nine statuses run from **Voorstel opstellen** to **Gearchiveerd**. A handler moves the case along; the workflow raises what each step needs.

1. **Voorstel opstellen.** The steller drafts the advice document.
2. **Ambtelijk advies.** Colleagues add their advice.
3. **Parafering.** The officials who must sign off do so. The sign-off chain runs in Decidiq, and each approver sees their step in the work queue they already read.
4. **Gereed voor agendering.** The case is ready for a meeting agenda.
5. **Geagendeerd** and **Vergadering.** Decidiq owns the agenda and the meeting.
6. **Besluit genomen.** The outcome comes back onto the case, under **Besluitvorming**.
7. **Bekendmaking.** Dossiq publishes to DROP or LVBB.
8. **Gearchiveerd.** The dossier closes with its documents linked.

## Mandates

A mandaatbesluit may only be signed by someone who holds the mandate for it. Dossiq checks this against the mandaatregister before the case may advance, so an unmandated signature is refused rather than recorded.

## Where the parts live

| Concern | Owner |
|---|---|
| The case, its type, its statuses and its documents | Dossiq |
| Which decision a case type needs | `caseType.decisionTypes` |
| The sign-off chain and the meeting | Decidiq |
| The outcome recorded on the case | `case.decisions` |
| Publication to DROP or LVBB | Dossiq |

## Next

Activate the besluitvorming case types from **Settings, Case types**, then open **All cases** and filter on the one you need.
