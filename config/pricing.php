<?php

/*
|--------------------------------------------------------------------------
| Pricing catalogue
|--------------------------------------------------------------------------
|
| Amounts and billing identifiers for every plan. Views never hardcode
| prices; they read them through App\Pricing\PricingCatalog. Plan names,
| descriptions and features are translated in lang/{locale}/pricing.php.
|
| Each plan has a list of price sets. A price set is one currency (and,
| optionally, one locale) with a monthly and an annual price, each of which
| can carry a Stripe Price ID. Amounts are whole units as shown to the
| customer.
|
| `backup_frequency` (daily, weekly or monthly) drives the backup line in
| each plan's feature list, so the two cannot disagree.
|
| Only HUF prices exist today. EUR, USD and GBP have display formats so they
| can be switched on by adding price sets; there are deliberately no
| converted amounts.
|
*/

return [

    // Currency shown for each site locale. Add 'en' => 'EUR' etc. once
    // international price sets exist.
    'locale_currencies' => [
        'en' => 'HUF',
        'hu' => 'HUF',
    ],

    'default_currency' => env('PRICING_CURRENCY', 'HUF'),

    'default_product_line' => 'wordpress',

    'default_interval' => 'monthly',

    /*
    | Display formats. `formats` is keyed by site locale so the same amount
    | reads naturally in each language ("8,990 Ft" vs "8 990 Ft").
    */
    'currencies' => [
        'HUF' => [
            'symbol' => 'Ft',
            'symbol_position' => 'after',
            'decimals' => 0,
            'formats' => [
                'en' => ['thousands_separator' => ',', 'decimal_separator' => '.'],
                'hu' => ['thousands_separator' => "\u{202F}", 'decimal_separator' => ','],
            ],
        ],
        'EUR' => [
            'symbol' => '€',
            'symbol_position' => 'before',
            'decimals' => 0,
            'formats' => [
                'en' => ['thousands_separator' => ',', 'decimal_separator' => '.'],
            ],
        ],
        'USD' => [
            'symbol' => '$',
            'symbol_position' => 'before',
            'decimals' => 0,
            'formats' => [
                'en' => ['thousands_separator' => ',', 'decimal_separator' => '.'],
            ],
        ],
        'GBP' => [
            'symbol' => '£',
            'symbol_position' => 'before',
            'decimals' => 0,
            'formats' => [
                'en' => ['thousands_separator' => ',', 'decimal_separator' => '.'],
            ],
        ],
    ],

    'product_lines' => [

        'wordpress' => [
            'plans' => [
                'basic' => [
                    'backup_frequency' => 'monthly',
                    'prices' => [
                        [
                            'currency' => 'HUF',
                            'locale' => null,
                            'monthly' => ['amount' => 8990, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_BASIC_MONTHLY_HUF')],
                            'annual' => ['amount' => 89900, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_BASIC_ANNUAL_HUF')],
                        ],
                    ],
                ],
                'business' => [
                    'backup_frequency' => 'weekly',
                    'highlighted' => true,
                    'badge' => 'most_popular',
                    'prices' => [
                        [
                            'currency' => 'HUF',
                            'locale' => null,
                            'monthly' => ['amount' => 18990, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_BUSINESS_MONTHLY_HUF')],
                            'annual' => ['amount' => 189900, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_BUSINESS_ANNUAL_HUF')],
                        ],
                    ],
                ],
                'secure' => [
                    'backup_frequency' => 'daily',
                    'prices' => [
                        [
                            'currency' => 'HUF',
                            'locale' => null,
                            'monthly' => ['amount' => 28990, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_SECURE_MONTHLY_HUF')],
                            'annual' => ['amount' => 289900, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_SECURE_ANNUAL_HUF')],
                        ],
                    ],
                ],
            ],
        ],

        'woocommerce' => [
            'plans' => [
                'basic' => [
                    'backup_frequency' => 'weekly',
                    'prices' => [
                        [
                            'currency' => 'HUF',
                            'locale' => null,
                            'monthly' => ['amount' => 14900, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_BASIC_MONTHLY_HUF')],
                            'annual' => ['amount' => 149000, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_BASIC_ANNUAL_HUF')],
                        ],
                    ],
                ],
                'business' => [
                    'backup_frequency' => 'daily',
                    'highlighted' => true,
                    'badge' => 'most_popular',
                    'prices' => [
                        [
                            'currency' => 'HUF',
                            'locale' => null,
                            'monthly' => ['amount' => 24900, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_BUSINESS_MONTHLY_HUF')],
                            'annual' => ['amount' => 249000, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_BUSINESS_ANNUAL_HUF')],
                        ],
                    ],
                ],
                'secure' => [
                    'backup_frequency' => 'daily',
                    'prices' => [
                        [
                            'currency' => 'HUF',
                            'locale' => null,
                            'monthly' => ['amount' => 39900, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_SECURE_MONTHLY_HUF')],
                            'annual' => ['amount' => 399000, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_SECURE_ANNUAL_HUF')],
                        ],
                    ],
                ],
            ],
        ],

    ],

];
