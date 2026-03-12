# ZGW Implementation Knowledge Base

Shared knowledge file for sub-agents working on Procest's ZGW API implementation.
**Read this file before starting work. Append new learnings at the bottom.**

## Architecture

### Controller Split (per ZGW register)

| Controller | Register | zgwApi value | Resources |
|---|---|---|---|
| `ZrcController` | Zaken | `zaken` | zaken, statussen, resultaten, rollen, zaakeigenschappen, zaakinformatieobjecten, zaakobjecten, klantcontacten |
| `ZtcController` | Catalogi | `catalogi` | catalogussen, zaaktypen, statustypen, resultaattypen, roltypen, eigenschappen, informatieobjecttypen, besluittypen, zaaktype-informatieobjecttypen |
| `BrcController` | Besluiten | `besluiten` | besluiten, besluitinformatieobjecten |
| `DrcController` | Documenten | `documenten` | enkelvoudiginformatieobjecten, objectinformatieobjecten, gebruiksrechten, verzendingen |
| `NrcController` | Notificaties | `notificaties` | kanaal, abonnement |
| `AcController` | Autorisaties | `autorisaties` | applicaties (uses ConsumerMapper, NOT OpenRegister objects) |

### Shared Service: `ZgwService`

All controllers depend on `ZgwService` (`lib/Service/ZgwService.php`). Key methods:

**CRUD orchestration** (handles auth, mapping, validation, save, notification):
- `handleIndex(IRequest, zgwApi, resource)` — paginated list
- `handleCreate(IRequest, zgwApi, resource, ?zaakClosed, hasForceer)` — create with business rules
- `handleShow(IRequest, zgwApi, resource, uuid)` — get single
- `handleUpdate(IRequest, zgwApi, resource, uuid, partial, ?parentZtDraft, ?zaakClosed, hasForceer)` — PUT/PATCH
- `handleDestroy(IRequest, zgwApi, resource, uuid, ?parentZtDraft, ?zaakClosed, hasForceer)` — DELETE

**Utility methods:**
- `validateJwtAuth(IRequest)` — returns JSONResponse on failure, null on success
- `loadMappingConfig(zgwApi, resource)` — loads Twig mapping from IAppConfig
- `getRequestBody(IRequest)` — parses JSON body (with malformed JSON fallback)
- `buildBaseUrl(IRequest, zgwApi, resource)` — constructs ZGW-style URL
- `createOutboundMapping/createInboundMapping(mappingConfig)` — builds Mapping objects
- `applyOutboundMapping/applyInboundMapping(...)` — executes Twig-based field translation
- `translateQueryParams(params, mappingConfig)` — ZGW query params to OpenRegister filters
- `consumerHasScope(IRequest, component, scope)` — checks JWT consumer scopes
- `publishNotification(zgwApi, resource, resourceUrl, actie)` — sends to NRC subscribers
- `buildValidationError(ruleResult)` — formats validation error response
- `unavailableResponse()` / `mappingNotFoundResponse(zgwApi, resource)` — standard error responses

**OpenRegister access:**
- `getObjectService()` — OpenRegister ObjectService (find, saveObject, deleteObject, buildSearchQuery, searchObjectsPaginated)
- `getConsumerMapper()` — OpenRegister ConsumerMapper (for AC)
- `getZgwMappingService()` — Procest's ZgwMappingService (IAppConfig storage)
- `getBusinessRulesService()` — ZgwBusinessRulesService
- `getDocumentService()` — ZgwDocumentService (file storage)
- `getLogger()` — PSR LoggerInterface

**Cross-register resolvers:**
- `resolveZaakClosed(resource, existingData)` — checks if zaak has einddatum (for zrc-007)
- `resolveZaakClosedFromBody(resource, body)` — same but from request body (sub-resource creation)
- `resolveParentZaaktypeDraft(resource, existingData)` — checks if parent zaaktype is concept (for ztc-010)

### Other Services

