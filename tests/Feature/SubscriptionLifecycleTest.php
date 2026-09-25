<?php

namespace Tests\Feature;

use App\Billing\SubscriptionStatus;
use App\Checkout\CheckoutStatus;
use App\Mail\AccountActivation;
use App\Mail\PaymentConfirmation;
use App\Mail\PaymentFailed;
use App\Mail\SubscriptionActivated;
use App\Mail\SubscriptionCanceled;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SendsStripeWebhooks;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase, SendsStripeWebhooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useWebhookSecret();
        Mail::fake();
    }

    private function intent(array $attributes = []): CheckoutIntent
    {
        return CheckoutIntent::create($attributes + [
            'status' => CheckoutStatus::CheckoutCreated,
            'email' => 'Anna.Kovacs@Company.example',
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
            'stripe_price_id' => 'price_wordpress_business_monthly',
            'stripe_checkout_session_id' => 'cs_test_1',
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function completeCheckout(CheckoutIntent $intent, array $overrides = [], ?string $eventId = null, string $type = 'checkout.session.completed')
    {
        return $this->stripeEvent($type, $overrides + [
            'id' => $intent->stripe_checkout_session_id,
            'object' => 'checkout.session',
            'client_reference_id' => $intent->uuid,
            'payment_status' => 'paid',
            'customer' => 'cus_test_1',
            'subscription' => 'sub_test_1',
        ], $eventId);
    }

    private function subscriptionEvent(string $type, string $status, CheckoutIntent $intent, array $overrides = [], ?int $created = null, ?string $eventId = null)
    {
        return $this->stripeEvent($type, $this->stripeSubscription('sub_test_1', $status, $overrides + ['metadata' => ['checkout_intent' => $intent->uuid]]), $eventId, $created);
    }

    // --- Signature ---------------------------------------------------------

    public function test_unsigned_or_wrongly_signed_events_are_rejected(): void
    {
        $intent = $this->intent();

        $this->stripeEvent('checkout.session.completed', ['id' => 'cs_test_1', 'client_reference_id' => $intent->uuid, 'payment_status' => 'paid'], secret: 'whsec_other')->assertStatus(400);
        $this->postJson(route('stripe.webhook'), ['type' => 'checkout.session.completed'])->assertStatus(400);

        $this->assertSame(CheckoutStatus::CheckoutCreated, $intent->refresh()->status);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_missing_webhook_secret_is_an_error(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $this->stripeEvent('invoice.paid', ['id' => 'in_1'])->assertStatus(500);
    }

    // --- Confirmed payment -------------------------------------------------

    public function test_paid_checkout_creates_customer_user_and_subscription(): void
    {
        $intent = $this->intent();

        $this->completeCheckout($intent)->assertStatus(204);

        $intent->refresh();
        $customer = Customer::sole();
        $subscription = Subscription::sole();

        $this->assertSame(CheckoutStatus::Completed, $intent->status);
        $this->assertSame($customer->id, $intent->customer_id);

        $this->assertSame('anna.kovacs@company.example', $customer->email, 'Email is normalised.');
        $this->assertSame('cus_test_1', $customer->stripe_customer_id);
        $this->assertSame('HU', $customer->country_code);

        $user = $customer->user;
        $this->assertNotNull($user);
        $this->assertSame($customer->email, $user->email);
        $this->assertNull($user->email_verified_at, 'Verified only once the activation link is used.');

        $this->assertSame('sub_test_1', $subscription->stripe_subscription_id);
        $this->assertSame($customer->id, $subscription->customer_id);
        $this->assertSame($intent->id, $subscription->checkout_intent_id);
        // Stripe has not reported the subscription status yet: no access.
        $this->assertSame(SubscriptionStatus::Pending, $subscription->status);
        $this->assertFalse($customer->hasActiveSubscription());

        Mail::assertQueued(AccountActivation::class, fn ($mail) => $mail->hasTo('anna.kovacs@company.example'));
        Mail::assertNotQueued(SubscriptionActivated::class);
    }

    public function test_subscription_event_activates_access_and_announces_it_once(): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent);

        $this->subscriptionEvent('customer.subscription.created', 'active', $intent)->assertStatus(204);
        $this->subscriptionEvent('customer.subscription.updated', 'active', $intent)->assertStatus(204);

        $subscription = Subscription::sole();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNotNull($subscription->current_period_end);
        $this->assertNotNull($subscription->activated_at);
        $this->assertTrue($subscription->customer->hasActiveSubscription());

        Mail::assertQueued(SubscriptionActivated::class, 1);
    }

    public function test_events_in_reverse_order_resolve_to_one_customer_and_subscription(): void
    {
        $intent = $this->intent();

        // Stripe may deliver the subscription before the checkout session.
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent)->assertStatus(204);
        $this->completeCheckout($intent)->assertStatus(204);

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('subscriptions', 1);

        $subscription = Subscription::sole();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(Customer::sole()->id, $subscription->customer_id);
        $this->assertSame($intent->id, $subscription->checkout_intent_id);
        Mail::assertQueued(AccountActivation::class, 1);
        Mail::assertQueued(SubscriptionActivated::class, 1);
    }

    public function test_an_older_subscription_event_does_not_overwrite_newer_state(): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent);

        $this->subscriptionEvent('customer.subscription.updated', 'past_due', $intent, created: now()->timestamp);
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent, created: now()->subMinutes(5)->timestamp);

        $this->assertSame(SubscriptionStatus::PastDue, Subscription::sole()->status);
    }

    // --- Idempotency -------------------------------------------------------

    public function test_repeated_event_is_processed_once(): void
    {
        $intent = $this->intent();

        $this->completeCheckout($intent, eventId: 'evt_same')->assertStatus(204);
        $this->completeCheckout($intent, eventId: 'evt_same')->assertStatus(204);
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent, eventId: 'evt_sub')->assertStatus(204);
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent, eventId: 'evt_sub')->assertStatus(204);

        $this->assertDatabaseCount('stripe_events', 2);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('subscriptions', 1);
        Mail::assertQueued(AccountActivation::class, 1);
        Mail::assertQueued(SubscriptionActivated::class, 1);
    }

    public function test_different_events_for_the_same_subscription_do_not_duplicate_it(): void
    {
        $intent = $this->intent();

        $this->completeCheckout($intent);
        $this->completeCheckout($intent, type: 'checkout.session.async_payment_succeeded');
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent);
        $this->stripeEvent('invoice.paid', $this->invoice($intent));

        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_stored_events_hold_identifiers_only(): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent, eventId: 'evt_x');

        $event = StripeEvent::sole();
        $this->assertSame('evt_x', $event->stripe_event_id);
        $this->assertSame('checkout.session.completed', $event->type);
        $this->assertSame('cs_test_1', $event->object_id);
        $this->assertArrayNotHasKey('payload', $event->getAttributes());
    }

    // --- Payment not (yet) confirmed ----------------------------------------

    public function test_unpaid_checkout_does_not_provision_anything(): void
    {
        $intent = $this->intent();

        $this->completeCheckout($intent, ['payment_status' => 'unpaid'])->assertStatus(204);

        $this->assertSame(CheckoutStatus::CheckoutCreated, $intent->refresh()->status);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        Mail::assertNothingQueued();
    }

    public function test_async_payment_success_activates_correctly(): void
    {
        $intent = $this->intent();

        $this->completeCheckout($intent, ['payment_status' => 'unpaid']);
        $this->completeCheckout($intent, type: 'checkout.session.async_payment_succeeded');
        $this->subscriptionEvent('customer.subscription.updated', 'active', $intent);

        $this->assertSame(CheckoutStatus::Completed, $intent->refresh()->status);
        $this->assertTrue(Customer::sole()->hasActiveSubscription());
    }

    public function test_async_payment_failure_does_not_grant_access(): void
    {
        $intent = $this->intent();

        $this->completeCheckout($intent, ['payment_status' => 'unpaid']);
        $this->completeCheckout($intent, ['payment_status' => 'unpaid'], type: 'checkout.session.async_payment_failed');
        $this->subscriptionEvent('customer.subscription.updated', 'incomplete', $intent);

        $this->assertSame(CheckoutStatus::Failed, $intent->refresh()->status);
        $this->assertDatabaseCount('customers', 0);
        $this->assertSame(SubscriptionStatus::Incomplete, Subscription::sole()->status);
        $this->assertNull(Subscription::sole()->customer_id);
    }

    public function test_expired_session_expires_the_intent_but_never_a_completed_one(): void
    {
        $open = $this->intent();
        $this->stripeEvent('checkout.session.expired', ['id' => 'cs_test_1', 'client_reference_id' => $open->uuid])->assertStatus(204);
        $this->assertSame(CheckoutStatus::Expired, $open->refresh()->status);

        $open->update(['status' => CheckoutStatus::Completed]);
        $this->stripeEvent('checkout.session.expired', ['id' => 'cs_test_1', 'client_reference_id' => $open->uuid]);
        $this->assertSame(CheckoutStatus::Completed, $open->refresh()->status);
    }

    public function test_session_must_match_the_intent_it_names(): void
    {
        $intent = $this->intent();

        $this->stripeEvent('checkout.session.completed', [
            'id' => 'cs_test_other',
            'client_reference_id' => $intent->uuid,
            'payment_status' => 'paid',
            'customer' => 'cus_x',
            'subscription' => 'sub_x',
        ])->assertStatus(204);

        $this->assertSame(CheckoutStatus::CheckoutCreated, $intent->refresh()->status);
        $this->assertDatabaseCount('customers', 0);
    }

    // --- Status changes ----------------------------------------------------

    /** @return array<string, array{string, SubscriptionStatus}> */
    public static function stripeStatuses(): array
    {
        return [
            'trialing' => ['trialing', SubscriptionStatus::Trialing],
            'past_due' => ['past_due', SubscriptionStatus::PastDue],
            'unpaid' => ['unpaid', SubscriptionStatus::Unpaid],
            'incomplete' => ['incomplete', SubscriptionStatus::Incomplete],
            'incomplete_expired' => ['incomplete_expired', SubscriptionStatus::IncompleteExpired],
            'paused' => ['paused', SubscriptionStatus::Paused],
            'canceled' => ['canceled', SubscriptionStatus::Canceled],
        ];
    }

    #[DataProvider('stripeStatuses')]
    public function test_stripe_statuses_map_explicitly(string $stripe, SubscriptionStatus $local): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent);
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent, created: now()->subMinute()->timestamp);

        $this->subscriptionEvent('customer.subscription.updated', $stripe, $intent);

        $subscription = Subscription::sole();
        $this->assertSame($local, $subscription->status);
        $this->assertSame($local->grantsAccess(), $subscription->customer->hasActiveSubscription());
    }

    public function test_past_due_and_unpaid_remove_access(): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent);
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent, created: now()->subMinutes(2)->timestamp);
        $customer = Customer::sole();
        $this->assertTrue($customer->hasActiveSubscription());

        $this->subscriptionEvent('customer.subscription.updated', 'past_due', $intent, created: now()->subMinute()->timestamp);
        $this->assertFalse($customer->hasActiveSubscription());

        $this->subscriptionEvent('customer.subscription.updated', 'unpaid', $intent);
        $this->assertFalse($customer->hasActiveSubscription());
        $this->assertSame(SubscriptionStatus::Unpaid, Subscription::sole()->status);
    }

    public function test_cancellation_at_period_end_and_deletion(): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent);
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent, created: now()->subMinutes(2)->timestamp);

        $this->subscriptionEvent('customer.subscription.updated', 'active', $intent, ['cancel_at_period_end' => true], created: now()->subMinute()->timestamp);
        $subscription = Subscription::sole();
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertNull($subscription->nextBillingDate());
        $this->assertTrue($subscription->grantsAccess());

        $this->subscriptionEvent('customer.subscription.deleted', 'canceled', $intent, ['canceled_at' => now()->timestamp, 'ended_at' => now()->timestamp]);
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->status);
        $this->assertNotNull($subscription->canceled_at);
        $this->assertNotNull($subscription->ended_at);
        $this->assertFalse($subscription->customer->hasActiveSubscription());
        Mail::assertQueued(SubscriptionCanceled::class, 1);
    }

    public function test_unknown_stripe_status_is_ignored(): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent);

        $this->subscriptionEvent('customer.subscription.updated', 'something_new', $intent)->assertStatus(204);

        $this->assertSame(SubscriptionStatus::Pending, Subscription::sole()->status);
    }

    // --- Invoices ----------------------------------------------------------

    private function invoice(CheckoutIntent $intent, array $overrides = []): array
    {
        return $overrides + [
            'id' => 'in_test_1',
            'object' => 'invoice',
            'customer' => 'cus_test_1',
            'currency' => 'huf',
            'amount_paid' => 1899000,
            'amount_due' => 1899000,
            'parent' => ['subscription_details' => ['subscription' => 'sub_test_1', 'metadata' => ['checkout_intent' => $intent->uuid]]],
        ];
    }

    public function test_invoice_paid_records_the_payment(): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent);

        $this->stripeEvent('invoice.paid', $this->invoice($intent))->assertStatus(204);

        $subscription = Subscription::sole();
        $this->assertSame('paid', $subscription->last_payment_status);
        $this->assertNotNull($subscription->last_payment_at);
        Mail::assertQueued(PaymentConfirmation::class, fn ($mail) => $mail->amount->format() === "18,990\u{00A0}Ft");
    }

    public function test_failed_payment_does_not_grant_or_provision_anything(): void
    {
        $intent = $this->intent();

        // No confirmed payment yet: a failed invoice must not create a customer.
        $this->stripeEvent('invoice.payment_failed', $this->invoice($intent))->assertStatus(204);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('subscriptions', 0);

        // Existing customer: failure is recorded and notified, access unchanged until Stripe changes the status.
        $this->completeCheckout($intent);
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent);
        $this->stripeEvent('invoice.payment_failed', $this->invoice($intent, ['id' => 'in_test_2']))->assertStatus(204);

        $this->assertSame('failed', Subscription::sole()->last_payment_status);
        Mail::assertQueued(PaymentFailed::class, 1);
    }

    // --- Identity ----------------------------------------------------------

    public function test_existing_customer_is_reused_and_not_overwritten(): void
    {
        $existing = Customer::factory()->withUser()->create(['email' => 'anna.kovacs@company.example', 'first_name' => 'Anna', 'last_name' => 'Original', 'country_code' => 'HU']);
        $intent = $this->intent(['last_name' => 'Changed', 'country' => 'AT']);

        $this->completeCheckout($intent);

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('users', 1);
        $existing->refresh();
        $this->assertSame('Original', $existing->last_name, 'Checkout input must not overwrite an existing profile.');
        $this->assertSame('HU', $existing->country_code);
        $this->assertSame($existing->id, Subscription::sole()->customer_id);
        Mail::assertNotQueued(AccountActivation::class);
    }

    public function test_login_belonging_to_another_customer_is_not_taken_over(): void
    {
        $other = Customer::factory()->withUser()->create(['email' => 'someone@company.example']);
        $login = $other->user;
        // A customer whose email equals that login's email but is a different record.
        $login->forceFill(['email' => 'anna.kovacs@company.example'])->save();

        $this->completeCheckout($this->intent());

        $newCustomer = Customer::where('email', 'anna.kovacs@company.example')->sole();
        $this->assertNull($newCustomer->user_id);
        $this->assertSame($other->id, $login->refresh()->customer->id);
        Mail::assertNotQueued(AccountActivation::class);
    }

    public function test_customer_email_is_unique_and_normalised(): void
    {
        Customer::factory()->create(['email' => '  Test@Company.Example ']);

        $this->assertNotNull(Customer::findByEmail('test@company.example'));
        $this->expectException(UniqueConstraintViolationException::class);
        Customer::factory()->create(['email' => 'TEST@company.example']);
    }

    public function test_customer_and_user_are_related(): void
    {
        $customer = Customer::factory()->withUser()->create();

        $this->assertTrue($customer->user->is(User::sole()));
        $this->assertTrue(User::sole()->customer->is($customer));
    }
}
