# The Besluit resolves to decidiq's Decision

## Why

Two apps declared a `decision`. A schema slug is global per organisation and
`SchemaMapper::find()` matches `LOWER(slug)`, so whichever row was reached first
answered for both.

decidiq's is the governance decision (48 properties: motion, voting, adoption,
repeals). This app's is the VNG **Besluit** behind the BRC — 12 properties,
wired into `BrcController`, ZRC, WOO assessments and the bezwaar lifecycle.

decidiq's Decision now carries the four BRC fields it lacked (decidiq#1161), so
it can hold the record.

## What changes

`decision_schema` falls back to decidiq's `decision` schema when this app has no
value of its own.

`BrcController` stays here. The standard belongs where it is served from; only
the schema it reads moves.

The lookup uses the `(application, slug)` PAIR, never the slug alone — slug
alone is exactly the ambiguity this exists to end, since it matches this app's
own row as readily as decidiq's.

## The ordering is the change

It resolves **last**, only when nothing local answered.

Preferring decidiq unconditionally was the obvious shape and the wrong one:
every existing instance HAS `decision_schema` set, and its besluiten are in the
schema that key names. Pointing them at decidiq's schema would have made the BRC
answer 404 for every besluit it holds, with nothing saying why.

Resolving last needs no operator timing. A configured instance keeps its own; a
fresh install has no key and lands on decidiq's; and a migrated one lands there
too, once its own schema is retired.

## What this does NOT do

It does not retire this app's `decision` schema, and it does not migrate a single
besluit. On every existing instance the fallback is inert, because the key is
set. That is deliberate: moving the rows is an operator action with a decision
attached (what happens to besluiten whose `case` link has no counterpart on
decidiq's Decision, since `case` was deliberately not added there), and it
belongs in its own change with its own command.
