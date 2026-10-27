<?php

$configuredProfile = env('MARKETING_RELEASE_PROFILE', 'marketing_only');

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
];
