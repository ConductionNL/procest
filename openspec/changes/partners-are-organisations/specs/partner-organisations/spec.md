# partner-organisations Specification

## Purpose

A ketenpartner is stored once, as an OpenRegister Organisation, without losing
what case sharing needs from it.

## ADDED Requirements

### Requirement: REQ-PRT-001 A partner migrates without losing a field

The system SHALL provide a migration that projects each `partnerOrganization`
onto an OpenRegister Organisation.

Every property the partner schema declares SHALL have a destination:
`name`, `slug`, `oin`, `contactEmail`, `isActive`, `groupId`,
`defaultPermissionLevel`, `qualityScore` and `qualityStatus`.

The last three are why dossiq kept its own copy. A migration that dropped them
would leave case sharing without the permission default it reads, and would do
so silently.

#### Scenario: The case-sharing fields survive

- **GIVEN** a partner carrying a default permission level and a quality score
- **WHEN** it is migrated
- **THEN** the Organisation MUST carry both

### Requirement: REQ-PRT-002 The partner's identity is preserved

The migration SHALL set the Organisation's uuid to the partner's own.

Case shares reference partners by id. Minting a new one would strand every
existing share while reporting success.

#### Scenario: The uuid carries over

- **GIVEN** a partner with a uuid
- **THEN** the Organisation MUST carry the same uuid

### Requirement: REQ-PRT-003 A partner is external, and says so

A migrated partner SHALL be marked as an external organisation rather than a
tenant of this instance.

Once partners and tenants share one table, the difference has to be recorded on
the row. Inferring it later from which app wrote it is not something the data
supports.

#### Scenario: A migrated partner is not a local tenant

- **GIVEN** a migrated partner
- **THEN** it MUST NOT be marked as a local tenant

### Requirement: REQ-PRT-004 Running it twice changes nothing

The migration SHALL be idempotent by the partner's own id.

The id, not the slug. `partnerOrganization` requires only `name` and
`contactEmail`, so a partner is free to carry no slug and keying on one would
fail the migration on ordinary data. Deriving a slug from the name is worse
still: two partners sharing a name derive one slug, and the second is then
skipped as already migrated, silently merging two organisations into one.

#### Scenario: A second run creates nothing

- **GIVEN** a partner already migrated
- **WHEN** the migration runs again
- **THEN** it MUST skip that partner and create no second Organisation

#### Scenario: A partner with no slug still migrates

- **GIVEN** a partner row carrying no slug
- **THEN** it MUST migrate, taking a slug derived from its id

#### Scenario: A partner with no id is refused

- **GIVEN** a partner row carrying no id
- **THEN** the migration MUST refuse it and report it as failed, because the
  id is the idempotency key and without one a re-run would duplicate it
