# An LHS matrix is a decision table

## Why

The Landelijke Handhavingsstrategie matrix is a three-axis lookup: severity by
behaviour by actor type, yielding an intervention. That is a decision table.

openregister#3186 gave the fleet one evaluator for decision tables, and its own
suite proves this exact shape evaluates (`testTheLhsMatrixShapeEvaluates`).
dossiq meanwhile indexes the cells by hand into a
"severity:behaviour:actorType" dictionary and throws when the triple misses.

That hand-rolled lookup is not merely duplicate work. It is what let the
shipped matrix label all twelve of its government cells `government` while the
axis said `overheid`, leaving a quarter of the enforcement strategy unreachable
with nothing to notice (dossiq#1596). A declared decision table cannot hide the
same defect: its inputs are named, and the evaluator refuses what it cannot
resolve rather than quietly missing.

## What this change does

Projects each stored matrix onto a decision table, the same way workflow
definitions and approval routes were projected, and for the same reason.

Each cell becomes one rule keyed by its own triple. The table declares
`UNIQUE`, because a grid has exactly one cell per triple — and UNIQUE turns an
overlapping pair into a refusal rather than something silently resolved by
declaration order, which is precisely what the hand-indexed dictionary gave up.

## The refusal that matters

A matrix whose cell names a value absent from its own axis is SKIPPED, not
projected. Projecting it would carry the defect across while looking like a
migration that worked: the rule would be unreachable in the table exactly as
the cell is unreachable in the matrix.

Measured against the running instance, the shipped matrix is refused today —
twelve cells off-axis — and becomes projectable once dossiq#1596 lands.

## What this change does NOT do

It does not retire the matrix, the schema or the Enforcement strategy page. The
projection arrives disabled, because the matrix still drives recommendations
through LhsRecommendationService and a table that also answered would be a
second source of truth for an enforcement decision.
