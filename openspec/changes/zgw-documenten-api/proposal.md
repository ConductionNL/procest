# Proposal: zgw-documenten-api

## Summary
Add full ZGW Documenten API (DRC) support to Procest, enabling document management with binary file upload/download through the standard ZGW API interface. Documents are stored as OpenRegister objects with file content managed via Nextcloud's file system.

## Motivation
The ZGW Documenten API is a core component of the Dutch government's "Zaakgericht Werken" standard. Cases (zaken) need attached documents — permits, correspondence, decisions. Without DRC support, Procest cannot pass the VNG ZGW compliance tests and cannot interoperate with other ZGW-compliant systems that expect document exchange.

The existing Postman test suites (`ZGW OAS tests` and `ZGW business rules`) already test DRC endpoints, so this implementation enables full test coverage.

## Affected Projects
- [x] Project: `procest` — New ZGW DRC endpoints via ZgwController
- [ ] Reference: `openregister` — Uses ObjectService for document metadata storage

## Scope
### In Scope
- **EnkelvoudigInformatieObject (EIO)** — Document metadata + binary content
  - `GET/POST /api/zgw/documenten/v1/enkelvoudiginformatieobjecten`
  - `GET/PUT/PATCH/DELETE /api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}`
  - `GET /api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download` — Binary download
  - `POST /api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/lock` — Document locking
  - `POST /api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/unlock` — Document unlocking
- **ObjectInformatieObject (OIO)** — Links between documents and cases/decisions
  - `GET/POST /api/zgw/documenten/v1/objectinformatieobjecten`
  - `GET/DELETE /api/zgw/documenten/v1/objectinformatieobjecten/{uuid}`
- **GebruiksRechten** — Usage rights on documents
  - `GET/POST /api/zgw/documenten/v1/gebruiksrechten`
  - `GET/PUT/PATCH/DELETE /api/zgw/documenten/v1/gebruiksrechten/{uuid}`
- **Bestandsdelen** — Chunked file upload for large files
  - `PUT /api/zgw/documenten/v1/bestandsdelen/{uuid}`
- **ZGW pagination format**: `{ count, next, previous, results }`
- **ZGW filtering**: Query parameters as defined in the OAS spec
- **Binary file storage**: Files stored in Nextcloud via IRootFolder, metadata in OpenRegister

### Out of Scope
- Audit trail API (audittrail resource) — separate concern
- Document versioning history UI
- Full-text search within document content

## Approach
1. Create OpenRegister schemas for EIO, OIO, GebruiksRechten in `procest_register.json`
2. Create ZGW mapping configurations for each resource (`zgw_mapping_enkelvoudiginformatieobject`, etc.)
3. Extend `ZgwController` to handle the `documenten` API group
4. Add binary file handling: multipart upload → Nextcloud Files + metadata in OpenRegister
5. Implement document locking via a `lock` field on EIO objects
6. Add bestandsdelen support for chunked uploads
7. Register routes in `routes.php`

## Cross-Project Dependencies
- Depends on existing ZGW mapping infrastructure in Procest (ZgwController, ZgwMappingService)
- Document-case links (OIO) reference Zaken API resources (already implemented)

## Rollback Strategy
Remove DRC routes, mapping configs, and schemas. No changes to existing ZRC/ZTC/BRC functionality.
