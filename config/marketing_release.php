<?php

$configuredProfile = env('MARKETING_RELEASE_PROFILE', 'marketing_only');
$configuredTestAppEnvironment = env('APP_ENV', 'production');
$configuredTestDeploymentEnvironment = env('CAREGRID_DEPLOYMENT_ENV', 'production');
$configuredEnforcementMode = env('MARKETING_RELEASE_ENFORCEMENT_MODE', 'enforced');
$configuredStagingExempt = env('MARKETING_RELEASE_STAGING_EXEMPT', false);

return [
    /*
    |--------------------------------------------------------------------------
    | Marketing release profile
    |--------------------------------------------------------------------------
    |
    | This selects the release surface, not a user's permission. In particular,
    | invitation_beta does not grant checkout, billing, or customer access.
    |
    */
    'profile' => is_string($configuredProfile)
        && in_array($configuredProfile, ['marketing_only', 'invitation_beta'], true)
            ? $configuredProfile
            : 'marketing_only',
    // Both exemptions are disabled by default and require exact server-side identities.
    'staging_exempt' => $configuredStagingExempt === true,

    // Snapshot server-side test identity at configuration load; request/runtime changes cannot alter it.
    'test_app_environment' => $configuredTestAppEnvironment,
    'test_deployment_environment' => $configuredTestDeploymentEnvironment,

    'enforcement_mode' => is_string($configuredEnforcementMode)
        && in_array($configuredEnforcementMode, ['enforced', 'testing'], true)
            ? $configuredEnforcementMode
            : 'enforced',
];
