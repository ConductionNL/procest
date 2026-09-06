# reassignment-bulk-action Specification

## Purpose

Reassigning cases becomes an act on the cases a user is looking at, rather than
a settings screen that first asks which handler to empty.

## ADDED Requirements

### Requirement: REQ-RBA-001 A selection is reassigned as one act

The system SHALL reassign an explicitly named set of cases to one receiving
handler, and SHALL report the outcome per case.

The rows SHALL share one batch identifier, so a selection remains recoverable
as a single act.

#### Scenario: The selected cases move

- **GIVEN** two cases and a receiving handler
- **WHEN** the selection is reassigned
- **THEN** both cases MUST carry the new assignee, and the summary MUST report
  two requested and two succeeded

#### Scenario: One failure does not abort the rest

- **GIVEN** a selection whose first case cannot be written
- **THEN** the remaining cases MUST still be attempted, and the failure MUST be
  reported for that case rather than raised

### Requirement: REQ-RBA-002 The audit records each case's own previous assignee

The system SHALL record `reassignedFrom` from the case's OWN assignee at the
time of the move.

A hand-picked selection may hold rows belonging to different handlers. A
batch-level value is truthful only when they all came from the same one, so
recording it would name the wrong person on every other row.

#### Scenario: A mixed selection records mixed origins

- **GIVEN** one case assigned to `jan` and one to `klaas`, both moved to `piet`
- **THEN** the first case's audit entry MUST name `jan` and the second's `klaas`

#### Scenario: A case already assigned to the receiver is left alone

- **GIVEN** a case whose assignee is already the receiving handler
- **THEN** it MUST be counted as succeeded and MUST NOT be rewritten, because
  an audit entry saying it moved from somebody to themselves is not true

### Requirement: REQ-RBA-003 The endpoint refuses what it cannot honour

The system SHALL answer 400 when the selection is empty, when no receiving
handler is named, or when `caseIds` is not an array.

`caseIds` arrives off the wire, so a caller can send a string; that is a bad
request, not a crash.

#### Scenario: A non-array selection is a bad request

- **GIVEN** a request whose `caseIds` is a string
- **THEN** the response MUST be 400 and the service MUST NOT be called

#### Scenario: An unexpected failure is a logged 500

- **GIVEN** the service raises an unexpected error
- **THEN** the response MUST be 500 and the failure MUST be logged, because a
  selection that half moved must not be reported as a clean 200

### Requirement: REQ-RBA-004 Reassignment is reachable from the cases

The Cases index SHALL declare a `reassign` bulk action, and the admin page
whose only job was to reach the same operation SHALL be retired from the
navigation.

The page remains routable for deep links and e2e specs.

#### Scenario: The bulk action is declared

- **GIVEN** the Cases page
- **THEN** its config MUST declare a bulk action whose handler opens the
  reassignment dialog with the live selection
