<?php

/*
|--------------------------------------------------------------------------
| Pricing copy (English)
|--------------------------------------------------------------------------
|
| Plan names, descriptions, feature labels and pricing table text. Which
| features a plan has, and all amounts, live in config/pricing.php.
|
*/

return [

    'tabs_label' => 'Plan type',
    'billing_label' => 'Billing period',

    // Currency selector: only currencies that have real prices are offered.
    'currency_label' => 'Currency',
    'currency_apply' => 'Apply',
    'currency_only' => 'Prices are currently in :currency only',

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
    'everything_in' => 'Everything in :plan',

    'badges' => [
        'most_popular' => 'Most popular',
    ],

    // Shown under the prices, per the market's tax treatment.
    'tax_notes' => [
        'final_price_no_vat_added' => 'Prices in Hungarian forint, per website. These are final prices; no VAT is added.',
        'undetermined' => '',
    ],
    'licenses_note' => 'Third-party plugin and theme licenses are not included.',

    'product_types' => [
        'wordpress' => [
            'label' => 'WordPress',
            'summary' => 'For company websites, blogs and landing pages.',
        ],
        'woocommerce' => [
            'label' => 'WooCommerce',
            'summary' => 'For online stores, with extra checks around the purchasing process.',
        ],
    ],

    'plans' => [
        'wordpress' => [
            'basic' => ['name' => 'Basic', 'description' => 'Essential maintenance for smaller WordPress sites.'],
            'business' => ['name' => 'Business', 'description' => 'Regular maintenance for business websites.'],
            'secure' => ['name' => 'Secure', 'description' => 'Additional plan-specific security checks and eligible malware-cleanup support.'],
        ],
        'woocommerce' => [
            'basic' => ['name' => 'Basic', 'description' => 'Core maintenance for smaller WooCommerce stores.'],
            'business' => ['name' => 'Business', 'description' => 'Regular maintenance for active WooCommerce stores.'],
            'secure' => ['name' => 'Secure', 'description' => 'Expanded maintenance and security checks for WooCommerce stores.'],
        ],
    ],

    'features' => [
        'core_plugin_theme_updates' => 'WordPress, plugin and theme updates',
        'core_woocommerce_plugin_theme_updates' => 'WordPress, WooCommerce, plugin and theme updates',
        'remote_backups' => [
            'daily' => 'Daily backups; separate storage where configured',
            'weekly' => 'Weekly backups; separate storage where configured',
            'monthly' => 'Monthly backups; separate storage where configured',
        ],
        'security_check' => [
            'daily' => 'Daily security check',
            'weekly' => 'Weekly security check',
            'monthly' => 'Monthly security check',
        ],
        'uptime_monitoring' => 'Uptime monitoring for supported sites',
        'activity_log' => 'Activity log',
        'functional_checks' => 'Functional checks after important updates',
        'license_check' => 'Plugin and theme license check',
        'checkout_flow_check' => 'Checkout flow check after updates',
        'payment_email_checks' => 'Payment and order email checks',
        'malware_cleanup' => 'Malware cleanup for eligible, supported cases',
        'priority_response' => 'Priority support according to plan scope',
        'monthly_report' => 'Monthly maintenance report',
    ],

];
