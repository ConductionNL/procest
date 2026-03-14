#!/bin/bash
#
# Seed ZGW consumer applicaties for Newman tests.
#
# Creates JWT-authenticated consumers via the OpenRegister Consumers API.
# These match the credentials in tests/zgw/zgw-environment.json.
#
# Usage:
#   bash seed-consumers.sh                  # Uses http://localhost:8080
#   BASE_URL=http://localhost:80 bash seed-consumers.sh
#

set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
API_URL="$BASE_URL/index.php/apps/openregister/api/consumers"
AUTH="admin:admin"

echo "Seeding ZGW consumers at $API_URL ..."

# Create a consumer via the OpenRegister API.
# Args: name, secret, superuser (true/false), scopes_json
create_consumer() {
    local name="$1"
    local secret="$2"
    local superuser="$3"
    local scopes="$4"
    local description="${5:-$name (CI test)}"

    local payload
    payload=$(cat <<EOJSON
{
    "name": "$name",
    "description": "$description",
    "authorizationType": "jwt-zgw",
    "userId": "admin",
    "authorizationConfiguration": {
        "publicKey": "$secret",
        "algorithm": "HS256",
        "superuser": $superuser,
        "scopes": $scopes
    }
}
EOJSON
)

    local http_code
    http_code=$(curl -s -o /dev/null -w "%{http_code}" \
        -X POST "$API_URL" \
        -u "$AUTH" \
        -H "Content-Type: application/json" \
        -H "OCS-APIREQUEST: true" \
        -d "$payload")

    if [ "$http_code" = "200" ] || [ "$http_code" = "201" ]; then
        echo "  + Created consumer: $name"
    elif [ "$http_code" = "409" ]; then
        echo "  ~ Consumer already exists: $name"
    else
        echo "  ! Failed to create consumer: $name (HTTP $http_code)"
        # Show response body for debugging
        curl -s -X POST "$API_URL" \
            -u "$AUTH" \
            -H "Content-Type: application/json" \
            -H "OCS-APIREQUEST: true" \
            -d "$payload" | head -200
        echo ""
    fi
}

# procest-admin: superuser with full access (matches ZGW environment)
create_consumer \
    "procest-admin" \
    "procest-admin-secret-key-for-testing" \
    true \
    '[]' \
    "Procest Admin (CI test - superuser)"

# procest-limited: restricted scopes for authorization tests
create_consumer \
    "procest-limited" \
    "procest-limited-secret-key-for-test" \
    false \
    '[{"component":"ztc","scopes":["zaaktypen.lezen"]},{"component":"zrc","scopes":["zaken.lezen"],"maxVertrouwelijkheidaanduiding":"openbaar"}]' \
    "Procest Limited (CI test - restricted)"

echo "Consumer seeding complete."
