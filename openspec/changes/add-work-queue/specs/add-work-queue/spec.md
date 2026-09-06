# Spec: add-work-queue

## ADDED Requirements

### Requirement: The queue holds the work nobody has picked up

dossiq SHALL provide a Queue page at `/queue`, an index over the `case` schema
filtered to cases with no `assignee` and `isFinalStatus` false. It SHALL sit first in
the My work group, above Assigned to me.

The base filter SHALL be `assignee: "IS NULL"` and `isFinalStatus: false`.
`assignee: "IS NULL"` is the literal sentinel every OpenRegister condition builder
matches by value, and it SHALL be preferred over the `assignee_isnull=true` suffix,
which was unimplemented when this page shipped and works only on instances carrying
openregister `isnull-filter-operator`.

#### Scenario: The queue holds unassigned open cases

- **WHEN** a handler opens `/queue`
- **THEN** every row is a case with no assignee whose `isFinalStatus` is false
- **AND** the page holds strictly fewer rows than `/cases`

#### Scenario: The queue narrows by case type

- **WHEN** a handler selects a case type in the folder sidebar on `/queue`
- **THEN** the rows are the unassigned open cases of that case type

#### Scenario: An empty queue says so

- **WHEN** no case is both unassigned and open
- **THEN** `/queue` renders its empty state rather than an empty table

### Requirement: Three surfaces, one rule

The My work group SHALL offer Queue, Assigned to me and All cases, and a case SHALL
be reachable from at least one of them at all times. Assigning a case SHALL move it
from the Queue to the assignee's Assigned to me; All cases SHALL show it in both
states.

#### Scenario: The group offers all five surfaces

- **WHEN** a handler opens the navigation
- **THEN** the My work group holds Queue, Assigned to me, All cases, Tasks and
  Workflow board

#### Scenario: Assigning a case moves it
@e2e exclude mutates a shared instance — assigning a case rewrites demo data the other suites read; the filter itself is asserted by queue.spec.ts, which proves the queue is a strict subset of the case index

- **GIVEN** an unassigned open case on `/queue`
- **WHEN** a handler is set as its assignee
- **THEN** the case no longer appears on `/queue`
- **AND** it appears on that handler's `/my-work`
- **AND** it appears on `/cases` in both states
