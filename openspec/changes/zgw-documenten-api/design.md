# Design: zgw-documenten-api

## Architecture Overview

DRC endpoints follow the same pattern as existing ZRC/ZTC/BRC: the `ZgwController` routes requests through `ZgwMappingService` to OpenRegister's ObjectService. Binary file content is stored in Nextcloud Files and linked to the OpenRegister metadata object.

```
Client                      Procest                         OpenRegister / Nextcloud
  |                            |                                    |
  |-- POST /documenten/v1/    |                                    |
  |   enkelvoudiginformatie-   |-> ZgwController::create()          |
  |   objecten (multipart)     |     |-> ZgwMappingService::map()   |
  |                            |     |-> ObjectService::saveObject() --> metadata
  |                            |     |-> IRootFolder::newFile()      --> binary content
  |                            |     |-> link file path to object    |
  |<-- 201 + EIO JSON --------|                                    |
```

## Resource Schemas (OpenRegister)

### EnkelvoudigInformatieObject
| Field | Type | ZGW Name | Description |
|-------|------|----------|-------------|
| identificatie | string | identificatie | Unique identifier |
| bronorganisatie | string | bronorganisatie | RSIN of source org |
| creatiedatum | date | creatiedatum | Creation date |
| titel | string | titel | Document title |
| vertrouwelijkheidaanduiding | string | vertrouwelijkheidaanduiding | Confidentiality level |
| auteur | string | auteur | Author |
| status | string | status | 'in_bewerking', 'ter_vaststelling', 'definitief', 'gearchiveerd' |
| formaat | string | formaat | MIME type |
| taal | string | taal | ISO 639-2/B language code |
| bestandsnaam | string | bestandsnaam | Filename |
| bestandsomvang | integer | bestandsomvang | File size in bytes |
| inhoud | string | inhoud | Base64 content or file reference |
| link | string | link | URL to external content |
| beschrijving | string | beschrijving | Description |
| informatieobjecttype | uri | informatieobjecttype | Reference to InformatieObjectType |
| locked | boolean | locked | Lock status |
| bestandsdelen | array | bestandsdelen | Chunked upload parts |

### ObjectInformatieObject
| Field | Type | ZGW Name | Description |
|-------|------|----------|-------------|
| informatieobject | uri | informatieobject | Reference to EIO |
| object | uri | object | Reference to Zaak/Besluit |
| objectType | string | objectType | 'zaak' or 'besluit' |

### GebruiksRechten
| Field | Type | ZGW Name | Description |
|-------|------|----------|-------------|
| informatieobject | uri | informatieobject | Reference to EIO |
| startdatum | datetime | startdatum | Start of rights |
| einddatum | datetime | einddatum | End of rights |
| omschrijvingVoorwaarden | string | omschrijvingVoorwaarden | Conditions description |

## File Storage Strategy

Documents are stored in Nextcloud's file system under a dedicated folder:
```
/appdata_<instanceid>/procest/documenten/<uuid>/<bestandsnaam>
```

- Upload: Base64 content in JSON body or multipart form upload
- Download: `GET .../enkelvoudiginformatieobjecten/{uuid}/download` streams the file
- Chunked upload: Bestandsdelen are temporary files merged on completion
- Locking: `lock`/`unlock` endpoints set the `locked` field and prevent modifications

## Routes

All follow the existing pattern in `routes.php`:
```php
// Documenten API (DRC)
['name' => 'zgw#index',   'url' => '/api/zgw/documenten/v1/{resource}', 'verb' => 'GET'],
['name' => 'zgw#create',  'url' => '/api/zgw/documenten/v1/{resource}', 'verb' => 'POST'],
['name' => 'zgw#show',    'url' => '/api/zgw/documenten/v1/{resource}/{uuid}', 'verb' => 'GET'],
['name' => 'zgw#update',  'url' => '/api/zgw/documenten/v1/{resource}/{uuid}', 'verb' => 'PUT'],
['name' => 'zgw#patch',   'url' => '/api/zgw/documenten/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
['name' => 'zgw#destroy', 'url' => '/api/zgw/documenten/v1/{resource}/{uuid}', 'verb' => 'DELETE'],
// Special endpoints
['name' => 'zgw#download', 'url' => '/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download', 'verb' => 'GET'],
['name' => 'zgw#lock',     'url' => '/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/lock', 'verb' => 'POST'],
['name' => 'zgw#unlock',   'url' => '/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/unlock', 'verb' => 'POST'],
```

## Mapping Configuration

Add to `RESOURCE_MAP` in ZgwController:
```php
'enkelvoudiginformatieobjecten' => 'zgw_mapping_enkelvoudiginformatieobject',
'objectinformatieobjecten'      => 'zgw_mapping_objectinformatieobject',
'gebruiksrechten'               => 'zgw_mapping_gebruiksrechten',
```