- `ZgwBusinessRulesService` — validates VNG business rules before save. Call via `zgwService->getBusinessRulesService()->validate(...)`
- `ZgwMappingService` — stores/retrieves Twig mapping configs from IAppConfig
- `ZgwPaginationHelper` — wraps results in ZGW HAL-style `{count, next, previous, results}`
- `ZgwDocumentService` — stores binary files in Nextcloud filesystem at `/admin/files/procest/documenten/{uuid}/{filename}`
- `NotificatieService` — delivers notifications to NRC subscribers via HTTP POST

## Business Rules by Register

### ZRC (Zaken)
- **zrc-007**: Closed zaak protection — zaak sub-resources cannot be modified when the parent zaak has an `einddatum`, unless the consumer has `zaken.geforceerd-bijwerken` scope
- **zrc-007a**: When creating a status whose statustype has `isEindstatus=true`, automatically set the parent zaak's `einddatum` to the `datumStatusGezet` date
- Zaakeigenschappen are nested sub-resources (`/zaken/{zaakUuid}/zaakeigenschappen`)
- `_zoek` endpoint delegates to index and returns HTTP 201 (not 200)

### ZTC (Catalogi)
- **ztc-010**: Sub-resources of a published (non-concept) zaaktype cannot be modified or deleted
- **ztc-004**: Resultaattype `afleidingswijze` in [eigenschap, zaakobject, ander_datumkenmerk] requires `datumkenmerk`
- **ztc-005**: `afleidingswijze` in [afgehandeld, termijn] forbids `einddatumBekend=true`
- **ztc-006**: `afleidingswijze` in [zaakobject, ander_datumkenmerk] requires `objecttype`
- Publish endpoints set `isDraft=false` on zaaktypen, besluittypen, informatieobjecttypen

### DRC (Documenten)
- **drc-009**: Document must be locked before updates. Lock ID must be provided and must match.
- Binary content (`inhoud`) is stored as base64 in the request, decoded and saved to filesystem
- `inhoud` is NOT stored in OpenRegister — only as a Nextcloud file
- Lock/unlock uses `locked` (bool) and `lockId` (string) fields on the OpenRegister object
- On destroy, stored files must be cleaned up via `documentService->deleteFiles(uuid)`

### BRC (Besluiten)
- **brc-001**: Standard besluit CRUD (create, update, patch) with besluittype validation
- **brc-002**: Identificatie uniqueness under verantwoordelijke_organisatie; immutable on update
- **brc-003a**: BIO informatieobject URL validation — must resolve to a valid EIO
- **brc-004a/b**: BesluitInformatieObject is immutable — PUT/PATCH returns 405
- **brc-005a**: Cross-register OIO sync — creating a BIO also creates an OIO in DRC with objectType=besluit
- **brc-005b**: Deleting a BIO also deletes the corresponding OIO in DRC
- **brc-006a**: Zaak-besluit relation — checks both directions (BT.caseTypes -> ZT UUID, and ZT.decisionTypes -> BT omschrijving/UUID)
- **brc-007**: BesluitInformatieObject validates that informatieobjecttype is in besluittype.informatieobjecttypen
- **brc-008a**: BIO create validates IOT is in BT.informatieobjecttypen
- **brc-009**: Cascade delete — deleting a besluit also deletes related BIOs and their OIOs in DRC; audit trail returns 404 for deleted besluiten

### NRC (Notificaties)
- `notificatieCreate` endpoint just echoes the body back with HTTP 201
- Standard CRUD for kanaal and abonnement resources

### AC (Autorisaties)
- Completely custom — maps OpenRegister Consumers to ZGW Applicatie format
- Does NOT use the standard CRUD flow (no Twig mapping, no ObjectService)
- `show('consumer')` with `?clientId=...` is a special lookup pattern

## ZGW Standard Quirks & Workarounds

### Malformed JSON in VNG test collections
The VNG Postman test collections sometimes send unquoted Postman variables in JSON bodies (e.g., `"field": {{var}}` instead of `"field": "{{var}}"`). The `getRequestBody()` method in ZgwService handles this with a regex fallback that quotes unquoted values.

