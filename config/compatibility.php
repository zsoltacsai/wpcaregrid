<?php

return [
    'policy_version' => env('COMPATIBILITY_POLICY_VERSION', 'compatibility-policy-v1'),
    'evaluator_version' => '1',
    'dynamic_ttl_seconds' => (int) env('COMPATIBILITY_DYNAMIC_TTL_SECONDS', 1800),
    // High-impact mutations require a recently completed inventory/preflight.
    'operation_ttl_seconds' => (int) env('COMPATIBILITY_OPERATION_TTL_SECONDS', 300),
    'disk_reserve_bytes' => (int) env('COMPATIBILITY_DISK_RESERVE_BYTES', 52428800),
    'minimum_read_connector' => env('COMPATIBILITY_MINIMUM_READ_CONNECTOR', '0.4.5'),
    'minimum_mutating_connector' => env('COMPATIBILITY_MINIMUM_MUTATING_CONNECTOR', '0.4.6'),
    'declared_minimum_php' => '8.1',
    // Optional legacy visibility only. PHP 8.1 is EOL and remains UNKNOWN for
    // every operation until an explicitly reviewed legacy test policy exists.
    'legacy_eol_php_branches' => ['8.1'],
    'tested_php_branches' => ['8.2', '8.3', '8.4', '8.5'],
    'declared_minimum_wordpress' => '6.4',
    'tested_wordpress_branches' => ['6.8', '7.1'],
    'tested_mysql_branches' => ['8.4'],
    'tested_mariadb_branches' => ['10.11'],
];
