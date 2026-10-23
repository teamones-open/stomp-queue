<?php

declare(strict_types=1);

/**
 * Exit-code bridge for tests/integration.php.
 *
 * workerman's master process always exits 0, so CI cannot rely on the exit code
 * of the integration script itself. This checker reads the result file instead.
 *
 * Usage: php tests/integration-check.php [result.json]
 */

$file = $argv[1] ?? dirname(__DIR__) . '/.teones/tmp/it-result-latest.json';

if (!is_file($file)) {
    fwrite(STDERR, "[integration] result file not found: {$file}" . PHP_EOL);
    exit(1);
}

$result = json_decode((string)file_get_contents($file), true);
if (!is_array($result) || ($result['pass'] ?? false) !== true) {
    $detail = is_array($result) ? json_encode($result['checks'] ?? [], JSON_UNESCAPED_UNICODE) : '';
    fwrite(STDERR, '[integration] FAIL ' . $detail . PHP_EOL);
    exit(1);
}

echo '[integration] PASS delay=' . ($result['delay_elapsed'] ?? '?') . 's' . PHP_EOL;
exit(0);
