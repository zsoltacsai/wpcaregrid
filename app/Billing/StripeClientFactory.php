<?php

namespace App\Billing;

use Stripe\StripeClient;

/** Creates Stripe clients pinned to the application's reviewed API contract. */
final class StripeClientFactory
{
    public static function make(string $apiKey): StripeClient
    {
        return new StripeClient([
            'api_key' => $apiKey,
            'stripe_version' => (string) config('services.stripe.api_version'),
        ]);
    }
}
