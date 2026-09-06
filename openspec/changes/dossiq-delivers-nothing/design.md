# Design — dossiq-delivers-nothing

## The seam: ADR-041 typed events, integriq as the target

Per ADR-041, a cross-app command travels as a typed `IEventDispatcher` event defined by the
**target** app. Integriq (change `absorb-dossiq-deliveries`) defines:

- `OCA\Integriq\Event\DeliveryRequestedEvent` — provenance (`sourceApp`, `subjectRegister`,
  `subjectSchema`, `subjectId`, `subjectLabel`), `deliveryKind`, `channel`, `payload`,
  `correlationId`, optional `externalReference`/`userId`, and a synchronous result slot
  (`setHandled`/`isHandled`, `setResultId`/`getResultId`, `setMatchedSubscriptions`).
- `OCA\Integriq\Event\DeliveryConcludedEvent` — the async terminal outcome (`delivered` /
  `abandoned`), echoing `sourceApp`, `correlationId`, `subjectId`, `channel`, with `attempts`,
  `error`, `concludedAt`.

Integriq's in-process listener persists the request as a CloudEvent
(`nl.conduction.delivery.requested`) and fans it out to admin-configured `event_subscription`s —
webhook, flow, synchronization or notificaties actions — inheriting retry, backoff, dead-letter,
replay and HMAC signing. This deliberately lands on integriq's event/flow surfaces, NOT on the
wave-3 retirement targets (`SynchronizationService`/`RuleService`/`JobService` are never called
directly).

## Why event-out and not the alternatives

- **Direct POST to integriq's sibling-push controllers** (`stufZkn#outbound` etc.): those routes are
  session-bound; a server-side call from a background flow context carries no user session and 401s
  (the exact phantom ADR-041 documents). The typed event runs in-process in integriq's DI context.
- **An OR flow node owned by integriq** (`SourceCallNode`): right shape for flow-authored
  deliveries, but the publication dispatch fires from a transition handler
  (`BesluitvormingPublishHandler` → `PublicationService`), not from a flow the admin authors. The
  event seam serves both; a flow can still be the *subscription's action* on the integriq side.
- **Wholesale port of transport into dossiq**: forbidden by the ruling this change implements.

## Fail-closed semantics (mutation-tested)

`requestDelivery()` distinguishes four honest outcomes, each recorded on the publication record:

| Outcome | Condition | `delivery.status` |
|---|---|---|
| refused | integriq not installed (`class_exists` false) | `refused` / `integriq_not_installed` |
| refused | dispatch threw | `refused` / `dispatch_failed` |
| refused | event returned unhandled | `refused` / `not_handled` |
| unrouted | handled, `matchedSubscriptions === 0` | `unrouted` |
| requested | handled, ≥1 subscription | `requested` |

None of them blocks the publication itself or the status transition (per the
`besluitvorming-workflow` spec: a failed dispatch must not roll back the status change). The case
never claims a publication travelled unless integriq later concludes `delivered`.

## Status on the case

The publication entry's `delivery` block is the single home:

```json
{
  "channel": "gemeenteblad",
  "publishedAt": "2026-09-02T12:00:00+00:00",
  "notes": null,
  "delivery": {
    "status": "requested | unrouted | refused | delivered | abandoned",
    "correlationId": "…",
    "eventId": "…",
    "matchedSubscriptions": 2,
    "requestedAt": "…",
    "attempts": 3,
    "error": null,
    "concludedAt": "…"
  }
}
```

`DeliveryConcludedListener` matches by `correlationId` (every conclusion originates from a
dispatched request, which always carried one), is idempotent on a
repeated terminal state, and never advances on a non-terminal status. A replayed integriq message
that later succeeds simply supersedes `abandoned` with `delivered` — last terminal state wins,
which matches integriq's replay semantics.

## The schema defect

`PublicationService` writes `publications[]` + `publishedAt`; the `case` schema declared neither, so
OpenRegister dropped both on every save (the store strips what the schema does not declare — the
same measured defect the `commissieBesluit` declaration documents). Declared in
`dossiq_register.json` AND `dossiq_mock_register.json` so the mock cannot diverge. `publications`
items are typed `object` without a closed property list — the delivery block is integriq-reported
data and a closed list would strip future outcome fields (union types are avoided entirely; every
field is single-typed).

## Test seam

`tests/Stubs/Integriq/Event/*` mirror the REAL integriq constructors parameter-for-parameter (a
stub written from the call site would encode the caller's bug), loaded by bootstrap only when the
real classes are absent — the same both-spellings-guarded pattern the decidiq stubs use, minus the
second spelling because this contract never existed under `OCA\OpenConnector`.
