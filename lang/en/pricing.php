<?php

/*
|--------------------------------------------------------------------------
| Pricing copy (English)
|--------------------------------------------------------------------------
|
| Plan names, descriptions and features, plus the pricing table's interface
| text. Amounts and Stripe IDs live in config/pricing.php.
|
*/

return [

    'tabs_label' => 'Plan type',
    'billing_label' => 'Billing period',

    'intervals' => [
        'monthly' => 'Monthly',
        'annual' => 'Annual',
    ],

    'units' => [
        'monthly' => 'month',
        'annual' => 'year',
    ],

    'per_unit' => ':currency / :unit',
    'free_months' => '{1} :count month free|[2,*] :count months free',
    'annual_offer' => 'Annual: :offer',
    'billed_monthly' => 'Billed monthly. No long-term contract.',
    'billed_annually' => 'Billed annually.',
    'save' => 'Save :amount',
    'choose' => 'Choose :plan',

    // Replaces the ':backups' entry in a plan's features, from config('pricing...backup_frequency').
    'backups' => [
        'daily' => 'Daily remote backups',
        'weekly' => 'Weekly remote backups',
        'monthly' => 'Monthly remote backups',
    ],

    'badges' => [
        'most_popular' => 'Most popular',
    ],

    // Shown under the plans for the currency being displayed.
    'currency_notes' => [
        'HUF' => 'Prices in Hungarian forint, per website. These are final prices; no VAT is added.',
    ],
    'licenses_note' => 'Third-party plugin and theme licenses are not included.',

    'product_lines' => [

        'wordpress' => [
            'label' => 'WordPress',
            'summary' => 'For company websites, blogs and landing pages.',
            'plans' => [
                'basic' => [
                    'name' => 'Basic',
                    'description' => 'Essential maintenance for smaller WordPress sites.',
                    'features' => [
                        'WordPress, plugin and theme updates',
                        ':backups',
                        'Uptime monitoring',
                        'Monthly security check',
                        'Activity log',
                    ],
                ],
                'business' => [
                    'name' => 'Business',
                    'description' => 'Regular maintenance for business websites.',
                    'features' => [
                        'Everything in Basic',
                        ':backups',
                        'Functional checks after important updates',
                        'Weekly security check',
                        'Plugin and theme license check',
                    ],
                ],
                'secure' => [
                    'name' => 'Secure',
                    'description' => 'Extra security and faster response.',
                    'features' => [
                        'Everything in Business',
                        ':backups',
                        'Daily security check',
                        'Malware cleanup',
                        'Priority response',
                        'Monthly maintenance report',
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
                    'description' => 'Essential maintenance for smaller WordPress sites.',
                    'features' => [
                        'WordPress, WooCommerce, plugin and theme updates',
                        ':backups',
                        'Uptime monitoring',
                        'Monthly security check',
                        'Activity log',
                    ],
                ],
                'business' => [
                    'name' => 'Business',
                    'description' => 'Regular maintenance for business websites.',
                    'features' => [
                        'Everything in Basic',
                        ':backups',
                        'Checkout flow check after updates',
                        'Payment and order email checks',
                        'Weekly security check',
                        'Plugin and theme license check',
                    ],
                ],
                'secure' => [
                    'name' => 'Secure',
                    'description' => 'Extra security and faster response.',
                    'features' => [
                        'Everything in Business',
                        ':backups',
                        'Daily security check',
                        'Malware cleanup',
                        'Priority response',
                        'Monthly maintenance report',
                    ],
                ],
            ],
        ],

    ],

];
