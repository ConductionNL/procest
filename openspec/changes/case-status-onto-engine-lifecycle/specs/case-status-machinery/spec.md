# Spec delta: case status machinery stays on one engine line

## ADDED Requirements

### Requirement: one write path for case status

`case.status` MUST change only through `StatusTransitionService` (its
guarded `execute()`, the admin `executeFreeForm()`, and the flow-node
seam that wraps the same `lib/Service/Transitions/` handlers). No second
public entry point to the transition engine may exist: a facade or
wrapper without production callers is dead machinery and MUST be
removed.

#### Scenario: no second entry point to the transition engine

- GIVEN the tree after this change
- WHEN `lib/` is scanned for the retired classes
- THEN `WorkflowEngineService` does not exist as a file
- AND the scanner test fails naming any file that brings it back

`@e2e exclude` structural backend assertion; pinned by
LocalStatusMachineryTest.

### Requirement: no dead local state machines

A status machine whose states cannot occur in stored data (a literal
vocabulary written against a reference-valued field, or a scan whose
filter can never match) is dead machinery and MUST be removed rather
than migrated. The vergadering case machine
(`VergaderingCaseService`, `VergaderingDeadlineJob`) is retired under
this rule.

#### Scenario: the vergadering machine stays retired

- GIVEN the tree after this change
- WHEN `lib/` is scanned for the retired classes
- THEN `VergaderingCaseService` and `VergaderingDeadlineJob` do not
  exist as files
- AND `appinfo/info.xml` registers no vergadering background job

`@e2e exclude` structural backend assertion; pinned by
LocalStatusMachineryTest; the retired UI surface is already asserted by
tests/e2e/spec-coverage/retired-surfaces.spec.ts.

### Requirement: a declared lifecycle agrees with its enum and its service

Every `x-openregister-lifecycle` a dossiq schema declares MUST use the
object-form dialect OR validates (`field`, `initial`, `transitions`,
`final`), MUST name only states of the field's enum, and where an
app-side transition table exists for the same object it MUST agree with
the declaration edge for edge. The complaint lifecycle is rewritten
under this rule in both register manifests.

#### Scenario: the complaint declaration mirrors the service table

- GIVEN the complaint schema in `dossiq_register.json` and
  `dossiq_mock_register.json`
- WHEN the declared transitions are compared with
  `ComplaintService::TRANSITIONS`
- THEN the edge sets are equal
- AND every declared state is a member of the status enum

`@e2e exclude` manifest and service parity; pinned by
LocalStatusMachineryTest.

### Requirement: the transition-table census is closed

Every transition-table constant under `lib/` (`const *TRANSITIONS =`,
`const *VALID_STATUSES =`) MUST sit in the scanner's closed allowlist
with the reason it may exist (domain machine, staged thinning, or
another change's ownership). A new file declaring one fails the suite
naming itself.

#### Scenario: a new local status machine cannot ship quietly

- GIVEN a new file under `lib/` declaring a transition-table constant
- WHEN the unit suite runs
- THEN `LocalStatusMachineryTest` fails naming the file
- AND the failure message says where status sequencing belongs

`@e2e exclude` structural backend assertion; pinned by
LocalStatusMachineryTest.
