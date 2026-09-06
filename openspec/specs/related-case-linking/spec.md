---
status: done
---

# related-case-linking Specification

## Purpose

Typed peer relations between cases (RGBZ/ZRC `relevanteAndereZaken`): link a bezwaar to the
originating besluit-zaak (`onderwerp`), a WOO request to its bronzaken, a toezicht case to the
vergunning it follows (`vervolg`), an advies-deelproces contributing to a hoofdbehandeling
(`bijdrage`). Relations are stored on the existing `case.relatedCases` field as
`{caseId, aardRelatie, toelichting?}`, written symmetrically by `CaseRelationService`,
guarded against self/duplicate/hierarchy-overlap with OR-RBAC read access to both cases,
rendered on the case detail with RBAC-safe masking, and mapped bidirectionally to the ZRC
Zaak `relevanteAndereZaken` field. Hierarchy (hoofdzaak/deelzaak) stays `deelzaak-support`'s
concern and is explicitly not duplicated as a peer relation.

**Status note (2026-06-13):** Implemented. Backend (`CaseRelationService`, `CaseRelationController`,
ZRC mapping in `ZrcController`) + UI (the generic `case-related` panel on CaseDetail; the bespoke
`RelatedCasesSection` sidebar tab and `AddCaseRelationModal` were retired on 2026-09-03) +
i18n shipped. Covered by PHPUnit (service guards + two-sided invariants + controller status mapping),
vitest (presentation helpers), a Newman collection (`relevante-andere-zaken`, ZGW outbound/inbound/reject),
and Playwright spec-coverage for the UI. The `relatedCases` field is a JSON-encoded **string** (not an
array column), so the service stringifies/parses it and the ZGW `relevanteAndereZaken` array is
synthesised in `ZrcController` rather than via the declarative mapping config.

## Requirements
### Requirement: Case peer relations MUST be typed per RGBZ

The system SHALL store peer relations in the existing `case.relatedCases` array as `{caseId, aardRelatie, toelichting}` entries, where `aardRelatie` is one of `vervolg`, `onderwerp`, `bijdrage` per ZRC, and `toelichting` is an optional free-text clarification.

@e2e exclude Backend storage/typing contract — proven by CaseRelationService PHPUnit (typed entries, enum constraint) and the controller validation tests; no dedicated UI surface beyond the add-relation flow covered under the render requirement.

#### Scenario: Link a bezwaar to the original besluit-zaak

- **GIVEN** a bezwaar case and the case containing the contested besluit
- **WHEN** the handler links the besluit-zaak to the bezwaar with `aardRelatie = onderwerp` and a toelichting
- **THEN** the bezwaar's `relatedCases` MUST contain `{caseId: <besluit-zaak>, aardRelatie: onderwerp, toelichting}`

#### Scenario: Relation type is mandatory and constrained

- **WHEN** a relation is submitted without an `aardRelatie`, or with a value outside `vervolg`/`onderwerp`/`bijdrage`
- **THEN** the request MUST be rejected with a validation error

### Requirement: Peer relations MUST be bidirectionally consistent

Adding a relation SHALL make it visible from both cases; removing it from either side SHALL remove it from both; deleting a case SHALL remove its entries from all counterpart cases, leaving no dangling references.

@e2e exclude Backend two-sided invariant — proven by CaseRelationService PHPUnit (testAddRelationIsTwoSided, testRemovalIsTwoSided, testCleanupForDeletedCaseRemovesCounterparts) and the Newman symmetric-inverse case; storage-level guarantee with no separate UI proof.

#### Scenario: Relation visible from both sides

- **GIVEN** the bezwaar→besluit-zaak relation above
- **WHEN** a user opens the besluit-zaak's detail
- **THEN** its related-cases section MUST show the bezwaar with the inverse presentation of the same relation type

#### Scenario: Removal is two-sided

