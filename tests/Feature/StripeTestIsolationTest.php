<?php

namespace Tests\Feature;

use App\Pricing\StripePriceVerifier;
use Tests\TestCase;

/**
 * The suite never sees real Stripe configuration, whatever the developer's
 * .env or shell holds (phpunit.xml forces these values): no test can reach
 * Stripe with real keys, and none passes only because real IDs are set.
 */
class StripeTestIsolationTest extends TestCase
{
    public function test_no_stripe_keys_secrets_or_ids_reach_the_tests(): void
    {
        $this->assertEmpty(config('services.stripe.key'));
        $this->assertEmpty(config('services.stripe.secret'));
        $this->assertEmpty(config('services.stripe.webhook_secret'));
        $this->assertFalse(config('services.stripe.allow_live_mode'));
        $this->assertFalse(app(StripePriceVerifier::class)->configured());

        $this->assertSame([], array_filter((array) config('pricing.stripe_products')));
        $this->assertSame([], array_filter(array_column((array) config('pricing.prices'), 'stripe_price_id')));
        $this->assertSame('http://localhost', config('app.url'));
    }
}
