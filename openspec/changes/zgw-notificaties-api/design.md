# Design: zgw-notificaties-api

## Architecture Overview

The NRC provides a pub/sub mechanism for ZGW events. Channels (kanalen) represent resource types, subscriptions (abonnementen) register callback URLs, and notifications are delivered via HTTP POST when resources change.

```
ZGW Client                  Procest NRC                     Subscriber
  |                            |                                |
  |-- POST /abonnement ------->| (register callback URL)        |
  |<-- 201 + subscription -----|                                |
  |                            |                                |
  |-- POST /zaken/v1/zaken --> | ZgwController::create()        |
  |   (creates a zaak)        |   |-> NotificatieService        |
  |<-- 201 + zaak ----------- |   |   ::publish('zaken', ...)   |
  |                            |   |     |-> POST callback ---->|
  |                            |   |     |<-- 200 -------------|
```

## Resource Schemas (OpenRegister)

### Kanaal
| Field | Type | ZGW Name | Description |
|-------|------|----------|-------------|
| naam | string | naam | Channel name (e.g., 'zaken', 'documenten') |
| documentatieLink | uri | documentatieLink | URL to API docs |
| filters | array | filters | Available filter attributes |

### Abonnement
| Field | Type | ZGW Name | Description |
|-------|------|----------|-------------|
| callbackUrl | uri | callbackUrl | URL to POST notifications to |
| auth | string | auth | Authorization header value for callbacks |
| kanalen | array | kanalen | Array of { naam, filters } |

## NotificatieService

New service class: `procest/lib/Service/NotificatieService.php`

```php
class NotificatieService {
    // Publish a notification for a ZGW resource change
    public function publish(string $kanaal, string $hoofdObject, string $resource,
                           string $resourceUrl, string $actie, array $kenmerken = []): void;

    // Find matching subscriptions and deliver callbacks
    private function deliver(array $notification): void;
}
```

**Notification payload format:**
```json
{
  "kanaal": "zaken",
  "hoofdObject": "https://host/api/zgw/zaken/v1/zaken/{uuid}",
  "resource": "zaak",
  "resourceUrl": "https://host/api/zgw/zaken/v1/zaken/{uuid}",
  "actie": "create",
  "aanmaakdatum": "2026-03-07T10:00:00Z",
  "kenmerken": {
    "bronorganisatie": "123456789",
    "zaaktype": "https://host/api/zgw/catalogi/v1/zaaktypen/{uuid}"
  }
}
```

## Integration with Existing ZGW Flows

Hook into `ZgwController` create/update/delete methods:
```php
// After successful create/update/delete in ZgwController:
$this->notificatieService->publish(
    kanaal: $zgwApi,          // 'zaken', 'documenten', 'besluiten'
    hoofdObject: $objectUrl,
    resource: $resource,       // 'zaak', 'status', 'besluit', etc.
    resourceUrl: $resourceUrl,
    actie: $actie              // 'create', 'update', 'destroy'
);
```

## Default Channels

Pre-register channels via repair step:
- `zaken` — filters: bronorganisatie, zaaktype, vertrouwelijkheidaanduiding
- `documenten` — filters: bronorganisatie, informatieobjecttype, vertrouwelijkheidaanduiding
- `besluiten` — filters: verantwoordelijkeOrganisatie, besluittype
- `catalogi` — filters: (none)
- `autorisaties` — filters: (none)

## Routes

```php
// Notificaties API (NRC)
['name' => 'zgw#index',   'url' => '/api/zgw/notificaties/v1/{resource}', 'verb' => 'GET'],
['name' => 'zgw#create',  'url' => '/api/zgw/notificaties/v1/{resource}', 'verb' => 'POST'],
['name' => 'zgw#show',    'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}', 'verb' => 'GET'],
['name' => 'zgw#update',  'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}', 'verb' => 'PUT'],
['name' => 'zgw#patch',   'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
['name' => 'zgw#destroy', 'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}', 'verb' => 'DELETE'],
```
