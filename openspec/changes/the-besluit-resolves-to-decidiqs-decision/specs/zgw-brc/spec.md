# ZGW BRC

## ADDED Requirements

### Requirement: The Besluit resolves to decidiq's Decision (REQ-BRC-020)

`decision_schema` SHALL resolve to decidiq's `decision` schema when this app has
no value of its own configured.

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so two apps declaring a `decision` meant whichever row was
reached first answered for both. decidiq's Decision now carries the five BRC
fields it lacked (`deliveryDate`, `expiryDate`, `publicationDate`,
`responsibleOrganisation`, `governingBody`), so it can hold the Besluit.

`BrcController` SHALL stay in this app. The standard belongs where it is served
from; only the schema it reads moves.

The lookup SHALL use the `(application, slug)` PAIR, never the slug alone. Slug
alone is the ambiguity this exists to end: it matches this app's own row as
readily as decidiq's, and which one it returns depends on insertion order.

Resolution SHALL happen LAST, only when nothing local answered. An instance that
still has its own `decision_schema` keeps it, because its besluiten are in that
schema; a fresh install has none and lands on decidiq's. Preferring decidiq
unconditionally would point every existing instance at a schema holding none of
its besluiten, and the BRC would answer 404 for every one it has.

Resolution SHALL fail to the empty string when decidiq is absent or carries no
such schema, so the caller behaves exactly as it did when the key was unset.

It SHALL apply to `decision_schema` alone, and not to any other key whose name
contains `decision`.

#### Scenario: A configured instance keeps its own schema

- **GIVEN** an instance with `decision_schema` set
- **WHEN** the value is read
- **THEN** the configured value is returned, not decidiq's.

#### Scenario: An unconfigured instance resolves to decidiq

- **GIVEN** an instance with no `decision_schema` set, and decidiq installed
- **WHEN** the value is read
- **THEN** decidiq's `decision` schema id is returned.

#### Scenario: Without decidiq it resolves to empty

- **GIVEN** an instance with no `decision_schema` set and decidiq absent
- **WHEN** the value is read
- **THEN** the empty string is returned.

#### Scenario: Sibling keys are unaffected

- **WHEN** `decision_type_schema`, `decision_document_schema` or
  `case_decision_schema` is read with no value set
- **THEN** each resolves as it always did, with no fallback.

### Requirement: Besluiten move onto decidiq only when asked (REQ-BRC-021)

Because REQ-BRC-020 resolves LAST, an instance that already has a
`decision_schema` never moves on its own. `occ dossiq:migrate-besluiten` SHALL be
the supported way to move it.

It SHALL report and change nothing unless `--commit` is given. It moves records
across an app boundary, and an upgrade is not the moment to do that silently:
the operator reads the counts first. For the same reason this SHALL NOT be a
repair step.

Each migrated Decision SHALL carry `externalReference` of `dossiq:<source uuid>`,
and a run SHALL skip every source already stamped. The source UUID is the key
because a besluit is free to carry no slug and no unique title.

The `case` reference SHALL travel through the generic subject block
(`sourceApp`, `subjectRegister`, `subjectSchema`, `subjectId`) and not through a
`case` field. decidiq's Decision has none and is not gaining one: cases and
decisions are already linked, and a second link would compete with the first.

`governingBody` SHALL map to decidiq's `governingBody` and never to `targetBody`.
`targetBody` is the body an appointment is made FOR and is format `uuid`; the
bestuursorgaan that TOOK the decision is a different thing and is not a uuid.

A source field declared `date` whose target declares `date-time` SHALL be widened
to midnight UTC. OpenRegister validates on write, so an unwidened `decisionDate`
does not move the besluit at all.

`decision_schema` SHALL be removed only when every besluit is accounted for.
Detaching while one is behind would point the BRC at decidiq for a record that
never arrived, and it would answer 404 with nothing saying why. The source schema
and its rows SHALL be left in place, so restoring the key reverses the move.

#### Scenario: A dry run writes nothing

- **GIVEN** an instance with besluiten and a local `decision_schema`
- **WHEN** `occ dossiq:migrate-besluiten` runs without `--commit`
- **THEN** it reports the count, writes no Decision and keeps the key.

#### Scenario: A commit moves the besluiten and detaches

- **WHEN** the same command runs with `--commit`
- **THEN** each besluit is written to decidiq's schema with its provenance stamp
- **AND** `decision_schema` is removed.

#### Scenario: A second run does not duplicate

- **GIVEN** besluiten already migrated
- **WHEN** the command runs again with `--commit`
- **THEN** every already-stamped source is skipped and no Decision is written twice.

#### Scenario: A besluit left behind keeps the key

- **GIVEN** one besluit that cannot be written
- **WHEN** the command runs with `--commit`
- **THEN** it is reported as failed and `decision_schema` is kept.

#### Scenario: Without decidiq there is nothing to migrate onto

- **GIVEN** decidiq is not installed
- **WHEN** the command runs
- **THEN** it reports that it is blocked, rather than a successful run of zero.
