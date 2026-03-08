#!/bin/bash
#
# ZGW Newman Test Runner for Procest
#
# Runs VNG ZGW Postman test collections against Procest's ZGW API endpoints.
# Can run on the host (delegates to Docker container) or inside the container directly.
#
# Usage:
#   bash run-zgw-tests.sh [options]
#
# Options:
#   --oas-only       Run only OAS specification tests
#   --business-only  Run only business rules tests
#   --folder <name>  Run only a specific folder (e.g., zrc, ztc, brc)
#   --bail           Stop on first failure (skip business rules if OAS fails)
#   --help           Show this help message

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATA_DIR="$(cd "$SCRIPT_DIR/../../data" && pwd)"
RESULTS_DIR="$SCRIPT_DIR/results"
ENV_FILE="$SCRIPT_DIR/zgw-environment.json"

OAS_COLLECTION="$DATA_DIR/ZGW OAS tests.postman_collection.json"
BUSINESS_COLLECTION="$DATA_DIR/ZGW business rules.postman_collection.json"

# Defaults
RUN_OAS=true
RUN_BUSINESS=true
FOLDER=""
BAIL=false

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

show_help() {
    head -n 16 "$0" | tail -n +2 | sed 's/^# \?//'
    exit 0
}

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --oas-only)
            RUN_BUSINESS=false
            shift
            ;;
        --business-only)
            RUN_OAS=false
            shift
            ;;
        --folder)
            FOLDER="$2"
            shift 2
            ;;
        --bail)
            BAIL=true
            shift
            ;;
        --help|-h)
            show_help
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            show_help
            ;;
    esac
done

# Detect environment: if not inside Docker, delegate to container
if [ ! -f /.dockerenv ] && [ -z "${NEWMAN_INSIDE_CONTAINER:-}" ]; then
    echo -e "${YELLOW}Running on host — delegating to Docker container...${NC}"

    CONTAINER_NAME="${CONTAINER_NAME:-nextcloud}"
    CONTAINER_APP_DIR="/var/www/html/custom_apps/procest"

    # Check if container is running
    if ! docker inspect "$CONTAINER_NAME" &>/dev/null; then
        echo -e "${RED}Error: Container '$CONTAINER_NAME' is not running.${NC}"
        echo "Start the environment first: docker compose up -d"
        exit 1
    fi

    # Build the command to run inside container
    CMD="cd $CONTAINER_APP_DIR/tests/zgw && NEWMAN_INSIDE_CONTAINER=1 bash run-zgw-tests.sh"
    for arg in "$@"; do
        CMD="$CMD $arg"
    done

    docker exec -w "$CONTAINER_APP_DIR/tests/zgw" "$CONTAINER_NAME" bash -c "$CMD"
    exit $?
fi

# Check Newman is available
if ! command -v newman &>/dev/null; then
    if command -v npx &>/dev/null && [ -f "$SCRIPT_DIR/package.json" ]; then
        NEWMAN="npx newman"
    else
        echo -e "${RED}Error: Newman is not installed.${NC}"
        echo ""
        echo "Install Newman with one of:"
        echo "  npm install -g newman"
        echo "  cd $(dirname "$0") && npm install"
        exit 1
    fi
else
    NEWMAN="newman"
fi

# Verify collections exist
if [ "$RUN_OAS" = true ] && [ ! -f "$OAS_COLLECTION" ]; then
    echo -e "${RED}Error: OAS test collection not found at:${NC}"
    echo "  $OAS_COLLECTION"
    exit 1
fi

if [ "$RUN_BUSINESS" = true ] && [ ! -f "$BUSINESS_COLLECTION" ]; then
    echo -e "${RED}Error: Business rules collection not found at:${NC}"
    echo "  $BUSINESS_COLLECTION"
    exit 1
fi

# Verify environment file exists
if [ ! -f "$ENV_FILE" ]; then
    echo -e "${RED}Error: Environment file not found at:${NC}"
    echo "  $ENV_FILE"
    exit 1
fi

# Create results directory
mkdir -p "$RESULTS_DIR"

# When running inside the container, rewrite localhost:8080 → localhost
# (Apache listens on port 80 internally, 8080 is only the host-side mapping).
if [ -n "${NEWMAN_INSIDE_CONTAINER:-}" ]; then
    CONTAINER_ENV_FILE="$RESULTS_DIR/.zgw-environment-container.json"
    sed 's|localhost:8080|localhost|g' "$ENV_FILE" > "$CONTAINER_ENV_FILE"
    ENV_FILE="$CONTAINER_ENV_FILE"
fi

OAS_EXIT=0
BUSINESS_EXIT=0

# Run a Newman collection with proper quoting
run_newman() {
    local collection="$1"
    local report_name="$2"

    local args=(
        run "$collection"
        --environment "$ENV_FILE"
        --reporters cli,json
        --reporter-json-export "$RESULTS_DIR/${report_name}.json"
        --insecure
    )

    if [ -n "$FOLDER" ]; then
        args+=(--folder "$FOLDER")
    fi

    $NEWMAN "${args[@]}"
}

# Run OAS tests
if [ "$RUN_OAS" = true ]; then
    echo ""
    echo -e "${YELLOW}========================================${NC}"
    echo -e "${YELLOW}  Running ZGW OAS Specification Tests   ${NC}"
    echo -e "${YELLOW}========================================${NC}"
    echo ""

    if run_newman "$OAS_COLLECTION" "oas-results"; then
        echo -e "\n${GREEN}OAS tests: PASSED${NC}"
    else
        OAS_EXIT=$?
        echo -e "\n${RED}OAS tests: FAILED (exit code $OAS_EXIT)${NC}"
    fi
fi

# Run business rules tests
if [ "$RUN_BUSINESS" = true ]; then
    if [ "$BAIL" = true ] && [ "$OAS_EXIT" -ne 0 ]; then
        echo -e "\n${YELLOW}Skipping business rules tests (--bail set and OAS tests failed)${NC}"
    else
        echo ""
        echo -e "${YELLOW}========================================${NC}"
        echo -e "${YELLOW}  Running ZGW Business Rules Tests      ${NC}"
        echo -e "${YELLOW}========================================${NC}"
        echo ""

        if run_newman "$BUSINESS_COLLECTION" "business-results"; then
            echo -e "\n${GREEN}Business rules tests: PASSED${NC}"
        else
            BUSINESS_EXIT=$?
            echo -e "\n${RED}Business rules tests: FAILED (exit code $BUSINESS_EXIT)${NC}"
        fi
    fi
fi

# Summary
echo ""
echo -e "${YELLOW}========================================${NC}"
echo -e "${YELLOW}  Test Summary                          ${NC}"
echo -e "${YELLOW}========================================${NC}"

if [ "$RUN_OAS" = true ]; then
    if [ "$OAS_EXIT" -eq 0 ]; then
        echo -e "  OAS tests:      ${GREEN}PASSED${NC}"
    else
        echo -e "  OAS tests:      ${RED}FAILED${NC}"
    fi
fi

if [ "$RUN_BUSINESS" = true ]; then
    if [ "$BUSINESS_EXIT" -eq 0 ]; then
        echo -e "  Business rules: ${GREEN}PASSED${NC}"
    else
        echo -e "  Business rules: ${RED}FAILED${NC}"
    fi
fi

echo ""
echo "JSON reports saved to: $RESULTS_DIR/"
echo ""

# Exit with failure if any test suite failed
if [ "$OAS_EXIT" -ne 0 ] || [ "$BUSINESS_EXIT" -ne 0 ]; then
    exit 1
fi
