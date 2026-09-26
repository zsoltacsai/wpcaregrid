<?php

/*
|--------------------------------------------------------------------------
| WP CareGrid Connector
|--------------------------------------------------------------------------
|
| Server-side settings for the WordPress connector plugin. Intervals are
| sent to the connector, which enforces its own minimums.
|
*/

return [

    // Newest connector release; older connectors are flagged as outdated.
    'latest_version' => env('CONNECTOR_LATEST_VERSION', '0.4.0'),

    // Refuse plain-HTTP connector requests (on by default in production).
    'require_https' => (bool) env('CONNECTOR_REQUIRE_HTTPS', env('APP_ENV') === 'production'),

    'heartbeat_interval' => (int) env('CONNECTOR_HEARTBEAT_INTERVAL', 900),

    'poll_interval' => (int) env('CONNECTOR_POLL_INTERVAL', 300),

    // No contact for longer than this: "stale". Much longer: "disconnected".
    'stale_after' => (int) env('CONNECTOR_STALE_AFTER', 2700),

    'disconnected_after' => (int) env('CONNECTOR_DISCONNECTED_AFTER', 86400),

    'pairing_code_ttl' => (int) env('CONNECTOR_PAIRING_CODE_TTL', 1800),

    // A job not reported back after this many deliveries is expired.
    'max_job_attempts' => 5,

    // Jobs returned per poll.
    'jobs_per_poll' => 5,

    // Major WordPress core updates (e.g. 7.x → 8.0) are refused unless enabled.
    'allow_major_core_updates' => (bool) env('CONNECTOR_ALLOW_MAJOR_CORE_UPDATES', false),

    // Where `php artisan connector:package` writes the plugin ZIP.
    'package_path' => storage_path('app/private/connector'),

    'plugin_source' => base_path('wordpress/wp-caregrid-connector'),

];
