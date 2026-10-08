<?php

return [
    // Foundation only. No tax rules or seller registration values are configured here.
    'identifiers' => [
        // Prefer a dedicated random secret in deployment. APP_KEY is a compatibility fallback.
        'fingerprint_key' => env('TAX_IDENTIFIER_FINGERPRINT_KEY'),
    ],
    'vies' => [
        'endpoint' => env('VIES_ENDPOINT', 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService'),
        'connect_timeout' => (int) env('VIES_CONNECT_TIMEOUT', 2),
        'response_timeout' => (int) env('VIES_RESPONSE_TIMEOUT', 4),
        'retries' => (int) env('VIES_RETRIES', 1),
        'retry_delay_ms' => (int) env('VIES_RETRY_DELAY_MS', 150),
        'rate_limit_per_minute' => (int) env('VIES_RATE_LIMIT_PER_MINUTE', 5),
        'cache_seconds' => (int) env('VIES_CACHE_SECONDS', 900),
        'supported_country_codes' => [
            'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
            'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
        ],
        // Country code used for invoicing can differ from the VAT-prefix accepted by VIES.
        'prefix_aliases' => ['GR' => 'EL'],
    ],
];
