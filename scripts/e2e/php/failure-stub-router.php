<?php

declare(strict_types=1);

$repo = dirname(__DIR__, 3);
$modeFile = $repo.'/storage/e2e/failure-stub-mode.txt';
$mode = is_file($modeFile) ? trim((string) file_get_contents($modeFile)) : '';

if (($_SERVER['REQUEST_URI'] ?? '') !== '/api/v1/connector/heartbeat' || ! in_array($mode, ['invalid', 'timeout'], true)) {
    http_response_code(404);
    exit;
}

if ($mode === 'timeout') {
    sleep(22);
}

http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo 'Synthetic local E2E upstream failure.';