### Boolean normalization
OpenRegister may store booleans as strings (`"true"`, `"1"`) or integers (`1`, `0`). Always normalize before comparing:
```php
if ($val === 'true' || $val === '1' || $val === 1) { $val = true; }
```

### Identifier type casting
OpenRegister DB may return `identifier` as an integer even when stored as string. Always cast before saving back:
```php
if (isset($data['identifier']) && is_int($data['identifier'])) {
    $data['identifier'] = (string) $data['identifier'];
}
```

### PATCH merge strategy
For partial updates, only English fields whose corresponding ZGW fields were in the request body should be merged. The reverse mapping is inspected to determine which English keys correspond to which ZGW fields. Existing array values must be re-encoded as JSON strings before merging (OpenRegister deserializes them).

### UUID extraction from URLs
ZGW resources reference each other by full URL (`http://host/api/zgw/zaken/v1/zaken/{uuid}`). Extract UUIDs with:
```php
preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $url, $matches);
```

### OpenRegister ObjectService API
- `find($uuid, register: $reg, schema: $schema)` — may return object or array
- `saveObject(register: $reg, schema: $schema, object: $data, uuid: $uuid)` — uuid optional for create
- `deleteObject(uuid: $uuid)` — delete by UUID
- `buildSearchQuery(requestParams: [...], register: $reg, schema: $schema)` — build query
- `searchObjectsPaginated(query: $query)` — returns `['results' => [...], 'total' => N]`
- Always handle both array and object returns: `is_array($obj) ? $obj : $obj->jsonSerialize()`

## Testing

### Per-register test commands
```bash
# Run inside container or let the script delegate
bash procest/tests/zgw/run-zgw-tests.sh --oas-only --folder setUp   # Initialize test data
bash procest/tests/zgw/run-zgw-tests.sh --oas-only --folder ZRC
bash procest/tests/zgw/run-zgw-tests.sh --oas-only --folder ZTC
bash procest/tests/zgw/run-zgw-tests.sh --oas-only --folder DRC
bash procest/tests/zgw/run-zgw-tests.sh --oas-only --folder BRC
bash procest/tests/zgw/run-zgw-tests.sh --oas-only --folder NRC
bash procest/tests/zgw/run-zgw-tests.sh --oas-only --folder AC
bash procest/tests/zgw/run-zgw-tests.sh --business-only --folder ztc  # Business rules
```

### After making changes
1. Clear OPcache: `docker exec nextcloud apache2ctl graceful`
2. Run the relevant register's tests
3. Compare failures against baseline — new failures = regression

### Known pre-existing test failures (baseline 2026-03-08)
These failures exist before the controller split and are NOT regressions:
- **ZRC**: zaakobjecten CRUD fails due to unresolved `{{zaakobject_url}}` Postman variable
- **ZTC**: zaaktype-informatieobjecttypen and zaaktypen PATCH/DELETE have comparison/status issues
- **DRC**: objectinformatieobjecten fail due to unresolved `{{objectinformatieobject_url}}`
- **BRC**: besluitinformatieobjecten fail due to unresolved `{{bio_url}}`
- **AC**: applicatie delete fails due to unresolved `{{created_applicatie_url}}`
- **NRC**: All tests pass

---

## Learnings Log

_Sub-agents: append new discoveries below this line. Include the date, register, and what you learned._

### 2026-03-08 — DRC: drc-009 lock enforcement response format

The VNG business rules tests for drc-009 expect error responses in a specific `invalidParams` format:
```json
{
  "detail": "...",
  "invalidParams": [
    { "name": "...", "code": "...", "reason": "..." }
  ]
}
```

Key distinctions:
- **drc-009a/b** (update while unlocked): `name: 'nonFieldErrors'`, `code: 'unlocked'`
- **drc-009d** (PUT without lock): `name: 'lock'`, `code: 'required'` (field-level validation)
- **drc-009e** (PATCH without lock): `name: 'nonFieldErrors'`, `code: 'missing-lock-id'` (non-field error)
- **drc-009h/i** (wrong lock ID): `name: 'nonFieldErrors'`, `code: 'incorrect-lock-id'`