- **WHEN** the handler removes the relation from the besluit-zaak's side
- **THEN** the entry MUST disappear from the `relatedCases` of both cases

#### Scenario: Case deletion cleans up counterpart entries

- **GIVEN** a case linked as `bijdrage` to three other cases
- **WHEN** that case is deleted
- **THEN** the corresponding entries MUST be removed from all three counterpart cases' `relatedCases`

### Requirement: Relation creation MUST be guarded

The system SHALL reject self-relations, duplicate `{caseId, aardRelatie}` pairs, and peer relations that duplicate an existing direct hoofdzaak/deelzaak hierarchy link, and SHALL require the linking user to have read access to both cases under OpenRegister RBAC.

@e2e exclude Server-authoritative guards — proven by CaseRelationService PHPUnit (self, duplicate-pair, hierarchy-overlap, access-denied) and CaseRelationController PHPUnit (reason→HTTP-status mapping); the guard responses surface inline in the add-relation modal covered under the render requirement.

#### Scenario: Self-relation rejected

- **WHEN** a user attempts to relate a case to itself
- **THEN** the request MUST be rejected with a validation error

#### Scenario: Duplicate relation rejected

- **GIVEN** an existing `{caseId: X, aardRelatie: vervolg}` relation on a case
- **WHEN** the same pair is submitted again
- **THEN** the request MUST be rejected
- **AND** a relation to the same case X with a *different* `aardRelatie` MUST be accepted

#### Scenario: Hierarchy is not mirrored as a peer relation

- **GIVEN** case A is the parent (hoofdzaak) of case B per deelzaak-support
- **WHEN** a user attempts to peer-link A and B
- **THEN** the request MUST be rejected with an error referencing the existing hierarchy link

#### Scenario: Linking requires read access to both cases

- **GIVEN** a user who can read case A but not case B under OR RBAC
- **WHEN** they attempt to link A to B
- **THEN** the request MUST be denied

### Requirement: Related cases MUST be rendered on the case detail

The case detail SHALL list each peer relation on its "Related cases" panel, with the target's title and status and navigation to it.

AMENDED 2026-09-03. This requirement used to name a bespoke "Gerelateerde zaken"
section and require RBAC-safe masking and an in-page add-relation flow. The
case-detail rewrite replaced that surface with the generic `case-related` widget,
and the bespoke one was left orphaned rather than removed: measured on the
deployed build, the `Related cases` tab renders `widgetId: case-related`, while
`RelatedCasesSection.vue` was still registered in `src/registry.js` and reachable
from nothing. Its "Link case" control and `AddCaseRelationModal` went with it, so
the add-relation flow had no entry point in the running app.

Rather than restore a surface the rewrite deliberately replaced, that decision is
now recorded: the generic widget IS the related-cases surface, and both orphaned
components are deleted. What this costs is stated plainly rather than left
implied. Two guarantees are RETIRED at the UI level, and neither is replaced:

1. **Linking a related case from the UI.** The relation is still created through
   `CaseRelationController`, which keeps its guards, its two-sided invariant and
   its PHPUnit coverage, and the ZGW `relevanteAndereZaken` mapping is unchanged.
   What is gone is the in-app affordance to make one.
2. **RBAC-safe masking of an unreadable target.** The masking branch lived in
   `RelatedCasesSection.hydrate()`. The generic widget does its own rendering, so
   whether an unreadable target is masked there is a property of that widget and
   is not asserted here.

Reinstating either means giving the generic widget a link control and a masking
rule, not resurrecting the deleted component.

#### Scenario: Section lists relations with navigation

- **GIVEN** a case with a readable related case
- **WHEN** a handler opens the case detail and selects the Related cases panel
- **THEN** the panel MUST list the relation with the target's title and status

@e2e exclude The add-relation flow and the masking branch are retired at the UI level, so the scenarios that covered them are gone with the component; the listing scenario above is covered by `tests/e2e/spec-coverage/related-case-linking.spec.ts`.

