# Case management

## ADDED Requirements

### Requirement: The case task is namespaced (REQ-CM-070)

The case task schema SHALL be `caseTask` and SHALL NOT be `task`. planninq's
project task keeps the bare slug; pipelinq uses `crmTask`.

The three claiming schemas share `description`, `priority` and `status` alone,
so all three are renamed apart rather than folded onto one owner.

Every local schema-id map keyed by the slug SHALL move with it, including
`KpiAggregationService::ids()` and `DemoCaseloadGateway::schemaIds()`, together
with their declared array shapes. A reader renamed without its builder resolves
to null and fails several frames away, where the cause is no longer visible.

`tests/e2e/ci-seed.sh` SHALL name the new slug in its required-schema list.

The rename SHALL NOT touch `task` where it is a row or item type label:
`WorkQueueService`'s `itemType`, or the `type` key in
`CaseReassignmentService`, `BulkReassignModal`, `taskApi` and
`dashboardHelpers`.

#### Scenario: The KPI counts still resolve their schema

- **WHEN** the dashboard KPIs are computed
- **THEN** the task count resolves a schema id rather than null.

#### Scenario: The demo caseload still seeds its tasks

- **WHEN** the demo caseload is seeded
- **THEN** each task is created against a resolved schema id.
