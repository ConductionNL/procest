# Spec: ZGW Newman Test Suite

## ZGW Standard References

### All ZGW API Specifications (tested by the suite)
| Component | OAS Source | Raw URL |
|-----------|-----------|---------|
| ZRC (Zaken) | [zrc/current_version/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/zrc/current_version/openapi.yaml) | https://raw.githubusercontent.com/VNG-Realisatie/gemma-zaken/master/api-specificatie/zrc/current_version/openapi.yaml |
| ZTC (Catalogi) | [ztc/current_version/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/ztc/current_version/openapi.yaml) | https://raw.githubusercontent.com/VNG-Realisatie/gemma-zaken/master/api-specificatie/ztc/current_version/openapi.yaml |
| BRC (Besluiten) | [brc/current_version/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/brc/current_version/openapi.yaml) | https://raw.githubusercontent.com/VNG-Realisatie/gemma-zaken/master/api-specificatie/brc/current_version/openapi.yaml |
| DRC (Documenten) | [drc/current_version/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/drc/current_version/openapi.yaml) | https://raw.githubusercontent.com/VNG-Realisatie/gemma-zaken/master/api-specificatie/drc/current_version/openapi.yaml |
| NRC (Notificaties) | [nrc/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/nrc/openapi.yaml) | https://raw.githubusercontent.com/VNG-Realisatie/gemma-zaken/master/api-specificatie/nrc/openapi.yaml |
| AC (Autorisaties) | [ac/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/ac/openapi.yaml) | https://raw.githubusercontent.com/VNG-Realisatie/gemma-zaken/master/api-specificatie/ac/openapi.yaml |

### Official Documentation
- **Standard overview**: https://vng-realisatie.github.io/gemma-zaken/standaard/
- **Developer guide**: https://vng-realisatie.github.io/gemma-zaken/ontwikkelaars/
- **Source repo**: https://github.com/VNG-Realisatie/gemma-zaken

### Reference Implementation Repos
- Zaken: https://github.com/VNG-Realisatie/zaken-api
- Documenten: https://github.com/VNG-Realisatie/documenten-api
- Catalogi: https://github.com/VNG-Realisatie/catalogi-api
- Besluiten: https://github.com/VNG-Realisatie/besluiten-api
- Notificaties: https://github.com/VNG-Realisatie/notificaties-api
- Autorisaties: https://github.com/VNG-Realisatie/autorisaties-api

## Requirements

### Requirement: Test Collection Compatibility
Newman MUST run the existing Postman collections from `procest/data/` without modification:
- `ZGW OAS tests.postman_collection.json`
- `ZGW business rules.postman_collection.json`

### Requirement: Environment Variable Coverage
The environment file MUST provide all variables referenced by both collections (67 for OAS, 120 for business rules). Dynamic variables set by pre-request scripts (e.g., `created_zaak_url`) are handled by the collections themselves.

### Requirement: Sequential Execution
OAS tests MUST run before business rules tests. Business rules depend on the data model being OAS-compliant.

### Requirement: Selective Execution
Support running:
- All tests (default)
- OAS tests only (`--oas-only`)
- Business rules only (`--business-only`)
- Specific API component (`--folder zrc`)

### Requirement: Docker Compatibility
The test runner MUST work both:
- On the host (delegating execution to the Docker container)
- Inside the container (running Newman directly)
