# Spec: ZGW Notificaties API (NRC)

## ZGW Standard References

### Official Documentation
- **Standard overview**: https://vng-realisatie.github.io/gemma-zaken/standaard/notificaties/
- **Developer guide**: https://vng-realisatie.github.io/gemma-zaken/ontwikkelaars/

### OpenAPI Specifications
- **Provider OAS (gemma-zaken canonical)**: [api-specificatie/nrc/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/nrc/openapi.yaml)
  - Raw: https://raw.githubusercontent.com/VNG-Realisatie/gemma-zaken/master/api-specificatie/nrc/openapi.yaml
- **Consumer OAS**: [api-specificatie/nrc/consumer-api/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/nrc/consumer-api/openapi.yaml)
  - Raw: https://raw.githubusercontent.com/VNG-Realisatie/gemma-zaken/master/api-specificatie/nrc/consumer-api/openapi.yaml
- **Reference implementation OAS**: [src/openapi.yaml](https://github.com/VNG-Realisatie/notificaties-api/blob/master/src/openapi.yaml)
  - Raw: https://raw.githubusercontent.com/VNG-Realisatie/notificaties-api/master/src/openapi.yaml

### Source Documentation (Markdown)
- **Standard page**: [docs/standaard/notificaties/index.md](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/docs/standaard/notificaties/index.md)
- **Authorization spec**: [src/autorisaties.md](https://github.com/VNG-Realisatie/notificaties-api/blob/master/src/autorisaties.md)
- **Notification spec**: [src/notificaties.md](https://github.com/VNG-Realisatie/notificaties-api/blob/master/src/notificaties.md)

### Note on Versioning
The NRC in gemma-zaken does NOT have versioned subdirectories — the spec files sit directly in the `nrc/` folder.

## Requirements

### Requirement: NRC Resource Coverage
The implementation MUST support these resources:
- **Kanaal** — Notification channels (one per resource type)
- **Abonnement** — Subscriptions with callback URLs and filters
- **Notificatie** — Published event notifications

### Requirement: Notification Payload Format
```json
{
  "kanaal": "zaken",
  "hoofdObject": "https://host/api/zgw/zaken/v1/zaken/{uuid}",
  "resource": "zaak",
  "resourceUrl": "https://host/api/zgw/zaken/v1/zaken/{uuid}",
  "actie": "create",
  "aanmaakdatum": "2026-03-07T10:00:00Z",
  "kenmerken": {}
}
```

### Requirement: Callback Delivery
- Notifications are delivered via HTTP POST to subscriber callback URLs
- The subscriber's configured `auth` header is included in callbacks
- Delivery failures MUST NOT block the original operation

### Requirement: Default Channels
Pre-register channels: `zaken`, `documenten`, `besluiten`, `catalogi`, `autorisaties`

### Requirement: ZGW Pagination
All list endpoints MUST return `{ count, next, previous, results }` format.
