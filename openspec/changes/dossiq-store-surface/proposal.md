# Dossiq gets the store the fleet already built

## Why

OpenRegister owns store discovery. ADR-080 decided that, the AppHost engine
shipped it as `GenericStoreService` + `StoreDescriptor`, and openbuild proved
the contract by deleting its own 331-line proxy and injecting the engine's.

Dossiq never adopted it. An administrator who wants a case type, an enforcement
decision table or an approval route has exactly one way to get one: build it by
hand, or receive a ZIP from somebody and import it through
`CaseDefinitionImportService`. Every municipality that runs dossiq builds the
same handful of case types independently, and none of them can publish one.

That is what a store is for, and dossiq is the app in the fleet with the most
configuration worth sharing.

## What changes

A `Store` surface backed by the engine's discovery client:

- `StoreDescriptor` naming `case-type-template` objects in the configured
  remote register, with a `kind` discriminator per ADR-080 Decision 5.
- `StoreController::search()` — a thin action over `GenericStoreService`.
  Roughly thirty lines, by composition, per Decision 3.
- `StoreController::install()` — dossiq's own, because install does not
  generalise. It writes each component through `ConfiguredRegistryService`,
  the same seam the admin settings tabs already write through.
- Registry connection settings, with the token write-only.

## The refusal that matters

**Install accepts configuration schemas and refuses record schemas.** A store
item may carry a `caseType`, a `statusType`, a `workflowTemplate`, an
`lhsMatrix` or a `parafeerroute`. It may not carry a `case`, a `task`, a
`decision` or an `objection`.

Without that line the install path is a remote write primitive: a registry, or
anyone who can answer as one, could push objects into a municipality's live case
records through a button labelled "Install". The allowlist is not a convenience,
it is the boundary between installing configuration and accepting data.

## What this change does NOT do

- It does not publish. Dossiq consumes a registry; it does not become one.
- It does not touch `CaseDefinitionImportService`. The ZIP path stays exactly
  as it is; the store is a second, additive way in.
- It does not invent a registry. With none configured the page renders the
  app's built-in templates and makes **no network call**, which is ADR-080
  Decision 4 and the reason a local-only grid may not be labelled "Store".

## Affected Projects

- [x] Project: `dossiq` — this change.
- [ ] Project: `openregister` — nothing asked. `GenericStoreService`,
  `StoreDescriptor` and the SSRF-guarded fetch already exist.

## Follow-ups this exposes, not fixed here

- hermiq and integriq both label a local card grid "Store" and neither injects
  `GenericStoreService`. That is the ADR-080 Decision 4 violation the ADR was
  written about, and it is theirs to fix.
- ADR-080 records no placement decision, so "Store" sits in the main section in
  those three apps and in the footer here. One of the two is wrong.
