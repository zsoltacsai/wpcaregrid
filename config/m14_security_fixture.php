<?php

return [
    // M14 is independent from the M8 fixture. Every capability is opt-in.
    'provisioning_enabled' => (bool) env('M14_SECURITY_FIXTURE_PROVISIONING_ENABLED', false),
    'fixture_enabled' => (bool) env('M14_SECURITY_FIXTURE_ENABLED', false),
    'controlled_polling_enabled' => (bool) env('M14_SECURITY_CONTROLLED_POLLING_ENABLED', false),
    'security_scan_enabled' => (bool) env('M14_SECURITY_SCAN_ENABLED', false),
    'scan_window_minutes' => (int) env('M14_SECURITY_SCAN_WINDOW_MINUTES', 10),
    'site_uuid' => env('M14_SECURITY_SITE_UUID'),
    'customer_uuid' => env('M14_SECURITY_CUSTOMER_UUID'),
    'connector_uuid' => env('M14_SECURITY_CONNECTOR_UUID'),
    'ttl_days' => (int) env('M14_SECURITY_FIXTURE_TTL_DAYS', 7),
    'customer_name' => 'WP CareGrid M14 Security Fixture',
    'customer_email' => 'm14-security-fixture@example.test',
    // Required Owner-approved, dedicated host. Intentionally unset by default.
    'site_host' => env('M14_SECURITY_SITE_HOST'),
    'purpose' => 'm14-security',
    'allowed_scan_types' => ['integrity'],
];
