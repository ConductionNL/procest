# Tasks — dossiq delivers nothing: extraction of outbound delivery to integriq

## Phase 1: Besluitvorming publication via the ADR-041 seam (this PR)

- [x] Dispatch `OCA\Integriq\Event\DeliveryRequestedEvent` from `PublicationService::publish()`
      (FQN-string `class_exists()` guard; fail-closed refusal recorded when integriq is absent,
      the dispatch throws, the event is unhandled, or zero subscriptions match).
- [x] Record the delivery outcome (`requested` / `unrouted` / `refused` + correlationId, eventId,
      requestedAt) in the publication entry's `delivery` block; never roll back the publication.
- [x] Add `lib/Listener/DeliveryConcludedListener.php`: filter `getSourceApp() === 'dossiq'`,
      terminal statuses only, correlation-id matched, idempotent projection onto the case.
- [x] Register the listener in `ListenerRegistrar` by FQN string, guarded on integriq's event class.
- [x] Declare `publications` + `publishedAt` on the `case` schema in `dossiq_register.json` AND
      `dossiq_mock_register.json` (closes the silent-drop defect: OpenRegister stripped both fields
      on every save since the service shipped).
- [x] Test stubs `tests/Stubs/Integriq/Event/{DeliveryRequestedEvent,DeliveryConcludedEvent}.php`
      mirroring integriq's real constructor signatures verbatim; bootstrap wiring.
- [x] Unit tests: refusal on not-handled, refusal on dispatch-throw, unrouted on zero matches,
      requested with correlationId persisted, terminal projection (delivered + abandoned),
      provenance filter, idempotency, no-match warning path.

## Phase 2: StUF-ZKN outbound re-point — staged

Blocked on: **endpoint + vault credential migration design** (dossiq stores `stufEndpoint` register
objects with `vault://` references resolved via `IAppConfig` keys `stuf.vault.<sha256>`; integriq
sources resolve credentials through the OpenRegister broker (`authentication.credentialRef`) — the
migration needs a repair step mapping one onto the other, and secrets cannot be copied blind).
NOT blocked on wave-3: integriq's StufZkn bridge is seam-based (`StufZknProviderInterface`), not the
legacy runner.

- [ ] Retire the four zero-caller outbound verbs on `StufAdapterService` (`creeerZaak`,
      `actualiseerZaak`, `geefZaakDetails`, `genereerZaakIdentificatie`) — dead capability, no
      caller anywhere in lib/ or src/ (gate-57 shape).
- [ ] Re-point `StufController::outbound()` (`vrijBericht`) at integriq's `stuf_message` intake via
      the delivery seam or `StufZknSyncService`, keeping the admin surface read-only views.
- [ ] Repair step: migrate `stufEndpoint` objects to integriq `source` objects
      (`type=stuf-zkn`) with broker credential refs; keep `zaaksysteemMapping` (case↔extern id) in
      dossiq — it is case data.
- [ ] Delete the outbound half of `lib/Service/Stuf/` (`StufOutboundTransport`, `StufHttpClient`,
      `CircuitBreakerService`, `StufVaultService`, `NeedsInputDispatcher`, `StufRetryJob`) once
      nothing references it; the inbound responder half stays until its own extraction is ruled on.
- [ ] Update `openspec/specs/stuf-zkn-outbound/spec.md`: outbound orchestration, circuit breaker,
      retry and credential requirements move to integriq's `stuf-zkn-bridge` spec (reference, do
      not duplicate).

## Phase 3: Notificaties + webhooks re-point — staged

Blocked on: **integriq subscription provisioning** (the ZGW notificaties fan-out is per-abonnement
callback URLs stored as register objects; re-pointing needs either integriq's `notificaties` action
kind fed per-callback, or a delivery-request per callback — the mapping is a design decision for
the integriq side, tracked in `absorb-dossiq-deliveries` phase 2).

- [ ] Re-point `ZgwService::publishNotification()` through the delivery seam
      (`deliveryKind: 'zgw-notificatie'`); retire `NotificatieService`'s raw Guzzle client and one
      of the two duplicated SSRF CIDR lists.
- [ ] Re-point `Actions/CallWebhookHandler` (slug-resolved webhooks) through the seam
      (`deliveryKind: 'webhook'`); retire `Transitions/WebhookHandler` (inline-URL, no SSRF guard)
      outright — flows configure the URL on the integriq subscription instead.
- [ ] E2E: `tests/e2e/spec-coverage/integrations-and-flows.spec.ts` asserts the webhook action
      vocabulary — update alongside the retirement.

## Phase 4: Dormant/mocked transports — staged

Blocked on: **no production transport exists to move** (Berichtenbox has only `MockAdapter`;
`LogZgwExternalAdapter` returns synthetic `PUSH_DEFERRED`; DSO-LV production needs OAuth2 + OIN
PKIoverheid mTLS that was never built). These are integriq build-out items, not extractions.

- [ ] When a real MijnOverheid transport is commissioned: build it as an integriq provider quintet
      (controller + provider seam + sync service + `*_message` schema + retry job, the
      IwmoIjw/StufZkn pattern); dossiq keeps `BerichtenboxRoutingService` (channel choice is
      domain) and calls through the delivery seam.
- [ ] Also fix en route: `BerichtenboxReadStatusJob` is not registered in `appinfo/info.xml`
      (dead cron today — register it or delete it with the re-point).
- [ ] When cross-municipality ZGW push activates: bind `ZgwExternalAdapterInterface` to an
      integriq source (`zgw-external`) instead of a local HTTP client.
- [ ] DSO-LV: land production auth (OAuth2 + PKIoverheid mTLS) on integriq's `dso-omgevingsloket`
      source config; `DsoLvAuthService` retires.

## Phase 5: Shillinq billing export — staged

Blocked on: **shillinq must define its event contract first** (per ADR-041 the target app defines
the typed event; shillinq is an NC sibling, so integriq HTTP is the wrong seam — this is a
cross-app command to shillinq, mirroring the decidiq `DecisionRequestedEvent` precedent).

- [ ] shillinq defines `InvoiceIngestRequestedEvent` (its repo, its openspec).
- [ ] `ShillinqIntegrationService::exportInvoice()` dispatches it (class-guarded, fail-closed);
      the blocking `sleep()` retry loop and the bearer-token HTTP client retire.
