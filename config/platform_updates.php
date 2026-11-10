<?php

/*
| Platform Update Intelligence (docs/PLATFORM_UPDATE_INTELLIGENCE.md).
| Checks and reports only: nothing here can update, install or restart anything.
| The advisory endpoint is a fixed constant in code (App\Platform\Updates\OsvClient), never configuration.
*/
return [
    // Off by default: no scheduled outbound request until the Owner enables it for the environment.
    'enabled' => (bool) env('PLATFORM_UPDATES_ENABLED', false),

    'timeout' => 10,
    'connect_timeout' => 5,

    // Bounds of one check.
    'max_components' => 1500,
    'max_advisory_details' => 100,
    'max_response_bytes' => 5 * 1024 * 1024,

    // A second check cannot start while one runs, and not more often than this.
    'lock_seconds' => 600,
    'min_interval_seconds' => 300,
];
