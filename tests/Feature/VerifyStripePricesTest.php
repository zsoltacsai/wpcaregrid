<?php

namespace Tests\Feature;

use App\Pricing\StripePriceVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeStripePriceVerifier;
use Tests\TestCase;

/** pricing:verify-stripe: amount, currency, interval and product association of every configured Price. */
class VerifyStripePricesTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripePriceVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_unit']);
        foreach (config('pricing.prices') as $i => $price) {
            config(["pricing.prices.{$i}.stripe_price_id" => "price_{$price['product_type']}_{$price['plan']}_{$price['interval']}_{$price['currency']}"]);
        }
        config(['pricing.stripe_products' => ['wordpress' => 'prod_wordpress', 'woocommerce' => 'prod_woocommerce']]);

        // Stripe as configured: every Price matches and belongs to its product type's Product.
        $this->verifier = new FakeStripePriceVerifier;
        $this->app->instance(StripePriceVerifier::class, $this->verifier);
    }

    public function test_passes_when_every_price_matches_and_belongs_to_its_product(): void
    {
        $this->artisan('pricing:verify-stripe')->assertSuccessful();
    }

    public function test_a_price_attached_to_the_other_product_fails(): void
    {
        $this->verifier->products['price_wordpress_business_monthly_HUF'] = 'prod_woocommerce';

        $this->artisan('pricing:verify-stripe')
            ->expectsOutputToContain('product prod_woocommerce')
            ->assertFailed();
    }

    public function test_an_unconfigured_product_fails_instead_of_being_skipped(): void
    {
        config(['pricing.stripe_products.woocommerce' => null]);

        $this->artisan('pricing:verify-stripe')
            ->expectsOutputToContain('product not configured (STRIPE_PRODUCT_WOOCOMMERCE)')
            ->assertFailed();
    }

    public function test_without_a_secret_nothing_is_checked(): void
    {
        config(['services.stripe.secret' => null]);

        $this->artisan('pricing:verify-stripe')->expectsOutputToContain('STRIPE_SECRET is not set.')->assertFailed();
    }
}
