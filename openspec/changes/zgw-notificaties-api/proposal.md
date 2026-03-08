# Proposal: zgw-notificaties-api

## Summary
Add ZGW Notificaties API (NRC) support to Procest, enabling webhook-based notifications when ZGW resources change. External systems can subscribe to channels and receive callbacks when cases, documents, or decisions are created/updated/deleted.

## Motivation
The ZGW Notificaties API enables event-driven integration between ZGW components. When a zaak status changes or a document is added, subscribed systems receive a notification. This is essential for:
- Workflow automation (n8n triggers on zaak events)
- Inter-system synchronization (other ZGW providers consuming Procest events)
- VNG ZGW compliance (the Notificaties API is part of the standard test suite)

## Affected Projects
- [x] Project: `procest` — New ZGW NRC endpoints + event publishing
- [ ] Reference: `openregister` — Object change events trigger notifications

## Scope
### In Scope
- **Kanaal** — Notification channels (one per resource type: zaken, documenten, besluiten, etc.)
  - `GET/POST /api/zgw/notificaties/v1/kanaal`
  - `GET /api/zgw/notificaties/v1/kanaal/{uuid}`
- **Abonnement** — Subscriptions to channels with callback URLs and filters
  - `GET/POST /api/zgw/notificaties/v1/abonnement`
  - `GET/PUT/PATCH/DELETE /api/zgw/notificaties/v1/abonnement/{uuid}`
- **Notificatie** — Publishing notifications (POST by the system when events occur)
  - `POST /api/zgw/notificaties/v1/notificaties`
- **Event publishing**: When ZGW resources change (create/update/delete), publish notifications to all matching subscribers
- **Callback delivery**: POST notification payloads to subscriber callback URLs
- **Auth header forwarding**: Include subscriber's configured auth header in callbacks
- **ZGW notification format**: `{ kanaal, hoofdObject, resource, resourceUrl, actie, aanmaakdatum, kenmerken }`

### Out of Scope
- Guaranteed delivery / retry mechanism (future enhancement)
- Notification history / audit log UI
- WebSocket/SSE real-time push (ZGW standard uses webhook callbacks)

## Approach
1. Create OpenRegister schemas for Kanaal and Abonnement in `procest_register.json`
2. Create ZGW mapping configurations for NRC resources
3. Extend `ZgwController` to handle the `notificaties` API group
4. Create `NotificatieService` that:
   - Maintains a registry of channels and subscriptions
   - Publishes notifications when ZGW resources change
   - Delivers callbacks to subscriber URLs via HTTP POST
5. Hook into existing ZGW create/update/delete flows to trigger notifications
6. Register routes in `routes.php`

## Cross-Project Dependencies
- Depends on existing ZGW mapping infrastructure in Procest
- Event triggers come from ZRC, DRC, BRC, ZTC operations (all must be implemented)
- Uses n8n or Guzzle for outbound HTTP callbacks

## Rollback Strategy
Remove NRC routes, mapping configs, schemas, and NotificatieService. No changes to existing ZGW functionality — notification hooks are additive.
