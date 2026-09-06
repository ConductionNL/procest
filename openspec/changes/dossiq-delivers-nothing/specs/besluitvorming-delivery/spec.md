# besluitvorming-delivery Specification

**Status:** proposed
**Scope:** dossiq
**Tier:** V1
**Depends on:** integriq delivery-seam contract (`OCA\Integriq\Event\DeliveryRequestedEvent` /
`OCA\Integriq\Event\DeliveryConcludedEvent`, integriq change `absorb-dossiq-deliveries`), Nextcloud
`OCP\EventDispatcher\IEventDispatcher`.

## Purpose

Dossiq keeps no delivery code: it composes WHAT a besluit publication contains (domain) and hands
HOW it travels (transport, retry, circuit breaking, dead-lettering) to integriq through the ADR-041
typed-event seam. The delivery status of every publication is visible as case data.

@e2e exclude The delivery seam is a backend-only in-process event exchange between two apps' PHP
containers with no dossiq browser surface: the case detail renders whatever the publication record
holds, and the seam itself is proven by the PHPUnit suites on both sides (dossiq
PublicationServiceTest + DeliveryConcludedListenerTest, integriq EventServiceDeliverySeamTest +
DeliveryRequestedListenerTest). Mirrors the `stuf-zkn-outbound` no-browser-surface precedent.

## ADDED Requirements

### Requirement: Publication delivery is requested through integriq

`PublicationService::publish()` SHALL, after upserting the publication record, dispatch
`OCA\Integriq\Event\DeliveryRequestedEvent` via `IEventDispatcher::dispatchTyped()` with
`sourceApp = 'dossiq'`, `deliveryKind = 'besluit-publication'`, the requested channel, a
caller-generated `correlationId`, and a payload composed from the case (caseId, channel,
publishedAt, notes, besluitDocument, identifier). The event class SHALL be resolved by FQN string
behind a `class_exists()` guard so dossiq stays installable without integriq. Dossiq SHALL NOT
perform the outbound transport itself.

#### Scenario: A routed delivery is recorded as requested

- **GIVEN** integriq is installed and its listener handles the event with ≥1 matched subscription
- **WHEN** `publish()` runs for a valid channel
- **THEN** the publication entry MUST carry `delivery.status = 'requested'` with the integriq event
  uuid, the correlation id and `requestedAt`, and the publication MUST persist

### Requirement: Delivery refusals fail closed on the case record

When integriq is not installed, the dispatch throws, the event returns unhandled, or zero
subscriptions match, `publish()` SHALL record the outcome on the publication entry
(`delivery.status = 'refused'` with a reason, or `'unrouted'`) and SHALL NOT report the publication
as travelling. A delivery refusal SHALL NOT roll back the publication record or the status
transition that triggered it.

#### Scenario: Integriq absent records a refusal

- **GIVEN** the integriq event class does not exist
- **WHEN** `publish()` runs
- **THEN** the publication persists with `delivery.status = 'refused'` and
  `delivery.reason = 'integriq_not_installed'`

#### Scenario: No configured route records unrouted

- **GIVEN** integriq handles the event but reports zero matched subscriptions
- **WHEN** `publish()` runs
- **THEN** the publication persists with `delivery.status = 'unrouted'` and the integriq event uuid

### Requirement: The terminal delivery outcome is projected onto the case

A `DeliveryConcludedListener` SHALL consume integriq's `DeliveryConcludedEvent`, filtered to
`getSourceApp() === 'dossiq'` and terminal statuses (`delivered`, `abandoned`) only, match the
publication by `correlationId`, and idempotently write `status`, `attempts`, `error` and
`concludedAt` into the publication's `delivery` block. The listener SHALL be registered by FQN
string only when integriq is installed, and a projection failure SHALL never propagate into
integriq's delivery bookkeeping.

#### Scenario: Delivered conclusion lands on the publication

- **GIVEN** a case publication carrying `delivery.correlationId = 'X'` with status `requested`
- **WHEN** integriq concludes correlation `X` as `delivered`
- **THEN** the publication's `delivery.status` MUST become `delivered` with attempts and
  `concludedAt` recorded

#### Scenario: Non-terminal and foreign conclusions are ignored

- **WHEN** a conclusion arrives with a non-terminal status, or with `sourceApp` of another app
- **THEN** the case MUST NOT be written

### Requirement: The case schema declares what the publication writer persists

The `case` schema SHALL declare `publications` (array of objects) and `publishedAt` (date-time) in
both `dossiq_register.json` and `dossiq_mock_register.json`, because OpenRegister strips undeclared
properties on save — leaving them undeclared silently discards every publication and delivery
record.

#### Scenario: Publication survives a save round-trip

- **WHEN** `publish()` saves the case
- **THEN** the persisted case MUST still carry the `publications` array and `publishedAt` after the
  store applies schema validation
