# Proposal: zgw-autorisaties-api

## Summary
Add ZGW Autorisaties API (AC) support to Procest, enabling API client registration and authorization management. This bridges OpenRegister's new auth system (migrated from OpenConnector) with the ZGW standard interface for managing applicaties and their permissions.

## Motivation
The ZGW Autorisaties API is the standard way to:
- Register API clients (applicaties) with their credentials
- Define which ZGW APIs and scopes each client can access
- Generate and validate JWT tokens for API authentication

Both Postman test collections require working AC endpoints — the OAS tests create test applicaties, and the business rules tests validate authorization enforcement. Without AC support, no ZGW test suite can run.

## Affected Projects
- [x] Project: `procest` — New ZGW AC endpoints
- [x] Dependency: `openregister` — Requires the migrated auth system (Consumer entity, AuthorizationService)

## Scope
### In Scope
- **Applicatie** — API client registration (maps to OpenRegister's Consumer entity)
  - `GET/POST /api/zgw/autorisaties/v1/applicaties`
  - `GET/PUT/PATCH/DELETE /api/zgw/autorisaties/v1/applicaties/{uuid}`
- **Autorisatie** — Permission grants per applicatie (which APIs, which scopes)
  - Inline in Applicatie resource as `autorisaties` array
  - Each autorisatie defines: `component`, `scopes`, `zaaktype`, `maxVertrouwelijkheidaanduiding`
- **JWT token validation**: Incoming requests with `Authorization: Bearer <jwt>` are validated against registered applicaties
- **ZGW JWT format**: Standard ZGW JWT claims (`iss`, `iat`, `client_id`, `user_id`, `user_representation`)
- **Mapping to OpenRegister Consumers**: Applicatie CRUD operations map to Consumer CRUD in OpenRegister
- **Scope enforcement**: Check that the authenticated applicatie has permission for the requested ZGW operation

### Out of Scope
- OAuth2 authorization code flow (ZGW uses pre-shared secret JWT)
- Fine-grained field-level permissions
- Consent management UI

## Approach
1. Create ZGW mapping configuration for Applicatie → Consumer entity in OpenRegister
2. Extend `ZgwController` to handle the `autorisaties` API group
3. Create `ZgwAuthService` in Procest that:
   - Translates ZGW Applicatie format to/from OpenRegister Consumer format
   - Validates incoming JWT tokens using OpenRegister's AuthorizationService
   - Checks scopes against the applicatie's autorisaties
4. Add middleware to all ZGW endpoints that validates JWT and checks permissions
5. Register routes in `routes.php`

## Cross-Project Dependencies
- **Hard dependency** on `openregister/migrate-auth-system` — Consumer entity and AuthorizationService must exist
- All other ZGW APIs (ZRC, ZTC, BRC, DRC, NRC) use this for authentication

## Rollback Strategy
Remove AC routes, mapping configs, and ZgwAuthService. ZGW endpoints revert to unauthenticated access (current state).
