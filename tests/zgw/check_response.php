<?php
$j = json_decode(file_get_contents($argv[1]), true);
$target = $argv[2] ?? '';
$found = 0;
foreach ($j["run"]["executions"] as $e) {
    $name = $e["item"]["name"] ?? "?";
    if ($target !== '' && strpos($name, $target) === false) continue;
    $resp = $e["response"] ?? [];
    $code = $resp["code"] ?? "?";
    // Only show failures or specific targets
    $hasFail = false;
    foreach ($e["assertions"] ?? [] as $a) {
        if (($a["error"] ?? null) !== null) { $hasFail = true; break; }
    }
    if (!$hasFail && $target === '') continue;

    echo "=== " . $name . " (HTTP " . $code . ") ===\n";
    $stream = $resp["stream"] ?? $resp["body"] ?? "";
    if (is_array($stream)) {
        $stream = json_encode($stream);
    }
    if (is_string($stream) && $stream !== '') {
        $body = json_decode($stream, true);
        if ($body) {
            echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo substr($stream, 0, 500) . "\n";
        }
    }
    echo "\n";
    $found++;
    if ($found >= 3) break;
}
