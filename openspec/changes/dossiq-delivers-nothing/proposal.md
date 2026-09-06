# Proposal: dossiq-delivers-nothing

kind: architecture enforcement / extraction — cites **ADR-041** (cross-app commands via events),
**ADR-022** (apps consume OpenRegister abstractions) and **ADR-012** (deduplication). Coupled to the
integriq change `absorb-dossiq-deliveries`, which ships the receiving half of the seam. Train order:
the integriq PR merges first (it defines `DeliveryRequestedEvent`/`DeliveryConcludedEvent`); this PR
is safe to merge before or after because every integriq lookup is `class_exists()`-guarded and fails
closed.

## Summary

Ruling: **dossiq keeps NO delivery code — integrations belong to integriq.** Dossiq keeps composing
WHAT to deliver (documents, besluit payloads: domain); it loses HOW it travels (transport, retry,
circuit breaking).

An evidence-based audit of every outbound surface in dossiq (2026-09-02, branch `development`
@ `3ba4a285`) found nine delivery-shaped surfaces. This change:

1. **Implements now** the cleanest slice: besluitvorming publication delivery through integriq's
   ADR-041 delivery seam. `PublicationService` composes the publication and dispatches integriq's
   `DeliveryRequestedEvent`; integriq's CloudEvents pipeline owns transport, retry, dead-letter and
   replay; the terminal `DeliveryConcludedEvent` is projected back onto the case's publication
   record by a new `DeliveryConcludedListener`, so **the delivery status is visible as case data**.
   Fail-closed: integriq absent, unhandled, or unrouted is recorded as a refusal on the publication
   record — never as a delivery.
2. **Fixes a silent-drop defect the audit exposed**: `PublicationService` has written
   `publications[]` + `publishedAt` onto the case since it shipped, but the `case` schema declared
   neither — OpenRegister strips what the schema does not declare, so no publication record ever
   persisted. Both properties are now declared in `dossiq_register.json` and
   `dossiq_mock_register.json`.
3. **Stages the remaining extractions** as tasks with honest blockers (see tasks.md phases 2-5).

## The delivery inventory and per-item disposition

| # | Surface | Evidence | Disposition |
|---|---|---|---|
| 1 | Besluitvorming publication (`PublicationService`, channels gemeenteblad/website/open_raadsinformatie/pdc) | Record-writing only; docblock says cross-app publication "handled by openconnector wiring (out of scope)" — i.e. it never travelled | **Implemented now**: ADR-041 event seam to integriq (this change) |
| 2 | StUF-ZKN outbound gateway (`lib/Service/Stuf/` outbound half: `StufOutboundTransport`, `StufHttpClient`, `StufAdapterService`, `CircuitBreakerService`, `StufVaultService`, `StufRetryJob`; ~2.5k lines) | Complete and largely unreachable: `creeerZaak`/`actualiseerZaak`/`geefZaakDetails`/`genereerZaakIdentificatie` have zero production callers; only `vrijBericht` (admin REST) and `retrySend` (retry job) are live | **Staged re-point** (phase 2): integriq already owns a seam-based StUF-ZKN bridge (`StufZknProviderInterface`, `StufZknClient`, `StufZknSyncService`, `stuf_message` schema, hourly `StufZknRetryJob`, and `stufZkn#outbound` documented as the sibling-app push). Re-point `vrijBericht`, retire the zero-caller verbs, migrate endpoint + vault config to integriq sources. NOT blocked on wave-3: the StufZkn bridge is seam-based, not the legacy runner |
| 3 | ZGW Notificaties fan-out (`NotificatieService`: raw Guzzle POST to subscriber callbacks, no retry, no breaker) | Live: called from Zrc/Brc/Drc controllers via `ZgwService::publishNotification()` | **Staged re-point** (phase 3): integriq has a `notificaties` action kind (`dispatchNotificationsAction`) and the `notificaties-api-connector` spec. Re-pointing gains retry + dead-letter the current code never had. Also removes the duplicated SSRF CIDR list kept in sync by a comment |
| 4 | Generic webhooks out (`Transitions/WebhookHandler` inline-URL 5s, `Actions/CallWebhookHandler` slug-resolved 10s; both no retry) | Live transition/flow actions | **Staged re-point** (phase 3): same `DeliveryRequestedEvent` seam; integriq subscriptions deliver with HMAC signing, retry, dead-letter. The inline-URL variant (no SSRF guard) retires outright |
| 5 | Berichtenbox (`BerichtenboxService` + `MockAdapter` only; `BerichtenboxReadStatusJob` not registered in info.xml — dead cron) | No real transport exists | **Staged** (phase 4): the real MijnOverheid transport lands as an integriq provider quintet when built; dossiq keeps `BerichtenboxRoutingService` (channel choice is domain). Nothing to move today — there is no transport |
| 6 | Shillinq invoice export (`ShillinqIntegrationService::exportInvoice`, blocking `sleep()` retry) | Live from `TenantBillingService` | **Staged** (phase 5): shillinq is an NC sibling, so per ADR-041 this becomes a typed event **to shillinq**, not integriq HTTP. The blocking sleep retires with it |
| 7 | Cross-municipality ZGW push (`External/Zgw/LogZgwExternalAdapter` — dormant, synthetic `PUSH_DEFERRED`) | Zero production callers; production binding was always intended as an openconnector source | **Staged** (phase 4): bind to an integriq source when the feature activates; the dormant seam stays |
| 8 | Email via `IMailer` (`CaseEmailService`, `TenantWelcomeMailer`) | NC platform mailer | **Keep, with rationale**: the Nextcloud mailer is platform infrastructure, not an integration transport — every NC app sends mail this way. `EmailPdfRetryJob` retries PDF *archival conversion* (its adapter is not wired — separate defect), not transport |
| 9 | WOO publication → OpenCatalogi (`WooPublicationService`) | In-process OR object writes per ADR-080/gate-62, one catalog-listing read | **Keep**: in-process object writes are the sanctioned pattern, not transport |

