<?php

$configuredProfile = env('MARKETING_RELEASE_PROFILE', 'marketing_only');
$configuredTestAppEnvironment = env('APP_ENV', 'production');
$configuredTestDeploymentEnvironment = env('CAREGRID_DEPLOYMENT_ENV', 'production');
$configuredEnforcementMode = env('MARKETING_RELEASE_ENFORCEMENT_MODE', 'enforced');
$configuredAppUrl = env('APP_URL', 'https://wpcaregrid.com');
$configuredAppDebug = env('APP_DEBUG', false);
$stagingAdminQaEnabled = filter_var(env('STAGING_ADMIN_QA_ENABLED', false), FILTER_VALIDATE_BOOL)
    && filter_var(env('STAGING_ADMIN_QA_SYNTHETIC_DATA', false), FILTER_VALIDATE_BOOL)
    && $configuredTestAppEnvironment === 'staging'
    && $configuredTestDeploymentEnvironment === 'staging_next'
    && $configuredAppUrl === 'https://staging-next.wpcaregrid.com'
    && ! filter_var($configuredAppDebug, FILTER_VALIDATE_BOOL);
$profileAllowed = ['marketing_only', 'invitation_beta'];
if ($stagingAdminQaEnabled) {
    $profileAllowed[] = 'staging_admin_qa';
}

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
        && in_array($configuredProfile, $profileAllowed, true)
            ? $configuredProfile
            : 'marketing_only',
    // The QA surface requires an explicit non-production deployment identity,
    // a synthetic-only dataset declaration and debug=false at config load.
    'staging_admin_qa_enabled' => $stagingAdminQaEnabled,
    // Snapshot server-side test identity at configuration load; request/runtime changes cannot alter it.
    'test_app_environment' => $configuredTestAppEnvironment,
    'test_deployment_environment' => $configuredTestDeploymentEnvironment,

    'enforcement_mode' => is_string($configuredEnforcementMode)
        && in_array($configuredEnforcementMode, ['enforced', 'testing'], true)
            ? $configuredEnforcementMode
            : 'enforced',
];
