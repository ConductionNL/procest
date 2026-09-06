# Proposal: add-work-queue

## Problem

The My work group answers two of the three questions a case handler asks.
**Assigned to me** shows their own cases. **All cases** shows everything. Nothing
shows the third: what is open and waiting for somebody to pick it up.

**Unclaimed work is invisible until somebody goes looking for it.** A case with no
assignee is in All cases, one row among every case the organisation has ever opened,
closed ones included. On the running instance that is 17 unassigned cases inside 37
total, and only 2 of those 17 are still open. Finding the 2 means reading past 35
rows that are either somebody's problem already or finished.

**The one place the word "unassigned" appears is a card label** on the workflow
board. There is no surface that lists unclaimed work, so nothing shows how much of it
there is, and nothing shows it growing.

Pipelinq had the opposite problem and is being fixed in the same pass: it modelled
queues as records, so a handler had to pick the right named bucket before they could
start. Both apps land on the same rule.

## Solution

Add a **Queue** page (`/queue`), first in the My work group: an index over `case`
filtered to unassigned and not finished.

- **Queue**: `assignee` is null and `isFinalStatus` is false
- **Assigned to me**: `assignee` is the signed-in user (unchanged)
- **All cases**: everything (unchanged)

Assigning a case moves it from the Queue to that person's Assigned to me. All cases
shows it in both states. The case-type folder sidebar is the same control the Cases
index carries, so the queue narrows by case type without a second page.

Nothing is removed. dossiq never modelled a queue as a record, so this change is
additive: one page, one menu entry, one relocation.

## Impact

- **Data model**: none. `assignee` and `isFinalStatus` are existing `case` fields.
- **Backend**: none. The page is declarative.
- **Frontend**: one `type: index` page and its menu entry.

## Note on the filter spelling

The base filter is `{"assignee": "IS NULL", "isFinalStatus": false}`.

`assignee: "IS NULL"` is the literal sentinel every OpenRegister condition builder
matches by value. Verified against a live instance: 17 unassigned cases with the
sentinel, 20 assigned, 2 unassigned and open.

The suffix spelling `assignee_isnull=true` was dead when this page shipped, and the
reason is deeper than it first looked: the only code mentioning the operator had no
production callers, and `isnull` was absent from
`MagicSearchHandler::COMPARISON_OPERATORS`, so the filter contributed no condition at
all rather than an inverted one. Fixed in openregister `isnull-filter-operator`. The
sentinel is kept here because it also works on instances that do not yet carry that
change, and the two are two spellings of one predicate.

`isFinalStatus` is a real boolean on every case row (measured: 37 of 37 carry it, none
absent), so plain equality reaches it and no derived predicate is needed.

## Why a page rather than a menu preset

gate-68 (`duplicate-index-pages`, ADR-097 Decision 5) reports Queue and All cases as
two `type: index` pages over `dossiq`/`case`, and proposes a `menu[].query` preset
instead. It reports this informationally and does not block.

Both filter values here are scalars, so a preset could carry them. It is still the
wrong shape. A preset is only a link into another page: the query filters and the
reader's own facet filters share one map there, so a facet interaction can widen the
queue back to every case, closed ones included. A base filter on the page itself
cannot be cleared by the person reading it, and that is the property that makes the
queue trustworthy. Assigned to me is already a page for the same reason.
