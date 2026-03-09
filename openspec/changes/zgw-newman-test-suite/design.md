# Design: zgw-newman-test-suite

## Architecture Overview

Newman runs the VNG Postman collections against Procest's ZGW endpoints. The test infrastructure lives in `procest/tests/zgw/` and can run both on the host (delegating to the Docker container) and inside the container directly.

```
Host                         Docker Container (nextcloud)
  |                              |
  |-- run-zgw-tests.sh -------->|  newman run "ZGW OAS tests.postman_collection.json"
  |                              |    --environment zgw-environment.json
  |                              |    --reporters cli,json
  |                              |
  |  (if OAS tests pass)         |
  |                              |  newman run "ZGW business rules.postman_collection.json"
  |                              |    --environment zgw-environment.json
  |                              |    --reporters cli,json
  |                              |
  |<-- exit code + reports ------|
```

## File Structure

```
procest/tests/zgw/
  package.json                    # Newman dependency
  zgw-environment.json            # Postman environment with URL mappings
  run-zgw-tests.sh                # Main test runner script
  README.md                       # Usage instructions
```

## Environment File (`zgw-environment.json`)

Maps the standard ZGW API URL variables to Procest's endpoints:

```json
{
  "id": "procest-zgw-local",
  "name": "Procest ZGW Local",
  "values": [
    { "key": "zrc_url", "value": "http://localhost/index.php/apps/procest/api/zgw/zaken/v1" },
    { "key": "ztc_url", "value": "http://localhost/index.php/apps/procest/api/zgw/catalogi/v1" },
    { "key": "brc_url", "value": "http://localhost/index.php/apps/procest/api/zgw/besluiten/v1" },
    { "key": "drc_url", "value": "http://localhost/index.php/apps/procest/api/zgw/documenten/v1" },
    { "key": "nrc_url", "value": "http://localhost/index.php/apps/procest/api/zgw/notificaties/v1" },
    { "key": "ac_url", "value": "http://localhost/index.php/apps/procest/api/zgw/autorisaties/v1" },
    { "key": "baseUrl", "value": "http://localhost/index.php/apps/procest/api/zgw" },
    { "key": "client_id", "value": "procest-admin" },
    { "key": "secret", "value": "procest-admin-secret" },
    { "key": "client_id_limited", "value": "procest-limited" },
    { "key": "secret_limited", "value": "procest-limited-secret" },
    { "key": "tokenuser", "value": "admin" }
  ]
}
```

## Test Runner Script

`run-zgw-tests.sh` follows the OpenRegister pattern:
1. Detect environment (host vs container)
2. Verify Newman is installed
3. Run OAS tests collection with `--environment zgw-environment.json`
4. If OAS tests pass, run business rules collection
5. Output summary with color-coded pass/fail
6. Save JSON reports to `procest/tests/zgw/results/`

### CLI options:
- `--oas-only` — Run only OAS tests
- `--business-only` — Run only business rules
- `--folder <name>` — Run specific folder (e.g., `--folder zrc` to test only Zaken API)
- `--bail` — Stop on first failure

## npm Scripts

Add to `procest/package.json`:
```json
"test:zgw": "cd tests/zgw && bash run-zgw-tests.sh",
"test:zgw:oas": "cd tests/zgw && bash run-zgw-tests.sh --oas-only",
"test:zgw:business": "cd tests/zgw && bash run-zgw-tests.sh --business-only"
```

## Prerequisites

Before tests can run:
1. All ZGW APIs implemented (ZRC, ZTC, BRC, DRC, NRC, AC)
2. OpenRegister auth system with Consumer entity
3. Default test applicaties created (procest-admin + procest-limited)
4. Newman installed: `npm install -g newman` (in container)
