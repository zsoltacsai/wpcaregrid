<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Stripe, test mode only for now. Keys come from the environment and are
    | never committed. A live key (sk_live_…) is refused unless
    | STRIPE_ALLOW_LIVE_MODE=true is set deliberately.
    */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'api_version' => '2026-09-30.endive',
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'allow_live_mode' => (bool) env('STRIPE_ALLOW_LIVE_MODE', false),
        'mode' => env('STRIPE_MODE', env('STRIPE_ALLOW_LIVE_MODE', false) ? 'live' : 'test'),
        'payment_method_portal_configuration' => env('STRIPE_PAYMENT_METHOD_PORTAL_CONFIGURATION'),
        // Owner → Pricing → Stripe Catalogue (docs/STRIPE_CATALOGUE.md). The catalogue is only ever read
        // unless the Owner turns apply_enabled on; changing LIVE needs live_apply_approved as well, and
        // a staging stack can never use a live key at all.
        'catalogue' => [
            'apply_enabled' => (bool) env('STRIPE_CATALOGUE_APPLY_ENABLED', false),
            'live_apply_approved' => (bool) env('STRIPE_CATALOGUE_LIVE_APPLY_APPROVED', false),
            'plan_ttl_minutes' => 30,
        ],
    ],

    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
