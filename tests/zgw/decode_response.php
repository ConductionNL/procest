<?php
$j = json_decode(file_get_contents($argv[1]), true);
$target = $argv[2] ?? '';
foreach ($j["run"]["executions"] as $e) {
    $name = $e["item"]["name"] ?? "?";
    if ($target !== '' && strpos($name, $target) === false) continue;
    $hasFail = false;
    foreach ($e["assertions"] ?? [] as $a) {
        if (($a["error"] ?? null) !== null) { $hasFail = true; break; }
    }
    if (!$hasFail) continue;

    $resp = $e["response"] ?? [];
    $code = $resp["code"] ?? "?";
    echo "=== " . $name . " (HTTP " . $code . ") ===\n";

    $stream = $resp["stream"] ?? $resp["body"] ?? null;
    if (is_array($stream) && isset($stream["type"]) && $stream["type"] === "Buffer") {
        $bytes = $stream["data"] ?? [];
        $str = '';
        foreach ($bytes as $b) {
            if (is_int($b)) $str .= chr($b);
        }
        echo $str . "\n";
    } elseif (is_string($stream)) {
        echo $stream . "\n";
    }
    echo "\n";
}
