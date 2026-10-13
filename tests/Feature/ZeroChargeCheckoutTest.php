<?php

namespace Tests\Feature;

use App\Checkout\CheckoutGateway;
use App\Checkout\CheckoutStatus;
use App\Checkout\StripeCheckoutGateway;
use App\Mail\AccountActivation;
use App\Mail\PaymentConfirmation;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\Promotion;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\SendsStripeWebhooks;
use Tests\Fakes\FakeCheckoutGateway;
use Tests\TestCase;

/**
 * A 100% coupon (docs/COUPONS.md): a legitimate zero-charge checkout. Stripe
 * reports payment_status "no_payment_required"; the signed webhook is still the
 * only thing that provisions, exactly once.
 */
class ZeroChargeCheckoutTest extends TestCase
{
    use RefreshDatabase, SendsStripeWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useWebhookSecret();
        Mail::fake();

        foreach (config('pricing.prices') as $i => $price) {
            config(["pricing.prices.{$i}.stripe_price_id" => "price_{$price['product_type']}_{$price['plan']}_{$price['interval']}_{$price['currency']}"]);
        }
    }

    private function coupon(string $percent, string $duration = 'once', array $overrides = []): Promotion
    {
        return Promotion::create($overrides + [
            'code' => 'FREEUPDATE', 'stripe_promotion_code_id' => 'promo_free', 'stripe_coupon_id' => 'coupon_free', 'discount_type' => 'percent',
            'percent_off' => $percent, 'duration' => $duration,
        ]);
    }

    private function intent(?Promotion $promotion = null): CheckoutIntent
    {
        return CheckoutIntent::create([
            'status' => CheckoutStatus::CheckoutCreated, 'email' => 'zero@company.example', 'first_name' => 'Zoe', 'last_name' => 'Zero', 'country' => 'HU',
            'product_type' => 'wordpress', 'plan' => 'business', 'interval' => 'monthly', 'market' => 'hu', 'currency' => 'HUF', 'amount' => 1899000,
            'tax_treatment' => 'final_price_no_vat_added', 'stripe_price_id' => 'price_wordpress_business_monthly_HUF',
            // The first one is the session the webhook tests refer to; any further ones (one test builds several) are unique.
            'stripe_checkout_session_id' => CheckoutIntent::exists() ? 'cs_test_'.Str::random(12) : 'cs_test_zero',
            'promotion_id' => $promotion?->id, 'promotion_code' => $promotion?->code,
        ]);
    }

    /** What Stripe sends when the total is zero. */
    private function zeroSession(CheckoutIntent $intent, string $type = 'checkout.session.completed', ?string $eventId = null)
    {
        return $this->stripeEvent($type, [
            'id' => $intent->stripe_checkout_session_id, 'object' => 'checkout.session', 'client_reference_id' => $intent->uuid,
            'payment_status' => 'no_payment_required', 'amount_total' => 0, 'customer' => 'cus_zero', 'subscription' => 'sub_zero',
        ], $eventId);
    }

    public function test_the_session_asks_for_no_payment_method_only_when_nothing_will_ever_be_charged(): void
    {
        $gateway = new StripeCheckoutGateway('sk_test_x');

        $forever = $this->coupon('100', 'forever');
        $this->assertTrue($forever->makesEveryInvoiceFree());
        $parameters = $gateway->sessionParameters($this->intent($forever), $forever);
        $this->assertSame('if_required', $parameters['payment_method_collection']);
        $this->assertSame([['promotion_code' => 'promo_free']], $parameters['discounts']);
        $this->assertSame('subscription', $parameters['mode']);
        $this->assertSame('price_wordpress_business_monthly_HUF', $parameters['line_items'][0]['price'], 'Still the catalogue price; the discount is Stripe\'s.');

        // 100% once or for some months: the subscription is charged afterwards, so a payment method is collected.
        foreach ([['100', 'once'], ['100', 'repeating'], ['99.99', 'forever'], ['50', 'forever'], ['10', 'once']] as $i => [$percent, $duration]) {
            $other = $this->coupon($percent, $duration, ['code' => 'OTHER'.$i, 'stripe_promotion_code_id' => 'promo_o'.$i]);
            $this->assertFalse($other->makesEveryInvoiceFree(), "{$percent}% {$duration}");
            $this->assertArrayNotHasKey('payment_method_collection', $gateway->sessionParameters($this->intent($other), $other), "{$percent}% {$duration}");
        }

        // Without a coupon nothing changes.
        $this->assertArrayNotHasKey('payment_method_collection', $gateway->sessionParameters($this->intent(), null));
        $this->assertArrayNotHasKey('discounts', $gateway->sessionParameters($this->intent(), null));
    }

    public function test_the_checkout_never_shows_or_sends_a_negative_or_browser_chosen_amount(): void
    {
        $gateway = new FakeCheckoutGateway;
        $this->app->instance(CheckoutGateway::class, $gateway);
        $this->coupon('100', 'forever');

        $this->withCookie(config('session.cookie'), str_repeat('z', 40))->post(route('checkout.store'), [
            'product' => 'wordpress', 'plan' => 'business', 'interval' => 'monthly', 'email' => 'zero@company.example', 'first_name' => 'Zoe', 'last_name' => 'Zero', 'country' => 'HU', 'currency' => 'HUF',
            'promotion_code' => 'freeupdate', 'amount' => 0, 'total' => 0, 'stripe_price_id' => 'price_free',
        ])->assertStatus(303);

        $intent = CheckoutIntent::sole();
        $this->assertSame(1899000, $intent->amount, 'The intent keeps the list price; Stripe applies the discount.');
        $this->assertSame('price_wordpress_business_monthly_HUF', $intent->stripe_price_id);
        $this->assertSame('HUF', $intent->currency);
        $this->assertTrue($gateway->created[0]['promotion']->isFullDiscount());
    }

    public function test_a_zero_total_checkout_is_provisioned_only_by_the_signed_webhook_and_exactly_once(): void
    {
        $promotion = $this->coupon('100', 'forever', ['max_redemptions' => 3]);
        $intent = $this->intent($promotion);

        // The browser reaching the success page changes nothing.
        $this->get(route('checkout.success', ['session_id' => 'cs_test_zero']))->assertSuccessful();
        $this->assertSame(CheckoutStatus::CheckoutCreated, $intent->refresh()->status);
        $this->assertDatabaseCount('customers', 0);

        // An unsigned or wrongly signed event is refused.
        $this->stripeEvent('checkout.session.completed', ['id' => 'cs_test_zero', 'client_reference_id' => $intent->uuid, 'payment_status' => 'no_payment_required'], secret: 'whsec_other')->assertStatus(400);
        $this->assertDatabaseCount('customers', 0);

        $this->zeroSession($intent, eventId: 'evt_zero_1')->assertStatus(204);

        $intent->refresh();
        $this->assertSame(CheckoutStatus::Completed, $intent->status);
        $this->assertSame('no_payment_required', $intent->stripe_payment_status);
        $this->assertSame(1, Customer::count());
        $this->assertSame(1, User::count());
        $this->assertSame(1, Subscription::count());
        $this->assertSame(1, $promotion->refresh()->times_redeemed);
        $subscription = Subscription::sole();
        $this->assertSame('sub_zero', $subscription->stripe_subscription_id);
        $this->assertFalse($subscription->grantsAccess(), 'Service starts when Stripe reports the subscription active, not before.');

        // Replays and the sibling events change nothing.
        $this->zeroSession($intent, eventId: 'evt_zero_1')->assertStatus(204);
        $this->zeroSession($intent, eventId: 'evt_zero_2')->assertStatus(204);
        $this->zeroSession($intent, 'checkout.session.async_payment_succeeded', 'evt_zero_3')->assertStatus(204);

        $this->assertSame(1, Customer::count());
        $this->assertSame(1, User::count());
        $this->assertSame(1, Subscription::count());
        $this->assertSame(1, $promotion->refresh()->times_redeemed, 'Counted once per completed checkout.');
        Mail::assertQueued(AccountActivation::class, 1);

        // Stripe then reports the subscription active and the $0 invoice paid.
        $this->stripeEvent('customer.subscription.created', $this->stripeSubscription('sub_zero', 'active', ['customer' => 'cus_zero', 'metadata' => ['checkout_intent' => $intent->uuid]]))->assertStatus(204);
        $this->stripeEvent('invoice.paid', [
            'id' => 'in_zero', 'object' => 'invoice', 'customer' => 'cus_zero', 'amount_paid' => 0, 'amount_due' => 0, 'currency' => 'huf',
            'parent' => ['subscription_details' => ['subscription' => 'sub_zero', 'metadata' => ['checkout_intent' => $intent->uuid]]],
        ])->assertStatus(204);

        $subscription->refresh();
        $this->assertTrue($subscription->grantsAccess());
        $this->assertSame('paid', $subscription->last_payment_status);
        $this->assertDatabaseHas('billing_email_outbox', ['notification' => 'subscription_activated']);
        Mail::assertNotQueued(PaymentConfirmation::class);
        $this->assertDatabaseMissing('billing_email_outbox', ['notification' => 'payment_received']);
        $this->assertSame(1, Subscription::count());
        $this->assertSame(1, Customer::count());
    }

    public function test_a_normal_paid_invoice_still_sends_the_payment_confirmation(): void
    {
        $intent = $this->intent();
        $this->stripeEvent('checkout.session.completed', ['id' => 'cs_test_zero', 'client_reference_id' => $intent->uuid, 'payment_status' => 'paid', 'customer' => 'cus_p', 'subscription' => 'sub_p'])->assertStatus(204);
        $this->stripeEvent('invoice.paid', [
            'id' => 'in_p', 'object' => 'invoice', 'customer' => 'cus_p', 'amount_paid' => 1899000, 'currency' => 'huf',
            'parent' => ['subscription_details' => ['subscription' => 'sub_p', 'metadata' => ['checkout_intent' => $intent->uuid]]],
        ])->assertStatus(204);

        $this->assertDatabaseHas('billing_email_outbox', ['event_key' => 'invoice:in_p:payment-received', 'notification' => 'payment_received']);
    }

    public function test_a_hundred_percent_coupon_obeys_the_same_rules_as_any_other(): void
    {
        $gateway = new FakeCheckoutGateway;
        $this->app->instance(CheckoutGateway::class, $gateway);
        $this->coupon('100', 'forever', ['max_redemptions' => 1, 'times_redeemed' => 1]);

        $this->withCookie(config('session.cookie'), str_repeat('z', 40))->post(route('checkout.store'), [
            'product' => 'wordpress', 'plan' => 'business', 'interval' => 'monthly', 'email' => 'zero@company.example', 'first_name' => 'Zoe', 'last_name' => 'Zero', 'country' => 'HU', 'currency' => 'HUF', 'promotion_code' => 'FREEUPDATE',
        ])->assertSessionHasErrors(['promotion_code' => __('checkout.promotion_errors.used_up')]);

        $this->assertSame([], $gateway->created);
        $this->assertDatabaseCount('checkout_intents', 0);
    }
}
