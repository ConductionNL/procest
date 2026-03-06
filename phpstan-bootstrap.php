<?php

/**
 * PHPStan bootstrap: register OCP/NCU autoloading.
 *
 * The nextcloud/ocp package has no autoload section in its composer.json,
 * so we register it manually so PHPStan can resolve OCP classes.
 */

$ocpDir = __DIR__ . '/vendor/nextcloud/ocp';
if (!is_dir($ocpDir)) {
    return;
}

spl_autoload_register(function (string $class): void {
    $prefixMap = [
        'OCP\\' => __DIR__ . '/vendor/nextcloud/ocp/OCP/',
        'NCU\\' => __DIR__ . '/vendor/nextcloud/ocp/NCU/',
    ];

    foreach ($prefixMap as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $dir . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
        break;
    }
});
