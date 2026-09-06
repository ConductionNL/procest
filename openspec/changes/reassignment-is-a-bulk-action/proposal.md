# Reassignment is a bulk action on cases, not a settings screen

## Why

Reassignment is an act on CASES. It lived behind a settings page that asked
first which handler to empty, which is the wrong question when what you have in
front of you is a handful of cases.

The two are genuinely different operations, and only one of them was offered:

- "move everything open that belongs to handler A over to handler B" —
  the existing `CaseReassignmentService::execute()`, for when somebody leaves
- "these rows, to this person" — what a coordinator actually does most days,
  and what the Cases page can express

## What changes

The Cases index carries a `reassign` bulk action. Tick the rows, pick who takes
them.

The new operation is its own service. Putting both on one class took its
complexity from under the threshold to 62 against a limit of 50, and phpmd was
right to say so: they are two operations that happen to share a write. That
write is now the `WritesReassignments` trait, so the audit entry cannot drift
between them.

## The audit records where each case came from

A hand-picked selection may hold rows from several handlers. A batch-level
`reassignedFrom` is truthful only when every row came from the same one, so
this reads each case's OWN assignee. The rows still share a `batchId`, so the
selection stays recoverable as a single act.

## What is retired

The Substitutions & reassignment admin page. Self-service substitution already
lives in personal settings, which is where a personal setting belongs. The page
stays routable for deep links and e2e.

## A library gap worth naming

The manifest supports `handler: "open-modal"` with a `target`, which reads like
the right way to declare this. It is not, yet: `CnIndexPage` emits an
`open-modal` event and nothing in the library listens for it, so declaring it
would ship a bulk action that does nothing when clicked. This uses the
function-handler path dossiq already uses for the Voorstellen row action.
Closing that gap in nextcloud-vue is worth doing, separately.
