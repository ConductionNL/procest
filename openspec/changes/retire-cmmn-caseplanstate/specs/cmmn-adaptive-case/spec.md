# cmmn-adaptive-case

## REMOVED Requirements

The capability's runtime requirements retire because openregister's `flow-cmmn-case-semantics` (merged) specifies and implements the same semantics natively, and this change makes dossiq consume them (see `specs/retire-cmmn-caseplanstate/spec.md`). Removals take effect at the removal release described in design.md section 4.

### Requirement: REQ-CMMN-001 — Case-Model Definition

**Reason**: The `caseModel` schema itself survives as authoring data (out of scope in proposal.md), but the requirement's loading contract binds it to `CaseModelEngine`, which retires. The definition's consumption contract is now REQ-RCMN-001: `CasePlanProjectionService` projects the published model onto openregister plan items at case start.

### Requirement: REQ-CMMN-002 — Plan-Item Lifecycle State Machine

**Reason**: Superseded by openregister's lifecycle requirement (one exhaustive table over the same six states, illegal transitions refused naming all four facts). dossiq no longer holds a transition table.

### Requirement: REQ-CMMN-003 — Sentry Evaluation

**Reason**: Superseded by openregister's sentry requirement (JSONLogic if-parts via `FlowExpression`, event-catalog on-parts). dossiq's `{field, operator, value}` dialect retires; existing sentries are converted per the closed table in design.md section 2.

### Requirement: REQ-CMMN-004 — Discretionary Item Enablement

**Reason**: Superseded by openregister's discretionary and ad-hoc requirement, which additionally authorizes fail-closed and adds ad-hoc items dossiq's engine never had.

### Requirement: REQ-CMMN-005 — Milestone Achievement

**Reason**: Superseded by openregister's lifecycle requirement; the milestone asymmetry (no enabled or active state) is preserved verbatim upstream.

### Requirement: REQ-CMMN-006 — Single OR Write Path

**Reason**: The requirement existed to discipline the blob's read-modify-write. The blob retires (REQ-RCMN-002 and REQ-RCMN-004); plan state is openregister rows, transitionable concurrently, so a single-writer rule over one field has nothing left to protect.

### Requirement: REQ-CMMN-007 — REST Surface

**Reason**: The five `/api/case/{caseId}/cmmn-plan*` routes and `CmmnCaseController` retire; the surface is openregister's `/api/cases` (REQ-RCMN-004 asserts the old routes answer 404).

### Requirement: REQ-CMMN-008 — BPMN/CMMN Coexistence

**Reason**: The invariant survives, the mechanism changes: `handlingModel` still selects exactly one engine per caseType, but the cmmn side is now the openregister case layer. Carried by REQ-RCMN-005 (a BPMN case is untouched) instead of by the retired `CmmnBpmnCoexistenceRegressionTest`.

### Requirement: REQ-CMMN-009 — Case-Driven End-To-End Run

**Reason**: Superseded by the retirement journey spec over openregister's surface (REQ-RCMN-001 and REQ-RCMN-005 scenarios); the local engine that the end-to-end run exercised no longer exists.
