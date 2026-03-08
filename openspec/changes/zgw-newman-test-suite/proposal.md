# Proposal: zgw-newman-test-suite

## Summary
Set up a Newman-based testing infrastructure for Procest's ZGW API implementation. Uses the existing VNG Postman test collections (`ZGW OAS tests` and `ZGW business rules`) to validate compliance with the ZGW API standards.

## Motivation
We have two Postman collection exports in `procest/data/` that comprehensively test ZGW API compliance:
- **ZGW OAS tests** (67 environment variables, tests all 6 ZGW APIs against their OAS specs)
- **ZGW business rules** (120 environment variables, tests business logic and edge cases)

Newman can run these Postman collections directly from the command line, enabling:
- Automated CI testing of ZGW compliance
- Quick local validation during development
- Regression testing as we add new ZGW APIs

## Affected Projects
- [x] Project: `procest` — Test infrastructure in `tests/zgw/`

## Scope
### In Scope
- **Newman package setup**: `procest/tests/zgw/package.json` with newman dependency
- **Environment file**: `procest/tests/zgw/zgw-environment.json` mapping ZGW API URLs to Procest endpoints
- **Test runner script**: `procest/tests/zgw/run-zgw-tests.sh` — runs both collections sequentially (OAS first, then business rules)
- **npm scripts**: Add `test:zgw`, `test:zgw:oas`, `test:zgw:business` to root `package.json`
- **Docker support**: Run inside Nextcloud container (same pattern as OpenRegister's Newman setup)
- **Environment variable mapping**:
  - `zrc_url` → `http://localhost/index.php/apps/procest/api/zgw/zaken/v1`
  - `ztc_url` → `http://localhost/index.php/apps/procest/api/zgw/catalogi/v1`
  - `brc_url` → `http://localhost/index.php/apps/procest/api/zgw/besluiten/v1`
  - `drc_url` → `http://localhost/index.php/apps/procest/api/zgw/documenten/v1`
  - `nrc_url` → `http://localhost/index.php/apps/procest/api/zgw/notificaties/v1`
  - `ac_url` → `http://localhost/index.php/apps/procest/api/zgw/autorisaties/v1`
  - `client_id`, `secret` → Test credentials for JWT generation
- **CLI reporter + JSON output** for CI integration

### Out of Scope
- Modifying the Postman collections themselves (they are VNG reference tests)
- Browser-based testing (that's the `test-app` skill)
- Performance/load testing

## Approach
1. Create `procest/tests/zgw/package.json` with newman ^6 dependency
2. Create `procest/tests/zgw/zgw-environment.json` with all required environment variables
3. Create `procest/tests/zgw/run-zgw-tests.sh` that:
   - Detects if running inside container or on host
   - Installs newman if needed
   - Runs OAS tests collection first
   - If OAS tests pass, runs business rules collection
   - Outputs summary with pass/fail counts
4. Add convenience scripts to `procest/package.json`

## Cross-Project Dependencies
- Requires all ZGW APIs to be implemented: ZRC, ZTC, BRC (existing) + DRC, NRC, AC (new changes)
- Requires OpenRegister auth system for JWT credential setup
- Newman must be installed in the Nextcloud container (`npm install -g newman`)

## Rollback Strategy
Remove `procest/tests/zgw/` directory and npm scripts from `package.json`. No production code changes.
