<?php

header('Content-Type: application/json');

if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/oversized') {
    $body = str_repeat('x', 1024 * 1024);
    header('Content-Length: '.strlen($body));
    echo $body;

    return;
}

$body = '{"fixture":true}';
header('Content-Length: '.strlen($body));
echo $body;
