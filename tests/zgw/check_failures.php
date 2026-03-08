<?php
$j = json_decode(file_get_contents($argv[1]), true);
$filter = $argv[2] ?? '';
foreach ($j["run"]["executions"] as $e) {
    $name = $e["item"]["name"] ?? "?";
    if ($filter !== '' && strpos($name, $filter) === false) continue;
    foreach ($e["assertions"] ?? [] as $a) {
        if (($a["error"] ?? null) !== null) {
            echo $name . ": " . $a["error"]["message"] . "\n";
        }
    }
}
