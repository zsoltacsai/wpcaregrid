<?php

namespace Tests\Feature;

use App\Checkout\CheckoutStatus;
use App\Models\CheckoutIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => self::SECRET]);
    }

    private function intent(CheckoutStatus $status = CheckoutStatus::CheckoutCreated): CheckoutIntent
    {
        return CheckoutIntent::create([
            'status' => $status,
            'email' => 'customer@company.example',
            'first_name' => 'Anna',
            'last_name' => 'Kovács',
            'country' => 'HU',
            'product_type' => 'wordpress',
            'plan' => 'business',
            'interval' => 'monthly',
            'market' => 'hu',
            'currency' => 'HUF',
            'amount' => 1899000,
            'tax_treatment' => 'final_price_no_vat_added',
            'stripe_price_id' => 'price_x',
            'stripe_checkout_session_id' => 'cs_test_123',
        ]);
    }

    /** @param  array<string, mixed>  $session */
    private function send(string $type, array $session, ?string $secret = self::SECRET)
    {
        $payload = json_encode([
            'id' => 'evt_test',
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $session + ['id' => 'cs_test_123', 'object' => 'checkout.session']],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? 'wrong');

        return $this->call('POST', route('stripe.webhook'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    public function test_unsigned_or_wrongly_signed_events_are_rejected(): void
    {
        $intent = $this->intent();

        $this->send('checkout.session.completed', ['payment_status' => 'paid'], secret: 'whsec_other')->assertStatus(400);
        $this->postJson(route('stripe.webhook'), ['type' => 'checkout.session.completed'])->assertStatus(400);

        $this->assertSame(CheckoutStatus::CheckoutCreated, $intent->refresh()->status);
    }

    public function test_missing_webhook_secret_is_an_error(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $this->send('checkout.session.completed', ['payment_status' => 'paid'])->assertStatus(500);
    }

    public function test_paid_checkout_completes_the_intent(): void
    {
        $intent = $this->intent();

        $this->send('checkout.session.completed', [
            'payment_status' => 'paid',
            'customer' => 'cus_123',
            'subscription' => 'sub_123',
        ])->assertStatus(204);

        $intent->refresh();
        $this->assertSame(CheckoutStatus::Completed, $intent->status);
        $this->assertNotNull($intent->completed_at);
        $this->assertSame('cus_123', $intent->stripe_customer_id);
        $this->assertSame('sub_123', $intent->stripe_subscription_id);
    }

    public function test_unpaid_completion_waits_for_payment(): void
    {
        $intent = $this->intent();

        $this->send('checkout.session.completed', ['payment_status' => 'unpaid'])->assertStatus(204);
        $this->assertSame(CheckoutStatus::CheckoutCreated, $intent->refresh()->status);

        $this->send('checkout.session.async_payment_succeeded', ['payment_status' => 'paid'])->assertStatus(204);
        $this->assertSame(CheckoutStatus::Completed, $intent->refresh()->status);
    }

    public function test_expired_session_expires_the_intent_but_never_a_completed_one(): void
    {
        $open = $this->intent();
        $this->send('checkout.session.expired', [])->assertStatus(204);
        $this->assertSame(CheckoutStatus::Expired, $open->refresh()->status);

        $open->update(['status' => CheckoutStatus::Completed]);
        $this->send('checkout.session.expired', [])->assertStatus(204);
        $this->assertSame(CheckoutStatus::Completed, $open->refresh()->status);
    }

    public function test_unrelated_events_are_acknowledged(): void
    {
        $this->send('invoice.paid', [])->assertStatus(204);
    }
}
