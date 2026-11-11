<?php

/*
| Platform Update Intelligence (docs/PLATFORM_UPDATE_INTELLIGENCE.md).
| Checks and reports only: nothing here can update, install or restart anything.
| The advisory endpoint is a fixed constant in code (App\Platform\Updates\OsvClient), never configuration.
*/
return [
    // Off by default: no scheduled outbound request until the Owner enables it for the environment.
    // Fail-closed: only a recognised "on" value (true, 1, yes, on) enables it. false, no, off, 0, empty, "disabled" and
    // any unknown or misspelled value read as off (a plain (bool) cast would turn every non-empty string, "off" included, on).
    'enabled' => filter_var(env('PLATFORM_UPDATES_ENABLED', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true,

    // Phase E1: read-only inventory of the GitHub Actions in .github/workflows/actions.lock. Off by default and only ever
    // effective while the feature above is on (PlatformUpdateGate::actionsInventoryEnabled). Inventory only: these
    // components have no advisory source and stay UNVERIFIED. Strict bool, like the switch above.
    'github_actions_inventory' => filter_var(env('PLATFORM_UPDATES_ACTIONS_INVENTORY', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true,

    'timeout' => 10,
    'connect_timeout' => 5,

    // Bounds of one check.
    'max_components' => 1500,
    'max_advisory_details' => 100,

    // Time budget of one check (detail lookups stop when it is used up) and the lock that must outlast it.
    'run_budget_seconds' => 900,
    'max_response_bytes' => 5 * 1024 * 1024,

    // A second check cannot start while one runs, and not more often than this.
    'lock_seconds' => 1800,
    'min_interval_seconds' => 300,
];
