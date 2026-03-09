# Tasks: zgw-notificaties-api

## 1. Schema & Mapping Configuration

### Task 1.1: Add NRC schemas to procest_register.json
- **files**: `procest/lib/Settings/procest_register.json`
- **acceptance_criteria**:
  - GIVEN the register config WHEN imported THEN schemas for Kanaal and Abonnement are created
  - GIVEN each schema WHEN validated THEN all ZGW-required fields are present
- [x] Implement
- [x] Test

### Task 1.2: Create ZGW mapping configurations for NRC resources
- **files**: `procest/lib/Repair/LoadDefaultZgwMappings.php`
- **acceptance_criteria**:
  - GIVEN the repair step WHEN executed THEN zgw_mapping_kanaal is stored in IAppConfig
  - GIVEN the repair step WHEN executed THEN zgw_mapping_abonnement is stored in IAppConfig
- [x] Implement
- [x] Test

## 2. Controller & Routes

### Task 2.1: Extend ZgwController RESOURCE_MAP with NRC resources
- **files**: `procest/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN the RESOURCE_MAP WHEN 'kanaal' is requested THEN it maps to the correct config key
  - GIVEN the RESOURCE_MAP WHEN 'abonnement' is requested THEN it maps to the correct config key
- [x] Implement
- [x] Test

### Task 2.2: Register NRC routes
- **files**: `procest/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN routes.php WHEN the app loads THEN all `/api/zgw/notificaties/v1/{resource}` CRUD routes are registered
- [x] Implement
- [x] Test

## 3. Notification Service

### Task 3.1: Create NotificatieService
- **files**: `procest/lib/Service/NotificatieService.php`
- **acceptance_criteria**:
  - GIVEN a resource change event WHEN publish() is called THEN matching subscriptions are found
  - GIVEN matching subscriptions WHEN deliver() is called THEN HTTP POST is sent to each callback URL
  - GIVEN subscriber auth config WHEN delivering THEN the configured auth header is included
  - GIVEN notification payload WHEN published THEN it contains kanaal, hoofdObject, resource, resourceUrl, actie, aanmaakdatum, kenmerken
- [x] Implement
- [x] Test

### Task 3.2: Create default notification channels
- **files**: `procest/lib/Repair/LoadDefaultZgwMappings.php`
- **acceptance_criteria**:
  - GIVEN the repair step WHEN executed THEN default kanalen are created: zaken, documenten, besluiten, catalogi, autorisaties
  - GIVEN each kanaal WHEN retrieved THEN it has the correct filters configured
- [x] Implement
- [x] Test

## 4. Integration with ZGW Flows

### Task 4.1: Hook notification publishing into ZgwController
- **files**: `procest/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN a successful create via ZgwController WHEN the response is returned THEN a notification with actie 'create' is published
  - GIVEN a successful update via ZgwController WHEN the response is returned THEN a notification with actie 'update' is published
  - GIVEN a successful delete via ZgwController WHEN the response is returned THEN a notification with actie 'destroy' is published
  - GIVEN a notification publish failure WHEN the main operation succeeded THEN the main response is still returned (non-blocking)
- [x] Implement
- [x] Test

### Task 4.2: Handle notification POST endpoint
- **files**: `procest/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN a POST to /api/zgw/notificaties/v1/notificaties WHEN valid notification payload THEN the notification is distributed to subscribers
  - GIVEN external notification delivery WHEN callback fails THEN error is logged but no exception is thrown
- [x] Implement
- [x] Test
