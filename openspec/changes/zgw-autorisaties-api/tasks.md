# Tasks: zgw-autorisaties-api

## 1. Mapping Configuration

### Task 1.1: Create ZGW mapping for Applicatie → Consumer
- **files**: `procest/lib/Repair/LoadDefaultZgwMappings.php`
- **acceptance_criteria**:
  - GIVEN the repair step WHEN executed THEN zgw_mapping_applicatie is stored in IAppConfig
  - GIVEN the mapping WHEN Applicatie JSON is received THEN it maps to Consumer fields in OpenRegister
  - GIVEN the mapping WHEN Consumer is read THEN it maps back to ZGW Applicatie format
- [x] Implement
- [x] Test

## 2. Controller & Routes

### Task 2.1: Extend ZgwController RESOURCE_MAP with AC resources
- **files**: `procest/lib/Controller/ZgwController.php`
- **acceptance_criteria**:
  - GIVEN the RESOURCE_MAP WHEN 'applicaties' is requested THEN it maps to the correct config key
- [x] Implement
- [x] Test

### Task 2.2: Register AC routes
- **files**: `procest/appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN routes.php WHEN the app loads THEN all `/api/zgw/autorisaties/v1/{resource}` CRUD routes are registered
- [x] Implement
- [x] Test

## 3. Auth Middleware

### Task 3.1: Create ZgwAuthMiddleware
- **files**: `procest/lib/Middleware/ZgwAuthMiddleware.php`
- **acceptance_criteria**:
  - GIVEN a request with valid JWT WHEN ZGW endpoint is called THEN the request is authorized and user session is set
  - GIVEN a request with invalid JWT WHEN ZGW endpoint is called THEN 403 response is returned
  - GIVEN a request with expired JWT WHEN ZGW endpoint is called THEN 403 response is returned
  - GIVEN a request without Authorization header WHEN ZGW endpoint is called THEN 403 response is returned
  - GIVEN the AC endpoints themselves WHEN called THEN they require admin-level JWT or basic auth
- [x] Implement
- [x] Test

### Task 3.2: Implement scope enforcement
- **files**: `procest/lib/Middleware/ZgwAuthMiddleware.php`
- **acceptance_criteria**:
  - GIVEN an applicatie with zrc.lezen scope WHEN GET /zaken is called THEN request is allowed
  - GIVEN an applicatie with zrc.lezen scope WHEN POST /zaken is called THEN 403 is returned
  - GIVEN an applicatie with heeftAlleAutorisaties=true WHEN any endpoint is called THEN request is allowed
  - GIVEN a scope limited to a specific zaaktype WHEN different zaaktype is accessed THEN 403 is returned
  - GIVEN a maxVertrouwelijkheidaanduiding WHEN accessing documents above that level THEN 403 is returned
- [x] Implement
- [x] Test

### Task 3.3: Register middleware
- **files**: `procest/lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the app boots WHEN ZGW routes are accessed THEN ZgwAuthMiddleware is invoked before the controller
- [x] Implement
- [x] Test

## 4. Default Test Applicaties

### Task 4.1: Create default applicaties via repair step
- **files**: `procest/lib/Repair/LoadDefaultZgwMappings.php`
- **acceptance_criteria**:
  - GIVEN the repair step WHEN executed THEN a superuser applicatie 'procest-admin' is created in OpenRegister
  - GIVEN the repair step WHEN executed THEN a limited applicatie 'procest-limited' is created with restricted scopes
  - GIVEN both applicaties WHEN JWT tokens are generated with their client_id and secret THEN they validate correctly
- [x] Implement
- [x] Test