The PUT vs PATCH distinction matters: PUT treats `lock` as a required field, PATCH treats it as a missing lock enforcement error.

### 2026-03-08 — DRC: Force unlock scope check

Force unlock (drc-009k) requires checking `documenten.geforceerd-bijwerken` scope via `consumerHasScope()`. When the lock ID doesn't match or is missing, the unlock endpoint checks if the consumer has this scope. If yes, force unlock succeeds (204). If no, returns 400.

The `consumerHasScope()` method returns `true` (bypass) when:
- `consumerMapper` is null (OpenRegister not loaded)
- No client_id in JWT
- Consumer not found in database
- Consumer has `superuser: true`

### 2026-03-08 — DRC: OAS test lock status code

The OAS test collection expects lock to return HTTP 201, while the business rules test (drc-009c) expects HTTP 200. The ZGW standard specifies 200 for lock. The lock endpoint returns 200.

The OAS unlock test expects 201, but the ZGW standard and business rules (drc-009k) expect 204. The unlock endpoint returns 204.

### 2026-03-08 — DRC: Boolean normalization for locked field

OpenRegister may store boolean fields as strings (`"true"`, `"1"`) or integers (`1`, `0`). The lock/unlock/checkDocumentLock methods must normalize the `locked` field value before comparison.

### 2026-03-08 — ZRC business rules implementation

**zrc-002a**: Unique identificatie enforcement. Added `checkIdentificatieUnique()` in `ZgwBusinessRulesService` that searches OpenRegister for existing zaken with the same `identifier` (and `sourceOrganisation`). Returns 400 with `identificatie-niet-uniek` error code.

**zrc-003d**: Invalid informatieobject URL validation. Added `validateInformatieobjectUrl()` that validates both URL format AND that the UUID resolves to an actual document in OpenRegister (`document_schema`).

**zrc-004a/b/c**: ZaakInformatieObject enrichment. Business rules now set `aardRelatieWeergave = "Hoort bij, omgekeerd: kent"` and `registratiedatum = date("Y-m-d")` on create. These values are forced immutable on update/patch. Since the `caseDocument` schema does NOT have a `relationshipType` field, these values are injected directly into the outbound response at the controller level via `enrichZioResponse()` and `enrichZioJsonResponse()`.

**zrc-005a/b**: Cross-register OIO sync. `ZrcController::create()` now calls `syncCreateObjectInformatieObject()` after creating a ZaakInformatieObject, which creates a corresponding ObjectInformatieObject in the DRC register. `ZrcController::destroy()` captures ZIO data before deletion and calls `syncDeleteObjectInformatieObject()` on success. OIO search uses `relatedObject` and `document` fields from the `objectinformatieobject` mapping config.

**zrc-006a/b/c**: Authorization-based filtering. Added `getConsumerAuthorisaties()` to `ZgwService` that returns the consumer's per-component authorization entries. `ZrcController::index()` now calls `filterZakenByAuthorisation()` which filters results based on `maxVertrouwelijkheidaanduiding`. `show()` calls `checkZaakReadAccess()` for the same check on individual zaken. `create()` checks `zaken.aanmaken` scope. All return 403 with `{"code": "permission_denied"}`.

**zrc-007**: Closed zaak protection. The validation error now includes a top-level `code: "permission_denied"` field in the response, matching the VNG test expectation (`pm.response.json().code`). This required changes to both `ZgwBusinessRulesService` (adding `'code' => 'permission_denied'` to the rule result) and `ZgwService::buildValidationError()` (propagating the `code` field to the response data).

**Key finding**: The `VERTROUWELIJKHEID_LEVELS` ordering for authorization filtering is: openbaar(1) < beperkt_openbaar(2) < intern(3) < zaakvertrouwelijk(4) < vertrouwelijk(5) < confidentieel(6) < geheim(7) < zeer_geheim(8). Consumer's `maxVertrouwelijkheidaanduiding` sets the ceiling — zaken with a higher level are filtered out.

### 2026-03-08 — AC business rules implementation

