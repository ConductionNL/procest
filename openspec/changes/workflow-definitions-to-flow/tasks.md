# Tasks: workflow-definitions-to-flow

## Implementation Tasks

### Task 1: The migrator
- **spec_ref**: `openspec/changes/workflow-definitions-to-flow/specs/workflow-definitions-to-flow/spec.md#requirement-req-wdf-001-a-definition-is-projected-onto-a-flow`
- **files**: `lib/Service/Workflow/WorkflowTemplateFlowMigrator.php`
- **acceptance_criteria**:
  - GIVEN a template WHEN projected THEN one `dossiq.setStatus` node per distinct status and one edge per transition
  - GIVEN JSON-encoded OR native-array transitions THEN both decode
  - GIVEN a wildcard `fromStatus` THEN that transition is skipped and the rest still project
  - GIVEN no usable transitions THEN the template is skipped, not projected empty
- [x] Implement
- [x] Test

### Task 2: Disabled, and named statuses
- **spec_ref**: `.../spec.md#requirement-req-wdf-002-the-projection-arrives-disabled` (+ REQ-WDF-003)
- **files**: `lib/Service/Workflow/WorkflowTemplateFlowMigrator.php`
- **acceptance_criteria**:
  - GIVEN a projected flow THEN `enabled` is false
  - GIVEN the nodes THEN each carries a status NAME, never a statusType id
- [x] Implement
- [x] Test — mutation-checked: creating the flow enabled turns the suite red

### Task 3: Idempotency and the command
- **spec_ref**: `.../spec.md#requirement-req-wdf-004-a-re-run-updates-rather-than-duplicating` (+ REQ-WDF-005)
- **files**: `lib/Command/MigrateWorkflowDefinitionsToFlowsCommand.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a second run THEN the existing flow is updated, resolved by its provenance marker and not by name
  - GIVEN `--dry-run` THEN nothing is written and the report still names the outcome
  - GIVEN a refused write THEN it is counted as failed, the rest still project, and the command exits non-zero
  - GIVEN no FlowService or no OpenRegister THEN the run reports why and writes nothing
- [x] Implement
- [x] Test

### Task 4: Collapse the two menu entries onto Flows
- **spec_ref**: `.../spec.md#requirement-req-wdf-006-flows-is-the-single-authoring-entry`
- **files**: `src/manifest.json`, `src/menu-layout.json`, `tests/e2e/changed-surfaces.spec.ts`, `tests/e2e/spec-coverage/settings-pages.spec.ts`
- **acceptance_criteria**:
  - GIVEN the settings menu THEN Flows is present and Workflow definitions is absent
  - GIVEN a direct link to `/settings/workflow-definitions` THEN the page still renders
- [x] Implement
- [x] Test — with a control asserting Flows IS present, so a menu that failed to render cannot pass as an absence

### Task 5: Retire the workflowTemplate object itself
- **spec_ref**: deferred
- **acceptance_criteria**:
  - Deliberately out of scope, and narrower than it was. The menu no longer offers two answers (Task 4), and OpenRegister#3350 gave `UserTaskNode` the ability to arm its own deadline, so a projected flow can now carry the per-step SLAs the definition used to hold alone. What the projection still does not carry is per-step checklists and roles. Retiring the object means moving those first; until then the page stays routable so a live definition can still be read and edited deliberately.
- [ ] Implement
- [ ] Test
