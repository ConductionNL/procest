# Proposal: bezwaar-beroep-cards-collapse

> **SUPERSEDED 2026-09-02.** The landing page this change delivered
> (`BezwaarBeroepOverview`, route `/bezwaar-beroep`) has been deleted, along with
> its manifest fragment, its Vue component and its registry entry. Every one of
> its four cards had stopped resolving: `BezwaarDecisions` and
> `BezwaarAdviceRequests` were retired by `case-type-navigation`, and `Bezwaren`
> and `Beroepen` were retired as pages on 2026-09-02 because each was an index
> over register `dossiq` and schema `case` narrowed by a `caseType` that sits
> under `_caseTypes_disabled`, so both listed nothing. Objections and appeals are
> case types on `Cases`. Their detail routes `/bezwaren/:id` and `/beroepen/:id`
> stay registered. Read this file as history, not as a description of the app.

## Summary

Collapse the **Bezwaar & Beroep** group (`BezwaarBeroepGroup`) in the dossiq navigation into a single top-level menu item that links to a new card-grid landing page. Each of the four former child leaves (`Bezwaren`, `Beroepen`, `BezwaarDecisions`, `BezwaarAdviceRequests`) is rendered as a card on that landing page. All former leaf page routes remain registered and reachable as deep links; only the navigation nesting changes. This change follows the ADR-044 "Menu architecture" cards-collapse rule.

## Motivation

The Bezwaar & Beroep section currently expands into four peer sub-items in the sidebar navigation (`Bezwaren`, `Beroepen`, `BezwaarDecisions`, `BezwaarAdviceRequests`). This is a textbook case for the ADR-044 cards-collapse pattern: the sub-items are a flat list of peer views with no meaningful hierarchy between them, and displaying them as an expanded group consumes sidebar space while offering no additional orientation value. A card-grid landing page communicates the available views at a glance, reduces visual noise in the nav, and preserves full deep-link access to every former leaf.

## Affected Projects

- [x] Project: dossiq
