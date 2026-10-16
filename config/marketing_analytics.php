<?php

return [
    // External collection is an explicit production configuration choice.
    'enabled' => filter_var(env('MARKETING_ANALYTICS_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Separate reviewed-policy gate. Enabling analytics alone cannot authorize collection.
    'policy_authorized' => filter_var(env('MARKETING_ANALYTICS_POLICY_AUTHORIZED', false), FILTER_VALIDATE_BOOL),
    'measurement_id' => (string) env('GA4_MEASUREMENT_ID', ''),

    // Technical preference format version, not a legal policy revision.
    'consent_version' => (string) env('MARKETING_ANALYTICS_CONSENT_VERSION', 'technical-v1'),
];
