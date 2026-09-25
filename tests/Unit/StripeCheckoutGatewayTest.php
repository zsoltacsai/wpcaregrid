<?php

namespace Tests\Unit;

use App\Checkout\CheckoutUnavailable;
use App\Checkout\StripeCheckoutGateway;
use App\Models\CheckoutIntent;
use App\Models\Promotion;
use Tests\TestCase;

class StripeCheckoutGatewayTest extends TestCase
{
    private function intent(): CheckoutIntent
    {
        return new CheckoutIntent([
            'uuid' => '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b',
            'email' => 'customer@company.example',
            'product_type' => 'woocommerce',
            'plan' => 'business',
            'interval' => 'annual',
            'market' => 'hu',
            'stripe_price_id' => 'price_server_resolved',
        ]);
    }

    public function test_session_uses_the_intent_price_in_subscription_mode(): void
    {
        $parameters = (new StripeCheckoutGateway('sk_test_x'))->sessionParameters($this->intent(), null);

        $this->assertSame('subscription', $parameters['mode']);
        $this->assertSame([['price' => 'price_server_resolved', 'quantity' => 1]], $parameters['line_items']);
        $this->assertSame('customer@company.example', $parameters['customer_email']);
        $this->assertSame('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $parameters['client_reference_id']);
        $this->assertSame('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $parameters['subscription_data']['metadata']['checkout_intent']);
        $this->assertStringEndsWith('/checkout/success?session_id={CHECKOUT_SESSION_ID}', $parameters['success_url']);
        $this->assertStringEndsWith('/checkout/cancel/0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $parameters['cancel_url']);
        $this->assertArrayNotHasKey('discounts', $parameters);
        $this->assertArrayNotHasKey('allow_promotion_codes', $parameters);
    }

    public function test_eligible_promotion_is_applied_by_id(): void
    {
        $promotion = new Promotion(['stripe_promotion_code_id' => 'promo_123']);
        $parameters = (new StripeCheckoutGateway('sk_test_x'))->sessionParameters($this->intent(), $promotion);

        $this->assertSame([['promotion_code' => 'promo_123']], $parameters['discounts']);
    }

    public function test_missing_secret_is_refused(): void
    {
        $this->expectException(CheckoutUnavailable::class);

        (new StripeCheckoutGateway(null))->createSession($this->intent(), null);
    }

    public function test_live_key_is_refused_unless_enabled(): void
    {
        $this->expectException(CheckoutUnavailable::class);
        $this->expectExceptionMessage('live Stripe key');

        (new StripeCheckoutGateway('sk_live_x'))->createSession($this->intent(), null);
    }
}
