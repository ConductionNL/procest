# stuf-zkn-outbound Specification

## Purpose
TBD - created by archiving change dossiq-stuf-zkn-outbound-gateway. Update Purpose after archive.

@e2e exclude The StUF-ZKN/BG outbound gateway is a SOAP integration engine: envelope construction (Lk01/Lk02/Lv01/Du01), Bv01/La01/Fo02 response parsing, WSSE-from-vault, HTTPS-only + mTLS transport, circuit-breaker thresholds, retry scheduling and contact-betrokkene mapping are all backend behaviours proven by the Vitest-free PHPUnit unit suite (`StufMessageBuilderOutboundTest`, `StufMessageParserTest`, `CircuitBreakerServiceTest`, `ContactBetrokkeneMapperTest`) and, where a live peer is required, by the env-gated live-e2e + Newman jobs that talk to a seeded zaaksysteem. There is no dossiq-only browser surface that drives a real StUF round-trip without an external zaaksysteem endpoint installed; the two admin read-only views (StufEndpoints, StufAuditLog) only render what the backend persists. Mirrors the `zgw-autorisaties-api` (Newman/PHPUnit, no Playwright UI) precedent.

## Requirements
### Requirement: Outbound envelope construction

The system SHALL construct StUF 0310 SOAP 1.1 envelopes for the outbound
message set (Lk01 creeerZaak, Lk02 actualiseerZaak, Lv01 geefZaakDetails, Du01
genereerZaakIdentificatie and Du01 vrijBericht) from a dossiq `case` and the
target endpoint, with a `zkn:`-namespaced stuurgegevens block carrying a fresh
26-character ULID-like referentienummer and a millisecond-precise
Europe/Amsterdam tijdstipBericht. The outbound methods SHALL live on the same
`StufMessageBuilder` as the existing inbound builders, without regressing the
inbound builder behaviour.

#### Scenario: Lk01 carries stuurgegevens, zaaktype and WSSE

- **GIVEN** an endpoint with a `zaaktypeMappings` entry for the case type
- **WHEN** `buildLk01CreeerZaak` is called for that case
- **THEN** the envelope MUST contain `<stuf:berichtcode>Lk01</stuf:berichtcode>`, `<stuf:functie>creeerZaak</stuf:functie>`, the mapped `<zkn:omschrijving>` zaaktype, the `zkn`/`stuf`/`bg`/`wsse` namespace declarations, and a `<wsse:Security>` UsernameToken header

#### Scenario: Unmapped zaaktype is rejected before send

- **WHEN** `buildLk01CreeerZaak` is called for a case type absent from the endpoint's `zaaktypeMappings`
- **THEN** the builder MUST throw `ZaaktypeNotMappedException` before any envelope is produced

### Requirement: Document payload limit

The system SHALL reject an Lk01 build whose attached documents exceed the
configured pre-base64 payload ceiling, and SHALL base64-encode included
documents without line wrapping.

#### Scenario: Oversized payload is rejected

- **WHEN** included documents exceed the configured pre-base64 limit
- **THEN** the builder MUST throw `PayloadTooLargeException`

### Requirement: Secure credential handling

The system SHALL never persist WSSE passwords or mTLS certificate blobs in the
schema; only a vault reference is stored, resolved at send time. Vault-backed
app-config keys MUST stay within Nextcloud's 64-character app-config key limit.

#### Scenario: WSSE password resolved from the vault at send time

- **GIVEN** an endpoint whose `authenticatie.wachtwoordKluisRef` resolves to a stored secret
- **WHEN** an outbound envelope is wrapped
- **THEN** the `<wsse:Password>` MUST contain the resolved secret and the app-config key used to resolve it MUST be at most 64 characters

### Requirement: Secure transport

The system SHALL transport envelopes over HTTPS only, with server certificate
verification always enabled, loading the mTLS client certificate from the vault
reference; if the reference is set but the certificate cannot be loaded the
call MUST fail rather than fall back to anonymous transport.

#### Scenario: Non-HTTPS endpoint is refused

- **WHEN** an endpoint URL does not start with `https://`
- **THEN** the HTTP client MUST refuse to send and return a permanent `TRANSPORT_NON_HTTPS` fout

### Requirement: Response parsing

The system SHALL parse Bv01 bevestigingen (crossRefnummer + optional
server-allocated zaakIdentificatie), La01 antwoorden (geefZaakDetails) and Fo02
foutberichten (code, omschrijving, details, transient/permanent classification)
without resolving external XML entities.

#### Scenario: Bv01 yields crossRefnummer and zaakIdentificatie

- **WHEN** a Bv01 envelope is parsed
- **THEN** the parser MUST return the `crossRefnummer` and the server-allocated `zaakIdentificatie` when present

### Requirement: Synchronous zaak query

The system SHALL support a synchronous Lv01 geefZaakDetails round-trip that
returns the hydrated zaak (identificatie, omschrijving, dates, statussen,
betrokkenen) and raises a timeout when no answer arrives within the deadline.

