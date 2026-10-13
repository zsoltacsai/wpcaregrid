<?php

namespace Tests\Feature\Owner;

use App\Billing\StripeSubscriptionReader;
use App\Billing\SubscriptionEntitlements;
use App\Billing\SubscriptionStatus;
use App\Models\AdminAuditEvent;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsOwner;
use Tests\Concerns\SendsStripeWebhooks;
use Tests\TestCase;

class M11LifecycleValidationTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase, SendsStripeWebhooks;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            // Staging intentionally runs with APP_ENV=production. The exact
            // deployment identity, not APP_ENV, is the staging boundary.
            'app.env' => 'production',
            'app.deployment_environment' => 'staging',
            'app.url' => 'https://staging.wpcaregrid.com',
            'app.deployment_hostname' => 'stg1.wpcaregrid.com',
            'services.stripe.mode' => 'test',
            'services.stripe.secret' => 'sk_test_m11_fixture',
            'services.stripe.webhook_secret' => 'whsec_test_m11_fixture',
        ]);
        $this->useWebhookSecret();
    }

    public function test_harness_is_owner_only_and_fails_closed_outside_exact_staging_identity(): void
    {
        $this->assertSame('production', config('app.env'));
        $this->get(route('owner.billing.m11-lifecycle-validation.index'))->assertRedirect(route('owner.login'));
        $owner = $this->owner();
        $this->actingAs($owner, 'owner');
        $this->get(route('owner.billing.m11-lifecycle-validation.index'))->assertOk()->assertSee('Stripe TEST-mode');

        $customerUser = Customer::factory()->withUser()->create(['email' => 'unauthorized@company.example'])->user;
        $this->actingAs($customerUser, 'owner')->get(route('owner.billing.m11-lifecycle-validation.index'))->assertRedirect(route('owner.login'));
        $this->actingAs($owner, 'owner');

        config(['app.deployment_environment' => 'production']);
        $this->post(route('owner.billing.m11-lifecycle-validation.run'), [
            'email' => 'contact+m11-test@zsoltacsai.com',
            'scenario' => 'payment_failure',
            'confirmation_phrase' => 'RUN M11 STAGING LIFECYCLE TEST',
        ])->assertNotFound();
        config(['app.deployment_environment' => 'staging', 'services.stripe.mode' => 'live']);
        $this->post(route('owner.billing.m11-lifecycle-validation.run'), [
            'email' => 'contact+m11-test@zsoltacsai.com',
            'scenario' => 'payment_failure',
            'confirmation_phrase' => 'RUN M11 STAGING LIFECYCLE TEST',
        ])->assertNotFound();
        $this->assertDatabaseMissing('stripe_events', ['type' => 'customer.subscription.updated']);
    }

    public function test_non_synthetic_email_is_rejected_without_webhook_or_subscription_changes(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'owner')->post(route('owner.billing.m11-lifecycle-validation.run'), [
            'email' => 'person@company.example',
            'scenario' => 'payment_failure',
            'confirmation_phrase' => 'RUN M11 STAGING LIFECYCLE TEST',
        ])->assertNotFound();

        $this->assertDatabaseCount('stripe_events', 0);
        $this->assertDatabaseHas('admin_audit_events', [
            'event' => 'billing.m11_validation_run',
            'actor_user_id' => $owner->id,
        ]);
    }

    public function test_payment_failure_duplicate_replay_and_recovery_follow_real_signed_webhook_handlers(): void
    {
        [$customer, $subscription] = $this->syntheticSubscription('contact+m11-lifecycle@zsoltacsai.com');
        $owner = $this->owner();
        $this->actingAs($owner, 'owner');

        $response = $this->runScenario($customer, 'payment_failure');
        $response->assertSessionHas('status');
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertNotNull($subscription->grace_started_at);
        $this->assertSame($subscription->grace_started_at->copy()->addDays(7)->timestamp, $subscription->grace_ends_at->timestamp);
        $this->assertTrue($customer->fresh()->hasActiveSubscription());
        $this->assertDatabaseHas('billing_email_outbox', ['notification' => 'grace_started', 'subscription_id' => $subscription->id]);
        $this->assertDatabaseHas('billing_email_outbox', ['notification' => 'payment_failed', 'subscription_id' => $subscription->id]);

        $graceStart = $subscription->grace_started_at->timestamp;
        $graceEnd = $subscription->grace_ends_at->timestamp;
        $this->runScenario($customer, 'duplicate_failure')->assertSessionHas('status');
        $subscription->refresh();
        $this->assertSame($graceStart, $subscription->grace_started_at->timestamp);
        $this->assertSame($graceEnd, $subscription->grace_ends_at->timestamp);
        $this->assertSame(1, $customer->billingEmailOutbox()->where('notification', 'payment_failed')->count());
        $this->assertSame(1, $customer->billingEmailOutbox()->where('notification', 'grace_started')->count());

        $this->runScenario($customer, 'recovery')->assertSessionHas('status');
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->grace_started_at);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertTrue($customer->fresh()->hasActiveSubscription());
        $this->assertDatabaseHas('billing_email_outbox', ['notification' => 'payment_recovered', 'subscription_id' => $subscription->id]);
        $this->assertSame(1, $customer->billingEmailOutbox()->where('notification', 'grace_started')->count());
        $this->assertSame(1, $customer->billingEmailOutbox()->where('notification', 'payment_failed')->count());
        $this->assertSame(1, $customer->billingEmailOutbox()->where('notification', 'payment_recovered')->count());
        $this->assertSame(3, DB::table('admin_audit_events')->where('event', 'billing.m11_validation_run')->where('actor_user_id', $owner->id)->count());
    }

    public function test_grace_expiry_is_scoped_to_the_one_synthetic_subscription_and_retains_restore_entitlement(): void
    {
        Queue::fake();
        [$customer, $subscription] = $this->syntheticSubscription('contact+m11-expiry@zsoltacsai.com');
        $other = Customer::factory()->create(['email' => 'other@company.example']);
        $otherSubscription = Subscription::factory()->for($other)->create([
            'status' => SubscriptionStatus::PastDue,
            'grace_started_at' => now()->subDays(8),
            'grace_ends_at' => now()->subDay(),
        ]);
        $owner = $this->owner();
        $this->actingAs($owner, 'owner');

        $this->runScenario($customer, 'grace_expiry')->assertSessionHas('status');

        $this->assertNotNull($subscription->refresh()->grace_expired_at);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($customer->fresh()->hasActiveSubscription());
        $this->assertTrue(app(SubscriptionEntitlements::class)->allows($subscription->fresh(), 'restore'));
        $this->assertNull($otherSubscription->refresh()->grace_expired_at);
        $run = AdminAuditEvent::query()->where('event', 'billing.m11_validation_run')->latest('id')->firstOrFail();
        $this->assertSame('past_due', $run->data['simulated_status']);
        $this->assertFalse($run->data['simulated_commercial_access']);
        $this->assertTrue($run->data['simulated_restore_entitlement']);
        $this->assertDatabaseHas('admin_audit_events', [
            'event' => 'billing.grace_expired',
            'subject_key' => $subscription->uuid,
        ]);
        $this->assertDatabaseHas('billing_email_outbox', [
            'subscription_id' => $subscription->id,
            'notification' => 'billing_action_required',
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseMissing('billing_email_outbox', [
            'subscription_id' => $subscription->id,
            'notification' => 'billing_action_required',
            'status' => 'pending',
        ]);
    }

    public function test_processed_webhook_replay_and_out_of_order_event_are_idempotent(): void
    {
        [$customer, $subscription] = $this->syntheticSubscription('contact+m11-ordering@zsoltacsai.com');
        $owner = $this->owner();
        $this->actingAs($owner, 'owner');

        $this->stripeEvent('customer.subscription.updated', $this->subscriptionPayload($subscription, 'active'), 'evt_m11_replay_source')->assertStatus(204);
        $this->runScenario($customer, 'webhook_replay')->assertSessionHas('status');
        $this->assertDatabaseCount('stripe_events', 1);

        $this->runScenario($customer, 'payment_failure')->assertSessionHas('status');
        $graceStart = $subscription->fresh()->grace_started_at?->timestamp;
        $graceEnd = $subscription->fresh()->grace_ends_at?->timestamp;
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->fresh()->status);

        $this->runScenario($customer, 'out_of_order')->assertSessionHas('status');
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->fresh()->status);
        $this->assertSame($graceStart, $subscription->fresh()->grace_started_at?->timestamp);
        $this->assertSame($graceEnd, $subscription->fresh()->grace_ends_at?->timestamp);
    }

    public function test_reconciliation_action_reads_and_repairs_only_the_selected_synthetic_subscription(): void
    {
        [$customer, $subscription] = $this->syntheticSubscription('contact+m11-reconcile@zsoltacsai.com');
        $other = Customer::factory()->create(['email' => 'unrelated@company.example']);
        $otherSubscription = Subscription::factory()->for($other)->create(['billing_source' => 'stripe']);
        $owner = $this->owner();
        $this->actingAs($owner, 'owner');
        $this->runScenario($customer, 'payment_failure')->assertSessionHas('status');

        $this->app->instance(StripeSubscriptionReader::class, new class($subscription) implements StripeSubscriptionReader
        {
            public function __construct(private Subscription $subscription) {}

            public function retrieve(string $stripeSubscriptionId): object
            {
                return (object) [
                    'id' => $stripeSubscriptionId,
                    'customer' => $this->subscription->stripe_customer_id,
                    'status' => 'active',
                    'cancel_at_period_end' => false,
                    'metadata' => (object) [],
                    'latest_invoice' => null,
                    'items' => (object) ['data' => [(object) [
                        'price' => (object) ['id' => $this->subscription->stripe_price_id, 'unit_amount' => $this->subscription->amount, 'currency' => strtolower($this->subscription->currency)],
                        'current_period_start' => now()->timestamp,
                        'current_period_end' => now()->addMonth()->timestamp,
                    ]]],
                ];
            }
        });

        $this->runScenario($customer, 'reconcile')->assertSessionHas('status');
        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        $this->assertSame('healthy', $subscription->fresh()->reconciliation_status);
        $this->assertSame(SubscriptionStatus::Active, $otherSubscription->fresh()->status);
        $this->assertNull($otherSubscription->fresh()->last_reconciled_at);
    }

    private function runScenario(Customer $customer, string $scenario)
    {
        return $this->post(route('owner.billing.m11-lifecycle-validation.run'), [
            'email' => $customer->email,
            'scenario' => $scenario,
            'confirmation_phrase' => 'RUN M11 STAGING LIFECYCLE TEST',
        ]);
    }

    /** @return array{Customer, Subscription} */
    private function syntheticSubscription(string $email): array
    {
        $customer = Customer::factory()->withUser()->create([
            'email' => $email,
            'stripe_customer_id' => 'cus_m11_'.Str::lower(Str::random(12)),
        ]);
        $intent = CheckoutIntent::create([
            'status' => 'completed',
            'email' => $email,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'country' => 'HU',
            'product_type' => 'wordpress',
            'plan' => 'basic',
            'interval' => 'monthly',
            'market' => 'hu',
            'currency' => 'HUF',
            'amount' => 899000,
            'tax_treatment' => 'final_price_no_vat_added',
            'stripe_price_id' => 'price_wordpress_basic_monthly_HUF',
            'stripe_customer_id' => $customer->stripe_customer_id,
            'stripe_subscription_id' => 'sub_m11_'.Str::lower(Str::random(12)),
            'stripe_payment_status' => 'paid',
            'customer_id' => $customer->id,
            'completed_at' => now(),
        ]);
        $subscription = Subscription::factory()->for($customer)->create([
            'stripe_customer_id' => $customer->stripe_customer_id,
            'stripe_subscription_id' => $intent->stripe_subscription_id,
            'stripe_price_id' => $intent->stripe_price_id,
            'checkout_intent_id' => $intent->id,
            'billing_source' => 'stripe',
            'status' => SubscriptionStatus::Active,
            'activated_at' => now()->subDay(),
            'activation_notified_at' => now()->subDay(),
            'stripe_synced_at' => now()->subMinute(),
            'stripe_synced_event_id' => 'evt_m11_seed',
        ]);

        return [$customer, $subscription];
    }

    private function subscriptionPayload(Subscription $subscription, string $status): array
    {
        return [
            'id' => $subscription->stripe_subscription_id,
            'object' => 'subscription',
            'customer' => $subscription->stripe_customer_id,
            'status' => $status,
            'cancel_at_period_end' => false,
            'metadata' => [],
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_m11_test',
                'object' => 'subscription_item',
                'current_period_start' => now()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'price' => ['id' => $subscription->stripe_price_id, 'object' => 'price', 'unit_amount' => $subscription->amount, 'currency' => strtolower($subscription->currency)],
            ]]],
        ];
    }
}
