# ZGW Newman Test Suite

Newman-based test suite for validating Procest's ZGW API compliance using VNG's official Postman test collections.

## Prerequisites

1. **Running environment** — Procest app installed and enabled in a Nextcloud instance
2. **Newman** — Install globally or locally:
   ```bash
   # Global install (recommended for container)
   npm install -g newman

   # Local install
   cd procest/tests/zgw && npm install
   ```
3. **Default test data** — Run the Procest repair step to create default applicaties and kanalen:
   ```bash
   docker exec nextcloud php occ maintenance:repair
   ```

## Usage

### From project root

```bash
# Run all ZGW tests (OAS + business rules)
npm run test:zgw

# Run only OAS specification tests
npm run test:zgw:oas

# Run only business rules tests
npm run test:zgw:business
```

### From tests/zgw directory

```bash
# Run all tests
bash run-zgw-tests.sh

# Run only OAS tests
bash run-zgw-tests.sh --oas-only

# Run only business rules
bash run-zgw-tests.sh --business-only

# Run tests for a specific API folder
bash run-zgw-tests.sh --folder zrc

# Stop on first failure (skip business rules if OAS fails)
bash run-zgw-tests.sh --bail
```

### Host vs Container

The test runner auto-detects the environment:
- **On host**: Delegates execution to the `nextcloud` Docker container
- **Inside container**: Runs Newman directly

Override the container name:
```bash
CONTAINER_NAME=my-nextcloud bash run-zgw-tests.sh
```

## CLI Options

| Option | Description |
|--------|-------------|
| `--oas-only` | Run only OAS specification tests |
| `--business-only` | Run only business rules tests |
| `--folder <name>` | Run a specific test folder (e.g., `zrc`, `ztc`, `brc`) |
| `--bail` | Skip business rules if OAS tests fail |
| `--help` | Show usage information |

## Test Collections

The test collections live in `procest/data/`:

- **ZGW OAS tests** — Validates API responses against OpenAPI specifications for all 6 ZGW components (ZRC, ZTC, BRC, DRC, NRC, AC)
- **ZGW business rules** — Tests business logic, edge cases, and cross-API interactions

## Environment Variables

The `zgw-environment.json` file maps ZGW API URLs to Procest endpoints:

| Variable | Endpoint |
|----------|----------|
| `zrc_url` | `/api/zgw/zaken/v1` |
| `ztc_url` | `/api/zgw/catalogi/v1` |
| `brc_url` | `/api/zgw/besluiten/v1` |
| `drc_url` | `/api/zgw/documenten/v1` |
| `nrc_url` | `/api/zgw/notificaties/v1` |
| `ac_url` | `/api/zgw/autorisaties/v1` |

Auth credentials (`client_id`, `secret`) are configured for test applicaties created by the repair step.

## Test Results

JSON reports are saved to `tests/zgw/results/`:
- `oas-results.json` — OAS test results
- `business-results.json` — Business rules results

The `results/` directory is gitignored.

## Interpreting Results

- **Green (PASSED)**: All assertions in the collection passed
- **Red (FAILED)**: One or more assertions failed — check the CLI output for details
- **Exit code 0**: All test suites passed
- **Exit code 1**: At least one test suite failed

For detailed failure analysis, inspect the JSON report files or review the CLI output which shows each failing assertion with the expected vs actual values.