#### Scenario: geefZaakDetails returns a hydrated zaak

- **WHEN** the zaaksysteem answers an Lv01 with a La01
- **THEN** the adapter MUST return the parsed zaak object

### Requirement: Zaak identificatie allocation

The system SHALL pre-allocate a zaak identificatie via a Du01
genereerZaakIdentificatie round-trip when the endpoint's strategy is `vooraf`,
and persist an anticipatory case→zaak mapping.

#### Scenario: vooraf strategy pre-allocates an id

- **GIVEN** an endpoint whose `zaakIdentificatieStrategie` is `vooraf`
- **WHEN** `creeerZaak` runs
- **THEN** a Du01 genereerZaakIdentificatie MUST be sent before the Lk01

### Requirement: Free message templates

The system SHALL support endpoint-registered free-message (vrijBericht) Du01
templates, validating that the named template exists and that all its mandatory
fields are present before sending.

#### Scenario: Unknown template is rejected

- **WHEN** `vrijBericht` is called with a name not registered on the endpoint
- **THEN** the builder MUST throw `VrijBerichtNotRegisteredException`

### Requirement: Outbound audit log

The system SHALL persist one append-only `stufMessage` row per outbound
envelope (and per inbound reception), recording the full envelope XML, HTTP
status, duration, retry history, lifecycle status, and a generic source-ref
(`sourceEntity`/`sourceId`/`relatedCaseId`) back to the dossiq entity that
triggered it.

#### Scenario: Outbound send is logged

- **WHEN** an outbound envelope is sent
- **THEN** a `stufMessage` row MUST be persisted with `direction=outbound`, the `referenceNumber`, and the source-ref of the originating case

### Requirement: Bidirectional mapping

The system SHALL maintain a `zaaksysteemMapping` linking a dossiq entity
(`sourceEntity` ∈ {case, contact} + `sourceId`, plus `caseId` for case mappings)
to its external zaaksysteem identifier, reused idempotently across retries and
updates.

#### Scenario: Contact mapping is reused on retry

- **GIVEN** a contact already mapped to an external betrokkene for an endpoint
- **WHEN** the mapper resolves the same contact again
- **THEN** it MUST reuse the existing mapping rather than create a duplicate

### Requirement: Circuit breaker and retry

The system SHALL isolate a failing endpoint behind a per-endpoint circuit
breaker that opens after a failure threshold and resets after a cooldown, and
SHALL retry transient failures on an exponential-backoff schedule via an
on-demand background job that reuses the same referentienummer. Circuit-breaker
app-config keys MUST stay within the 64-character limit.

#### Scenario: Threshold failures open the circuit

- **WHEN** an endpoint accrues the failure threshold of consecutive failures
- **THEN** the breaker MUST open, `checkEndpoint` MUST return false, and a needs-input event MUST be raised

#### Scenario: Transient send schedules a retry

- **WHEN** an outbound send fails transiently
- **THEN** a `StufRetryJob` MUST be queued carrying the same `stufMessageId`

### Requirement: Needs-input escalation

The system SHALL escalate non-recoverable conditions (circuit open, permanent
error, timeout) as a structured log line plus a best-effort Nextcloud admin
notification, never throwing from the dispatcher.

#### Scenario: Permanent error notifies admins

- **WHEN** an outbound send fails permanently
- **THEN** a needs-input event MUST be logged and an admin notification attempted

### Requirement: Outbound orchestration

The system SHALL orchestrate each operation end-to-end (circuit check → build →
audit-log → send → parse → status transition → mapping persist → retry/escalate)
behind a single adapter service.

#### Scenario: creeerZaak orchestrates the full round-trip

- **WHEN** `creeerZaak` is called for a reachable endpoint that confirms with a Bv01
- **THEN** the adapter MUST return success, the audit row MUST transition to `bevestigd`, and a case→zaak mapping MUST be persisted

### Requirement: Outbound REST surface

The system SHALL expose admin-gated REST endpoints to list configured endpoints
(with circuit-breaker health), query the audit log, and send a vrijBericht, plus
the existing inbound SOAP reception endpoints.

#### Scenario: Endpoint listing is admin-gated

- **WHEN** an unauthenticated client calls `GET /api/stuf/endpoints`
- **THEN** the request MUST be rejected (not 404), and an authenticated admin MUST receive the endpoint list with a `health` block per endpoint

### Requirement: Async confirmation

The system SHALL accept an asynchronous inbound confirmation at a WSSE-verified
public webhook, persist it as an inbound `stufMessage`, and when it is a Bv01
transition the matching outbound row (by crossRefnummer) to `bevestigd`.

#### Scenario: Bv01 confirms the matching outbound row

- **GIVEN** an outbound row awaiting confirmation
- **WHEN** a WSSE-valid Bv01 arrives whose crossRefnummer matches its referentienummer
- **THEN** the outbound row MUST transition to `bevestigd`