**ac-001**: ClientId uniqueness. `validateClientIdUniqueness()` iterates all existing consumers and checks both the primary `name` field and any extra clientIds stored in `authorizationConfiguration.clientIds`. Returns 400 with `clientId-exists` error code.

**ac-002a/b**: heeftAlleAutorisaties consistency. `validateAutorisatieConsistency()` checks: if `heeftAlleAutorisaties=true` and `autorisaties` is non-empty, returns 400 with `ambiguous-authorizations-specified`. If `heeftAlleAutorisaties=false` and `autorisaties` is empty (and explicitly provided), returns 400 with `missing-authorizations`.

**ac-003a-f**: Scope-based field validation. `validateAutorisatieScopes()` checks each autorisatie entry: for `zrc` component with scope containing "zaken", requires `zaaktype` and `maxVertrouwelijkheidaanduiding`. For `drc` with "documenten", requires `informatieobjecttype` and `maxVertrouwelijkheidaanduiding`. For `brc` with "besluiten", requires `besluittype`.

**Multiple clientIds support**: The Consumer entity stores only one `name`, so extra clientIds beyond the first are stored in `authorizationConfiguration.clientIds`. The `consumerToApplicatie()` method reconstructs the full list.

**Validation ordering**: Business rules (ac-002, ac-003) must run BEFORE uniqueness checks (ac-001). If uniqueness fires first on test data with pre-existing clientIds, the actual business rule errors are masked.

**Bug fix**: `ConsumerMapper::createFromArray()` parameter is named `$object` not `$data` -- using named parameter `data:` caused "Unknown named parameter $data" errors. Fixed to use `object:`.

**Index filter**: Added support for both `clientId` (singular) and `clientIds` (plural) query parameters, since the OAS cleanup test uses `clientIds` (plural).

**Pre-existing setUp issue**: The "Create Zaaktype" step in the ac business rules setUp has a TypeError in its test script (`Cannot read properties of undefined (reading '0')`) -- this is a pre-existing Postman collection issue, not an AC regression.

## ZTC Cross-Reference URL Enrichment (ztc-0xx)

### Architecture

ZTC types (zaaktypen, besluittypen) contain cross-reference arrays (informatieobjecttypen, besluittypen, deelzaaktypen, gerelateerdeZaaktypen) that must return valid URLs pointing to published, date-valid objects. This is implemented in two phases:

**Write path (business rules)**: `ZgwZtcRulesService` resolves omschrijving/identificatie strings to object UUIDs at creation time, storing them via `_directFields` to bypass Twig mapping limitations with arrays.

**Read path (enrichment)**: `ZtcController::enrichZaaktype()` and `enrichBesluittype()` expand stored UUIDs to full URLs, using identifier-based expansion to include all versions of the same logical type. `filterValidUrls()` then removes concept or date-invalid entries.

### Key Patterns

**`_directFields` mechanism**: Array fields that Twig cannot handle (drops to empty strings) are stored via a special `_directFields` key in the enriched body. `ZgwService::handleCreate()` and `handleUpdate()` extract these and merge them directly into the English data, bypassing the Twig mapping.

**Identifier-based expansion at read time**: For deelzaaktypen and gerelateerdeZaaktypen, the enrichment code looks up each stored UUID's identifier, then finds ALL objects with that identifier. This ensures that ZT2 (created after ZT1 but with the same identifier) appears in ZT1's deelzaaktypen even though ZT2 didn't exist when ZT1 was created.

**ZIOT-based IOT enrichment**: informatieobjecttypen on zaaktypen are NOT stored directly. Instead, ZIOT (zaaktype-informatieobjecttype) records link ZTs to IOTs. The enrichment queries ZIOTs for the zaaktype, looks up each IOT's name, finds ALL IOTs with that name, and lets filterValidUrls select valid ones.

### Schema Considerations

**`relatedCaseTypes` must be type `array` in OR schema (not `string`)**: OpenRegister auto-parses JSON strings back to arrays. If the schema says `string`, the PATCH flow fails because: (1) existing data is read as array (OR auto-parsed it), (2) json_encode for Twig, (3) after merge, json_decode back to array, (4) OR rejects "expected string, got array". Changing the schema to `array` with items of type `object` resolves this.

