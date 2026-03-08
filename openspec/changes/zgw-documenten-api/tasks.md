# Tasks: zgw-documenten-api

## 1. Schema & Mapping Configuration

### Task 1.1: Add DRC schemas to procest_register.json
- **files**: `procest/lib/Settings/procest_register.json`
- **acceptance_criteria**:
  - GIVEN the register config WHEN imported THEN schemas for EnkelvoudigInformatieObject, ObjectInformatieObject, and GebruiksRechten are created
  - GIVEN each schema WHEN validated THEN all ZGW-required fields are present with correct types
- [x] Implement
- [x] Test

### Task 1.2: Create ZGW mapping configurations for DRC resources
- **files**: `procest/lib/Repair/LoadDefaultZgwMappings.php`
- **acceptance_criteria**:
  - GIVEN the repair step WHEN executed THEN zgw_mapping_enkelvoudiginformatieobject is stored in IAppConfig
  - GIVEN the repair step WHEN executed THEN zgw_mapping_objectinformatieobject is stored in IAppConfig
  - GIVEN the repair step WHEN executed THEN zgw_mapping_gebruiksrechten is stored in IAppConfig
  - GIVEN each mapping WHEN used by ZgwMappingService THEN Dutch ZGW fields map correctly to English schema fields
- [x] Implement
- [x] Test

## 2. Controller & Routes

### Task 2.1: Extend ZgwController RESOURCE_MAP with DRC resources
- **files**: `procest/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN the RESOURCE_MAP WHEN 'enkelvoudiginformatieobjecten' is requested THEN it maps to the correct config key
  - GIVEN the RESOURCE_MAP WHEN 'objectinformatieobjecten' is requested THEN it maps to the correct config key
  - GIVEN the RESOURCE_MAP WHEN 'gebruiksrechten' is requested THEN it maps to the correct config key
- [x] Implement
- [x] Test

### Task 2.2: Register DRC routes
- **files**: `procest/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN routes.php WHEN the app loads THEN all `/api/zgw/documenten/v1/{resource}` CRUD routes are registered
  - GIVEN routes.php WHEN the app loads THEN `/download`, `/lock`, `/unlock` special routes are registered
- [x] Implement
- [x] Test

## 3. File Handling

### Task 3.1: Implement binary file upload for EIO creation
- **files**: `procest/lib/Controller/ZgwController.php` or `procest/lib/Service/ZgwDocumentService.php`
- **acceptance_criteria**:
  - GIVEN a POST with base64 'inhoud' field WHEN creating an EIO THEN the file is decoded and stored in Nextcloud Files
  - GIVEN a POST with multipart file upload WHEN creating an EIO THEN the file is stored in Nextcloud Files
  - GIVEN a stored file WHEN the EIO is retrieved THEN the 'inhoud' field contains a download URL
  - GIVEN a stored file WHEN bestandsomvang is not provided THEN it is calculated from the file size
- [x] Implement
- [x] Test

### Task 3.2: Implement binary file download
- **files**: `procest/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN an EIO with stored file WHEN GET .../download is called THEN the binary file is streamed with correct Content-Type
  - GIVEN an EIO without file WHEN GET .../download is called THEN 404 is returned
- [x] Implement
- [x] Test

### Task 3.3: Implement document locking
- **files**: `procest/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN an unlocked EIO WHEN POST .../lock is called THEN the document is locked and lock ID is returned
  - GIVEN a locked EIO WHEN PUT/PATCH is called without matching lock THEN 400 is returned
  - GIVEN a locked EIO WHEN POST .../unlock is called with correct lock THEN the document is unlocked
- [x] Implement
- [x] Test

### Task 3.4: Implement bestandsdelen (chunked upload)
- **files**: `procest/lib/Controller/ZgwController.php`, `procest/lib/Service/ZgwDocumentService.php`
- **acceptance_criteria**:
  - GIVEN a POST creating EIO with bestandsomvang but no inhoud WHEN created THEN bestandsdelen URLs are returned
  - GIVEN bestandsdelen URLs WHEN PUT with binary chunks THEN chunks are stored temporarily
  - GIVEN all chunks uploaded WHEN the last chunk completes THEN chunks are merged into the final file
- [ ] Implement
- [ ] Test

## 4. ObjectInformatieObject Links

### Task 4.1: Implement OIO resource linking
- **files**: `procest/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN a zaak and an EIO WHEN POST objectinformatieobjecten THEN the link is created
  - GIVEN an OIO link WHEN GET objectinformatieobjecten?object={zaakUrl} THEN linked documents are returned
  - GIVEN an OIO link WHEN DELETE is called THEN the link is removed (document is NOT deleted)
- [x] Implement
- [x] Test
