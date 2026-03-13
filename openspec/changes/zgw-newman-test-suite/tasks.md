# Tasks: zgw-newman-test-suite

## 1. Test Infrastructure

### Task 1.1: Create Newman package.json
- **files**: `procest/tests/zgw/package.json`
- **acceptance_criteria**:
  - GIVEN the package.json WHEN npm install is run THEN newman ^6 is installed
  - GIVEN the package WHEN scripts are listed THEN test, test:oas, test:business are available
- [x] Implement
- [x] Test

### Task 1.2: Create ZGW environment file
- **files**: `procest/tests/zgw/zgw-environment.json`
- **acceptance_criteria**:
  - GIVEN the environment file WHEN loaded by Newman THEN all ZGW API URLs point to Procest endpoints
  - GIVEN the environment file WHEN loaded by Newman THEN client_id, secret, and auth variables are set
  - GIVEN the environment file WHEN loaded by Newman THEN all 6 API component URLs are mapped (zrc, ztc, brc, drc, nrc, ac)
- [x] Implement
- [x] Test

### Task 1.3: Create test runner script
- **files**: `procest/tests/zgw/run-zgw-tests.sh`
- **acceptance_criteria**:
  - GIVEN the script runs on host WHEN executed THEN it delegates to Docker container
  - GIVEN the script runs inside container WHEN executed THEN it runs Newman directly
  - GIVEN Newman is not installed WHEN script runs THEN helpful error message is shown
  - GIVEN --oas-only flag WHEN script runs THEN only OAS tests run
  - GIVEN --business-only flag WHEN script runs THEN only business rules tests run
  - GIVEN --folder zrc flag WHEN script runs THEN only Zaken API tests run
  - GIVEN OAS tests pass WHEN script continues THEN business rules tests are run next
  - GIVEN OAS tests fail WHEN --bail is set THEN business rules tests are skipped
  - GIVEN tests complete WHEN results are output THEN JSON reports are saved to results/
- [x] Implement
- [x] Test

## 2. Integration

### Task 2.1: Add npm scripts to root package.json
- **files**: `procest/package.json`
- **acceptance_criteria**:
  - GIVEN package.json WHEN npm run test:zgw is called THEN the test runner script executes
  - GIVEN package.json WHEN npm run test:zgw:oas is called THEN only OAS tests run
  - GIVEN package.json WHEN npm run test:zgw:business is called THEN only business rules tests run
- [x] Implement
- [x] Test

### Task 2.2: Create README with usage instructions
- **files**: `procest/tests/zgw/README.md`
- **acceptance_criteria**:
  - GIVEN the README WHEN read THEN it explains prerequisites (Newman, running environment, test applicaties)
  - GIVEN the README WHEN read THEN it documents all CLI options
  - GIVEN the README WHEN read THEN it explains how to interpret test results
- [x] Implement
- [x] Test
