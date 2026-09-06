# Tasks

- [x] Project each cell onto a rule keyed by its own triple
- [x] Declare UNIQUE, so an overlapping pair is refused not resolved
- [x] Refuse a matrix whose cell names a value absent from its axis
- [x] Accept the axes and cells in both their stored shapes
- [x] Arrive disabled, like the workflow and approval-route projections
- [x] Run as a named owner
- [x] Phase 2: enable, and retire the bespoke lookup and the settings page

## Phase 2, and what it turned up

**The recorded blocker was not the one that was there.** The proposal said the
shipped matrix is refused today, twelve cells off-axis. Measured against a
running instance it is not: the matrix carries `overheid` on both the axis and
all twelve cells, and `occ dossiq:lhs:migrate-to-decision-tables --dry-run`
projects it cleanly, 48 rules, 0 skipped.

The split was one level up, in the schema. `lhsRecommendation.actorType`
declared the enum `["burger", "bedrijf", "government", "recidivist"]` while
every cell said `overheid`. `RenameDutchValues` translated ONE member of the
axis and could not reach the cells, which live inside a JSON column rather than
a column of their own. So a caller sending the value the schema offers could
never key a cell, and `recommend()` threw "Geen LHS-cel gevonden" — which reads
like bad input rather than a broken axis. That is dossiq#1596, and the tell that
it was a slip is that the other three axis values were never translated.

Fixed at source: the mapping is gone from `RenameDutchValueDecisions`, the enum
matches the axis in both register descriptors, and
`RealignLhsActorTypeVocabulary` repairs instances that already ran it.
`LhsAxisVocabularyTest` is the check that was missing, comparing the three files
against each other rather than against a hardcoded list. Its negative control:
reintroducing both halves of the defect fails three of its four tests.

## What "retire the bespoke lookup" came to mean

`recommend()` evaluates the projected decision table through OpenRegister and
reads the matrix only where no projection exists. The fallback is not dead code
and is not hedging: projecting a table needs an owner for the object it writes,
so it is an occ command a person runs and cannot happen unattended on upgrade.
An instance that has not run it must still be able to enforce.

The projection now arrives ENABLED, which is what phase 2 means. Shipping it
disabled would no longer be the cautious choice: with the evaluator as the
lookup, a disabled table silently means the matrix answers instead, and the
migration becomes a no-op that reports success.

## Two defects the phase-2 work exposed in phase 1

1. **A re-run minted a second table.** `saveObject()` was called with no id, so
   every run wrote another table carrying the same provenance marker — and the
   lookup resolves by marker and takes the first match, so which duplicate
   answered an enforcement question was arbitrary. A re-run now resolves the
   existing table by marker and updates it.

2. **Nothing protected an edited table.** The projection is one-way and the
   matrix no longer has a settings page, so an overwrite would replace an
   administrator's work with a source they cannot read. A re-run whose rules
   differ from the stored ones now REFUSES and says so.

## Not done here

The `lhsMatrix` schema stays. It is still the source the projection reads, and
deleting it would strip the fallback from every instance that has not run the
command.
