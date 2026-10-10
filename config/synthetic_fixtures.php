<?php

return [
    // All capabilities are opt-in and are additionally bound to the staging identity.
    'provisioning_enabled' => (bool) env('SYNTHETIC_FIXTURE_PROVISIONING_ENABLED', false),
    'controlled_execution_enabled' => (bool) env('SYNTHETIC_FIXTURE_CONTROLLED_EXECUTION_ENABLED', false),
    'restore_enabled' => (bool) env('SYNTHETIC_FIXTURE_RESTORE_ENABLED', false),
    'site_uuid' => env('M8_SYNTHETIC_SITE_UUID'),
    'ttl_days' => (int) env('SYNTHETIC_FIXTURE_TTL_DAYS', 30),
    'staging_app_host' => 'staging.wpcaregrid.com',
    'staging_server_hostname' => 'stg1.wpcaregrid.com',
    'site_host' => 'm8-e2e.staging.wpcaregrid.com',
    'customer_email' => 'm8-e2e@example.test',
    'customer_name' => 'WP CareGrid M8 E2E',
    'purpose' => 'm8-e2e',
];
