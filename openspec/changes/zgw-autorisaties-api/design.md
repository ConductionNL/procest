# Design: zgw-autorisaties-api

## Architecture Overview

The AC provides ZGW-standard API client management. It maps the ZGW Applicatie concept to OpenRegister's Consumer entity, allowing external systems to register credentials and manage permissions through the standard ZGW interface.

```
Client                      Procest AC                      OpenRegister
  |                            |                                |
  |-- POST /applicaties ------>| ZgwController::create()        |
  |                            |   |-> ZgwMappingService        |
  |                            |   |   ::mapToConsumer()         |
  |                            |   |-> ConsumerMapper::insert() -|-> openregister_consumers
  |<-- 201 + applicatie -------|                                |
  |                            |                                |
  |-- GET /zaken (+ JWT) ----->| ZgwController::index()         |
  |                            |   |-> ZgwAuthMiddleware         |
  |                            |   |   ::validate(jwt)           |
  |                            |   |   |-> AuthorizationService  |
  |                            |   |   |   ::authorizeJwt()      |
  |                            |   |   |-> checkScopes()         |
  |                            |   |-> ObjectService::find() ----|
  |<-- 200 + zaken ------------|                                |
```

## Applicatie → Consumer Mapping

| ZGW Applicatie Field | OpenRegister Consumer Field | Notes |
|---------------------|---------------------------|-------|
| uuid | uuid | Direct map |
| clientIds | name | Primary client ID used as JWT issuer |
| label | description | Display name |
| heeftAlleAutorisaties | authorization_configuration.superuser | Boolean flag |
| autorisaties | authorization_configuration.scopes | Array of scope grants |

### Autorisatie (scope grant) structure:
```json
{
  "component": "zrc",
  "scopes": ["zaken.lezen", "zaken.aanmaken", "zaken.bijwerken"],
  "zaaktype": "https://host/api/zgw/catalogi/v1/zaaktypen/{uuid}",
  "maxVertrouwelijkheidaanduiding": "vertrouwelijk"
}
```

## ZgwAuthMiddleware

New class: `procest/lib/Middleware/ZgwAuthMiddleware.php`

Registered for all ZGW routes. On each request:
1. Extract `Authorization: Bearer <jwt>` header
2. Call OpenRegister's `AuthorizationService::authorizeJwt()` — validates signature, checks expiry
3. Look up the Consumer's autorisaties from `authorization_configuration.scopes`
4. Check that the requested operation (component + scope) is allowed
5. If `heeftAlleAutorisaties` is true, skip scope check

### Scope mapping:
| ZGW Scope | HTTP Method | Component |
|-----------|-------------|-----------|
| `*.lezen` | GET | zrc, ztc, brc, drc, nrc |
| `*.aanmaken` | POST | zrc, ztc, brc, drc, nrc |
| `*.bijwerken` | PUT, PATCH | zrc, ztc, brc, drc |
| `*.verwijderen` | DELETE | zrc, ztc, brc, drc |

### Component mapping:
| ZGW Component | Procest API Group |
|---------------|-------------------|
| zrc | zaken |
| ztc | catalogi |
| brc | besluiten |
| drc | documenten |
| nrc | notificaties |
| ac | autorisaties |

## Routes

```php
// Autorisaties API (AC)
['name' => 'zgw#index',   'url' => '/api/zgw/autorisaties/v1/{resource}', 'verb' => 'GET'],
['name' => 'zgw#create',  'url' => '/api/zgw/autorisaties/v1/{resource}', 'verb' => 'POST'],
['name' => 'zgw#show',    'url' => '/api/zgw/autorisaties/v1/{resource}/{uuid}', 'verb' => 'GET'],
['name' => 'zgw#update',  'url' => '/api/zgw/autorisaties/v1/{resource}/{uuid}', 'verb' => 'PUT'],
['name' => 'zgw#patch',   'url' => '/api/zgw/autorisaties/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
['name' => 'zgw#destroy', 'url' => '/api/zgw/autorisaties/v1/{resource}/{uuid}', 'verb' => 'DELETE'],
```

## Default Applicatie

Create a default superuser applicatie via repair step for development/testing:
```json
{
  "clientIds": ["procest-admin"],
  "label": "Procest Admin (development)",
  "heeftAlleAutorisaties": true
}
```
