<?php

namespace Tests\Feature;

use App\Billing\SubscriptionEntitlements;
use App\Billing\SubscriptionStatus;
use App\Checkout\CheckoutStatus;
use App\Invoicing\InvoiceIntentStatus;
use App\Invoicing\InvoiceIntentWorkflow;
use App\Mail\AccountActivation;
use App\Mail\SubscriptionActivated;
use App\Models\AdminAuditEvent;
use App\Models\BillingInvoice;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\CustomerBillingIdentity;
use App\Models\InvoiceIntent;
use App\Models\Promotion;
use App\Models\StripeEvent;
use App\Models\StripeWebhookInbox;
use App\Models\Subscription;
use App\Models\User;
use App\Pricing\BillingInterval;
use App\Pricing\PlanTier;
use App\Pricing\PricingCatalog;
use App\Pricing\ProductType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SendsStripeWebhooks;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase, SendsStripeWebhooks;

    private ?string $raceEmailToCleanUp = null;

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
            'customer_type' => 'individual',
            'country' => 'HU',
            'billing_address' => ['line1' => 'Teszt utca 1.', 'postal_code' => '1011', 'city' => 'Budapest'],
            'product_type' => 'wordpress',
            'plan' => 'business',
            'interval' => 'monthly',
            'market' => 'hu',
            'currency' => 'HUF',
            'amount' => 1899000,
            'tax_treatment' => 'final_price_no_vat_added',
            'stripe_price_id' => 'price_wordpress_business_monthly_HUF',
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

    public function test_declared_oversized_webhook_body_is_rejected_before_body_read(): void
    {
        $this->withServerVariables(['CONTENT_LENGTH' => (string) (2 * 1024 * 1024 + 1)])
            ->call('POST', route('stripe.webhook'))
            ->assertStatus(413);

        $this->assertDatabaseCount('stripe_webhook_inbox', 0);
    }

    public function test_stale_signed_webhook_is_rejected_before_inbox_persistence(): void
    {
        $this->stripeEvent('invoice.paid', ['id' => 'in_stale'], eventId: 'evt_stale_signature', signatureTimestamp: time() - 900)
            ->assertStatus(400);

        $this->assertSame(0, StripeWebhookInbox::where('stripe_event_id', 'evt_stale_signature')->count());
    }

    public function test_secret_rotation_is_a_single_secret_cutover_and_old_secret_is_rejected(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_rotated']);

        $this->stripeEvent('invoice.paid', ['id' => 'in_rotation'], eventId: 'evt_rotation', secret: 'whsec_previous')
            ->assertStatus(400);
        $this->assertSame(0, StripeWebhookInbox::where('stripe_event_id', 'evt_rotation')->count());

        $this->stripeEvent('invoice.paid', ['id' => 'in_rotation'], eventId: 'evt_rotation', secret: 'whsec_rotated')
            ->assertStatus(204);
        $this->assertSame(1, StripeWebhookInbox::where('stripe_event_id', 'evt_rotation')->count());
    }

    public function test_oversized_signed_webhook_is_rejected_before_inbox_persistence(): void
    {
        $this->stripeEvent('invoice.paid', ['id' => 'in_oversized', 'padding' => str_repeat('x', 2 * 1024 * 1024)], eventId: 'evt_oversized')
            ->assertStatus(413);

        $this->assertSame(0, StripeWebhookInbox::where('stripe_event_id', 'evt_oversized')->count());
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
        $this->assertSame('sub_test_1', $intent->stripe_subscription_id);

        $this->assertSame('anna.kovacs@company.example', $customer->email, 'Email is normalised.');
        $this->assertSame('cus_test_1', $customer->stripe_customer_id);
        $this->assertSame('HU', $customer->country_code);
        $identity = $customer->latestBillingIdentity();
        $this->assertInstanceOf(CustomerBillingIdentity::class, $identity);
        $this->assertSame($identity->id, $intent->billing_identity_id);
        $this->assertSame($identity->id, $subscription->billing_identity_id);
        $this->assertSame('Anna Kovács', $identity->legal_name);
        $this->assertSame('Teszt utca 1.', $identity->address_line1);

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
        $this->assertDatabaseCount('billing_email_outbox', 0);
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

        $this->assertDatabaseHas('billing_email_outbox', ['notification' => 'subscription_activated']);
        $this->assertDatabaseCount('billing_email_outbox', 1);
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
        $this->assertDatabaseCount('billing_email_outbox', 1);
        $this->assertDatabaseHas('billing_email_outbox', ['notification' => 'subscription_activated']);
    }

    public function test_concurrent_webhook_provisioning_recovers_from_customer_and_user_email_races(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This isolation test requires independent MySQL connections.');
        }

        $email = 'race-'.Str::uuid().'@company.example';
        $this->raceEmailToCleanUp = $email;
        $intent = $this->intent(['email' => $email]);
        $customerRaceInjected = false;
        $userRaceInjected = false;
        $this->useRaceConnection();

        Customer::creating(function (Customer $candidate) use (&$customerRaceInjected, $email) {
            if (! $customerRaceInjected && Customer::normalizeEmail($candidate->email) === Customer::normalizeEmail($email)) {
                $customerRaceInjected = true;
                DB::connection('race')->table('customers')->insert([
                    'email' => $candidate->email,
                    'first_name' => 'Concurrent',
                    'last_name' => 'Customer',
                    'country_code' => 'DE',
                    'customer_type' => 'individual',
                    'uuid' => (string) Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        User::creating(function (User $candidate) use (&$userRaceInjected, $email) {
            if (! $userRaceInjected && Customer::normalizeEmail($candidate->email) === Customer::normalizeEmail($email)) {
                $userRaceInjected = true;
                DB::connection('race')->table('users')->insert([
                    'name' => 'Concurrent Login',
                    'email' => $candidate->email,
                    'password' => bcrypt(Str::random(48)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->completeCheckout($intent, eventId: 'evt_checkout_race')->assertStatus(204);
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent, eventId: 'evt_subscription_race')->assertStatus(204);
        $this->stripeEvent('invoice.paid', $this->invoice($intent), eventId: 'evt_invoice_race')->assertStatus(204);

        $intent->refresh();
        $customer = Customer::query()->where('email', $email)->lockForUpdate()->sole();
        $user = User::query()->where('email', $email)->lockForUpdate()->sole();
        $subscription = Subscription::sole();

        $this->assertTrue($customerRaceInjected);
        $this->assertTrue($userRaceInjected);
        $this->assertSame(CheckoutStatus::Completed, $intent->status);
        $this->assertSame($customer->id, $intent->customer_id);
        $this->assertSame('sub_test_1', $intent->stripe_subscription_id);
        $this->assertSame('Concurrent', $customer->first_name, 'A raced-in customer profile is not overwritten by checkout input.');
        $this->assertSame('DE', $customer->country_code);
        $this->assertSame('Concurrent Login', $user->name);
        $this->assertSame($customer->id, $user->customer->id);
        $this->assertSame($customer->id, $subscription->customer_id);
        $this->assertSame($intent->id, $subscription->checkout_intent_id);
        $this->assertSame(1, DB::connection('race')->table('customers')->where('email', $email)->count());
        $this->assertSame(1, DB::connection('race')->table('users')->where('email', $email)->count());
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billing_invoices', 1);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->raceEmailToCleanUp === null || getenv('DB_CONNECTION') !== 'mysql') {
            return;
        }

        $connection = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: '127.0.0.1',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_DATABASE') ?: '',
        );
        $pdo = new \PDO($connection, getenv('DB_USERNAME') ?: '', getenv('DB_PASSWORD') ?: '');
        $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$this->raceEmailToCleanUp]);
        $pdo->prepare('DELETE FROM customers WHERE email = ?')->execute([$this->raceEmailToCleanUp]);
        $pdo = null;
    }

    private function useRaceConnection(): void
    {
        $defaultConnection = DB::connection();
        $connectionConfig = $defaultConnection->getConfig();
        $connectionConfig['database'] = $defaultConnection->getDatabaseName();

        config(['database.connections.race' => $connectionConfig]);
        DB::purge('race');
    }

    public function test_an_older_subscription_event_does_not_overwrite_newer_state(): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent);

        $this->subscriptionEvent('customer.subscription.updated', 'past_due', $intent, created: now()->timestamp);
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent, created: now()->subMinutes(5)->timestamp);

        $this->assertSame(SubscriptionStatus::PastDue, Subscription::sole()->status);
    }

    public function test_same_second_subscription_events_use_a_stable_event_id_tie_break(): void
    {
        $created = now()->timestamp;
        $first = $this->intent();
        $second = $this->intent(['email' => 'other@example.test', 'stripe_checkout_session_id' => 'cs_test_2']);

        $this->subscriptionEvent('customer.subscription.updated', 'past_due', $first, ['id' => 'sub_first'], $created, 'evt_aa_old')->assertStatus(204);
        $this->subscriptionEvent('customer.subscription.updated', 'active', $first, ['id' => 'sub_first'], $created, 'evt_zz_winner')->assertStatus(204);
        $this->subscriptionEvent('customer.subscription.updated', 'active', $second, ['id' => 'sub_second', 'customer' => 'cus_test_2'], $created, 'evt_zz_winner_second')->assertStatus(204);
        $this->subscriptionEvent('customer.subscription.updated', 'past_due', $second, ['id' => 'sub_second', 'customer' => 'cus_test_2'], $created, 'evt_aa_loser_second')->assertStatus(204);

        $this->assertSame(SubscriptionStatus::Active, Subscription::where('stripe_subscription_id', 'sub_first')->sole()->status);
        $this->assertSame(SubscriptionStatus::Active, Subscription::where('stripe_subscription_id', 'sub_second')->sole()->status);
        $this->assertSame('evt_zz_winner', Subscription::where('stripe_subscription_id', 'sub_first')->sole()->stripe_synced_event_id);
        $this->assertSame('evt_zz_winner_second', Subscription::where('stripe_subscription_id', 'sub_second')->sole()->stripe_synced_event_id);
    }

    public function test_same_second_cancellation_wins_and_cannot_be_resurrected(): void
    {
        $intent = $this->intent();
        $created = now()->timestamp;
        $this->subscriptionEvent('customer.subscription.updated', 'active', $intent, created: $created, eventId: 'evt_zz_active')->assertStatus(204);
        $this->subscriptionEvent('customer.subscription.deleted', 'canceled', $intent, created: $created, eventId: 'evt_aa_deleted')->assertStatus(204);
        $this->subscriptionEvent('customer.subscription.updated', 'active', $intent, created: $created, eventId: 'evt_zzz_resurrect')->assertStatus(204);

        $this->assertSame(SubscriptionStatus::Canceled, Subscription::sole()->status);
        $this->assertSame('evt_aa_deleted', Subscription::sole()->stripe_synced_event_id);
    }

    public function test_subscription_event_with_a_foreign_customer_cannot_reassign_local_billing(): void
    {
        $intent = $this->intent();
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent)->assertStatus(204);
        $subscription = Subscription::sole();
        $customerId = $subscription->customer_id;

        $this->subscriptionEvent('customer.subscription.deleted', 'canceled', $intent, ['customer' => 'cus_foreign'], eventId: 'evt_foreign_customer')->assertStatus(204);

        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame($customerId, $subscription->customer_id);
        $this->assertSame('cus_test_1', $subscription->stripe_customer_id);
    }

    public function test_checkout_cannot_attach_a_stripe_customer_mapped_to_another_email(): void
    {
        $intent = $this->intent();
        $otherCustomer = Customer::factory()->create([
            'email' => 'different@example.test',
            'stripe_customer_id' => 'cus_other_customer',
        ]);

        $this->completeCheckout($intent, ['customer' => 'cus_other_customer'])->assertStatus(500);

        $this->assertSame(CheckoutStatus::CheckoutCreated, $intent->refresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('stripe_events', 0);
        $this->assertSame('different@example.test', $otherCustomer->refresh()->email);
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

    public function test_signed_webhook_payload_is_durably_encrypted_before_processing(): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent, eventId: 'evt_inbox');

        $receipt = StripeWebhookInbox::sole();
        $stored = DB::table('stripe_webhook_inbox')->where('id', $receipt->id)->value('payload');

        $this->assertSame('evt_inbox', $receipt->stripe_event_id);
        $this->assertSame(StripeWebhookInbox::STATUS_PROCESSED, $receipt->status);
        $this->assertSame(1, $receipt->attempts);
        $this->assertNotSame($stored, $receipt->payload, 'The database value must be encrypted, not plaintext JSON.');
        $this->assertStringContainsString('checkout.session.completed', $receipt->payload);
        $this->assertNotNull($receipt->processed_at);
    }

    public function test_live_mode_event_is_refused_by_test_mode_configuration(): void
    {
        config(['services.stripe.secret' => 'sk_test_M11_fake']);

        $this->stripeEvent('invoice.paid', ['id' => 'in_test'], eventId: 'evt_wrong_mode', livemode: true)
            ->assertStatus(400);

        $this->assertDatabaseCount('stripe_webhook_inbox', 0);
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public function test_failed_signed_event_remains_in_retryable_inbox_and_redelivery_succeeds(): void
    {
        $intent = $this->intent();
        $failing = true;
        Subscription::creating(function () use (&$failing) {
            if ($failing) {
                throw new \RuntimeException('Database unavailable.');
            }
        });

        $this->completeCheckout($intent, eventId: 'evt_durable_retry')->assertStatus(500);
        $receipt = StripeWebhookInbox::sole();
        $this->assertSame(StripeWebhookInbox::STATUS_FAILED, $receipt->status);
        $this->assertSame(1, $receipt->attempts);
        $this->assertSame('RuntimeException', $receipt->last_error_category);
        $this->assertStringNotContainsString('Database unavailable', $receipt->last_error_category);
        $this->assertDatabaseCount('stripe_events', 0);

        $failing = false;
        $this->completeCheckout($intent, eventId: 'evt_durable_retry')->assertStatus(204);
        $this->assertSame(StripeWebhookInbox::STATUS_PROCESSED, $receipt->refresh()->status);
        $this->assertSame(2, $receipt->attempts);
        $this->assertDatabaseCount('stripe_events', 1);
    }

    // --- Payment not (yet) confirmed ----------------------------------------

    public function test_a_paid_checkout_redeems_its_promotion_once(): void
    {
        // Found in the real Stripe test run: redemptions were never counted, so max_redemptions never applied.
        $promotion = Promotion::create(['code' => 'PILOT10', 'stripe_promotion_code_id' => 'promo_1', 'discount_type' => 'percent', 'percent_off' => 10, 'max_redemptions' => 1]);
        $intent = $this->intent(['promotion_id' => $promotion->id, 'promotion_code' => 'PILOT10']);
        $unpaid = $this->intent(['promotion_id' => $promotion->id, 'promotion_code' => 'PILOT10', 'stripe_checkout_session_id' => 'cs_test_2']);

        $this->completeCheckout($unpaid, ['id' => 'cs_test_2', 'payment_status' => 'unpaid', 'subscription' => 'sub_test_2'])->assertStatus(204);
        $this->assertSame(0, $promotion->refresh()->times_redeemed, 'Not redeemed before Stripe reports the payment.');

        $this->completeCheckout($intent, eventId: 'evt_paid')->assertStatus(204);
        $this->completeCheckout($intent, eventId: 'evt_paid')->assertStatus(204);
        $this->completeCheckout($intent, ['payment_status' => 'paid'], eventId: 'evt_async', type: 'checkout.session.async_payment_succeeded')->assertStatus(204);

        $this->assertSame(1, $promotion->refresh()->times_redeemed, 'Counted once per completed checkout, never per event.');
        $catalog = app(PricingCatalog::class);
        $price = $catalog->price(ProductType::WordPress, PlanTier::Business, BillingInterval::Monthly, $catalog->market('hu'));
        $this->assertSame('used_up', $promotion->ineligibilityReason($price), 'The next checkout with the code is refused.');
    }

    public function test_a_failure_while_processing_leaves_nothing_behind_and_the_redelivery_succeeds(): void
    {
        // The real mailer on the sync queue: Mail::fake() would record mail queued inside the rolled-back transaction.
        Mail::swap(new MailManager($this->app));
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent) {
            $sent[] = $event->message->getSubject();
        });

        $intent = $this->intent();
        $failing = true;
        Subscription::creating(function () use (&$failing) {
            if ($failing) {
                throw new \RuntimeException('Database unavailable.');
            }
        });

        $this->completeCheckout($intent, eventId: 'evt_retry')->assertStatus(500);

        $this->assertSame(CheckoutStatus::CheckoutCreated, $intent->refresh()->status, 'Rolled back with the failure.');
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('stripe_events', 0);
        $this->assertSame([], $sent, 'No email for a login that was rolled back.');

        // Stripe retries the same event; it is processed once.
        $failing = false;
        $this->completeCheckout($intent, eventId: 'evt_retry')->assertStatus(204);
        $this->completeCheckout($intent, eventId: 'evt_retry')->assertStatus(204);

        $this->assertSame(CheckoutStatus::Completed, $intent->refresh()->status);
        $this->assertSame(1, Customer::count());
        $this->assertSame(1, Subscription::count());
        $this->assertSame(1, StripeEvent::where('stripe_event_id', 'evt_retry')->count());
        $this->assertSame([(new AccountActivation(Customer::sole(), 'x'))->envelope()->subject], $sent, 'One activation email, after the successful retry.');
    }

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
        $expectedAccess = $local->grantsAccess() || $local === SubscriptionStatus::PastDue;
        $this->assertSame($expectedAccess, $subscription->customer->hasActiveSubscription());
    }

    public function test_past_due_grants_fixed_grace_then_unpaid_restricts_access(): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent);
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent, created: now()->subMinutes(2)->timestamp);
        $customer = Customer::sole();
        $this->assertTrue($customer->hasActiveSubscription());

        $this->subscriptionEvent('customer.subscription.updated', 'past_due', $intent, created: now()->subMinute()->timestamp);
        $subscription = Subscription::sole();
        $this->assertTrue($customer->hasActiveSubscription());
        $this->assertNotNull($subscription->grace_started_at);
        $this->assertSame($subscription->grace_started_at->copy()->addDays(7)->timestamp, $subscription->grace_ends_at->timestamp);

        Carbon::setTestNow($subscription->grace_ends_at->copy()->addSecond());
        $this->assertFalse($customer->fresh()->hasActiveSubscription());
        $this->assertFalse($subscription->fresh()->grantsAccess());
        $this->assertTrue(app(SubscriptionEntitlements::class)->allows($subscription->fresh(), 'restore'));
        Carbon::setTestNow();

        $this->subscriptionEvent('customer.subscription.updated', 'unpaid', $intent);
        $this->assertFalse($customer->hasActiveSubscription());
        $this->assertSame(SubscriptionStatus::Unpaid, Subscription::sole()->status);
    }

    public function test_duplicate_failed_payment_does_not_extend_grace_and_paid_invoice_clears_it(): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent);
        $this->subscriptionEvent('customer.subscription.created', 'past_due', $intent);
        $subscription = Subscription::sole();
        $startedAt = $subscription->grace_started_at->copy();
        $endsAt = $subscription->grace_ends_at->copy();

        $this->stripeEvent('invoice.payment_failed', $this->invoice($intent, ['id' => 'in_failed_again']))->assertStatus(204);
        $this->assertTrue($subscription->refresh()->grace_started_at->equalTo($startedAt));
        $this->assertTrue($subscription->grace_ends_at->equalTo($endsAt));

        $this->stripeEvent('invoice.paid', $this->invoice($intent, ['id' => 'in_recovered', 'amount_paid' => 1899000]))->assertStatus(204);
        $this->assertNull($subscription->refresh()->grace_started_at);
        $this->assertNull($subscription->grace_ends_at);
    }

    /** @return array<string, array{string}> */
    public static function graceFailureEventOrders(): array
    {
        return [
            'subscription status first' => ['subscription_first'],
            'invoice failure first' => ['invoice_first'],
        ];
    }

    #[DataProvider('graceFailureEventOrders')]
    public function test_invoice_failure_and_past_due_status_record_one_grace_transition(string $order): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent);
        $this->subscriptionEvent('customer.subscription.created', 'active', $intent, created: now()->subMinutes(2)->timestamp);

        if ($order === 'invoice_first') {
            $this->stripeEvent('invoice.payment_failed', $this->invoice($intent, ['id' => 'in_grace_order']), 'evt_grace_invoice_first', now()->timestamp)->assertStatus(204);
            $this->subscriptionEvent('customer.subscription.updated', 'past_due', $intent, created: now()->addSecond()->timestamp, eventId: 'evt_grace_status_second')->assertStatus(204);
        } else {
            $this->subscriptionEvent('customer.subscription.updated', 'past_due', $intent, created: now()->timestamp, eventId: 'evt_grace_status_first')->assertStatus(204);
            $this->stripeEvent('invoice.payment_failed', $this->invoice($intent, ['id' => 'in_grace_order']), 'evt_grace_invoice_second', now()->addSecond()->timestamp)->assertStatus(204);
        }

        $subscription = Subscription::sole();
        $this->assertSame(604800, $subscription->grace_ends_at->timestamp - $subscription->grace_started_at->timestamp);
        $this->assertSame(1, AdminAuditEvent::query()
            ->where('event', 'billing.grace_started')
            ->where('subject_type', 'subscription')
            ->where('subject_key', $subscription->uuid)
            ->count());
        $this->assertSame(1, $subscription->customer->billingEmailOutbox()->where('notification', 'grace_started')->count());
        $this->assertSame(1, $subscription->customer->billingEmailOutbox()->where('notification', 'payment_failed')->count());
        $this->assertDatabaseHas('billing_email_outbox', [
            'event_key' => 'subscription:'.$subscription->uuid.':grace-started:'.$subscription->grace_started_at->utc()->format('YmdHis'),
            'notification' => 'grace_started',
        ]);
        $this->assertDatabaseHas('billing_email_outbox', [
            'event_key' => 'invoice:in_grace_order:payment-failed',
            'notification' => 'payment_failed',
        ]);
    }

    /** @return array<string, array{string}> */
    public static function graceRecoveryEventOrders(): array
    {
        return [
            'subscription status first' => ['subscription_first'],
            'paid invoice first' => ['invoice_first'],
        ];
    }

    #[DataProvider('graceRecoveryEventOrders')]
    public function test_recovery_clears_previous_grace_expiry_in_either_event_order(string $order): void
    {
        $intent = $this->intent();
        $this->completeCheckout($intent);
        $this->subscriptionEvent('customer.subscription.created', 'past_due', $intent, created: now()->subMinute()->timestamp);
        $subscription = Subscription::sole();
        $subscription->forceFill([
            'last_payment_status' => 'failed',
            'grace_expired_at' => now()->subSecond(),
        ])->save();

        if ($order === 'invoice_first') {
            $this->stripeEvent('invoice.paid', $this->invoice($intent, ['id' => 'in_grace_recovered', 'amount_paid' => 1899000]), 'evt_grace_paid_first', now()->timestamp)->assertStatus(204);
            $this->subscriptionEvent('customer.subscription.updated', 'active', $intent, created: now()->addSecond()->timestamp, eventId: 'evt_grace_active_second')->assertStatus(204);
        } else {
            $this->subscriptionEvent('customer.subscription.updated', 'active', $intent, created: now()->timestamp, eventId: 'evt_grace_active_first')->assertStatus(204);
            $this->stripeEvent('invoice.paid', $this->invoice($intent, ['id' => 'in_grace_recovered', 'amount_paid' => 1899000]), 'evt_grace_paid_second', now()->addSecond()->timestamp)->assertStatus(204);
        }

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->grace_started_at);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertNull($subscription->grace_expired_at);
        $this->assertSame(1, $subscription->customer->billingEmailOutbox()->where('notification', 'payment_recovered')->count());
        $this->assertDatabaseHas('admin_audit_events', [
            'event' => 'billing.payment_recovered',
            'subject_type' => 'subscription',
            'subject_key' => $subscription->uuid,
        ]);
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
        $this->assertDatabaseHas('billing_email_outbox', ['notification' => 'subscription_canceled']);
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
        Queue::fake();
        $intent = $this->intent();
        $this->completeCheckout($intent);

        $this->stripeEvent('invoice.paid', $this->invoice($intent), eventId: 'evt_invoice_identity_snapshot')->assertStatus(204);
        $this->stripeEvent('invoice.paid', $this->invoice($intent), eventId: 'evt_invoice_identity_snapshot')->assertStatus(204);

        $subscription = Subscription::sole();
        $customer = Customer::sole();
        $identity = $customer->latestBillingIdentity();
        $invoice = BillingInvoice::sole();
        $invoiceIntent = InvoiceIntent::sole();
        app(InvoiceIntentWorkflow::class)->prepare($invoiceIntent->id);
        $invoiceIntent->refresh();

        $this->assertSame('paid', $subscription->last_payment_status);
        $this->assertNotNull($subscription->last_payment_at);
        $this->assertNotNull($identity);
        $this->assertSame($identity->id, $intent->fresh()->billing_identity_id);
        $this->assertSame($identity->id, $subscription->billing_identity_id);
        $this->assertSame($identity->id, $invoice->billing_identity_id);
        $this->assertSame($identity->id, $invoiceIntent->billing_identity_id);
        $this->assertArrayHasKey('billing_identity', $invoiceIntent->commercial_snapshot);
        $this->assertNotSame(InvoiceIntentStatus::BlockedIdentity, $invoiceIntent->status);
        $this->assertNotSame('billing_identity_missing', $invoiceIntent->blocked_reason);
        $this->assertSame(1, InvoiceIntent::query()->count(), 'Duplicate payment delivery creates only one invoice intent.');
        $this->assertDatabaseHas('billing_email_outbox', ['event_key' => 'invoice:in_test_1:payment-received', 'notification' => 'payment_received']);
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
        $this->assertDatabaseHas('billing_email_outbox', ['event_key' => 'invoice:in_test_2:payment-failed', 'notification' => 'payment_failed']);
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
