# Spec: ZGW Documenten API (DRC)

## ZGW Standard References

### Official Documentation
- **Standard overview**: https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/
- **Developer guide**: https://vng-realisatie.github.io/gemma-zaken/ontwikkelaars/

### OpenAPI Specifications
- **Current OAS (gemma-zaken canonical)**: [api-specificatie/drc/current_version/openapi.yaml](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/api-specificatie/drc/current_version/openapi.yaml)
  - Raw: https://raw.githubusercontent.com/VNG-Realisatie/gemma-zaken/master/api-specificatie/drc/current_version/openapi.yaml
- **Reference implementation OAS**: [src/openapi.yaml](https://github.com/VNG-Realisatie/documenten-api/blob/master/src/openapi.yaml)
  - Raw: https://raw.githubusercontent.com/VNG-Realisatie/documenten-api/master/src/openapi.yaml

### Source Documentation (Markdown)
- **Standard page**: [docs/standaard/documenten/index.md](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/docs/standaard/documenten/index.md)
- **Release notes**: [docs/standaard/documenten/release_notes.md](https://github.com/VNG-Realisatie/gemma-zaken/blob/master/docs/standaard/documenten/release_notes.md)
- **Authorization spec**: [src/autorisaties.md](https://github.com/VNG-Realisatie/documenten-api/blob/master/src/autorisaties.md)
- **Notification spec**: [src/notificaties.md](https://github.com/VNG-Realisatie/documenten-api/blob/master/src/notificaties.md)

### Versioned Specs
- 1.0.x: `api-specificatie/drc/1.0.x/`
- 1.1.x through 1.5.x available in `api-specificatie/drc/`

## Requirements

### Requirement: DRC Resource Coverage
The implementation MUST support these resources as defined in the OAS:
- **EnkelvoudigInformatieObject** — Document metadata + binary content
- **ObjectInformatieObject** — Links between documents and zaak/besluit objects
- **GebruiksRechten** — Usage rights on documents

### Requirement: Binary Content Handling
- Upload via base64 `inhoud` field in JSON body
- Upload via multipart form data
- Download via `GET .../enkelvoudiginformatieobjecten/{uuid}/download`
- Chunked upload via bestandsdelen for large files

### Requirement: Document Locking
- `POST .../lock` acquires a lock, returns lock ID
- `POST .../unlock` releases the lock
- Modifications to locked documents require the lock ID

### Requirement: ZGW Pagination
All list endpoints MUST return `{ count, next, previous, results }` format.

### Requirement: ZGW Filtering
Support query parameter filtering as defined in the OAS (e.g., `?informatieobjecttype=...`, `?bronorganisatie=...`).
