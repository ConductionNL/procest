## Purpose

Defines a variant: one of several routes through a single case type. Says how a definition names its route, how a case follows one, which route a case takes when nobody picked, and what a variant is deliberately not.

@e2e exclude The variant model is a backend data and resolution concern with no operator surface in this change. The picker, the variant column and the route badge are staged, and the e2e that covers them lands with them.

## ADDED Requirements

### Requirement: A workflow definition names the route it describes

A workflow definition SHALL carry a `variant`: a slug naming which route through its case type the definition describes. A route is one path from intake to closure inside one case type. It shares the case type's identity, its statuses, its result types, its deadlines and its registration. What differs is the graph.

A definition that names no route SHALL be read as being on the route `standaard`. Every definition created before variants existed names no route, so every one of them is on `standaard`, and a case type with one workflow has one route.

A variant SHALL NOT be a case type. Introducing a route SHALL NOT change what a municipality registers as a zaaktype.

#### Scenario: A definition declares its route
- **WHEN** a workflow definition is created with `variant` set to `spoedeisend`
- **THEN** that definition is on the `spoedeisend` route of its case type
- **AND** it is a sibling of, not a version of, a definition on another route of the same case type

#### Scenario: A definition with no route is on the default route
- **GIVEN** a workflow definition created before variants existed, carrying no `variant`
- **WHEN** its route is resolved
- **THEN** it is on the route `standaard`
- **AND** no write is performed to make that true

#### Scenario: A route is not a case type
- **GIVEN** a case type carrying two routes
- **WHEN** a case is registered on either route
- **THEN** the case's case type is the same in both cases
- **AND** its statuses, result types and deadlines come from that one case type

### Requirement: A case type has one default route, and it is the definition the case type points at

Each case type SHALL have exactly one default route: the route of the definition named by `caseType.workflowDefinition`. A case that has not been given a route of its own SHALL follow the default route.

The default SHALL be recorded in one place. A system SHALL NOT carry a second marker of defaultness on the definition, because two markers can disagree and only one drives behaviour.

Publishing a definition SHALL take the default when, and only when, no default is recorded yet, the recorded default no longer exists, or the recorded default is on the same route as the definition being published. Publishing a definition on a different route SHALL leave the default where it is.

#### Scenario: The first published route becomes the default
- **GIVEN** a case type with no default route recorded
- **WHEN** a definition is published for it
- **THEN** that definition becomes the case type's default route

#### Scenario: A new version of the default route keeps the default
- **GIVEN** a case type whose default route is `regulier`, at version 1
- **WHEN** version 2 of the `regulier` route is published
- **THEN** the case type's default route is version 2
- **AND** it is still the `regulier` route

#### Scenario: Publishing a second route does not steal the default
- **GIVEN** a case type whose default route is `regulier`
- **WHEN** a definition on the `spoedeisend` route is published for the same case type
- **THEN** the case type's default route is still the `regulier` definition
- **AND** both definitions are published and active

### Requirement: A case follows the definition it is pinned to, and the default route otherwise

Resolving which workflow drives a case SHALL follow the case before it follows the case type:

1. When the case names a definition, that definition SHALL be used, including when a newer version of its route has since been published.
2. When the case names none, the case type's default route SHALL be used.
3. When neither resolves and the case type has exactly one active definition, that one SHALL be used.
4. When neither resolves and the case type has several active definitions, the choice SHALL be deterministic, ordered by route slug and then by highest version, and SHALL be logged with the case type and the routes it chose between.

A case SHALL NOT carry a copy of its route alongside its pin. The pinned definition names the route, so there is nothing to keep in step.

#### Scenario: A pinned case runs its own route
- **GIVEN** a case type carrying a `regulier` and a `spoedeisend` route, both active
- **AND** a case pinned to the `spoedeisend` definition
- **WHEN** the transitions available on that case are computed
- **THEN** they come from the `spoedeisend` definition
- **AND** the `regulier` definition's transitions are not offered

#### Scenario: An unpinned case follows the default route
- **GIVEN** a case type carrying two active routes and a default of `regulier`
- **AND** a case naming no definition
- **WHEN** the transitions available on that case are computed
- **THEN** they come from the `regulier` definition

#### Scenario: A pin outlives a newer version of its route
- **GIVEN** a case pinned to version 1 of the `regulier` route
- **WHEN** version 2 of the `regulier` route is published
- **THEN** the case still runs version 1
- **AND** only cases created afterwards run version 2

#### Scenario: An ambiguous case type resolves the same way twice and says so
- **GIVEN** a case type with two active definitions and no usable default
- **WHEN** an unpinned case on it is resolved twice
- **THEN** both resolutions return the same definition
- **AND** a warning names the case type and the routes it chose between

### Requirement: A route is not workflow inheritance

A variant SHALL NOT inherit from another variant. There SHALL be no base definition, no step override and no merge of two definitions into one.

`workflowTemplate.parentWorkflow` SHALL remain unimplemented. It describes an Enterprise-tier hierarchy between case types, which is a different feature with a different data model, and this requirement exists so that adding routes is not later read as having built it.

#### Scenario: Adding a route implements no inheritance
- **GIVEN** a case type carrying two routes
- **WHEN** either definition is read
- **THEN** its steps and transitions are its own
- **AND** neither definition inherits, overrides or merges anything from the other

#### Scenario: parentWorkflow stays inert
- **WHEN** the shipped code is read for readers or writers of `parentWorkflow`
- **THEN** there are none
