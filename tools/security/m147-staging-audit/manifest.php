#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = __DIR__;
$files = ['audit.php', 'evidence.schema.json', 'README.md', 'REPORT_TEMPLATE.md', 'manifest.php'];
$manifest = ['manifest_version' => '1.0.0', 'files' => []];
foreach ($files as $name) {
    $path = $root.'/'.$name;
    if (is_link($path) || ! is_file($path)) {
        fwrite(STDERR, "BLOCKED: MANIFEST_FILE_UNAVAILABLE\n");
        exit(2);
    }
    $hash = hash_file('sha256', $path);
    if (! is_string($hash)) {
        fwrite(STDERR, "BLOCKED: MANIFEST_HASH_FAILED\n");
        exit(2);
    }
    $manifest['files'][] = ['path' => $name, 'sha256' => $hash, 'bytes' => filesize($path)];
}
echo json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
