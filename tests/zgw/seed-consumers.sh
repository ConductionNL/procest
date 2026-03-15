#!/bin/bash
#
# Seed ZGW consumer applicaties for Newman tests.
#
# Creates JWT-authenticated consumers using php occ (which fully bootstraps
# Nextcloud including app autoloaders) by running an inline PHP script via
# the maintenance:run-script approach. Falls back to direct SQL if needed.
#
# Usage:
#   bash seed-consumers.sh   # Run from the Nextcloud server root (cd server)
#

set -uo pipefail

echo "Seeding ZGW consumers..."

# Write a temporary PHP script that occ can execute
SEED_SCRIPT=$(mktemp /tmp/seed-consumers-XXXXXX.php)

cat > "$SEED_SCRIPT" << 'EOPHP'
<?php
/**
 * Seed ZGW consumer applicaties for CI tests.
 *
 * This script is executed via: php -f <script> -- <server-root>
 * It bootstraps Nextcloud and creates consumers via ConsumerMapper.
 */

// Bootstrap Nextcloud
$serverRoot = $argv[1] ?? getcwd();
define('OC_CONSOLE', 1);
require_once $serverRoot . '/lib/base.php';

// Register the OpenRegister autoloader
$orAutoload = $serverRoot . '/apps/openregister/vendor/autoload.php';
if (file_exists($orAutoload)) {
    require_once $orAutoload;
}

// Get database connection
$db = \OC::$server->get(\OCP\IDBConnection::class);

// Check if oc_openregister_consumers table exists
try {
    $result = $db->executeQuery("SELECT COUNT(*) FROM oc_openregister_consumers");
    $count = $result->fetchOne();
    echo "  Found $count existing consumers in database.\n";
} catch (\Throwable $e) {
    echo "  ERROR: oc_openregister_consumers table does not exist.\n";
    echo "  This means OpenRegister's migrations didn't run. Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Define consumers to seed
$consumers = [
    [
        'name' => 'procest-admin',
        'description' => 'Procest Admin (CI test - superuser)',
        'authorization_type' => 'jwt-zgw',
        'user_id' => 'admin',
        'authorization_configuration' => json_encode([
            'publicKey' => 'procest-admin-secret-key-for-testing',
            'algorithm' => 'HS256',
            'superuser' => true,
            'scopes' => [],
        ]),
    ],
    [
        'name' => 'procest-limited',
        'description' => 'Procest Limited (CI test - restricted)',
        'authorization_type' => 'jwt-zgw',
        'user_id' => 'admin',
        'authorization_configuration' => json_encode([
            'publicKey' => 'procest-limited-secret-key-for-test',
            'algorithm' => 'HS256',
            'superuser' => false,
            'scopes' => [
                ['component' => 'ztc', 'scopes' => ['zaaktypen.lezen']],
                ['component' => 'zrc', 'scopes' => ['zaken.lezen'], 'maxVertrouwelijkheidaanduiding' => 'openbaar'],
            ],
        ]),
    ],
];

$created = 0;
$skipped = 0;

foreach ($consumers as $data) {
    // Check if consumer already exists
    $existing = $db->executeQuery(
        "SELECT id FROM oc_openregister_consumers WHERE name = ?",
        [$data['name']]
    )->fetchOne();

    if ($existing) {
        echo "  ~ Consumer already exists: {$data['name']}\n";
        $skipped++;
        continue;
    }

    // Insert via raw SQL (avoids needing the ConsumerMapper class)
    $now = (new \DateTime())->format('Y-m-d H:i:s');
    $uuid = \OC::$server->get(\OCP\Security\ISecureRandom::class)->generate(36);

    $db->executeStatement(
        "INSERT INTO oc_openregister_consumers (uuid, name, description, authorization_type, user_id, authorization_configuration, created, updated)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $uuid,
            $data['name'],
            $data['description'],
            $data['authorization_type'],
            $data['user_id'],
            $data['authorization_configuration'],
            $now,
            $now,
        ]
    );

    echo "  + Created consumer: {$data['name']}\n";
    $created++;
}

echo "Consumer seeding complete: $created created, $skipped skipped.\n";
EOPHP

echo "Running seed script..."
php -f "$SEED_SCRIPT" -- "$(pwd)"
EXIT_CODE=$?

rm -f "$SEED_SCRIPT"

if [ "$EXIT_CODE" -ne 0 ]; then
    echo "WARNING: Consumer seeding failed (exit code $EXIT_CODE). Newman tests may fail on auth-related tests."
fi

echo "Seed script done."