**`$arrayKeys` tracking in PATCH flow**: `ZgwService::handleUpdate()` tracks which existing fields were originally arrays before json_encoding them for Twig. After merge, only those fields get decoded back to arrays. This prevents string-typed fields containing JSON from being incorrectly decoded.

### ZIOT omschrijving resolution

VNG tests may send ZIOT `informatieobjecttype` as an omschrijving string that happens to be UUID-shaped. `rulesZaaktypeinformatieobjecttypenCreate` handles this by: (1) if it's a URL, keep as-is; (2) if it's a bare UUID, verify it exists in OR -- if not, fall back to name-based lookup; (3) if not a UUID, resolve by name. This prevents storing non-existent UUIDs when the value is actually an omschrijving that looks like a UUID.

### 2026-03-08 — BRC business rules implementation

**brc-003a fix**: The `validateInformatieobjectUrl()` in `ZgwRulesBase` had a bug where `extractUuid()` returning null caused the validation to silently pass (the `if ($ioUuid !== null && $this->objectService !== null)` condition was skipped). Fixed by adding an explicit null check that returns 400 when UUID extraction fails.

**brc-005a/b fix (OpenRegister)**: `MetadataHydrationHandler::hydrateObjectMetadata()` line 100 assumed `$objectData['object']` was always a nested array, but ObjectInformatieObject has `object` as a URL string. When the OIO data had `['document' => url, 'object' => url, 'objectType' => 'besluit']`, the code used the URL string as the business data array, causing a TypeError. Fixed by adding `is_array()` check.

**brc-006a**: ZGW zaak-besluit relation requires checking BOTH directions: (1) BesluitType.caseTypes contains the zaaktype UUID, and (2) ZaakType.decisionTypes contains the BesluitType omschrijving or UUID. The `decisionTypes` array field was added to the caseType schema and the zaaktype mapping was updated to store `besluittypen` as `decisionTypes`.

**brc-009c/d/e (cascade delete)**: Deleting a besluit must cascade delete BIOs and OIOs. The key challenge was that OpenRegister's `ObjectService::deleteObject()` performs a soft delete via `ObjectEntityMapper::update()`, but the update method checks `shouldUseMagicMapper` which reads the register's `configuration` JSON. If the register has no magic mapping configuration, the update falls through to the blob table path, leaving the magic table row unchanged.

**Fix**: Register 7 (Procest) needed explicit magic mapping configuration (`{"schemas": {"<slug>": {"magicMapping": true}}}`) set on its `configuration` column. Without this, `isMagicMappingEnabledForSchema()` returns false and soft deletes in magic tables silently fail.

**Important**: When calling `deleteObject()` during cascade operations (where we're deleting related objects, not the primary resource), pass `_rbac: false, _multitenancy: false` to avoid permission issues with related objects in other schemas.

**Audit trail for deleted resources**: `BrcController::audittrailIndex()` checks if the parent resource exists before returning audit trail data. If the resource was soft-deleted, `find()` throws `DoesNotExistException`, and the controller returns 404. This satisfies brc-009d.

### 2026-03-08 — OpenRegister Magic Mapper soft delete gotcha

**CRITICAL**: OpenRegister's `ObjectService::deleteObject()` performs soft delete by calling `ObjectEntityMapper::update()`. But `update()` checks `shouldUseMagicMapperForRegisterSchema()` which reads `Register::isMagicMappingEnabledForSchema()`. If the register's `configuration` column is NULL or doesn't have the schema listed with `magicMapping: true`, the update falls through to the blob table `parent::update()` call, which operates on `oc_openregister_objects` (the blob table). Since the object only exists in the magic table (`oc_openregister_table_{register}_{schema}`), the soft delete appears to succeed (returns true) but the magic table row is NOT updated.

**Workaround**: Ensure the register has proper `configuration` JSON with all schemas listed. Example:
```json
{"schemas": {"decisionDocument": {"magicMapping": true}, "decision": {"magicMapping": true}}}
```
