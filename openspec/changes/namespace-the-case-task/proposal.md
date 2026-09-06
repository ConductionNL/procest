# Namespace the case task

## Why

`task` was claimed by three apps: planninq, pipelinq and this one. They share
`description`, `priority` and `status` — what any task-shaped record carries,
and nothing that identifies the record.

planninq's project task is the largest and keeps the bare slug. pipelinq's
became `crmTask`; this is the case task.

## What the suite found that the grep did not

The rename reached the descriptors and the store calls on the first pass, and
the suite still failed six times. Twice for the same reason, in two different
places:

`KpiAggregationService::ids()` and `DemoCaseloadGateway::schemaIds()` each build
a local array keyed `'task'` and hand it round as `$ids['task']`. Renaming the
READERS without the BUILDERS left `$ids['caseTask']` resolving to null, which
surfaced as a `TypeError` on a nullable argument three frames away.

Then PHPStan caught the array shapes that still declared `task: string` after
the builders moved. A grep for the slug would never have found either: the key
is not the slug, it just happened to be spelled like it.

## The decoys

`itemType` in `WorkQueueService`, `type` in `CaseReassignmentService`,
`BulkReassignModal`, `taskApi` and `dashboardHelpers` — all row and item type
labels for mixed lists, not schemas.

`tests/e2e/ci-seed.sh` names the slug in its required-schema list and moved with
it. That list is checked after the import and exits before Playwright, so a miss
there reports every spec as not run.