Confirmed negatives: **no IWMO/iJW code exists in dossiq** (every grep hit was Dutch `bijwerken`;
integriq already owns the `iwmo-ijw-adapter`), and **no DROP/LVBB transport exists** (surface 1 is
the whole besluitvorming publication story). DSO is auth-header-only (`DsoLvAuthService`), staged
with phase 4 against integriq's `dso-omgevingsloket` spec.

## Why

Every delivery dossiq "has" is either unreachable (StUF case sync), mocked (Berichtenbox), retry-less
(notificaties, webhooks), or a silent no-op (publications dropped by the schema). Integriq already
operates the machinery each of them needs — sources with broker-resolved credentials, per-source
retry policies and circuit breakers, the CloudEvents pipeline with dead-letter + replay, seam-based
StUF-ZKN and IwmoIjw bridges with hourly retry sweeps. Duplicating any of it in a case app violates
ADR-022/ADR-012 and leaves dossiq operating transport it cannot see or replay.

## What (phase 1, this PR)

1. `PublicationService` dispatches `OCA\Integriq\Event\DeliveryRequestedEvent`
   (`class_exists()`-guarded by FQN string) after upserting the publication record, and records the
   outcome — `requested` / `unrouted` / `refused` — with `correlationId`, `eventId` and
   `requestedAt` in the publication's `delivery` block. A delivery failure never rolls back the
   publication (mirrors the `BesluitvormingPublishHandler` contract).
2. New `lib/Listener/DeliveryConcludedListener.php` projects integriq's terminal
   `DeliveryConcludedEvent` (`delivered` / `abandoned`) onto the matching publication's `delivery`
   block — filtered to `getSourceApp() === 'dossiq'`, idempotent, correlation-id matched.
   Registered in `ListenerRegistrar` by FQN string, only when integriq is installed.
3. The `case` schema declares `publications` (array) and `publishedAt` (date-time) in both register
   files, closing the silent-drop defect.
4. Test stubs `tests/Stubs/Integriq/Event/*` mirror integriq's real constructor signatures verbatim
   (bootstrap-loaded only when the real classes are absent), plus unit coverage for the fail-closed
   refusal paths and the terminal projection.

## Non-goals

- Moving the StUF stack in this PR (phase 2 — the move is mechanical but large, and endpoint/vault
  config migration deserves its own review).
- Building a real DROP/LVBB or Berichtenbox transport (integriq work, on demand).
- Changing the WOO/OpenCatalogi or email surfaces (keep, see dispositions).
