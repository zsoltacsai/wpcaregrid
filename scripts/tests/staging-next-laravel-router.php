<?php

declare(strict_types=1);

$current = getenv('WPCG_CURRENT_APP');
if (! is_string($current) || $current === '' || ! is_link($current)) {
    http_response_code(503);
    exit;
}

$entry = $current.'/public/index.php';
if (! is_file($entry) || is_link($entry)) {
    http_response_code(503);
    exit;
}

require $entry;
