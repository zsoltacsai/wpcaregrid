<?php

/*
|--------------------------------------------------------------------------
| Pricing catalogue
|--------------------------------------------------------------------------
|
| Single source of truth for plans shown on the marketing site. Views never
| hardcode prices; they read them through App\Pricing\PricingCatalog.
|
| Amounts are whole currency units as displayed to the customer. Each price
| can carry a Stripe Price ID so checkout can be wired up without touching
| the markup. To add a currency, add it to `currencies` and add a matching
| key under every plan's `prices`.
|
*/

return [

    'default_currency' => env('PRICING_CURRENCY', 'HUF'),

    'default_product_line' => 'wordpress',

    'default_interval' => 'monthly',

    'currencies' => [
        'HUF' => [
            'symbol' => 'Ft',
            'symbol_position' => 'after',
            'decimals' => 0,
            'decimal_separator' => ',',
            // Narrow no-break space keeps "8 990" on one line.
            'thousands_separator' => "\u{202F}",
        ],
    ],

    'product_lines' => [

        'wordpress' => [
            'label' => 'WordPress',
            'summary' => 'For company websites, blogs and landing pages.',
            'plans' => [
                'basic' => [
                    'name' => 'Basic',
                    'description' => 'Routine care for a simple website.',
                    'features' => [
                        'WordPress, plugin and theme updates',
                        'Weekly remote backups',
                        'Uptime monitoring',
                        'Monthly security check',
                        'Activity log',
                    ],
                    'prices' => [
                        'HUF' => [
                            'monthly' => ['amount' => 8990, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_BASIC_MONTHLY_HUF')],
                            'annual' => ['amount' => 89900, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_BASIC_ANNUAL_HUF')],
                        ],
                    ],
                ],
                'business' => [
                    'name' => 'Business',
                    'description' => 'For sites your business relies on.',
                    'highlighted' => true,
                    'badge' => 'Most popular',
                    'features' => [
                        'Everything in Basic',
                        'Daily remote backups',
                        'Functional checks after important updates',
                        'Weekly security check',
                        'Plugin and theme license check',
                    ],
                    'prices' => [
                        'HUF' => [
                            'monthly' => ['amount' => 18990, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_BUSINESS_MONTHLY_HUF')],
                            'annual' => ['amount' => 189900, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_BUSINESS_ANNUAL_HUF')],
                        ],
                    ],
                ],
                'secure' => [
                    'name' => 'Secure',
                    'description' => 'Closer monitoring and faster response.',
                    'features' => [
                        'Everything in Business',
                        'Daily security check',
                        'Malware cleanup',
                        'Priority response',
                        'Monthly maintenance report',
                    ],
                    'prices' => [
                        'HUF' => [
                            'monthly' => ['amount' => 28990, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_SECURE_MONTHLY_HUF')],
                            'annual' => ['amount' => 289900, 'stripe_price_id' => env('STRIPE_PRICE_WORDPRESS_SECURE_ANNUAL_HUF')],
                        ],
                    ],
                ],
            ],
        ],

        'woocommerce' => [
            'label' => 'WooCommerce',
            'summary' => 'For online stores, with extra checks around the purchasing process.',
            'plans' => [
                'basic' => [
                    'name' => 'Basic',
                    'description' => 'Routine care for a smaller store.',
                    'features' => [
                        'WordPress, WooCommerce, plugin and theme updates',
                        'Daily remote backups',
                        'Uptime monitoring',
                        'Monthly security check',
                        'Activity log',
                    ],
                    'prices' => [
                        'HUF' => [
                            'monthly' => ['amount' => 14900, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_BASIC_MONTHLY_HUF')],
                            'annual' => ['amount' => 149000, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_BASIC_ANNUAL_HUF')],
                        ],
                    ],
                ],
                'business' => [
                    'name' => 'Business',
                    'description' => 'For stores that take orders every day.',
                    'highlighted' => true,
                    'badge' => 'Most popular',
                    'features' => [
                        'Everything in Basic',
                        'Checkout flow check after updates',
                        'Payment and order email checks',
                        'Weekly security check',
                        'Plugin and theme license check',
                    ],
                    'prices' => [
                        'HUF' => [
                            'monthly' => ['amount' => 24900, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_BUSINESS_MONTHLY_HUF')],
                            'annual' => ['amount' => 249000, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_BUSINESS_ANNUAL_HUF')],
                        ],
                    ],
                ],
                'secure' => [
                    'name' => 'Secure',
                    'description' => 'Closer monitoring for high-volume stores.',
                    'features' => [
                        'Everything in Business',
                        'Daily security check',
                        'Malware cleanup',
                        'Priority response',
                        'Monthly maintenance report',
                    ],
                    'prices' => [
                        'HUF' => [
                            'monthly' => ['amount' => 39900, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_SECURE_MONTHLY_HUF')],
                            'annual' => ['amount' => 399000, 'stripe_price_id' => env('STRIPE_PRICE_WOOCOMMERCE_SECURE_ANNUAL_HUF')],
                        ],
                    ],
                ],
            ],
        ],

    ],

];
