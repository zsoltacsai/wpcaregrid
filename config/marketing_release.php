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
// staging_active lifts the marketing-only release restriction for the one isolated
// staging-next environment. Every condition must hold at configuration load; the gate
// re-checks them (plus the live database identity) on every request. Anything else
// silently falls back to marketing_only, so a production .env can never enable it.
$stagingActiveEligible = $configuredProfile === 'staging_active'
    && $configuredTestAppEnvironment === 'staging'
    && $configuredTestDeploymentEnvironment === 'staging_next'
    && $configuredAppUrl === 'https://staging-next.wpcaregrid.com'
    && ! filter_var($configuredAppDebug, FILTER_VALIDATE_BOOL)
    && $configuredEnforcementMode === 'enforced'
    && env('DB_CONNECTION', 'sqlite') === 'mysql'
    && env('DB_DATABASE') === 'wpcg_staging_next'
    && ! filter_var(env('STRIPE_ALLOW_LIVE_MODE', false), FILTER_VALIDATE_BOOL);
$stagingMailAllowlist = array_values(array_filter(array_map(
    static fn (string $entry): string => strtolower(trim($entry)),
    explode(',', (string) env('STAGING_MAIL_ALLOWLIST', '')),
), static fn (string $entry): bool => $entry !== ''));
$profileAllowed = ['marketing_only', 'invitation_beta'];
if ($stagingAdminQaEnabled) {
    $profileAllowed[] = 'staging_admin_qa';
}
if ($stagingActiveEligible) {
    $profileAllowed[] = 'staging_active';
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

    // staging_active: true only when the whole staging-next identity above is proven.
    'staging_active_enabled' => $stagingActiveEligible,
    // What the environment asked for (also when it was refused); used by system:check and the mail guard.
    'requested_profile' => is_string($configuredProfile) ? $configuredProfile : null,
    // Features that contact something outside this staging stack stay closed until the Owner opens them.
    'staging_active_features' => [
        'connector_api' => filter_var(env('STAGING_ACTIVE_CONNECTOR_API', false), FILTER_VALIDATE_BOOL),
        'external_services' => filter_var(env('STAGING_ACTIVE_EXTERNAL_SERVICES', false), FILTER_VALIDATE_BOOL),
    ],
    // Optional SHA-256 of the MySQL server_uuid; when set, the live database must match it.
    'staging_active_db_uuid_sha256' => env('STAGING_ACTIVE_DB_UUID_SHA256'),
    // Mail on staging-next goes only to these addresses or @domains (a log/array mailer is a sink and needs none).
    'staging_mail_allowlist' => $stagingMailAllowlist,
    // Snapshot server-side test identity at configuration load; request/runtime changes cannot alter it.
    'test_app_environment' => $configuredTestAppEnvironment,
    'test_deployment_environment' => $configuredTestDeploymentEnvironment,

    'enforcement_mode' => is_string($configuredEnforcementMode)
        && in_array($configuredEnforcementMode, ['enforced', 'testing'], true)
            ? $configuredEnforcementMode
            : 'enforced',
];
