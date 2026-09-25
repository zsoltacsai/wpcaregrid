<?php

/*
|--------------------------------------------------------------------------
| Pricing catalogue
|--------------------------------------------------------------------------
|
| The single source of truth for plans and prices. The homepage, the
| checkout and (later) invoices and the customer portal all read it through
| App\Pricing\PricingCatalog. Wording lives in lang/{locale}/pricing.php.
|
| Concepts are kept separate:
|   plans      product type × tier: backup frequency, full feature list
|   markets    which billing countries a price list applies to, its
|              currency and its tax treatment
|   prices     one row per product type × tier × interval × market, with
|              the amount and the Stripe Price ID that checkout uses
|
| Amounts are in the currency's smallest unit, as Stripe expects them
| (HUF has two minor units: 8 990 Ft = 899000). Annual prices are stored
| explicitly (10 × monthly = 2 months free), never calculated.
|
| Only the Hungarian market has prices. The other markets are defined so the
| structure is ready, but they deliberately have no prices: no exchange-rate
| conversion, no invented amounts. Checkout refuses a market without prices.
|
*/

return [

    // Market whose prices the public pricing table shows.
    'display_market' => env('PRICING_DISPLAY_MARKET', 'hu'),

    'default_product_type' => 'wordpress',

    'default_interval' => 'monthly',

    /*
    | Display formats. `minor_units` is the ISO 4217 exponent used for stored
    | amounts; `display_decimals` is how many decimals customers see. Number
    | formats are keyed by site locale ("8,990 Ft" in English, "8 990 Ft" in
    | Hungarian).
    */
    'currencies' => [
        'HUF' => [
            'symbol' => 'Ft',
            'symbol_position' => 'after',
            'minor_units' => 2,
            'display_decimals' => 0,
            'formats' => [
                'en' => ['thousands_separator' => ',', 'decimal_separator' => '.'],
                'hu' => ['thousands_separator' => "\u{202F}", 'decimal_separator' => ','],
            ],
        ],
        'EUR' => [
            'symbol' => '€',
            'symbol_position' => 'before',
            'minor_units' => 2,
            'display_decimals' => 0,
            'formats' => ['en' => ['thousands_separator' => ',', 'decimal_separator' => '.']],
        ],
        'USD' => [
            'symbol' => '$',
            'symbol_position' => 'before',
            'minor_units' => 2,
            'display_decimals' => 0,
            'formats' => ['en' => ['thousands_separator' => ',', 'decimal_separator' => '.']],
        ],
        'GBP' => [
            'symbol' => '£',
            'symbol_position' => 'before',
            'minor_units' => 2,
            'display_decimals' => 0,
            'formats' => ['en' => ['thousands_separator' => ',', 'decimal_separator' => '.']],
        ],
    ],

    /*
    | Markets, matched by the customer's billing country (ISO 3166-1
    | alpha-2), in order; '*' matches any country. `tax_treatment` is a
    | business setting recorded on every checkout, not a tax calculation:
    |   final_price_no_vat_added  the shown price is what the customer pays
    |   undetermined              to be decided by the accounting/tax provider
    */
    'markets' => [
        'hu' => [
            'countries' => ['HU'],
            'currency' => 'HUF',
            'tax_treatment' => env('PRICING_HU_TAX_TREATMENT', 'final_price_no_vat_added'),
        ],
        'eu' => [
            'countries' => ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'],
            'currency' => 'EUR',
            'tax_treatment' => 'undetermined',
        ],
        'gb' => [
            'countries' => ['GB'],
            'currency' => 'GBP',
            'tax_treatment' => 'undetermined',
        ],
        'international' => [
            'countries' => ['*'],
            'currency' => 'USD',
            'tax_treatment' => 'undetermined',
        ],
    ],

    /*
    | Plans. `features` is the complete, resolved list for each plan (no
    | "everything in Basic"); the pricing cards derive that shorter wording
    | from these lists. Features with a frequency take it from
    | `backup_frequency` / `security_check_frequency`.
    */
    'plans' => [
        'wordpress' => [
            'basic' => [
                'backup_frequency' => 'monthly',
                'security_check_frequency' => 'monthly',
                'features' => [
                    'core_plugin_theme_updates',
                    'remote_backups',
                    'uptime_monitoring',
                    'security_check',
                    'activity_log',
                ],
            ],
            'business' => [
                'backup_frequency' => 'weekly',
                'security_check_frequency' => 'weekly',
                'highlighted' => true,
                'badge' => 'most_popular',
                'features' => [
                    'core_plugin_theme_updates',
                    'remote_backups',
                    'uptime_monitoring',
                    'security_check',
                    'activity_log',
                    'functional_checks',
                    'license_check',
                ],
            ],
            'secure' => [
                'backup_frequency' => 'daily',
                'security_check_frequency' => 'daily',
                'features' => [
                    'core_plugin_theme_updates',
                    'remote_backups',
                    'uptime_monitoring',
                    'security_check',
                    'activity_log',
                    'functional_checks',
                    'license_check',
                    'malware_cleanup',
                    'priority_response',
                    'monthly_report',
                ],
            ],
        ],
        'woocommerce' => [
            'basic' => [
                'backup_frequency' => 'weekly',
                'security_check_frequency' => 'monthly',
                'features' => [
                    'core_woocommerce_plugin_theme_updates',
                    'remote_backups',
                    'uptime_monitoring',
                    'security_check',
                    'activity_log',
                ],
            ],
            'business' => [
                'backup_frequency' => 'daily',
                'security_check_frequency' => 'weekly',
                'highlighted' => true,
                'badge' => 'most_popular',
                'features' => [
                    'core_woocommerce_plugin_theme_updates',
                    'remote_backups',
                    'uptime_monitoring',
                    'security_check',
                    'activity_log',
                    'checkout_flow_check',
                    'payment_email_checks',
                    'license_check',
                ],
            ],
            'secure' => [
                'backup_frequency' => 'daily',
                'security_check_frequency' => 'daily',
                'features' => [
                    'core_woocommerce_plugin_theme_updates',
                    'remote_backups',
                    'uptime_monitoring',
                    'security_check',
                    'activity_log',
                    'checkout_flow_check',
                    'payment_email_checks',
                    'license_check',
                    'malware_cleanup',
                    'priority_response',
                    'monthly_report',
                ],
            ],
        ],
    ],

    /*
    | Prices. Stripe Price IDs come from the environment; a missing ID blocks
    | checkout for that price but does not hide it from the pricing table.
    | Set `active` to false to stop selling a price without deleting it.
    */
    'prices' => [
        ['product_type' => 'wordpress', 'plan' => 'basic', 'interval' => 'monthly', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 899000, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_BASIC_MONTHLY_HUF'), 'active' => true],
        ['product_type' => 'wordpress', 'plan' => 'basic', 'interval' => 'annual', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 8990000, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_BASIC_ANNUAL_HUF'), 'active' => true],
        ['product_type' => 'wordpress', 'plan' => 'business', 'interval' => 'monthly', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 1899000, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_BUSINESS_MONTHLY_HUF'), 'active' => true],
        ['product_type' => 'wordpress', 'plan' => 'business', 'interval' => 'annual', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 18990000, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_BUSINESS_ANNUAL_HUF'), 'active' => true],
        ['product_type' => 'wordpress', 'plan' => 'secure', 'interval' => 'monthly', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 2899000, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_SECURE_MONTHLY_HUF'), 'active' => true],
        ['product_type' => 'wordpress', 'plan' => 'secure', 'interval' => 'annual', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 28990000, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_SECURE_ANNUAL_HUF'), 'active' => true],
        ['product_type' => 'woocommerce', 'plan' => 'basic', 'interval' => 'monthly', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 1490000, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_BASIC_MONTHLY_HUF'), 'active' => true],
        ['product_type' => 'woocommerce', 'plan' => 'basic', 'interval' => 'annual', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 14900000, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_BASIC_ANNUAL_HUF'), 'active' => true],
        ['product_type' => 'woocommerce', 'plan' => 'business', 'interval' => 'monthly', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 2490000, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_BUSINESS_MONTHLY_HUF'), 'active' => true],
        ['product_type' => 'woocommerce', 'plan' => 'business', 'interval' => 'annual', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 24900000, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_BUSINESS_ANNUAL_HUF'), 'active' => true],
        ['product_type' => 'woocommerce', 'plan' => 'secure', 'interval' => 'monthly', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 3990000, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_SECURE_MONTHLY_HUF'), 'active' => true],
        ['product_type' => 'woocommerce', 'plan' => 'secure', 'interval' => 'annual', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 39900000, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_SECURE_ANNUAL_HUF'), 'active' => true],
    ],

];
