# Spec: ZGW Autorisaties API (AC)

## ZGW Standard References

### Official Documentation
- **Standard overview**: https://vng-realisatie.github.io/gemma-zaken/standaard/autorisaties/
- **Developer guide**: https://vng-realisatie.github.io/gemma-zaken/ontwikkelaars/

### OpenAPI Specifications
- **OAS (gemma-zaken canonical)**: [api-specificatie/ac/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/ac/openapi.yaml)
  - Raw: https://raw.githubusercontent.com/VNG-Realisatie/gemma-zaken/master/api-specificatie/ac/openapi.yaml
- **Reference implementation OAS**: [src/openapi.yaml](https://github.com/VNG-Realisatie/autorisaties-api/blob/master/src/openapi.yaml)
  - Raw: https://raw.githubusercontent.com/VNG-Realisatie/autorisaties-api/master/src/openapi.yaml

### Source Documentation (Markdown)
- **Standard page**: [docs/standaard/autorisaties/index.md](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/docs/standaard/autorisaties/index.md)
- **Authorization spec**: [src/autorisaties.md](https://github.com/VNG-Realisatie/autorisaties-api/blob/master/src/autorisaties.md)
- **Notification spec**: [src/notificaties.md](https://github.com/VNG-Realisatie/autorisaties-api/blob/master/src/notificaties.md)

### Note on Versioning
The AC in gemma-zaken does NOT have versioned subdirectories — the spec files sit directly in the `ac/` folder.

## Requirements

### Requirement: AC Resource Coverage
The implementation MUST support:
- **Applicatie** — API client registration with credentials and permissions
  - Fields: uuid, clientIds, label, heeftAlleAutorisaties, autorisaties[]
- **Autorisatie** — Permission grants (inline in Applicatie)
  - Fields: component, scopes[], zaaktype, maxVertrouwelijkheidaanduiding

### Requirement: JWT-ZGW Authentication
- Validate incoming JWT tokens per the ZGW standard
- JWT claims: `iss` (maps to clientId), `iat`, `client_id`, `user_id`, `user_representation`
- Token signed with shared secret (HMAC) registered in the Applicatie

### Requirement: Scope Enforcement
| Scope | Meaning |
|-------|---------|
| `*.lezen` | Read access (GET) |
| `*.aanmaken` | Create access (POST) |
| `*.bijwerken` | Update access (PUT, PATCH) |
| `*.verwijderen` | Delete access (DELETE) |

Scopes are prefixed by component: `zaken.lezen`, `documenten.aanmaken`, etc.

### Requirement: Superuser Mode
Applicaties with `heeftAlleAutorisaties: true` bypass all scope checks.

### Requirement: Confidentiality Enforcement
`maxVertrouwelijkheidaanduiding` limits access to documents/cases at or below the specified confidentiality level. Levels (low to high): `openbaar`, `beperkt_openbaar`, `intern`, `zaakvertrouwelijk`, `vertrouwelijk`, `confidentieel`, `geheim`, `zeer_geheim`.

### Requirement: Applicatie-Consumer Mapping
ZGW Applicatie resources MUST map to OpenRegister's Consumer entity for credential storage and JWT validation.

### Requirement: ZGW Pagination
All list endpoints MUST return `{ count, next, previous, results }` format.
