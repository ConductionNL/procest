# dossiq-store-surface Specification

## Purpose

Dossiq browses a remote OpenRegister registry for shareable case configuration
and installs it locally, using the engine's discovery client rather than one of
its own.

## ADDED Requirements

### Requirement: REQ-DSS-001 Discovery goes through the engine

The system SHALL reach the remote registry only through OpenRegister's
`GenericStoreService`, injected as a constructor type-hint.

Dossiq SHALL NOT build or fetch an `/apps/openregister/api/objects/` URL of its
own. The SSRF guard, the redirect refusal, the timeout bounds and the token
handling exist once, in the app that owns the protocol.

The dependency SHALL be composition, never `extends`. A cross-app base class is
resolved by the autoloader rather than the container, and Nextcloud's router
reflects every controller during route matching, so an absent OpenRegister
would return 500 on every route in this app rather than only the store's.

@e2e exclude Structural, not behavioural. That the controller INJECTS GenericStoreService rather than extending it, and that no file builds an OpenRegister objects-API URL, are properties of the source tree: proven by StoreControllerTest (which asserts the descriptor the engine is called with) and by gate-62's Decision-3 scan. A browser cannot see which class a response came from.

#### Scenario: The controller injects the engine client

- **GIVEN** `StoreController`
- **THEN** it MUST take `GenericStoreService` as a constructor parameter
- **AND** it MUST NOT extend any class from the `OCA\OpenRegister` namespace

#### Scenario: No app-local discovery

- **GIVEN** dossiq's `lib/` tree
- **THEN** no file MUST both name the OpenRegister objects-API path and use an
  HTTP client to fetch it

### Requirement: REQ-DSS-002 No registry means no network call

With no `registry_url` configured, the store surface SHALL report
`not_configured`, render the app's built-in templates, and make no outbound
request.

A local-only card grid is a Templates page. It may only be labelled "Store"
because the registry path exists and is merely unconfigured.

#### Scenario: An unconfigured instance stays offline

- **GIVEN** an instance with no `registry_url` set
- **WHEN** the store surface is opened
- **THEN** the response outcome MUST be `not_configured`
- **AND** no outbound HTTP request MUST be made

#### Scenario: The page still renders

- **GIVEN** the same unconfigured instance
- **THEN** the Store page MUST render dossiq's built-in templates rather than
  an error

### Requirement: REQ-DSS-003 Install accepts configuration and refuses records

The install action SHALL write only components whose schema slug is on the
configuration allowlist: `caseType`, `statusType`, `resultType`, `roleType`,
`propertyDefinition`, `documentType`, `decisionType`, `workflowTemplate`,
`inspectionChecklistTemplate`, `automaticAction`, `lhsMatrix`, `parafeerroute`.

A component naming any other schema SHALL be refused and reported, and the
remaining components SHALL still install.

Without this the install path is a remote write primitive against live case
records. The allowlist is the boundary between installing configuration and
accepting data.

@e2e exclude The install allowlist is a server-side boundary. Proven by StoreControllerTest with a negative control: widening INSTALLABLE_SLUGS to include `case` and `task` makes the refusal and mixed-item tests fail, so the assertions are known to bind. A browser can see that an install reported a refusal but not which schema a write would have gone to, which is the property that matters.

#### Scenario: A configuration component installs

- **GIVEN** a store item carrying one `caseType` component
- **WHEN** it is installed
- **THEN** the case type MUST be written through the app's configured registry
- **AND** the result MUST report that component as installed

#### Scenario: A record component is refused

- **GIVEN** a store item carrying one `case` component
- **WHEN** it is installed
- **THEN** nothing MUST be written for that component
- **AND** the result MUST name it as refused

#### Scenario: A mixed item installs the half it may

- **GIVEN** a store item carrying a `caseType` and a `task`
- **WHEN** it is installed
- **THEN** the case type MUST be written and the task MUST be refused

### Requirement: REQ-DSS-004 The registry token is write-only

Reading the store settings SHALL expose whether a token is set, never its value.

Saving an empty token SHALL leave the stored token unchanged, so an
administrator editing the registry URL does not silently clear the credential.

@e2e exclude The token never reaches the browser, which is the whole requirement, so the browser is the one place it cannot be observed. Proven by StoreControllerTest over the serialised settings body (a leak under a different key would pass a per-key check) plus a positive control that a supplied token IS written.

#### Scenario: A read never returns the token

- **GIVEN** a configured registry with a token
- **WHEN** the store settings are read
- **THEN** the response MUST carry `registryTokenSet: true`
- **AND** the response MUST NOT carry the token value under any key

#### Scenario: An empty token preserves the stored one

- **GIVEN** a stored token
- **WHEN** the settings are saved with an empty token field
- **THEN** the stored token MUST be unchanged

### Requirement: REQ-DSS-005 Browsing is authenticated, installing is administrative

Search SHALL require an authenticated user. Install and the store settings
SHALL require administrator authorization.

Installing writes case-type configuration that every handler then works
against, which is an administrative act even though browsing is not.

@e2e exclude Auth posture is enforced by Nextcloud middleware from the controller attributes. The anonymous-401 path is proven by StoreControllerTest; the admin guard is the `AuthorizedAdminSetting` attribute, which gate-5 and gate-7 read statically. Driving it in a browser would need a second, non-admin fixture user this suite does not provision.

#### Scenario: An anonymous caller cannot search

- **GIVEN** no signed-in user
- **WHEN** search is called
- **THEN** the response status MUST be 401

#### Scenario: Install is admin-guarded

- **GIVEN** `StoreController::install()`
- **THEN** it MUST declare an administrator authorization attribute

### Requirement: REQ-DSS-006 The menu entry names the concept

The store menu entry SHALL be labelled `Store` and render the `StoreOutline`
icon, in the footer section between Documentation and Reports.

#### Scenario: The entry carries the Tier A glyph

- **GIVEN** the dossiq manifest
- **THEN** the entry labelled `Store` MUST declare `icon: "StoreOutline"`
- **AND** it MUST sit in the `footer` section with an order between
  Documentation and Reports

### Requirement: REQ-DSS-007 An install creates, and can never replace

The install action SHALL strip every identity key the remote payload carries
(`id`, `uuid`, `@self`) before writing, so an installed component is always a
NEW local object.

OpenRegister resolves the object it writes from the payload itself: `saveObject`
reads `@self.id` first and `id` second, and treats a match as the uuid to
UPDATE. The write is PUT-semantic, so keys the payload omits are nulled rather
than left alone. A store item carrying the uuid of a live case type would
therefore not merely change it, it would gut it.

The schema allowlist does NOT cover this. It governs which schema a component
may write, never whether the write creates or replaces, so a component naming a
perfectly legitimate configuration schema is the attack.

Identity is not a remote registry's to supply. If install ever needs to be
idempotent it SHALL key on something dossiq controls.

@e2e exclude Server-side, and the property is the ABSENCE of an addressed object. A browser sees an install succeed either way; only the payload handed to the write reveals which object it addressed. Proven by StoreControllerTest with a negative control: removing the strip makes the assertion fail.

#### Scenario: A component carrying an id installs as a new object

- **GIVEN** a store item whose component object carries `id`, `uuid` and `@self`
- **WHEN** it is installed
- **THEN** none of those keys MUST reach the write
- **AND** the rest of the component MUST still install
