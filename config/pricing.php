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
| Amounts are integer values in the currency's configured Stripe unit
| (HUF: 8 990 Ft = 899000). Annual values are generated from the fixed
| monthly matrix as 10 × monthly (2 months free); no FX arithmetic is used.
|
| Hungary has its own market. Every other billing country is in the
| international market, where customers choose fixed EUR, USD or GBP prices.
| There is no FX conversion.
|
*/

$monthlyMajor = [
    'HUF' => [
        'wordpress' => ['basic' => 8990, 'business' => 18990, 'secure' => 28990],
        'woocommerce' => ['basic' => 14900, 'business' => 24900, 'secure' => 39900],
    ],
    'EUR' => [
        'wordpress' => ['basic' => 49, 'business' => 89, 'secure' => 149],
        'woocommerce' => ['basic' => 69, 'business' => 99, 'secure' => 159],
    ],
    'USD' => [
        'wordpress' => ['basic' => 49, 'business' => 89, 'secure' => 149],
        'woocommerce' => ['basic' => 69, 'business' => 99, 'secure' => 159],
    ],
    'GBP' => [
        'wordpress' => ['basic' => 49, 'business' => 89, 'secure' => 149],
        'woocommerce' => ['basic' => 69, 'business' => 99, 'secure' => 159],
    ],
];

$marketByCurrency = ['HUF' => 'hu', 'EUR' => 'eu', 'USD' => 'international', 'GBP' => 'gb'];
$prices = [];
foreach ($monthlyMajor as $currency => $products) {
    foreach ($products as $product => $plans) {
        foreach ($plans as $plan => $monthly) {
            foreach (['monthly' => $monthly, 'annual' => $monthly * 10] as $interval => $majorAmount) {
                $key = strtoupper("STRIPE_PRICE_{$product}_{$plan}_{$interval}_{$currency}");
                $prices[] = [
                    'product_type' => $product,
                    'plan' => $plan,
                    'interval' => $interval,
                    'market' => $marketByCurrency[$currency],
                    'currency' => $currency,
                    'stripe_price_config_key' => $key,
                    'amount' => $majorAmount * 100,
                    'stripe_price_id' => env($key),
                    'active' => true,
                ];
            }
        }
    }
}

return [

    // Market whose prices the public pricing table shows.
    'display_market' => env('PRICING_DISPLAY_MARKET', 'hu'),

    'default_product_type' => 'wordpress',

    'default_interval' => 'monthly',

    'default_international_currency' => 'EUR',
    'enabled_currencies' => ['HUF', 'EUR', 'USD', 'GBP'],
    'market_groups' => ['hu' => 'HU', 'eu' => 'INTERNATIONAL', 'gb' => 'INTERNATIONAL', 'international' => 'INTERNATIONAL'],
    'currency_markets' => ['HUF' => 'hu', 'EUR' => 'eu', 'USD' => 'international', 'GBP' => 'gb'],

    /*
    | Display formats. `minor_units` is the ISO 4217 exponent used for stored
    | amounts; `display_decimals` limits price-entry precision. Set
    | `display_fractional_minor_units` to show a non-zero fractional amount
    | at the currency's minor-unit precision while keeping whole amounts
    | compact. Number formats are keyed by site locale ("8,990 Ft" in English,
    | "8 990 Ft" in Hungarian).
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
            'display_fractional_minor_units' => true,
            'formats' => [
                'en' => ['thousands_separator' => ',', 'decimal_separator' => '.'],
                'hu' => ['thousands_separator' => "\u{202F}", 'decimal_separator' => ',', 'symbol_position' => 'after'],
            ],
        ],
        'USD' => [
            'symbol' => '$',
            'symbol_position' => 'before',
            'minor_units' => 2,
            'display_decimals' => 0,
            'display_fractional_minor_units' => true,
            'formats' => [
                'en' => ['thousands_separator' => ',', 'decimal_separator' => '.'],
                'hu' => ['thousands_separator' => "\u{202F}", 'decimal_separator' => ',', 'symbol_position' => 'after'],
            ],
        ],
        'GBP' => [
            'symbol' => '£',
            'symbol_position' => 'before',
            'minor_units' => 2,
            'display_decimals' => 0,
            'display_fractional_minor_units' => true,
            'formats' => [
                'en' => ['thousands_separator' => ',', 'decimal_separator' => '.'],
                'hu' => ['thousands_separator' => "\u{202F}", 'decimal_separator' => ',', 'symbol_position' => 'after'],
            ],
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
            'group' => 'HU',
            'tax_treatment' => env('PRICING_HU_TAX_TREATMENT', 'final_price_no_vat_added'),
        ],
        'eu' => [
            'countries' => ['*'],
            'currency' => 'EUR',
            'group' => 'INTERNATIONAL',
            'tax_treatment' => 'undetermined',
        ],
        'gb' => [
            'countries' => [],
            'currency' => 'GBP',
            'group' => 'INTERNATIONAL',
            'tax_treatment' => 'undetermined',
        ],
        'international' => [
            'countries' => [],
            'currency' => 'USD',
            'group' => 'INTERNATIONAL',
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
    | The Stripe Product each product type's Prices must belong to;
    | pricing:verify-stripe checks every configured Price against it.
    */
    'stripe_products' => [
        'wordpress' => env('STRIPE_PRODUCT_WORDPRESS'),
        'woocommerce' => env('STRIPE_PRODUCT_WOOCOMMERCE'),
    ],

    /*
    | Prices. Stripe Price IDs come from the environment; a missing ID blocks
    | checkout for that price but does not hide it from the pricing table.
    | Set `active` to false to stop selling a price without deleting it.
    */
    'prices' => $prices,

];
