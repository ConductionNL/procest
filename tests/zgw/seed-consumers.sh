#!/bin/bash
#
# Seed ZGW consumer applicaties for Newman tests.
#
# Creates JWT-authenticated consumers directly via PHP/OCC,
# bypassing the HTTP API which may not work with PHP's built-in server.
#
# Usage:
#   bash seed-consumers.sh   # Run from the Nextcloud server root (cd server)
#

set -uo pipefail

echo "Seeding ZGW consumers via OCC..."

# Use php to directly create consumers via OpenRegister's ConsumerMapper.
# This avoids HTTP routing issues with PHP's built-in server.
php -r '
define("OC_CONSOLE", 1);
require_once "lib/base.php";

$container = \OC::$server;

try {
    $mapper = $container->get("OCA\OpenRegister\Db\ConsumerMapper");
} catch (\Throwable $e) {
    echo "ERROR: ConsumerMapper not available: " . $e->getMessage() . "\n";
    exit(1);
}

$consumers = [
    [
        "name" => "procest-admin",
        "description" => "Procest Admin (CI test - superuser)",
        "authorizationType" => "jwt-zgw",
        "userId" => "admin",
        "authorizationConfiguration" => [
            "publicKey" => "procest-admin-secret-key-for-testing",
            "algorithm" => "HS256",
            "superuser" => true,
            "scopes" => [],
        ],
    ],
    [
        "name" => "procest-limited",
        "description" => "Procest Limited (CI test - restricted)",
        "authorizationType" => "jwt-zgw",
        "userId" => "admin",
        "authorizationConfiguration" => [
            "publicKey" => "procest-limited-secret-key-for-test",
            "algorithm" => "HS256",
            "superuser" => false,
            "scopes" => [
                ["component" => "ztc", "scopes" => ["zaaktypen.lezen"]],
                ["component" => "zrc", "scopes" => ["zaken.lezen"], "maxVertrouwelijkheidaanduiding" => "openbaar"],
            ],
        ],
    ],
];

$created = 0;
$skipped = 0;

foreach ($consumers as $data) {
    $existing = $mapper->findAll(filters: ["name" => $data["name"]]);
    if (count($existing) > 0) {
        echo "  ~ Consumer already exists: " . $data["name"] . "\n";
        $skipped++;
        continue;
    }

    $data["created"] = new \DateTime();
    $data["updated"] = new \DateTime();
    $mapper->createFromArray(object: $data);
    echo "  + Created consumer: " . $data["name"] . "\n";
    $created++;
}

echo "Consumer seeding complete: $created created, $skipped skipped.\n";
'

echo "Seed script done."
