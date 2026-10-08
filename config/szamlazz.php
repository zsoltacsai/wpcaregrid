<?php

return [
    'provider' => env('INVOICE_PROVIDER', 'szamlazz_hu'),
    'provider_enabled' => env('SZAMLAZZ_PROVIDER_ENABLED', false),
    'dry_run' => env('SZAMLAZZ_DRY_RUN', true),
    'issuance_enabled' => env('SZAMLAZZ_ISSUANCE_ENABLED', false),
    'request_timeout_seconds' => (int) env('SZAMLAZZ_REQUEST_TIMEOUT', 10),
    'retry_limit' => (int) env('SZAMLAZZ_RETRY_LIMIT', 3),
    'credential_reference' => env('SZAMLAZZ_CREDENTIAL_REFERENCE', 'szamlazz_agent'),
    'default_language' => env('SZAMLAZZ_DEFAULT_LANGUAGE', 'hu'),
    'account_reference' => env('SZAMLAZZ_ACCOUNT_REFERENCE'),
    'invoice_prefix_reference' => env('SZAMLAZZ_INVOICE_PREFIX_REFERENCE'),
    'xsd_path' => resource_path('schemas/szamlazz-agent-xmlszamla.xsd'),
];
